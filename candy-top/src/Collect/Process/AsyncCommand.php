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
 * lets it die. While candy-top runs, an exited child is closed by the
 * next spawn, by any liveCount() call, or by the shutdown hook — a child
 * abandoned without a successor spawn (the GPU-went-absent shape) stays
 * a zombie until that hook, nothing polls it on a timer (candy-top-042).
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

    /** @var (callable(list<string>, array<string,string>): (array{0: \Traversable<mixed>, 1: array<int|string, mixed>}|null))|null */
    private static ?\Closure $spawnFactory = null;

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
     * @param list<string> $argv argv[0] must be an executable path — a bare
     *        name is refused at the spawn gate and reports Absent, never
     *        resolved through execvp's own PATH search
     */
    public static function run(array $argv, float $timeout = self::TIMEOUT, int $maxBytes = self::MAX_OUTPUT): CommandResult
    {
        $child = self::spawn($argv, $maxBytes);
        if ($child === null) {
            return CommandResult::absent();
        }
        $deadline = $child->started + $timeout;
        // candy-top-044: if stream_select hard-errors every round (EBADF
        // class), the deadline loop would spin at 100 % CPU for the whole
        // timeout. Floor each error round and give up after a streak —
        // the give-up path below kills and reports Timeout honestly.
        $selectErrors = 0;
        while (!$child->drain() && ($left = $deadline - self::now()) > 0) {
            $read = [$child->stdout];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) max(1000, min(200000, $left * 1e6))) === false) {
                if (++$selectErrors >= 100) {
                    break;
                }
                usleep(1000);
            } else {
                $selectErrors = 0;
            }
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
     * @param list<string> $argv argv[0] must be an executable path — a bare
     *        name is refused at the spawn gate and reports Absent, never
     *        resolved through execvp's own PATH search
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
        // candy-top-043: one settlement ticket for every arm. The deadline
        // timer and a read callback can fire inside the same loop iteration,
        // and after closeStdout() the OS recycles the fd — a second pass
        // through these closures would removeReadStream a resource id that
        // now belongs to ANOTHER child's fresh stdout (cross-talk stall).
        $settled = false;

        $timedOut = static function () use ($child, $loop, $deferred, $stdout, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
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
        $loop->addReadStream($stdout, static function () use ($child, $loop, $deferred, $stdout, $deadline, &$timer, $timedOut, &$settled): void {
            if ($settled) {
                return; // the deadline arm already tore this child down
            }
            if (!$child->drain()) {
                return;
            }
            $loop->removeReadStream($stdout);
            if ($timer instanceof TimerInterface) {
                $loop->cancelTimer($timer);
            }
            $child->closeStdout();
            if ($child->overflow) {
                $settled = true;
                $child->kill();
                $child->reapOnLoop($loop);
                $deferred->resolve($child->tooLarge());

                return;
            }
            if ($child->exited()) {
                $settled = true;
                $deferred->resolve($child->finish());

                return;
            }
            // EOF before exit: poll (WNOHANG) for the rest of the budget.
            $poll = null;
            $poll = $loop->addPeriodicTimer(self::REAP_POLL, static function () use ($child, $loop, $deferred, $deadline, &$poll, $timedOut, &$settled): void {
                if ($child->exited()) {
                    $settled = true;
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
    /**
     * The only parent-environment variables a sensor child is given.
     *
     * argv[0] is an absolute path, so PATH is not how the tool is found — it is
     * what the tool uses to find ITS helpers. HOME and the XDG trio are the
     * cache the vendor SMIs write; TERM, LANG, the LC_ family and TZ are the
     * locale a monitor must not override; and the three visibility keys are how
     * a host pins which GPUs nvidia-smi, rocm-smi or HIP enumerate. Nothing here
     * is a credential, and nothing a collector runs needs one — which is the
     * point: the parent of a TUI is the user's shell, and PHP hands a child the
     * WHOLE environment whenever proc_open is called without the argument.
     */
    private const ENV_KEYS = [
        'PATH', 'HOME', 'TERM', 'LANG', 'TZ',
        'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME',
        'CUDA_VISIBLE_DEVICES', 'HIP_VISIBLE_DEVICES', 'ROCR_VISIBLE_DEVICES',
        'LD_LIBRARY_PATH',
    ];

    /**
     * @return array<string,string> the child environment, rebuilt from {@see ENV_KEYS}
     */
    private static function childEnv(): array
    {
        $env = [];
        foreach (\getenv() as $key => $value) {
            if (\in_array((string) $key, self::ENV_KEYS, true) || \str_starts_with((string) $key, 'LC_')) {
                $env[(string) $key] = (string) $value;
            }
        }
        // A host with no PATH must not birth a child that can find no
        // helper — per-OS shape (candy-top-038): the POSIX default is
        // meaningless on Windows, where SystemRoot carries the system
        // tools; with no SystemRoot either, an empty PATH is the honest
        // answer (a tool not found is reported, not mis-resolved).
        $env['PATH'] ??= PHP_OS_FAMILY === 'Windows'
            ? ((\getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32')
            : '/usr/bin:/bin';

        return $env;
    }

    /**
     * Test seam (like the sibling subsystems' runner closures): replace the
     * OS spawn behind {@see run()} and {@see launch()}. The factory takes
     * the gate-checked argv and the allowlisted child environment and
     * returns the `[process, pipes]` shape of proc_open for a child it
     * spawned itself — or null for a spawn failure (the Absent path). The
     * registry, the kill ladder and the reaping all run over the injected
     * child exactly as over a real one, which is what makes the timing
     * around the loop statable: launch a child, decline to run the loop,
     * and the exited-but-unclosed residue candy-top-042 is about is on the
     * table as a fact (a real kernel stages no SIGKILL-immune child — a
     * group-stopped one still dies, which the wedged-driver shape cannot
     * be faked, only accounted). Null (default) keeps the real proc_open;
     * a malformed return is a programming error and throws.
     *
     * @param (callable(list<string>, array<string,string>): mixed)|null $factory
     */
    public static function withSpawnFactory(?callable $factory): void
    {
        self::$spawnFactory = $factory === null ? null : \Closure::fromCallable($factory);
    }

    private static function spawn(array $argv, int $maxBytes = self::MAX_OUTPUT): ?self
    {
        // The argv-array form hands argv[0] to execvp, which searches PATH —
        // the very resolution the collectors' absolute sweeps ({@see
        // \SugarCraft\Top\Collect\Process\AbsoluteSearchPath}) exist to do
        // themselves, against a PATH that only the calling environment chose.
        // The contract is an executable path; enforce it here so no caller can
        // widen it by accident and a PATH planted by whoever started the TUI
        // never picks the binary.
        if ($argv === [] || !\str_contains((string) $argv[0], '/')) {
            return null;
        }
        self::reapExited();
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(static fn () => self::shutdown());
        }
        if (self::$spawnFactory !== null) {
            $pair = (self::$spawnFactory)($argv, self::childEnv());
            if ($pair === null) {
                return null;
            }
            if (!\is_array($pair) || !isset($pair[0], $pair[1][1]) || !\is_resource($pair[0]) || !\is_resource($pair[1][1])) {
                throw new \InvalidArgumentException('AsyncCommand spawn factory must return [process, pipes] with a resource stdout, or null.');
            }
            $process = $pair[0];
            $pipes = $pair[1];
        } else {
            $pipes = [];
            // Windows has no /dev/null; PHP's implicit mapping is exactly the
            // kind of undocumented dependency this file's env law refuses
            // (candy-top-058) — name the device per OS instead.
            $devnull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            try {
                $process = @proc_open($argv, [0 => ['file', $devnull, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $devnull, 'w']], $pipes, null, self::childEnv());
            } catch (\Error) {
                return null; // proc_open listed in disable_functions
            }
            if (!\is_resource($process)) {
                return null;
            }
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
