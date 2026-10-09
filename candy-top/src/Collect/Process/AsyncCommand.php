<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Process;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * One bounded helper child (nvidia-smi, ipmitool, ...) — the only place
 * candy-top's collectors spawn a long-lived process. Two ways to drive
 * it, sharing the spawn, the timeout and the reaping:
 *  - {@see run()} blocks until the child exits or the timeout passes
 *    (startup probes, scripted tests, any caller outside the loop);
 *  - {@see launch()} returns at once and collects stdout through a loop
 *    read stream, so a slow tool (`nvidia-smi pmon` 0.25-1 s, an
 *    `ipmitool sensor list` SDR walk 1.7-4 s) never stalls input or
 *    rendering.
 *
 * The child is spawned with an argv array (no shell), stdin and stderr on
 * /dev/null. On overrun it is SIGKILLed; it is reaped without ever
 * blocking the loop: an exited child is closed at once, a killed one is
 * polled on a loop timer for {@see REAP_WINDOW} seconds, and one that
 * still has not died (uninterruptible sleep in a wedged driver or BMC
 * ioctl) stays in a process-wide registry that every later spawn polls
 * (WNOHANG) and that a shutdown hook SIGKILLs and reaps when candy-top
 * exits. Cleanup is bounded, not absolute: a child stuck in
 * uninterruptible sleep ignores even SIGKILL, so after the shutdown wait
 * ({@see REAP_WAIT}) it is left to init, which reaps it once the kernel
 * lets it die. While candy-top runs, no exited child stays a zombie.
 *
 * Descriptors: the spec maps 0-2 only, so the child inherits the parent's
 * other open descriptors for its (bounded, ≤ timeout) life. The tools run
 * here never read or write them, and none of them is a pipe whose EOF
 * anyone waits for (a sibling child's pipe write end is closed in the
 * parent), which is the accounting row in tools/check-child-lifetimes.php.
 *
 * Mutable on purpose: it is an OS handle, not a value.
 */
final class AsyncCommand
{
    /** Default seconds a child may run before it is killed. */
    public const float TIMEOUT = 2.0;

    /** Default stdout cap in bytes: past it the child is killed (TooLarge). nvidia-smi and ipmitool write KiB. */
    public const MAX_OUTPUT = 4 * 1024 * 1024;

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

    /** Exit code once proc_get_status() saw the child gone (it reports it only once); -1 = signalled. */
    private ?int $exit = null;

    private bool $closed = false;

    /** True once stdout passed the cap. */
    private bool $overflow = false;

    /**
     * @param resource $process
     * @param resource|null $stdout
     */
    private function __construct(
        private $process,
        private $stdout,
        private readonly float $started,
        private readonly string $program,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * Blocking: the child's {@see CommandResult} — Ok on exit 0, Failed on
     * any other exit, Absent when nothing could be spawned, Timeout past
     * `$timeout`.
     *
     * @param list<string> $argv argv[0] must be an executable path
     */
    public static function run(array $argv, float $timeout = self::TIMEOUT, int $maxBytes = self::MAX_OUTPUT): CommandResult
    {
        $child = self::spawn($argv, $maxBytes);
        if ($child === null) {
            return CommandResult::absent();
        }
        $deadline = $child->started + $timeout;
        while (!$child->drain() && ($left = $deadline - self::now()) > 0) {
            $read = [$child->stdout];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, (int) max(1000, min(200000, $left * 1e6)));
        }
        $child->closeStdout();
        if ($child->overflow) {
            $child->kill();
            $reapBy = self::now() + self::REAP_WAIT;
            while (!$child->exited() && self::now() < $reapBy) {
                usleep(10000);
            }
            $child->release();

            return $child->tooLarge();
        }
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

        return $child->timedOut();
    }

    /**
     * Non-blocking: the same outcomes as {@see run()}, delivered by the
     * loop. Resolves on the loop thread; never rejects.
     *
     * @param list<string> $argv argv[0] must be an executable path
     * @return PromiseInterface<CommandResult>
     */
    public static function launch(array $argv, ?LoopInterface $loop = null, float $timeout = self::TIMEOUT, int $maxBytes = self::MAX_OUTPUT): PromiseInterface
    {
        $child = self::spawn($argv, $maxBytes);
        if ($child === null) {
            return resolve(CommandResult::absent());
        }
        $loop ??= Loop::get();
        $deferred = new Deferred();
        $deadline = $child->started + $timeout;
        $stdout = $child->stdout;
        $timer = null;

        $timedOut = static function () use ($child, $loop, $deferred, $stdout): void {
            $loop->removeReadStream($stdout);
            $child->closeStdout();
            $child->kill();
            $child->reapOnLoop($loop);
            $deferred->resolve($child->timedOut());
        };
        // Under ext-uv this timer is measured against the loop's cached clock (refreshed
        // once per iteration), so after synchronous work in the same iteration it can
        // fire a little early — harmless for a guard against a hung driver or BMC. The
        // kill reaches the direct child only: argv is exec'd without a shell, so that
        // child IS the tool.
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
            if ($child->overflow) {
                $child->kill();
                $child->reapOnLoop($loop);
                $deferred->resolve($child->tooLarge());

                return;
            }
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
     * Live children (running, or killed and not yet reaped) of program
     * `$program` (argv[0]) — a caller can refuse to stack another child on
     * one stuck in uninterruptible sleep.
     */
    public static function liveFor(string $program): int
    {
        self::reapExited();
        $n = 0;
        foreach (self::$live as $child) {
            $n += $child->program === $program ? 1 : 0;
        }

        return $n;
    }

    /**
     * @param list<string> $argv
     */
    private static function spawn(array $argv, int $maxBytes = self::MAX_OUTPUT): ?self
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
        $child = new self($process, $pipes[1], self::now(), (string) ($argv[0] ?? ''), max(1, $maxBytes));
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

    /** Read what is available; true once stdout reached EOF or passed the cap. */
    private function drain(): bool
    {
        if ($this->stdout === null) {
            return $this->eof || $this->overflow;
        }
        while (($chunk = @fread($this->stdout, 65536)) !== false && $chunk !== '') {
            $this->output .= $chunk;
            if (\strlen($this->output) > $this->maxBytes) {
                $this->overflow = true;
                $this->output = '';

                return true;
            }
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

    /** A finished child: closed, its outcome by exit code. */
    private function finish(): CommandResult
    {
        $this->release();
        $exit = $this->exit ?? -1;

        return new CommandResult(
            $exit === 0 ? CommandOutcome::Ok : CommandOutcome::Failed,
            $this->output,
            $exit >= 0 ? $exit : null,
            self::now() - $this->started,
        );
    }

    private function tooLarge(): CommandResult
    {
        return new CommandResult(CommandOutcome::TooLarge, '', null, self::now() - $this->started);
    }

    private function timedOut(): CommandResult
    {
        return new CommandResult(CommandOutcome::Timeout, '', null, self::now() - $this->started);
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
