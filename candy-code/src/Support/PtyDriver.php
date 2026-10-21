<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The interactive-PTY engine: spawn a program on a real pseudo-terminal of a
 * requested size, drive it with a scripted sequence of sends and expectation
 * windows, and always leave through the reaper's ladder.
 *
 * WHY A SEPARATE ENGINE (and not a widening of Bash's `interactive` flag):
 * {@see \SugarCraft\Code\Tools\Concerns\CapturesProcessOutput::runCapturedInteractive()}
 * answers "what did this program paint before it went quiet" — a read-only
 * drain that can never receive input, at a hardcoded 80x24. This engine answers
 * "what does the screen say AFTER I press this" — per-step output windows with
 * match booleans, a caller-chosen window size, and keystrokes written to the
 * master. The two shapes share every safety property (fail-fast env, group-
 * first teardown, bounded everything) and the follow-up seam is to fold the old
 * drain onto here once Bash's pins move.
 *
 * THE HANG BUDGET IS THE DESIGN. Every read is clamped to the smaller of the
 * step window and the remaining wall deadline; `$child->wait()` — an unbounded
 * poll in sugar-pty — is called ONLY when {@see ProcessReaper::escalate()} has
 * reported the child gone, exactly the Phase 9 doctrine: a child that traps
 * SIGTERM cannot turn a bounded refusal back into a hang. Teardown fires on
 * every exit path, including expect-timeout and mid-script exceptions.
 *
 * THE WINDOW SIZE IS SET BEFORE THE CHILD DRAWS. `Pty::spawn()` applies the
 * winsize to the master before forking (and re-asserts it best-effort after,
 * the Darwin zeroing quirk), so a TUI's first frame already sees the requested
 * geometry — the Python-PTY-harness law this repo learned auditing btop-style
 * renderers against 0x0 cttys.
 */
final class PtyDriver
{
    /**
     * One bounded quiet-drain pass: read until the master answers nothing
     * twice in a row, chunk-capped so a program still writing cannot extend
     * the drain forever. A twin of the trait-private `drainPty()` kept here
     * because that one lives inside a tool trait and cannot be shared without
     * refactoring it; folding the two is part of the Bash-interactive seam.
     */
    private const DRAIN_QUIET_READS = 2;

    private const DRAIN_MAX_CHUNKS = 32;

    private const DRAIN_READ_TIMEOUT_SECONDS = 0.05;

    private const READ_CHUNK_BYTES = 8192;

    /**
     * The per-pass read tick. Short enough that a matched expectation lands
     * within milliseconds of the paint, long enough that an idle window costs
     * twenty wakeups per second and not one busier.
     */
    private const READ_POLL_SECONDS = 0.05;

    /**
     * Fixed one-second courtesy between "the script ended" and "we call it
     * still running" — see the teardown comment for the race it settles.
     */
    private const EXIT_GRACE_SECONDS = 1.0;

    /**
     * Run one scripted PTY session.
     *
     * $steps is the tool-boundary-PARSED plan (never raw model input): each
     * entry `{send: string, windowSeconds: float, regex: ?string}` where a
     * non-null `$regex` is a compiled-true PCRE to match against the bytes
     * captured SINCE this step's window opened, and a null one just collects
     * for `windowSeconds`. Budgets (send sizes, step counts, dimensions) are
     * the caller's job — by the time this runs, everything is trusted, and an
     * oversized send here would mean the boundary leaked.
     *
     * Every step yields one result row, aligned with the plan, even when the
     * program exited or the wall fired before it ran (`skipped`).
     *
     * @param list<array{send: string, windowSeconds: float, regex: ?string}> $steps
     * @param (\Closure(): void)|null $heartbeat fired once per read pass
     * @param list<string> $spawnArgvPrefix jail argv (ISS-002) prefixed onto the spawn argv
     * @return array{
     *     refusal: ?string,
     *     pid: int,
     *     steps: list<array{output: string, matched: ?bool, note: string}>,
     *     retained: int,
     *     dropped: int,
     *     exitCode: ?int,
     *     exited: bool,
     *     killed: bool,
     * }
     */
    public static function run(
        string $command,
        ?string $cwd,
        int $cols,
        int $rows,
        float $wallSeconds,
        int $maxBytes,
        array $steps,
        ?\Closure $heartbeat = null,
        array $spawnArgvPrefix = [],
    ): array {
        $empty = self::result(refusal: null, pid: -1, ran: [], retained: 0, dropped: 0, exitCode: null, exited: false, killed: false);

        if (!ProcessContainment::interactiveAvailable()) {
            $empty['refusal'] = 'pty mechanism unavailable on this host (no pty); the program was refused and never started';

            return $empty;
        }

        $pty = null;
        try {
            // Open at the requested size, then spawn at it too: the master's
            // winsize is set before the child's first draw (class docblock).
            $pty = \SugarCraft\Pty\Pty::open($cols, $rows);
            $child = $pty->spawn(
                ProcessContainment::interactiveSpawnCommand($command, $cwd, $spawnArgvPrefix),
                // Scrubbed for the same reason as every other capture path:
                // the pty transcript is the model's to read (audit F-E1), and
                // NONINTERACTIVE_ENV's fail-fast prompts keep a driven program
                // from ever blocking on a password this terminal will not type.
                ProcessContainment::scrubbedEnv(),
                $cols,
                $rows,
                true,
            );
        } catch (\Throwable $failure) {
            // spawn() throwing AFTER open() succeeded would otherwise strand the
            // master fd — the finally below belongs to the run loop, which this
            // path never reaches. open() itself throwing leaves $pty null.
            if ($pty !== null) {
                try {
                    $pty->close();
                } catch (\Throwable) {
                    // Nothing to escalate a failure to; the refusal is the answer.
                }
            }
            $empty['refusal'] = 'pty refused: the pseudo-terminal could not be allocated (' . $failure->getMessage() . ')';

            return $empty;
        }

        // An empty plan is still a session: collect until the program exits or
        // the wall fires — the sized, safe shape of the old drain.
        $plan = $steps === []
            ? [['send' => '', 'windowSeconds' => $wallSeconds, 'regex' => null]]
            : $steps;

        /** @var list<array{output: string, matched: ?bool, note: string}> $ran */
        $ran = [];
        $retained = 0;
        $dropped = 0;
        $deadline = \microtime(true) + $wallSeconds;
        $childExited = false;
        $wallHit = false;

        try {
            foreach ($plan as $step) {
                if ($childExited) {
                    $ran[] = ['output' => '', 'matched' => null, 'note' => 'skipped (program exited)'];

                    continue;
                }

                if ($step['send'] !== '') {
                    try {
                        $pty->write($step['send']);
                    } catch (\Throwable $writeFailure) {
                        // A dead master (program raced us to exit) must not
                        // abort the script: note it and let the windows below
                        // observe the exit through their normal path.
                        $ran[] = ['output' => '', 'matched' => $step['regex'] === null ? null : false, 'note' => 'send failed: ' . $writeFailure->getMessage()];

                        $childExited = $child->exited();

                        continue;
                    }
                }

                [$output, $matched, $note, $childExited, $wallHit] = self::collectWindow(
                    $pty,
                    $child,
                    $step['windowSeconds'],
                    $deadline,
                    $maxBytes,
                    $retained,
                    $dropped,
                    $step['regex'],
                    $heartbeat,
                );
                $ran[] = ['output' => $output, 'matched' => $matched, 'note' => $note];

                if ($wallHit) {
                    // Pad the untouched tail so the result rows stay aligned
                    // with the plan the tool reported to the model.
                    foreach (\array_slice($plan, \count($ran)) as $unused) {
                        $ran[] = ['output' => '', 'matched' => null, 'note' => 'skipped (wall deadline)'];
                    }

                    break;
                }
            }
        } finally {
            // Teardown runs on EVERY path out of the loop — normal finish,
            // wall hit, or a throw from inside a step.
            $killed = false;
            if (!$child->exited()) {
                // A clean exit races its own last frame: the `BYE` a program
                // prints fires the final match a hair BEFORE its exit(0), and
                // the /bin/sh wrapper needs a beat more to follow its exec'd
                // child. One bounded grace poll (never a hang — it is fixed
                // at a second regardless of the plan) keeps "answered and
                // left" from being misreported as "terminated by us".
                $graceEnd = \microtime(true) + self::EXIT_GRACE_SECONDS;
                while (!$child->exited() && \microtime(true) < $graceEnd) {
                    if ($heartbeat !== null) {
                        $heartbeat();
                    }
                    \usleep(10000);
                }
            }

            if (!$child->exited()) {
                $killed = true;
                $gone = ProcessReaper::escalate(
                    static fn (int $signal): bool => ProcessContainment::terminatePid($child->pid(), $signal),
                    static fn (): bool => $child->exited(),
                );
                if ($gone) {
                    $child->wait();
                }
            }
            // One last bounded drain so the final frame rides the answer even
            // when the exit beat the last read; it belongs to the step that
            // was open when the program went.
            $tail = self::drain($pty);
            if ($tail !== '') {
                $lastIndex = \max(0, \count($ran) - 1);
                $ran[$lastIndex]['output'] = self::retain($ran[$lastIndex]['output'], $tail, $maxBytes, $retained, $dropped);
            }

            $pty->close();
        }

        $exitCode = $killed ? 124 : $child->exitCode();

        return self::result(
            refusal: null,
            pid: $child->pid(),
            ran: $ran,
            retained: $retained,
            dropped: $dropped,
            exitCode: $exitCode,
            exited: $child->exited(),
            killed: $killed,
        );
    }

    /**
     * One step's read window: collect until the pattern matches, the child
     * exits, the window closes, or the wall lands — whichever first. Bytes
     * after the child is gone are pulled with one bounded drain so a final
     * repaint (the `BYE` printed a hair before exit) still rides the step.
     *
     * @param \SugarCraft\Pty\Pty $pty
     * @param \SugarCraft\Pty\Child $child
     * @return array{0: string, 1: ?bool, 2: string, 3: bool, 4: bool}
     */
    private static function collectWindow($pty, $child, float $windowSeconds, float $deadline, int $maxBytes, int &$retained, int &$dropped, ?string $regex, ?\Closure $heartbeat): array
    {
        $output = '';
        $matched = $regex === null ? null : false;
        $note = '';
        $openedAt = \microtime(true);
        $windowEnd = \min($openedAt + $windowSeconds, $deadline);
        $childExited = false;
        $wallHit = false;

        while (true) {
            $now = \microtime(true);
            if ($now >= $windowEnd) {
                break;
            }

            if ($heartbeat !== null) {
                $heartbeat();
            }

            $tick = \min(self::READ_POLL_SECONDS, $windowEnd - $now);
            $chunk = $pty->read(self::READ_CHUNK_BYTES, $tick);
            if ($chunk !== null && $chunk !== '') {
                $output = self::retain($output, $chunk, $maxBytes, $retained, $dropped);
                if ($regex !== null && \preg_match($regex, $output) === 1) {
                    $matched = true;
                    break;
                }
            }

            if ($child->exited()) {
                $childExited = true;
                $output = self::retain($output, self::drain($pty), $maxBytes, $retained, $dropped);
                if ($regex !== null && $matched === false && \preg_match($regex, $output) === 1) {
                    $matched = true;
                }

                break;
            }

            if (\microtime(true) >= $deadline) {
                $wallHit = true;
                $note = 'wall deadline hit mid-step';

                break;
            }
        }

        if ($matched === false && $regex !== null) {
            $note = 'no-match within ' . \round($windowSeconds * 1000) . 'ms';
        }

        return [$output, $matched, $note, $childExited, $wallHit];
    }

    /**
     * Read the master until it answers nothing twice in a row, chunk-capped.
     */
    private static function drain($pty): string
    {
        $seen = '';
        $quiet = 0;
        $budget = self::DRAIN_MAX_CHUNKS;

        while ($quiet < self::DRAIN_QUIET_READS && $budget-- > 0) {
            $chunk = $pty->read(self::READ_CHUNK_BYTES, self::DRAIN_READ_TIMEOUT_SECONDS);
            if ($chunk === null || $chunk === '') {
                ++$quiet;

                continue;
            }

            $quiet = 0;
            $seen .= $chunk;
        }

        return $seen;
    }

    /**
     * Append into a step buffer under a cap SHARED ACROSS THE WHOLE SESSION
     * (`$retained` is the running total every step draws from): the answer's
     * bytes are the context-window budget, and step count must not multiply
     * it. Dropped bytes are counted, never silently lost.
     */
    private static function retain(string $buffer, string $chunk, int $cap, int &$retained, int &$dropped): string
    {
        if ($chunk === '') {
            return $buffer;
        }

        $room = $cap - $retained;
        if ($room <= 0) {
            $dropped += \strlen($chunk);

            return $buffer;
        }

        if (\strlen($chunk) <= $room) {
            $retained += \strlen($chunk);

            return $buffer . $chunk;
        }

        // The cap is a BYTE budget, so the cut is a byte cut — but a raw
        // substr($chunk, 0, $room) lands wherever $room falls, including the
        // middle of a multi-byte sequence. Decoding the retained buffer then
        // emits a lone continuation byte: one mojibake row in the transcript.
        // Back the cut off to a codepoint boundary (the same UTF-8 tail scan
        // sugar-core's InputReader::surfaceOversizePaste() uses); the few bytes
        // we give up are counted as dropped, never smuggled through half-cut.
        $cut = self::utf8SafeByteCut($chunk, $room);
        $dropped += \strlen($chunk) - $cut;
        $retained += $cut;

        return $buffer . substr($chunk, 0, $cut);
    }

    /**
     * Largest cut length at or below $room bytes that does not split a UTF-8
     * codepoint. $chunk is valid UTF-8 from its first byte, so the only place a
     * sequence can be torn is the very tail: scan back up to three bytes for the
     * lead, and if its declared length exceeds the bytes present, back the cut
     * off to before that incomplete sequence. A whole sequence's lead sits four
     * bytes back at most, so a three-step scan can never miss an incomplete one.
     */
    private static function utf8SafeByteCut(string $chunk, int $room): int
    {
        $size = \strlen($chunk);
        $cut = min($room, $size);

        for ($back = 1; $back <= 3 && $back <= $cut; $back++) {
            $b = \ord($chunk[$cut - $back]);
            if (($b & 0xc0) === 0x80) {
                continue; // continuation byte — keep looking for its lead
            }

            $need = match (true) {
                ($b & 0xe0) === 0xc0 => 2,
                ($b & 0xf0) === 0xe0 => 3,
                ($b & 0xf8) === 0xf0 => 4,
                default              => 1, // ASCII — the run is complete
            };
            if ($need > $back) {
                $cut -= $back; // a lead whose sequence is not yet complete
            }

            break;
        }

        return max(0, $cut);
    }

    /**
     * @param list<array{output: string, matched: ?bool, note: string}> $ran
     * @return array{refusal: ?string, pid: int, steps: list<array{output: string, matched: ?bool, note: string}>, retained: int, dropped: int, exitCode: ?int, exited: bool, killed: bool}
     */
    private static function result(?string $refusal, int $pid, array $ran, int $retained, int $dropped, ?int $exitCode, bool $exited, bool $killed): array
    {
        return [
            'refusal' => $refusal,
            'pid' => $pid,
            'steps' => $ran,
            'retained' => $retained,
            'dropped' => $dropped,
            'exitCode' => $exitCode,
            'exited' => $exited,
            'killed' => $killed,
        ];
    }
}
