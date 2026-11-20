<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Core\Util\Proc\WindowsPipes as CoreWindowsPipes;
use function React\Promise\reject;
use function React\Promise\resolve;

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
 * Cancellation: {@see withCancellation()} takes the token of a
 * {@see CancellationSource}. Cancelling the source kills the child through the
 * same ladder as the deadline, reaps it, and reports CANCELLED. A token that is
 * already cancelled spawns nothing. run() is blocking, so it can only observe a
 * cancel raised from its own callbacks ({@see withOnChunk()}, {@see withOnSpawn()}
 * or a signal handler); launch() observes a cancel from anywhere on the loop.
 *
 * Idle deadline: {@see withIdleTimeout()} is an opt-in silence budget. A child
 * that writes nothing to either stream for longer than the budget is killed
 * through the same ladder and reported as TIMED_OUT, the same status as the total
 * deadline. Off by default, so a child that is quiet but within its deadline is
 * never killed unless the caller asked for the idle budget. Once the child has
 * exited, the idle budget no longer applies: the exit grace below governs its
 * held pipes.
 *
 * A child that exits while a backgrounded grandchild still holds its output
 * pipes ends the run once the pipes have been silent for
 * {@see WindowsPipes::EXIT_DRAIN_GRACE_SECONDS} (counted from the later of the exit
 * and the last chunk), not at the deadline, unless {@see withExitGrace()} removed
 * that grace. launch() applies the same silence budget. A pipe cut that way while
 * still open is abandoned, and {@see ChildResult::abandonedPipes()} reports it.
 * Output a descendant writes after a silent stretch longer than the grace is not
 * captured; the cut itself is reported through {@see ChildResult::abandonedPipes()},
 * and output written inside the grace is kept. On the Windows runtime the window is
 * narrower: the poll arm ends at the first pass after the exit that reads nothing,
 * or {@see WindowsPipes::EXIT_DRAIN_GRACE_SECONDS} after the exit, whichever comes
 * first, ignores {@see withExitGrace()}, and does not set abandonedPipes(). Output a
 * descendant writes after that point is not captured there.
 *
 * Stdin: {@see withStdin()} feeds a payload to the child. On POSIX the payload
 * goes through a non-blocking pipe. On the Windows runtime, where a non-blocking
 * stdin pipe cannot be had, a non-empty payload is staged to a private temp file
 * and handed to the child as its stdin descriptor; the child reads it to EOF and
 * the file is removed once the child is reaped. An empty payload gives the child
 * an immediate EOF on every runtime.
 *
 * Consumers: sugar-dashboard's BoundedCommand runs every child through run() and
 * launch() and maps the ChildResult onto its outcome. candy-code's CommandBackend
 * runs its child through ChildRun as well, from both its blocking and its launch
 * entry points. candy-code's StreamingCommandBackend still spawns with its own
 * proc_open() and is not yet on this class.
 */
final class ChildRun
{
    /** Deadline when none was set: the ChildPump default. */
    public const DEFAULT_TIMEOUT_SECONDS = ChildPump::DEFAULT_TIMEOUT_SECONDS;

    /** Per-stream cap when none was set: the ChildPump default. */
    public const DEFAULT_MAX_BYTES = ChildPump::DEFAULT_MAX_BYTES;

    /** Longest gap between idle checks in run(): the pump's own slice, so the check costs no extra wakeups. */
    private const IDLE_BEAT_SECONDS = 0.1;

    /** How often an abandoned launch child is checked for exit before its handle is closed. */
    private const ABANDON_POLL_SECONDS = 0.02;

    /** Why run() stopped the walk before the output closed. */
    private const STOP_IDLE = 'idle';
    private const STOP_CANCELLED = 'cancelled';

    /**
     * @param list<string> $argv
     * @param null|string $stdin  Payload for the child's stdin, or null for none
     * @param null|array<string, string> $env  Environment, or null to inherit
     * @param null|int $groupPid  Process group the child leads (a containment wrapper), or null
     * @param null|\Closure(int|string, string): void $onChunk  Live feed of every chunk read, or null
     * @param null|\Closure(int): void $onSpawn  Told the child's pid right after it starts, or null
     * @param null|float $exitGrace  run() only: seconds read on after the exit is seen, or null to read to EOF
     * @param null|CancellationToken $cancellation  Kills the child and reports CANCELLED when cancelled, or null
     * @param null|float $idleTimeout  Seconds of silence before the child is killed, or null for none
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
        private readonly ?float $exitGrace = WindowsPipes::EXIT_DRAIN_GRACE_SECONDS,
        private readonly ?CancellationToken $cancellation = null,
        private readonly ?float $idleTimeout = null,
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

    /**
     * How long run() and launch() read on after the child's exit is seen, when a
     * backgrounded grandchild still holds the output pipes. The budget is silence:
     * the pipes are read until none has yielded a chunk for this many seconds, then
     * abandoned. The default is the WindowsPipes grace. Null removes the grace:
     * the call then reads until EOF or the deadline, so a held pipe keeps it going
     * to its deadline, where the group is killed and the result is TIMED_OUT.
     * Callers that bound a whole command by its budget pass null.
     *
     * The grace is a POSIX control. On the Windows runtime the post-exit drain ends
     * at {@see WindowsPipes::EXIT_DRAIN_GRACE_SECONDS} after the exit (or at the first
     * pass that reads nothing), whatever this is set to, and no pipe is reported
     * abandoned. A null here does not hold a Windows run to its deadline either.
     */
    public function withExitGrace(?float $seconds): self
    {
        if ($seconds !== null && ($seconds <= 0 || !is_finite($seconds))) {
            throw new \InvalidArgumentException('Exit grace must be null or a positive finite number of seconds');
        }

        return $this->mutate(['exitGrace' => $seconds]);
    }

    /**
     * Opt-in silence budget. The child is killed through the kill ladder once it
     * has neither written to stdout or stderr nor accepted a stdin write for this
     * many seconds, and reported as TIMED_OUT. Any chunk read resets the budget,
     * dropped bytes included, and so does each stdin write the child takes: a child
     * still consuming its input is working, not idle. That stdin count needs a pipe.
     * On the Windows runtime a non-empty {@see withStdin()} payload is staged to a
     * file with no pipe behind it, so the child's reads of that file are not counted:
     * a child that reads a staged payload slowly and writes nothing is killed once
     * the budget elapses, while it is still accepting its input. Null (the default)
     * disables it, so a quiet child runs until its deadline. Once the child has
     * exited the budget no longer applies: the exit grace governs its held pipes
     * instead.
     */
    public function withIdleTimeout(?float $seconds): self
    {
        if ($seconds !== null && ($seconds <= 0 || !is_finite($seconds))) {
            throw new \InvalidArgumentException('Idle timeout must be null or a positive finite number of seconds');
        }

        return $this->mutate(['idleTimeout' => $seconds]);
    }

    /**
     * Cancellation: once $token is cancelled the child is killed through the same
     * ladder as the deadline, reaped, and the result reports CANCELLED. The caller
     * holds the {@see CancellationSource} and calls cancel() on it. Null removes it.
     */
    public function withCancellation(?CancellationToken $token): self
    {
        return $this->mutate(['cancellation' => $token]);
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
     * @throws \RuntimeException when the child cannot be started, or its stdin cannot be staged
     */
    public function run(): ChildResult
    {
        if ($this->alreadyCancelled()) {
            return self::cancelledResult();
        }

        [$proc, $pipes, $staged] = $this->spawn();
        try {
            return $this->collect($proc, $pipes);
        } finally {
            // The collect() above reaps the child before it returns, so the staged stdin is no longer read.
            if ($staged !== null) {
                @unlink($staged);
            }
        }
    }

    /**
     * Start the child on $loop and return a promise of its result. The loop is
     * never blocked: output is read by stream callbacks, the deadline is a timer,
     * and the reap is a short periodic check for the exit. A child still running
     * after its kill budget is reaped by that same check once the kernel releases
     * it, never by a blocking proc_close().
     *
     * The promise resolves (never rejects) once the child is done, including when
     * it was cancelled. It rejects when the child cannot be started, or when onSpawn
     * throws whatever the type: the child is killed first, and the throw is the
     * rejection reason.
     *
     * @return PromiseInterface<ChildResult>
     */
    public function launch(LoopInterface $loop): PromiseInterface
    {
        if ($this->alreadyCancelled()) {
            return resolve(self::cancelledResult());
        }

        try {
            [$proc, $pipes, $staged] = $this->spawn($loop);
        } catch (\Throwable $e) {
            return reject($e);
        }
        $group = $this->groupFor($proc);

        $deferred = new Deferred();
        (new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            $staged === null ? ($this->stdin ?? '') : '',
            $this->timeout,
            $this->maxBytes,
            $deferred,
            $group,
            $this->onChunk,
            $this->idleTimeout,
            $this->cancellation,
            $staged,
            $this->exitGrace,
        ))->start();

        return $deferred->promise();
    }

    /**
     * The blocking body of {@see run()}: the pump, the stop checks and the kill ladder.
     *
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private function collect($proc, array $pipes): ChildResult
    {
        $clock = Clock::monotonicSeconds(...);
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

        // Set when the walk must end early: the pump's clock then reads the deadline,
        // so its next pass ends the walk as EXPIRED and the deadline's kill path runs.
        $stop = null;
        $lastActivity = $clock();
        $pumpClock = static function () use (&$stop, $deadline, $clock): float {
            return $stop === null ? $clock() : $deadline;
        };

        $onChunk = $this->onChunk;
        if ($this->idleTimeout !== null) {
            $onChunk = static function (int|string $slot, string $chunk) use (&$lastActivity, $clock, $onChunk): void {
                $lastActivity = $clock();
                if ($onChunk !== null) {
                    $onChunk($slot, $chunk);
                }
            };
        }

        // Unconditional: a cancel that lands after the idle heartbeat set its stop
        // must still win, as it does in ChildRunLaunch::outcome(). It stays live
        // through the post-close exit wait: run() is blocking, so a cancel raised
        // from a signal handler can only be observed while this method is waiting.
        $cancelSubscription = $this->cancellation?->onCancel(static function () use (&$stop): void {
            $stop = self::STOP_CANCELLED;
        });

        try {
            try {
                $pump = ChildPump::new([1 => $pipes[1], 2 => $pipes[2]])
                    ->withDeadline($deadline)
                    ->withCap($this->maxBytes)
                    ->withClock($pumpClock)
                    ->withOnChunk($onChunk)
                    ->withRunning($running)
                    ->withExitGrace($this->exitGrace);
                if ($this->idleTimeout !== null) {
                    $idle = $this->idleTimeout;
                    $pump = $pump->withOnStdinWrite(
                        static function () use (&$lastActivity, $clock): void {
                            $lastActivity = $clock();
                        },
                    );
                    $pump = $pump->withHeartbeat(
                        min($idle, self::IDLE_BEAT_SECONDS),
                        static function () use (&$stop, &$lastActivity, $clock, $idle, $running): void {
                            // Once the child has exited the exit grace governs the pipes, not the idle budget.
                            if ($stop === null && $running() && $clock() - $lastActivity >= $idle) {
                                $stop = self::STOP_IDLE;
                            }
                        },
                    );
                }
                if ($this->stdin !== null && $this->stdin !== '') {
                    // A staged Windows payload has no pipe; the child reads its file.
                    if (isset($pipes[0])) {
                        $pump = $pump->withStdin($pipes[0], $this->stdin);
                    }
                } elseif (isset($pipes[0])) {
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

            if ($stop === null && $r->reason === DrainResult::CLOSED) {
                if ($exitCode !== null) {
                    // The liveness probe reaped the child, so proc_close() would report -1.
                    proc_close($proc);

                    return self::result($r, $exitCode, ChildResult::EXITED);
                }
                $code = self::awaitExitAfterClose($proc, $deadline, $clock, $this->idleTimeout, $lastActivity, $stop);
                if ($code !== null) {
                    return self::result($r, $code, ChildResult::EXITED);
                }
                // Output closed but the child is still running past the deadline, its idle
                // budget or a cancel. A cancel that ended the wait is reported as CANCELLED.
                $code = BoundedShutdown::terminateBounded($proc, groupPid: self::killGroup($proc, $group));
                $status = $stop === self::STOP_CANCELLED ? ChildResult::CANCELLED : ChildResult::TIMED_OUT;

                return self::result($r, $code, $status);
            }

            $code = BoundedShutdown::terminateBounded($proc, groupPid: self::killGroup($proc, $group));
            $status = match (true) {
                $stop === self::STOP_CANCELLED => ChildResult::CANCELLED,
                $stop === self::STOP_IDLE, $r->reason === DrainResult::EXPIRED => ChildResult::TIMED_OUT,
                default => ChildResult::PIPE_FAILED,
            };

            return self::result($r, $exitCode ?? $code, $status);
        } finally {
            $cancelSubscription?->unsubscribe();
        }
    }

    /**
     * Waits for a child whose output has already closed to exit. The wait ends at
     * the deadline, once the idle budget has passed since the last chunk, or once
     * a cancel has set $stop, so a silent or cancelled child is cut at the same
     * point whether or not its pipes are still open. The caller tells the cut
     * apart by $stop: a cancel is CANCELLED, the other two are TIMED_OUT.
     *
     * Polled in short slices at every budget, not only the idle one: a cancel
     * raised from a signal handler sets $stop while this loop sleeps, and a
     * deadline-length slice would hold the child past it.
     *
     * @param resource $proc
     * @param callable(): float $clock
     */
    private static function awaitExitAfterClose($proc, float $deadline, callable $clock, ?float $idleTimeout, float $lastActivity, ?string &$stop): ?int
    {
        while (true) {
            if ($stop !== null) {
                return null;
            }
            $remaining = max(0.0, $deadline - $clock());
            $code = BoundedShutdown::reapIfExited($proc, min($remaining, self::IDLE_BEAT_SECONDS));
            if ($code !== null) {
                return $code;
            }
            if ($clock() >= $deadline) {
                return null;
            }
            if ($idleTimeout !== null && $clock() - $lastActivity >= $idleTimeout) {
                return null;
            }
        }
    }

    /**
     * The spawn: the child's stdin is a pipe, or on the Windows runtime a staged
     * file when there is a payload. Returns the process, its pipes (no key 0 when
     * the stdin is a file) and the staged file to remove once the child is reaped.
     * A launch passes its loop, so an abandoned child is reaped on a timer.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: ?string}
     */
    private function spawn(?LoopInterface $loop = null): array
    {
        $staged = null;
        if ($this->stdin !== null && $this->stdin !== '' && WindowsPipes::runtimeNeedsIt()) {
            $staged = CoreWindowsPipes::stageStdin($this->stdin);
            if ($staged === null) {
                throw new \RuntimeException('ChildRun: could not stage stdin for ' . $this->argv[0]);
            }
            $stdinDescriptor = ['file', $staged, 'r'];
        } else {
            $stdinDescriptor = ['pipe', 'r'];
        }

        $pipes = [];
        $proc = @proc_open(
            $this->argv,
            [0 => $stdinDescriptor, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->cwd,
            $this->env,
        );
        if (!is_resource($proc)) {
            if ($staged !== null) {
                @unlink($staged);
            }

            throw new \RuntimeException('ChildRun: could not start ' . $this->argv[0]);
        }
        if ($this->onSpawn !== null) {
            // Read before onSpawn runs: a wrapped child's group is reliably readable only
            // while the child is alive, and abandonSpawn() needs it if onSpawn throws.
            $group = $this->groupFor($proc);
            try {
                ($this->onSpawn)((int) (proc_get_status($proc)['pid'] ?? 0));
            } catch (\Throwable $e) {
                self::abandonSpawn($proc, $pipes, $staged, $group, $loop);

                throw $e;
            }
        }

        return [$proc, $pipes, $staged];
    }

    /**
     * A started child the spawn cannot hand back, because onSpawn threw. The child
     * is killed and reaped here, so no live process outlives the throw, and the
     * staged payload goes once the child no longer holds it. A wrapped child takes
     * its process group down too: its descendants are not reachable through the
     * wrapper pid alone.
     *
     * A launch passes its loop: the close is then a timer, never a blocking
     * proc_close() on the loop. The run() path has no loop and closes at once.
     *
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private static function abandonSpawn($proc, array $pipes, ?string $staged, ?int $group = null, ?LoopInterface $loop = null): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        // The spawn-time read can precede the wrapper's own setsid, so the kill rung's
        // live fallback is what reaches a group the child only now leads.
        $target = self::killGroup($proc, $group);
        if ($target !== null && \function_exists('posix_kill')) {
            @\posix_kill(-$target, 9);
        }
        @proc_terminate($proc, 9);

        if ($loop !== null) {
            self::reapAbandonedOnLoop($loop, $proc, $staged);

            return;
        }

        proc_close($proc);
        if ($staged !== null) {
            StagedStdin::removeOrRetry($staged, true);
        }
    }

    /**
     * Closes an abandoned child from a loop timer, never from proc_close() on the
     * loop: a SIGKILLed child is normally gone within one poll. Once it is seen gone,
     * the handle is closed and the payload removed. When the kill budget runs out
     * with the child still alive, the handle goes to the non-blocking reap, which
     * closes it the tick the kernel releases the child.
     *
     * @param resource $proc
     */
    private static function reapAbandonedOnLoop(LoopInterface $loop, $proc, ?string $staged): void
    {
        $deadline = Clock::monotonicSeconds() + BoundedShutdown::KILL_GRACE_SECONDS;
        $timer = null;
        $timer = $loop->addPeriodicTimer(
            self::ABANDON_POLL_SECONDS,
            static function () use (&$timer, $loop, $proc, $staged, $deadline): void {
                if (!is_resource($proc)) {
                    $loop->cancelTimer($timer);

                    return;
                }

                $gone = proc_get_status($proc)['running'] === false;
                if (!$gone && Clock::monotonicSeconds() < $deadline) {
                    return;
                }

                $loop->cancelTimer($timer);
                if (!$gone) {
                    $retry = $staged !== null && StagedStdin::removeOrRetry($staged, false);
                    BoundedShutdown::reapWhenExited($loop, $proc, StagedStdin::reapHook($staged, $retry));

                    return;
                }

                proc_close($proc);
                if ($staged !== null) {
                    StagedStdin::removeAfterExit($loop, $staged);
                }
            },
        );
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

    private function alreadyCancelled(): bool
    {
        return $this->cancellation !== null && $this->cancellation->isCancelled();
    }

    /** Nothing ran, so there is nothing read and no exit to report. */
    private static function cancelledResult(): ChildResult
    {
        return new ChildResult('', '', null, ChildResult::CANCELLED, 0);
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
            $r->abandonedAfterExit,
        );
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
