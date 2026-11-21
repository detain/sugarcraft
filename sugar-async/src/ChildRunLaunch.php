<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * The loop-driven body of {@see ChildRun::launch()}: one child, its pipes, its
 * deadline and its reap, all as callbacks on the loop. Built and started only by
 * ChildRun; not a public entry point.
 *
 * Exit is observed by a short periodic check (proc_get_status), because the
 * child's exit is not a readable stream. Once the exit is seen, the result settles
 * when the output is read to EOF, or when the pipes have been silent for the exit
 * grace (a descendant holding them open is then abandoned). At the deadline, on
 * an idle timeout or on a cancel, the child is signalled with SIGTERM, then
 * SIGKILL after the sugar-core
 * grace budgets, and the result settles as TIMED_OUT (deadline or idle) or
 * CANCELLED. Signals are integer literals, the sugar-core doctrine.
 *
 * The TERM/KILL ladder and the reap are sugar-core's loop variants,
 * {@see \SugarCraft\Core\Util\Proc\BoundedShutdown::escalateAsync()} and
 * {@see \SugarCraft\Core\Util\Proc\BoundedShutdown::reapWhenExited()}, so a rung
 * or reap change in sugar-core reaches this path too. The sync
 * terminateBounded() sleeps between rungs and cannot run on the loop.
 *
 * A child still running when the kill budget is spent is not waited for by
 * proc_close(), which would block the loop. Its handle is reaped by a periodic
 * poll once the kernel releases it, so the loop is never blocked; a child that
 * never dies keeps that poll armed for as long as it lives.
 *
 * The kill ladder normally ends with the result: settling cancels every timer,
 * the ladder's included. One exception is kept on purpose. A group-path launch
 * (a containment group is named or read from the live child) keeps its ladder
 * poll armed past settle, for at most the TERM and KILL grace, and only while a
 * group member is still alive, so a descendant that ignores TERM is not orphaned
 * once its leader has settled the run. The group-path ladder test in
 * ChildRunLaunchTest pins this behavior.
 */
final class ChildRunLaunch
{
    /** How often the exit is checked while the child runs. */
    private const EXIT_POLL_SECONDS = 0.02;

    /** @var array<int, resource> Open readable pipes, keyed by slot (1 stdout, 2 stderr) */
    private array $open;

    /** @var null|resource The stdin pipe while its payload is still going out */
    private $stdinPipe;

    private int $stdinOffset = 0;

    /** @var list<TimerInterface> */
    private array $timers = [];

    /** The kill ladder's poll; kept past settle while a containment group still has live descendants. */
    private ?TimerInterface $ladder = null;

    private ChildCapture $capture;

    private bool $exited = false;

    /** The exit grace ran out with a pipe still open: a descendant held it, and it was cut. */
    private bool $abandonedPipes = false;

    private ?int $exitCode = null;

    /** The signal that ended the child, when the status poll that saw the exit reported one. */
    private ?int $signal = null;

    private float $exitedAt = 0.0;

    /** The kill ladder has started, for the deadline, the idle budget or a cancel. */
    private bool $killing = false;

    private bool $cancelled = false;

    /**
     * The group read from the live child when the spawn-time snapshot named none.
     * Latched, since a reaped leader reads as no group.
     */
    private ?int $liveGroup = null;

    /** The child's own pid, read from the live handle at start(); a setsid wrapper's group id. */
    private int $spawnPid = 0;

    /**
     * Last sign of life: a chunk read from either stream, or a stdin write the child
     * accepted. The idle budget counts from here, as ChildRun::run() counts it.
     */
    private float $lastActivityAt = 0.0;

    /**
     * Last chunk read from either stream. The exit grace counts from here and not
     * from lastActivityAt: run()'s pump does not count a stdin write as output, so
     * launch() must not either, or a descendant that drains stdin silently holds the
     * pipes past the grace on one entry point and not the other.
     */
    private float $lastReadAt = 0.0;

    private ?Subscription $cancelSubscription = null;

    private bool $settled = false;

    private readonly bool $windows;

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes  0 stdin (absent when the stdin is a staged file), 1 stdout, 2 stderr
     * @param null|int $groupPid  Process group the kill rungs signal, or null to read it from the live child
     * @param null|\Closure(int|string, string): void $onChunk  Live feed of every chunk, or null
     * @param null|float $idleTimeout  Seconds of silence before the kill ladder starts, or null for none
     * @param null|CancellationToken $cancellation  Starts the kill ladder when cancelled, or null
     * @param null|string $stagedStdin  Temp file the child reads as stdin (Windows), removed at settle, or on the reap if the child still holds it
     * @param null|float $exitGrace  Seconds of pipe silence read on after the exit is seen, or null to read to EOF
     * @param null|float $heartbeatSeconds  Interval of the heartbeat timer, or null for none
     * @param null|\Closure(): void $onHeartbeat  The heartbeat hook, called on that interval until the run settles
     * @param bool $setsidWrapped  The child is a setsid wrapper that may not have led its group at the spawn read. Only then does the handle's pid stand in for the group once the child has exited; an unwrapped child leads no group of its own
     * @param float $terminateGrace  Seconds the kill ladder waits after SIGTERM before SIGKILL
     * @param float $killGrace  Seconds the kill ladder waits after SIGKILL before the run settles without the exit
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly mixed $proc,
        array $pipes,
        private readonly string $stdinBytes,
        private readonly float $timeout,
        int $maxBytes,
        private readonly Deferred $deferred,
        private readonly ?int $groupPid = null,
        ?\Closure $onChunk = null,
        private readonly ?float $idleTimeout = null,
        private readonly ?CancellationToken $cancellation = null,
        private readonly ?string $stagedStdin = null,
        private readonly ?float $exitGrace = null,
        private readonly ?float $heartbeatSeconds = null,
        private readonly ?\Closure $onHeartbeat = null,
        private readonly bool $setsidWrapped = false,
        private readonly float $terminateGrace = BoundedShutdown::TERMINATE_GRACE_SECONDS,
        private readonly float $killGrace = BoundedShutdown::KILL_GRACE_SECONDS,
    ) {
        $this->open = [1 => $pipes[1], 2 => $pipes[2]];
        $this->stdinPipe = $pipes[0] ?? null;
        $this->capture = new ChildCapture([1, 2], [], $maxBytes, $onChunk);
        $this->windows = WindowsPipes::runtimeNeedsIt();
    }

    public function start(): void
    {
        $this->spawnPid = (int) $this->readStatus()['pid'];
        $this->latchLiveGroup();
        $this->lastActivityAt = Clock::monotonicSeconds();
        $this->timers[] = $this->loop->addTimer($this->timeout, fn () => $this->expire());
        $this->timers[] = $this->loop->addPeriodicTimer(self::EXIT_POLL_SECONDS, fn () => $this->checkExit());
        if ($this->heartbeatSeconds !== null && $this->onHeartbeat !== null) {
            $beat = $this->onHeartbeat;
            $this->timers[] = $this->loop->addPeriodicTimer($this->heartbeatSeconds, static fn () => $beat());
        }

        if ($this->windows) {
            // No readable stream on the Windows runtime: poll each pipe's readable bytes.
            $this->timers[] = $this->loop->addPeriodicTimer(
                WindowsPipes::POLL_MICROSECONDS / 1_000_000,
                fn () => $this->pollWindows(),
            );
        } else {
            foreach ($this->open as $slot => $pipe) {
                stream_set_blocking($pipe, false);
                $this->loop->addReadStream($pipe, fn () => $this->onReadable($slot));
            }
        }

        if ($this->stdinPipe !== null && $this->stdinBytes === '') {
            fclose($this->stdinPipe);
            $this->stdinPipe = null;
        } elseif ($this->stdinPipe !== null) {
            stream_set_blocking($this->stdinPipe, false);
            $this->loop->addWriteStream($this->stdinPipe, fn () => $this->onWritable());
        }

        // Last, so a token that is already cancelled runs the ladder against a fully armed child.
        if ($this->cancellation !== null) {
            $this->cancelSubscription = $this->cancellation->onCancel(fn () => $this->cancel());
        }
    }

    private function onReadable(int $slot): void
    {
        if ($this->settled || !isset($this->open[$slot])) {
            return;
        }

        $pipe = $this->open[$slot];
        $chunk = fread($pipe, ChildPump::READ_BYTES);
        if ($chunk === false || $chunk === '') {
            // feof(), not the empty read: a non-blocking pipe returns '' while the child is quiet.
            if (feof($pipe)) {
                $this->closeSlot($slot);
                $this->checkDone();
            }

            return;
        }

        $this->noteRead();
        $this->capture->offer($slot, $chunk);
    }

    /** A chunk was read: it resets both the idle budget and the exit grace. */
    private function noteRead(): void
    {
        $now = Clock::monotonicSeconds();
        $this->lastActivityAt = $now;
        $this->lastReadAt = $now;
    }

    private function onWritable(): void
    {
        if ($this->stdinPipe === null) {
            return;
        }

        $written = @fwrite($this->stdinPipe, substr($this->stdinBytes, $this->stdinOffset, ChildPump::READ_BYTES));
        if ($written === false) {
            // Broken pipe: the child exited without reading its stdin. Stop feeding.
            $this->stdinOffset = strlen($this->stdinBytes);
        } else {
            $this->stdinOffset += $written;
            if ($written > 0) {
                // A child still taking its stdin is working: the idle budget counts this as activity.
                $this->lastActivityAt = Clock::monotonicSeconds();
            }
        }

        if ($this->stdinOffset >= strlen($this->stdinBytes)) {
            $this->closeStdin();
        }
    }

    private function pollWindows(): void
    {
        if ($this->settled) {
            return;
        }

        $got = false;
        foreach ($this->open as $slot => $pipe) {
            // The onChunk callback may cancel the launch mid-pass, which closes every pipe.
            if ($this->settled || $this->killing) {
                return;
            }
            $chunk = WindowsPipes::readAvailable($pipe, ChildPump::READ_BYTES);
            if ($chunk !== '') {
                $this->noteRead();
                $this->capture->offer($slot, $chunk);
                $got = true;
            }
        }

        // Windows cannot report a pipe's EOF, so the output is done once the
        // child has exited and a pass finds nothing (or the grace has run out).
        if ($this->exited && (!$got || Clock::monotonicSeconds() - $this->exitedAt >= WindowsPipes::EXIT_DRAIN_GRACE_SECONDS)) {
            $this->closeAllPipes();
        }

        $this->checkDone();
    }

    private function checkExit(): void
    {
        if ($this->settled) {
            return;
        }

        $this->probeGone();
        // Once the child has exited, the exit grace governs the pipes, not the idle budget.
        if ($this->idleTimeout !== null && !$this->exited && !$this->killing
            && Clock::monotonicSeconds() - $this->lastActivityAt >= $this->idleTimeout) {
            $this->expire();

            return;
        }
        $this->abandonSilentPipesAfterExit();
        $this->checkDone();
    }

    /**
     * The post-exit grace: once the child is gone, its pipes are read until they
     * have been silent for the grace, counted from the later of the exit and the
     * last chunk read (a stdin write does not count, as in run()). A pipe still open
     * at that cut is held by a descendant, so it is abandoned and the result reports
     * it. Not run once the kill ladder has started.
     *
     * Not run on the Windows runtime: there the post-exit drain is the poll arm's
     * (pollWindows()), which never reports an abandonment. This check reads through
     * readBuffered(), a blocking fread() on a Windows pipe, which would wedge the loop.
     *
     * Before a pipe is cut, the bytes it already holds are read. A loop that stalled
     * past the grace between the last read and this check left them unread, and
     * cutting the pipe would drop output the child wrote before it exited.
     */
    private function abandonSilentPipesAfterExit(): void
    {
        if (!$this->exited || $this->killing || $this->exitGrace === null || $this->open === [] || $this->windows) {
            return;
        }

        $quietFrom = max($this->exitedAt, $this->lastReadAt);
        if (Clock::monotonicSeconds() - $quietFrom < $this->exitGrace) {
            return;
        }

        foreach (array_keys($this->open) as $slot) {
            // A chunk's onChunk callback runs inside readBuffered() and may cancel the
            // launch: the ladder then closes every pipe, and may settle the run outright.
            // Nothing below may touch a closed pipe once that has happened.
            if ($this->settled || $this->killing) {
                return;
            }
            if ($this->readBuffered($slot)) {
                continue;
            }
            if (isset($this->open[$slot])) {
                $this->abandonedPipes = true;
                $this->closeSlot($slot);
            }
        }
    }

    /**
     * Reads what the pipe already holds, without waiting for more: true when it
     * yielded bytes, so it is not silent. A pipe at EOF is closed, as onReadable()
     * would close it. The budget bounds one check against a descendant that keeps
     * the pipe refilled; such a pipe stays open and the grace restarts from the read.
     *
     * Stops as soon as a chunk's callback has started the kill ladder (or settled the
     * launch): the pipe is closed by then, and reading it again is a TypeError.
     */
    private function readBuffered(int $slot): bool
    {
        $yielded = false;
        $budget = ChildPump::SWEEP_BYTES;
        while ($budget > 0) {
            if ($this->settled || $this->killing || !isset($this->open[$slot])) {
                break;
            }
            $pipe = $this->open[$slot];
            $chunk = fread($pipe, min(ChildPump::READ_BYTES, $budget));
            if ($chunk === false || $chunk === '') {
                // feof(), not the empty read: a non-blocking pipe returns '' while nothing is buffered.
                if (feof($pipe)) {
                    $this->closeSlot($slot);
                }
                break;
            }

            $yielded = true;
            $budget -= strlen($chunk);
            $this->noteRead();
            $this->capture->offer($slot, $chunk);
        }

        return $yielded;
    }

    /**
     * Records the exit the status poll sees. True once the child is gone, which
     * is what lets proc_close() run without blocking.
     */
    private function probeGone(): bool
    {
        if ($this->exited) {
            return true;
        }

        // Before the status read: the group is only readable while the leader lives.
        $this->latchLiveGroup();
        if ($this->exited) {
            return true;
        }

        return $this->readStatus()['running'] === false;
    }

    /**
     * Every status read in the launch goes through here, so whichever read first sees
     * the exit records it. The read that reaps the child is the only one that carries
     * the signal of a signal death: a later read reports the -1 sentinel alone.
     *
     * @return array<string, mixed>
     */
    private function readStatus(): array
    {
        $status = proc_get_status($this->proc);
        if ($status['running'] !== false || $this->exited) {
            return $status;
        }

        $this->exited = true;
        $this->exitedAt = Clock::monotonicSeconds();
        // Raw, as ChildRun::run() keeps it: a signal-killed child reports -1, and
        // a null here would read as "could not be reaped" and hide the failure.
        $this->exitCode = (int) $status['exitcode'];
        if (($status['signaled'] ?? false) === true && isset($status['termsig'])) {
            $this->signal = (int) $status['termsig'];
        }

        return $status;
    }

    /** The cancel token fired: the kill ladder runs as it does for the deadline. */
    private function cancel(): void
    {
        if ($this->settled) {
            return;
        }

        $this->cancelled = true;
        $this->expire();
    }

    /**
     * Starts the kill ladder once: the deadline, the idle budget and a cancel all
     * come here. A second call is a no-op, so the deadline timer firing after an
     * idle kill does not restart the ladder.
     */
    private function expire(): void
    {
        if ($this->settled || $this->killing) {
            return;
        }

        // Before the first signal: a shell that exited since the last poll is only
        // reachable through the setsid fallback in killGroup(), which needs exited set.
        $this->probeGone();

        $this->killing = true;
        $this->closeAllPipes();
        $this->closeStdin();

        if ($this->exited && $this->groupGone()) {
            $this->finish($this->outcome());

            return;
        }

        // The ladder settles on its own: a child gone after TERM answers at once,
        // and one that survives the kill budget settles whether or not SIGKILL
        // landed, so the loop never waits on the child. A containment group keeps
        // the ladder running past a leader that exited, while descendants live.
        // The handle is kept apart from $timers: finish() cancels it only once the
        // group is empty, so a launch settling on the leader's exit still escalates
        // to the descendants the containment group holds.
        $this->ladder = BoundedShutdown::escalateAsync(
            $this->loop,
            $this->signal(...),
            fn (): bool => $this->probeGone() && $this->groupGone(),
            function (): void {
                if (!$this->settled) {
                    $this->finish($this->outcome());
                }
            },
            $this->terminateGrace,
            $this->killGrace,
        );
    }

    /** One kill(-pgid, 0) per check, never a /proc walk: this runs on the loop. True without a group. */
    private function groupGone(): bool
    {
        $group = $this->killGroup();

        return $group === null || BoundedShutdown::groupEmpty($group, false);
    }

    /**
     * The group the kill rungs signal: the spawn-time one, else the group the live
     * child led when it was last seen alive. ChildRun::launch() reads the group before
     * the setsid wrapper has become a leader, so the snapshot can be null for a
     * containment-wrapped child. ChildRun::killGroup() takes the same live fallback
     * on the blocking path.
     */
    private function killGroup(): ?int
    {
        if ($this->groupPid !== null) {
            return $this->groupPid;
        }

        $this->latchLiveGroup();
        if ($this->liveGroup !== null) {
            return $this->liveGroup;
        }

        // A setsid wrapper that exits before any live read leaves no group to read, yet
        // it leads a group whose id is its own pid, which the handle keeps. An unwrapped
        // child never leads one, and its pid can be reused by a stranger's group leader,
        // so the fallback is limited to a child declared as a wrapper.
        return $this->exited && $this->setsidWrapped && $this->spawnPid > 0 ? $this->spawnPid : null;
    }

    /**
     * Latches the group a live child leads. Run from start() and from every status
     * poll before the exit is recorded: a shell that backgrounds a job and exits
     * leaves no live group to read at the deadline, so the group must be taken while
     * the leader still leads it. A reaped leader reads as no group, and a null read
     * keeps the earlier latch.
     */
    private function latchLiveGroup(): void
    {
        if ($this->groupPid === null && $this->liveGroup === null && !$this->exited) {
            // Containment::groupId()'s reading, on a status read that records an exit it
            // sees: a leader that died just now is reaped by this read, and its signal
            // would be lost to a read that discards the status.
            $status = $this->readStatus();
            $pid = (int) $status['pid'];
            if ($status['running'] === true && $pid > 0 && \function_exists('posix_getpgid')) {
                $this->liveGroup = \posix_getpgid($pid) === $pid ? $pid : null;
            }
        }
    }

    /**
     * Signals are integer literals (sugar-core doctrine); a gone child takes the
     * signal as a no-op. A containment-wrapped child signals its whole group first,
     * as BoundedShutdown does, and falls back to the direct child.
     */
    private function signal(int $signal): void
    {
        Containment::signal($this->proc, $this->killGroup(), $signal);
    }

    private function checkDone(): void
    {
        if ($this->settled || !$this->exited || $this->open !== []) {
            return;
        }

        $this->finish($this->outcome());
    }

    /** CANCELLED beats TIMED_OUT: a cancel that lands during an idle or deadline kill still reports as cancelled. */
    private function outcome(): string
    {
        if ($this->cancelled) {
            return ChildResult::CANCELLED;
        }

        return $this->killing ? ChildResult::TIMED_OUT : ChildResult::EXITED;
    }

    private function finish(string $status): void
    {
        $this->settled = true;
        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->timers = [];
        // Cancelling a ladder that is still signalling descendants would orphan them:
        // it stops on its own once the group is empty or the kill budget is spent.
        // That is the one exception to "no ladder timer outlives settle": a settled
        // group-path launch keeps its poll for at most TERM + KILL grace, and only
        // while a containment-group member is still alive (pinned by the group-path
        // ladder test in ChildRunLaunchTest).
        if ($this->ladder !== null && $this->groupGone()) {
            $this->loop->cancelTimer($this->ladder);
            $this->ladder = null;
        }
        $this->closeAllPipes();
        $this->closeStdin();
        $this->cancelSubscription?->unsubscribe();
        $this->cancelSubscription = null;

        $code = $this->exitCode;
        $staged = $this->stagedStdin;
        if ($this->exited) {
            // Seen gone by the status poll, so proc_close() cannot wait. The child is
            // reaped and no longer holds the staged payload, but a handle the OS has
            // not released yet can still refuse the unlink: that is retried on the loop.
            $reaped = proc_close($this->proc);
            $code ??= $reaped;
            if ($staged !== null) {
                StagedStdin::removeAfterExit($this->loop, $staged);
            }
        } else {
            // Survived the kill budget: a child that still holds the staged payload
            // refuses its removal. A refused removal is kept and retried on the reap.
            $retry = $staged !== null && StagedStdin::removeOrRetry($staged, false);
            BoundedShutdown::reapWhenExited(
                $this->loop,
                $this->proc,
                StagedStdin::reapHook($staged, $retry),
            );
        }

        $r = $this->capture->result(DrainResult::CLOSED);
        $this->deferred->resolve(new ChildResult(
            $r->buffer(1),
            $r->buffer(2),
            $code,
            $status,
            $r->droppedBytes(1) + $r->droppedBytes(2),
            $r->droppedBytes(2),
            $this->abandonedPipes,
            $this->signal,
        ));
    }

    private function closeSlot(int $slot): void
    {
        $pipe = $this->open[$slot];
        $this->loop->removeReadStream($pipe);
        fclose($pipe);
        unset($this->open[$slot]);
    }

    private function closeAllPipes(): void
    {
        foreach (array_keys($this->open) as $slot) {
            $this->closeSlot($slot);
        }
    }

    private function closeStdin(): void
    {
        if ($this->stdinPipe === null) {
            return;
        }

        $this->loop->removeWriteStream($this->stdinPipe);
        fclose($this->stdinPipe);
        $this->stdinPipe = null;
    }
}
