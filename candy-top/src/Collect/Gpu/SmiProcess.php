<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\GpuOutcome;

use function React\Promise\resolve;

/**
 * One bounded `nvidia-smi` child — the only place candy-top's GPU code
 * spawns. Two ways to drive it, sharing the spawn, the timeout and the
 * reaping:
 *  - {@see run()} blocks until the child exits or {@see TIMEOUT} passes
 *    (the startup probe, scripted tests, any caller outside the loop);
 *  - {@see launch()} returns at once and collects stdout through a loop
 *    read stream, so a 0.25-1 s `nvidia-smi pmon` never stalls input or
 *    rendering (measured on a 4-GPU host: the blocking form froze the UI
 *    for ~0.7 s every query cycle).
 *
 * The child is spawned with an argv array (no shell), stdin and stderr on
 * /dev/null. On overrun it is SIGKILLed; it is reaped without ever
 * blocking the loop: an exited child is closed at once, a killed one is
 * polled on a loop timer for {@see REAP_WINDOW} seconds, and one that
 * still has not died (uninterruptible sleep in a wedged driver) stays in
 * a process-wide registry that every later spawn polls (WNOHANG) and that
 * a shutdown hook SIGKILLs and reaps when candy-top exits. Cleanup is
 * bounded, not absolute: a child stuck in uninterruptible sleep ignores
 * even SIGKILL, so after the shutdown wait ({@see REAP_WAIT}) it is left
 * to init, which reaps it once the driver lets it die. While candy-top
 * runs, no exited child stays a zombie.
 *
 * Descriptors: the spec maps 0-2 only, so the child inherits the parent's
 * other open descriptors for its (bounded, ≤ TIMEOUT) life. nvidia-smi
 * never reads or writes them, and none of them is a pipe whose EOF anyone
 * waits for (a sibling child's pipe write end is closed in the parent),
 * which is the accounting row in tools/check-child-lifetimes.php.
 *
 * Mutable on purpose: it is an OS handle, not a value.
 */
final class SmiProcess
{
    /** Seconds a query may run before it is killed (a hung driver call). */
    public const float TIMEOUT = 2.0;

    /** Seconds {@see run()} waits for a killed child before leaving it to the registry. */
    public const float REAP_WAIT = 0.5;

    /** Seconds the loop keeps polling a killed / finishing child before leaving it to the registry. */
    public const float REAP_WINDOW = 2.0;

    /** Seconds between non-blocking exit polls on the loop. */
    private const float REAP_POLL = 0.02;

    /** @var array<int, self> every spawned child not yet closed, by object id */
    private static array $live = [];

    private static bool $hooked = false;

    private string $output = '';

    private bool $eof = false;

    /** Exit code once proc_get_status() saw the child gone (it reports it only once). */
    private ?int $exit = null;

    private bool $closed = false;

    /**
     * @param resource $process
     * @param resource|null $stdout
     */
    private function __construct(
        private $process,
        private $stdout,
    ) {
    }

    /**
     * Blocking: [Ok, stdout] on exit 0, [Absent, ''] on any other exit or
     * when nothing could be spawned, [Timeout, ''] past `$timeout`.
     *
     * @param list<string> $argv argv[0] must be an executable path
     * @return array{0: GpuOutcome, 1: string}
     */
    public static function run(array $argv, float $timeout = self::TIMEOUT): array
    {
        $child = self::spawn($argv);
        if ($child === null) {
            return [GpuOutcome::Absent, ''];
        }
        $deadline = self::now() + $timeout;
        while (!$child->drain() && ($left = $deadline - self::now()) > 0) {
            $read = [$child->stdout];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, (int) max(1000, min(200000, $left * 1e6)));
        }
        $child->closeStdout();
        if ($child->eof) {
            // Closing stdout is the child's last act; give it the rest of the budget to exit.
            while (!$child->exited() && self::now() < $deadline) {
                usleep(2000);
            }
            if ($child->exited()) {
                return $child->finish();
            }
        }
        $child->kill();
        $reapBy = self::now() + self::REAP_WAIT;
        while (!$child->exited() && self::now() < $reapBy) {
            usleep(10000);
        }
        $child->release();

        return [GpuOutcome::Timeout, ''];
    }

    /**
     * Non-blocking: the same outcomes as {@see run()}, delivered by the
     * loop. Resolves on the loop thread; never rejects.
     *
     * @param list<string> $argv argv[0] must be an executable path
     * @return PromiseInterface<array{0: GpuOutcome, 1: string}>
     */
    public static function launch(array $argv, ?LoopInterface $loop = null, float $timeout = self::TIMEOUT): PromiseInterface
    {
        $child = self::spawn($argv);
        if ($child === null) {
            return resolve([GpuOutcome::Absent, '']);
        }
        $loop ??= Loop::get();
        $deferred = new Deferred();
        $deadline = self::now() + $timeout;
        $stdout = $child->stdout;
        $timer = null;

        $timedOut = static function () use ($child, $loop, $deferred, $stdout): void {
            $loop->removeReadStream($stdout);
            $child->closeStdout();
            $child->kill();
            $child->reapOnLoop($loop);
            $deferred->resolve([GpuOutcome::Timeout, '']);
        };
        // Under ext-uv this timer is measured against the loop's cached clock (refreshed
        // once per iteration), so after synchronous work in the same iteration it can
        // fire a little early — harmless for a 2 s guard against a hung driver. The kill
        // reaches the direct child only: argv is exec'd without a shell, so that child
        // IS nvidia-smi.
        $timer = $loop->addTimer($timeout, $timedOut);
        $loop->addReadStream($stdout, static function () use ($child, $loop, $deferred, $stdout, $deadline, &$timer, $timedOut): void {
            if (!$child->drain()) {
                return;
            }
            $loop->removeReadStream($stdout);
            if ($timer instanceof TimerInterface) {
                $loop->cancelTimer($timer);
            }
            $child->closeStdout();
            if ($child->exited()) {
                $deferred->resolve($child->finish());

                return;
            }
            // EOF before exit: poll (WNOHANG) for the rest of the budget.
            $poll = null;
            $poll = $loop->addPeriodicTimer(self::REAP_POLL, static function () use ($child, $loop, $deferred, $deadline, &$poll, $timedOut): void {
                if ($child->exited()) {
                    $loop->cancelTimer($poll);
                    $deferred->resolve($child->finish());
                } elseif (self::now() >= $deadline) {
                    $loop->cancelTimer($poll);
                    $timedOut();
                }
            });
        });

        return $deferred->promise();
    }

    /** Children spawned and not yet closed (running, or killed and awaiting their reap). */
    public static function liveCount(): int
    {
        self::reapExited();

        return \count(self::$live);
    }

    /**
     * SIGKILL every live child and reap it, waiting at most `$wait`
     * seconds in all — the exit hook (also callable by tests).
     */
    public static function shutdown(float $wait = self::REAP_WAIT): void
    {
        foreach (self::$live as $child) {
            $child->closeStdout();
            $child->kill();
        }
        $until = self::now() + $wait;
        self::reapExited();
        while (self::$live !== [] && self::now() < $until) {
            usleep(10000);
            self::reapExited();
        }
    }

    /**
     * @param list<string> $argv
     */
    private static function spawn(array $argv): ?self
    {
        self::reapExited();
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(static fn () => self::shutdown());
        }
        $pipes = [];
        try {
            $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        } catch (\Error) {
            return null; // proc_open listed in disable_functions
        }
        if (!\is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $child = new self($process, $pipes[1]);
        self::$live[spl_object_id($child)] = $child;

        return $child;
    }

    /** Close every registered child that has exited (never blocks). */
    private static function reapExited(): void
    {
        foreach (self::$live as $child) {
            if ($child->exited()) {
                $child->release();
            }
        }
    }

    /** Read what is available; true once stdout reached EOF. */
    private function drain(): bool
    {
        if ($this->stdout === null) {
            return $this->eof;
        }
        while (($chunk = @fread($this->stdout, 65536)) !== false && $chunk !== '') {
            $this->output .= $chunk;
        }
        if (feof($this->stdout)) {
            $this->eof = true;
        }

        return $this->eof;
    }

    private function closeStdout(): void
    {
        if (\is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        $this->stdout = null;
    }

    /** Non-blocking (WNOHANG) exit check; remembers the exit code. */
    private function exited(): bool
    {
        if ($this->closed || $this->exit !== null) {
            return true;
        }
        $status = @proc_get_status($this->process);
        if ($status === false || !$status['running']) {
            $this->exit = \is_array($status) && !$status['signaled'] ? (int) $status['exitcode'] : -1;

            return true;
        }

        return false;
    }

    private function kill(): void
    {
        if (!$this->closed && !$this->exited()) {
            @proc_terminate($this->process, 9);
        }
    }

    /**
     * A finished child: closed, its outcome by exit code.
     *
     * @return array{0: GpuOutcome, 1: string}
     */
    private function finish(): array
    {
        $this->release();

        return $this->exit === 0 ? [GpuOutcome::Ok, $this->output] : [GpuOutcome::Absent, ''];
    }

    /** proc_close an exited child (never blocks); a running one stays registered. */
    private function release(): void
    {
        if ($this->closed || !$this->exited()) {
            return;
        }
        $this->closeStdout();
        $this->closed = true;
        unset(self::$live[spl_object_id($this)]);
        @proc_close($this->process);
    }

    /** Poll a killed child on the loop for REAP_WINDOW; then the registry owns it. */
    private function reapOnLoop(LoopInterface $loop): void
    {
        $until = self::now() + self::REAP_WINDOW;
        $poll = null;
        $poll = $loop->addPeriodicTimer(self::REAP_POLL, function () use ($loop, $until, &$poll): void {
            if ($this->exited() || self::now() >= $until) {
                $loop->cancelTimer($poll);
                $this->release();
            }
        });
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
