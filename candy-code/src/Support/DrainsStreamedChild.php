<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * Read a `proc_open()` child's stdout and stderr to EOF without letting either
 * one wedge the other, bounded by a deadline and by a caller's own appetite.
 *
 * WHY THIS EXISTS AS A SHARED CLASS RATHER THAN A THIRD COPY. The shape of the
 * pump is forced by the kernel: `stream_get_contents($stdout)` followed by
 * `stream_get_contents($stderr)` deadlocks the moment a child writes more than
 * one pipe buffer (64 KiB on Linux) to stderr — the parent is blocked reading
 * stdout, the child is blocked writing a full stderr pipe nobody is draining,
 * and neither ever moves. {@see walk()} is the answer everyone converged on:
 * `stream_select()` over both, reading only what is ready, with the pipes
 * switched to non-blocking so a select that wakes on a partial buffer cannot
 * block inside the `fread()` that follows it, and a slice so a deadline is
 * reachable while the child is silent (nothing wakes a select when the only
 * thing that has changed is the clock).
 *
 * {@see \SugarCraft\Code\Support\ProcessReaper} folded the sibling family —
 * the term-then-await EXIT ladders — and its E676 note is explicit that the
 * budgets stayed where they were pinned. This class does the same for the READ
 * half, and for the same reason: five hand-rolled copies of one loop is how
 * one of them ends up with a subtly different ride-out.
 *
 * THE `false` RIDE-OUT IS THE PART THAT IS EASY TO LOSE. PHP's
 * `stream_select()` is not installed with `SA_RESTART`, so any signal delivered
 * while it is parked returns false with EINTR — and
 * {@see \SugarCraft\Core\Program} turns on `pcntl_async_signals()` and installs
 * SIGWINCH/SIGINT handlers for the whole TUI, which makes a terminal RESIZE
 * during a run a routine occurrence. Treating that as end-of-output abandons
 * everything the child had not written yet: a truncated deny reason, a half an
 * `exit 3` question, and — worst — a partial `exit 4` rewrite, which is invalid
 * JSON and so becomes a DENY of a call the hook meant to permit with different
 * arguments. {@see SELECT_RETRIES} rides the signal out and gives up on a
 * genuinely broken descriptor, which fails instantly and forever.
 *
 * WHAT IS NOT SHARED, ON PURPOSE. Two callers sit here with two DIFFERENT
 * volume policies, and folding them into one would change behavior:
 *
 * - **per-stream tail-trim, with a marker** ({@see \SugarCraft\Code\Hooks\ScriptHook}):
 *   each stream is kept to its own ceiling, refused bytes are COUNTED, and every
 *   way out stamps a constant-size note naming the cap and the count, because a
 *   hook's output is a verdict the operator is owed even when it is truncated.
 * - **stop at the cap** ({@see \SugarCraft\Code\Config\StatusLineCommand}):
 *   stderr is read and DISCARDED, stdout is one row of a status bar, and once
 *   the cap is reached there is nothing more a one-row readout can use — so the
 *   drain ENDS and the caller kills the child rather than reading a runaway to
 *   EOF on the TUI's thread. No marker: the segment is blanked upstream.
 *
 * The divergence therefore lives at the two seams this class calls rather than
 * in its loop: $capture decides what a chunk is worth and where it goes, and
 * $keepGoing decides whether the walk still has a reason to run. $onGiveUp is
 * the third: one caller sweeps what is already buffered before letting go, the
 * other has nothing to sweep because its buffer is already all it wants.
 *
 * @see \SugarCraft\Code\Hooks\ScriptHook::drain()
 * @see \SugarCraft\Code\Config\StatusLineCommand::drain()
 */
final class DrainsStreamedChild
{
    /**
     * How many CONSECUTIVE `stream_select()` failures the walk rides out before
     * it gives up on a pipe.
     *
     * A signal is the expected cause and it is not an error, so the budget has
     * to be generous; a genuinely broken descriptor fails instantly and
     * forever, so it has to be finite. Any successful select resets it, which
     * means 128 is 128 signals with no readable byte in between rather than 128
     * signals per run.
     */
    public const SELECT_RETRIES = 128;

    /** Bytes taken per ready pipe per iteration — one non-blocking read, not a drain-to-EOF. */
    public const READ_BYTES = 8192;

    /**
     * The most the stdin pump offers a child per writable wake.
     *
     * A chunk, not the whole payload: a hook that reads its input line by line
     * should not have a megabyte sitting in its pipe buffer unread while the
     * parent is waiting on its output.
     */
    public const WRITE_CHUNK_BYTES = 65536;

    /** Every pipe reached EOF. */
    public const CLOSED = 'closed';

    /** $keepGoing answered false: the caller's own appetite is full. */
    public const STOPPED = 'stopped';

    /** The deadline passed with pipes still open. */
    public const EXPIRED = 'expired';

    /** {@see SELECT_RETRIES} consecutive selects failed — the descriptors are the problem. */
    public const RETRY_BUDGET_GONE = 'retry-budget-gone';

    /**
     * Pump the child's pipes until they close, the caller stops wanting them,
     * the deadline passes, or the descriptors fail.
     *
     * THE WRITE BRANCH RUNS BEFORE THE READS: bytes the child takes this round
     * free the buffer it reads into, so feeding first keeps a talk-then-read
     * child moving in one iteration instead of alternating two.
     *
     * The payload write is offered with `@` because a child that never reads
     * stdin and exits (the ordinary `exit 0` of a one-line permissive hook)
     * turns the next write into EPIPE — a diagnostic this pump EXPECTS, and one
     * that would otherwise trip `failOnWarning="true"`. The payload had one
     * offer; the child declined.
     *
     * EVERY WAY OUT LEAVES THE CHILD'S STDIN CLOSED, including a throw. The
     * pump owns that EOF: a child that reads stdin to EOF hangs forever without
     * it, and a deadline would answer the hang with a denial it never earned.
     * Nothing downstream may assume the pipe is still theirs to touch.
     *
     * THE READ SET IS THE CHILD'S LIFETIME, and that is load-bearing for the
     * write branch: `ScriptHook::spawnSpec()` wraps the command in
     * `setsid -w sh -c`, so the WRAPPER keeps both output pipes' write ends open
     * until the whole run exits — a child hanging up its own STDOUT/STDERR
     * cannot empty this set early. The loop therefore stays alive exactly as
     * long as the child can still take stdin, and the close above owns the EOF
     * on every exit path.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @param float $deadline absolute instant past which the walk gives up on
     *        whatever the child has not written yet
     * @param float $sliceSeconds longest one select waits before $deadline is
     *        re-checked — a PARAMETER, not a constant, because each caller's
     *        figure is doc-pinned where it stands
     * @param callable(): float $clock the caller's own time source (a hook runs
     *        on an injectable clock; a status row reads the wall clock)
     * @param callable(int $slot, string $bytes): void $capture what a chunk is
     *        worth and where it goes — slot 0 is stdout, slot 1 is stderr
     * @param ?callable(): bool $keepGoing null means "always"; a caller that
     *        caps its own appetite answers false to end the walk early
     * @param ?callable(string $reason, array<int, resource> $open): void $onGiveUp
     *        called only for {@see EXPIRED} and {@see RETRY_BUDGET_GONE}, while
     *        the pipes are still the walk's to hand out and stdin is still open
     *        to close — a non-blocking sweep cannot park
     * @param resource|null $stdin the child's stdin, flushed from inside the
     *        pump when given
     * @return string one of {@see CLOSED}, {@see STOPPED}, {@see EXPIRED},
     *         {@see RETRY_BUDGET_GONE}
     */
    public static function walk(
        $stdout,
        $stderr,
        float $deadline,
        float $sliceSeconds,
        callable $clock,
        callable $capture,
        ?callable $keepGoing = null,
        ?callable $onGiveUp = null,
        $stdin = null,
        string $stdinData = '',
        int $selectRetries = self::SELECT_RETRIES,
    ): string {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $pending = $stdinData;
        $stdinOpen = $stdin !== null;

        if ($stdinOpen) {
            stream_set_blocking($stdin, false);
        }

        try {
            $open = [0 => $stdout, 1 => $stderr];
            $failures = 0;
            $reason = self::CLOSED;

            while ($open !== []) {
                if ($keepGoing !== null && !$keepGoing()) {
                    $reason = self::STOPPED;
                    break;
                }

                $remaining = $deadline - $clock();

                if ($remaining <= 0.0) {
                    $reason = self::EXPIRED;
                    break;
                }

                $read = array_values($open);
                $write = $pending !== '' && $stdinOpen ? [$stdin] : null;
                $except = null;

                // Waits for one of the pipes to become readable — which includes
                // the readable-at-EOF a closing child produces — or, while the
                // payload still has bytes to give, for stdin to become writable
                // — or for the slice to run out, whichever is first.
                $slice = min($remaining, $sliceSeconds);
                $seconds = (int) $slice;
                $micros = (int) round(($slice - $seconds) * 1_000_000);

                if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                    if (++$failures > $selectRetries) {
                        // Not a signal, then: something is wrong with the
                        // descriptors themselves and retrying forever would wedge
                        // the caller harder than the deadlock this pump replaced.
                        $reason = self::RETRY_BUDGET_GONE;
                        break;
                    }

                    continue;
                }

                $failures = 0;

                if ($pending !== '' && \is_array($write) && \in_array($stdin, $write, true)) {
                    $written = @fwrite($stdin, substr($pending, 0, self::WRITE_CHUNK_BYTES));

                    if ($written === false) {
                        $pending = '';
                    } elseif ($written > 0) {
                        $pending = substr($pending, $written);
                    }

                    if ($pending === '') {
                        // THE CLOSE IS THE CONTRACT: see the docblock.
                        fclose($stdin);
                        $stdinOpen = false;
                    }
                }

                foreach ($open as $slot => $pipe) {
                    if (!\in_array($pipe, $read, true)) {
                        continue;
                    }

                    $chunk = fread($pipe, self::READ_BYTES);

                    if ($chunk === false || $chunk === '') {
                        // feof() is the EOF test, not the empty read: a
                        // non-blocking pipe legitimately returns '' when the
                        // child has written nothing YET.
                        //
                        // AN HONEST GAP, inherited from both sources and kept:
                        // nothing pins this guard, and replacing the whole block
                        // with a bare `unset($open[$slot]);` keeps the suite
                        // green. That is not an oversight a fixture closes. For
                        // a real pipe the two forms only diverge when
                        // `stream_select()` reports the descriptor readable and
                        // the read then yields no bytes WITHOUT the peer having
                        // closed — a spurious or EINTR wake, which cannot be
                        // provoked from inside this process; every reachable
                        // empty read here is an EOF, where both forms unset. The
                        // guard is kept because the divergent case, though
                        // unforceable, is the one that costs the tail of the
                        // child's output, and because the ride-out above
                        // documents that this pump really does run under a
                        // SIGWINCH handler with `pcntl_async_signals()` on.
                        if (feof($pipe)) {
                            unset($open[$slot]);
                        }

                        continue;
                    }

                    $capture($slot, $chunk);
                }
            }

            if ($onGiveUp !== null && ($reason === self::EXPIRED || $reason === self::RETRY_BUDGET_GONE)) {
                $onGiveUp($reason, $open);
            }

            return $reason;
        } finally {
            if ($stdinOpen && $stdin !== null && \is_resource($stdin)) {
                fclose($stdin);
            }
        }
    }
}
