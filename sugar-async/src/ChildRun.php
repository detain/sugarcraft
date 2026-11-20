<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * Run one child process with a deadline and a per-stream byte cap, and collect
 * what it wrote: blocking with {@see run()}, or on a loop with {@see launch()}.
 *
 * The argv is executed directly, never through a shell. The defaults are the
 * deadline and cap {@see ChildPump} uses: ten seconds, 8 MiB per stream.
 *
 *     $r = ChildRun::new(['git', 'status', '--porcelain'])
 *         ->withTimeout(5.0)
 *         ->withMaxBytes(1_048_576)
 *         ->run();
 *     if (!$r->succeeded()) { ... }
 *
 * A child past its deadline is killed (SIGTERM, a grace period, SIGKILL) and
 * reported as TIMED_OUT. run() does this through the sugar-core BoundedShutdown
 * ladder; launch() runs the same rungs on loop timers, with the same budgets. A
 * child that closes its output and then lingers past the deadline is treated
 * the same way.
 *
 * A child that exits while a backgrounded grandchild still holds its output
 * pipes ends the run once {@see WindowsPipes::EXIT_DRAIN_GRACE_SECONDS} has passed
 * since the exit, not at the deadline. Output the grandchild writes after that
 * cut-off is not captured, and nothing reports the drop; output written inside
 * the grace is kept.
 *
 * Stdin: {@see withStdin()} feeds a payload to the child. The POSIX arm does it
 * through a non-blocking pipe. On Windows a non-empty payload is refused (a
 * LogicException from run(), a rejected promise from launch()), because the
 * Windows runtime cannot give a non-blocking stdin pipe.
 *
 * Consumers: sugar-dashboard's BoundedCommand has moved, running every child
 * through run() and launch() and mapping the ChildResult onto its outcome. The
 * process runners in candy-git-server (BoundedCommand), candy-top (AsyncCommand)
 * and candy-code (CommandBackend) are the same pattern and still pending their
 * move to this class in the P1-5c lanes; their copies stay live until then.
 */
final class ChildRun
{
    /** Deadline when none was set: the ChildPump default. */
    public const DEFAULT_TIMEOUT_SECONDS = ChildPump::DEFAULT_TIMEOUT_SECONDS;

    /** Per-stream cap when none was set: the ChildPump default. */
    public const DEFAULT_MAX_BYTES = ChildPump::DEFAULT_MAX_BYTES;

    private const WINDOWS_STDIN_MESSAGE = 'ChildRun stdin needs non-blocking pipes, which the Windows runtime cannot give; '
        . 'pass the payload to the child as a file instead.';

    /**
     * @param list<string> $argv
     * @param null|string $stdin  Payload for the child's stdin, or null for none
     * @param null|array<string, string> $env  Environment, or null to inherit
     * @param null|int $groupPid  Process group the child leads (a containment wrapper), or null
     * @param null|\Closure(int|string, string): void $onChunk  Live feed of every chunk read, or null
     * @param null|\Closure(int): void $onSpawn  Told the child's pid right after it starts, or null
     */
    private function __construct(
        private readonly array $argv,
        private readonly float $timeout,
        private readonly int $maxBytes,
        private readonly ?string $stdin,
        private readonly ?string $cwd,
        private readonly ?array $env,
        private readonly ?int $groupPid = null,
        private readonly ?\Closure $onChunk = null,
        private readonly ?\Closure $onSpawn = null,
    ) {
    }

    /** @param list<string> $argv The program and its arguments; $argv[0] is the program */
    public static function new(array $argv): self
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('ChildRun needs a non-empty argv');
        }
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('ChildRun argv entries must be strings');
            }
        }

        return new self($argv, self::DEFAULT_TIMEOUT_SECONDS, self::DEFAULT_MAX_BYTES, null, null, null);
    }

    /** Seconds from the start of the run before the child is killed. */
    public function withTimeout(float $seconds): self
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout must be a positive finite number of seconds');
        }

        return $this->mutate(['timeout' => $seconds]);
    }

    /** Bytes kept per stream (>= 0); output past it is read and counted, not kept. */
    public function withMaxBytes(int $bytes): self
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Max bytes must be >= 0');
        }

        return $this->mutate(['maxBytes' => $bytes]);
    }

    /** Payload for the child's stdin; null or '' gives the child an immediate EOF. */
    public function withStdin(?string $bytes): self
    {
        return $this->mutate(['stdin' => $bytes]);
    }

    public function withCwd(?string $dir): self
    {
        return $this->mutate(['cwd' => $dir]);
    }

    /** @param null|array<string, string> $env The whole environment, or null to inherit */
    public function withEnv(?array $env): self
    {
        return $this->mutate(['env' => $env]);
    }

    /**
     * The process group the child leads, for a child started behind a containment
     * wrapper. The caller reads it with {@see \SugarCraft\Core\Util\Proc\BoundedShutdown::groupId()}
     * while the child is alive. Each kill rung then signals the whole group, so
     * descendants die with the wrapper. Null keeps the direct-child signal.
     */
    public function withGroupPid(?int $pid): self
    {
        if ($pid !== null && $pid <= 0) {
            throw new \InvalidArgumentException('Group pid must be a positive integer or null');
        }

        return $this->mutate(['groupPid' => $pid]);
    }

    /**
     * Live feed: called with (slot, chunk) for every chunk read from stdout (1)
     * or stderr (2), as it arrives, before the result is assembled. The buffered
     * result is unchanged; the callback only observes. Null removes it.
     *
     * @param null|callable(int|string, string): void $onChunk
     */
    public function withOnChunk(?callable $onChunk): self
    {
        return $this->mutate(['onChunk' => $onChunk === null ? null : \Closure::fromCallable($onChunk)]);
    }

    /**
     * Spawn seam: called once, with the child's pid, right after the child
     * starts and before anything is read or signalled. A child started behind a
     * containment wrapper leads its own process group, so this pid is also the
     * group id a kill rung needs; a runner records it here and passes it to
     * {@see withGroupPid()} for the rest of the run, because the group id cannot
     * be measured once the child has been reaped. Null removes the callback.
     *
     * @param null|callable(int): void $onSpawn
     */
    public function withOnSpawn(?callable $onSpawn): self
    {
        return $this->mutate(['onSpawn' => $onSpawn === null ? null : \Closure::fromCallable($onSpawn)]);
    }

    /**
     * Run the child to completion, blocking the caller, and return what it wrote.
     *
     * @throws \LogicException for a non-empty stdin payload on the Windows runtime
     * @throws \RuntimeException when the child cannot be started
     */
    public function run(): ChildResult
    {
        $this->guardStdin();
        $clock = Clock::monotonicSeconds(...);
        [$proc, $pipes] = $this->spawn();
        $group = $this->groupFor($proc);
        $deadline = $clock() + $this->timeout;

        // The probe keeps the status it reads: a proc_get_status() after the
        // child has been reaped reports -1, so the exit code is taken here.
        $exitCode = null;
        $running = static function () use ($proc, &$exitCode): bool {
            if ($exitCode !== null) {
                return false;
            }
            $status = @\proc_get_status($proc);
            if (!\is_array($status)) {
                return false;
            }
            if ($status['running'] === true) {
                return true;
            }
            $exitCode = (int) $status['exitcode'];

            return false;
        };

        try {
            $pump = ChildPump::new([1 => $pipes[1], 2 => $pipes[2]])
                ->withDeadline($deadline)
                ->withCap($this->maxBytes)
                ->withClock($clock)
                ->withOnChunk($this->onChunk)
                ->withRunning($running)
                ->withExitGrace(WindowsPipes::EXIT_DRAIN_GRACE_SECONDS);
            if ($this->stdin !== null && $this->stdin !== '') {
                $pump = $pump->withStdin($pipes[0], $this->stdin);
            } else {
                fclose($pipes[0]);
            }
            $r = $pump->pump();
        } catch (\Throwable $e) {
            self::closeAll($pipes);
            BoundedShutdown::terminateBounded($proc, groupPid: self::killGroup($proc, $group));
            throw $e;
        }

        // Pipes are closed before any proc_close: the reap helpers reap through
        // proc_close, and the pipe handles are ours to close first.
        self::closeAll($pipes);

        if ($r->reason === DrainResult::CLOSED) {
            if ($exitCode !== null) {
                // The liveness probe reaped the child, so proc_close() would report -1.
                proc_close($proc);

                return self::result($r, $exitCode, ChildResult::EXITED);
            }
            $code = BoundedShutdown::reapIfExited($proc, max(0.0, $deadline - $clock()));
            if ($code !== null) {
                return self::result($r, $code, ChildResult::EXITED);
            }
            // Output closed but the child is still running past the deadline.
            $code = BoundedShutdown::terminateBounded($proc, groupPid: self::killGroup($proc, $group));

            return self::result($r, $code, ChildResult::TIMED_OUT);
        }

        $code = BoundedShutdown::terminateBounded($proc, groupPid: self::killGroup($proc, $group));
        $status = $r->reason === DrainResult::EXPIRED ? ChildResult::TIMED_OUT : ChildResult::PIPE_FAILED;

        return self::result($r, $exitCode ?? $code, $status);
    }

    /**
     * Start the child on $loop and return a promise of its result. The loop is
     * never blocked: output is read by stream callbacks, the deadline is a timer,
     * and the reap is a short periodic check for the exit. A child still running
     * after its kill budget is reaped by that same check once the kernel releases
     * it, never by a blocking proc_close().
     *
     * The promise resolves (never rejects) once the child is done, and rejects
     * only when it cannot be started, or on a stdin payload on Windows.
     *
     * @return PromiseInterface<ChildResult>
     */
    public function launch(LoopInterface $loop): PromiseInterface
    {
        if (WindowsPipes::runtimeNeedsIt() && $this->stdin !== null && $this->stdin !== '') {
            return reject(new \LogicException(self::WINDOWS_STDIN_MESSAGE));
        }

        try {
            [$proc, $pipes] = $this->spawn();
        } catch (\RuntimeException $e) {
            return reject($e);
        }
        $group = $this->groupFor($proc);

        $deferred = new Deferred();
        (new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            $this->stdin ?? '',
            $this->timeout,
            $this->maxBytes,
            $deferred,
            $group,
            $this->onChunk,
        ))->start();

        return $deferred->promise();
    }

    /**
     * The group the kill rungs signal, measured right after the spawn: the one the
     * caller named, else the group the child leads. That is the moment the group is
     * reliably readable: a shell that backgrounds a job and exits leaves the job in
     * its group, and a group measured after the exit is no longer there to read.
     * An unwrapped child never leads a group, so this stays null for it.
     *
     * @param resource $proc
     */
    private function groupFor($proc): ?int
    {
        return $this->groupPid ?? Containment::groupId($proc);
    }

    /**
     * The group a kill rung signals: the one measured at spawn when there was one,
     * else the group the child leads right now. The spawn-time read can miss a
     * wrapper that has not yet made itself a group leader, and a live child still
     * answers the live read; a child that already exited answers neither, so the
     * spawn-time value is the one that reaches its job.
     *
     * @param resource $proc
     */
    private static function killGroup($proc, ?int $measured): ?int
    {
        return $measured ?? Containment::groupId($proc);
    }

    private static function result(DrainResult $r, ?int $code, string $status): ChildResult
    {
        return new ChildResult(
            $r->buffer(1),
            $r->buffer(2),
            $code,
            $status,
            self::dropped($r),
            $r->droppedBytes(2),
        );
    }

    private function guardStdin(): void
    {
        if ($this->stdin !== null && $this->stdin !== '' && WindowsPipes::runtimeNeedsIt()) {
            throw new \LogicException(self::WINDOWS_STDIN_MESSAGE);
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}  The process and its pipes: 0 stdin, 1 stdout, 2 stderr
     */
    private function spawn(): array
    {
        $pipes = [];
        $proc = @proc_open(
            $this->argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->cwd,
            $this->env,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('ChildRun: could not start ' . $this->argv[0]);
        }
        if ($this->onSpawn !== null) {
            ($this->onSpawn)((int) (proc_get_status($proc)['pid'] ?? 0));
        }

        return [$proc, $pipes];
    }

    /** @param array<int, resource> $pipes */
    private static function closeAll(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    private static function dropped(DrainResult $r): int
    {
        return $r->droppedBytes(1) + $r->droppedBytes(2);
    }

    /** @param array<string, mixed> $changes Constructor arguments to replace; the rest carry over */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
