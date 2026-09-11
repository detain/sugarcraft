<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

use SugarCraft\Crush\Support\ProcessContainment;

/**
 * Runs a shell command capturing BOTH stdout and stderr.
 *
 * `exec()` captures only stdout. The child inherits the PHP process's stderr,
 * so anything a command writes there goes straight to the real terminal —
 * underneath a running TUI that means text painted outside the managed frame,
 * at whatever cursor position the terminal happens to be at, surviving until
 * the next full repaint. A `grep … | head` pipeline emitting
 * `grep: write error: Broken pipe` was enough to corrupt the display.
 *
 * It is also a diagnostic loss: stderr is where a failing command explains
 * itself, so `exec()` handed the model an empty result with an error flag and
 * no reason. Capturing it means the agent can actually see why something
 * failed.
 *
 * stdout and stderr are kept SEPARATE rather than merged with `2>&1`, because
 * plenty of well-behaved tools write progress and warnings to stderr on a
 * successful run and folding that into the result would corrupt the output
 * the model reasons about.
 *
 * ## NO CONTROLLING TERMINAL FOR TOOL CHILDREN — Phase 9 layer A
 *
 * A closed stdin pipe does NOT make a command non-interactive. `sudo`, `ssh`,
 * git's credential path and every pager reach for `/dev/tty` — the
 * CONTROLLING TERMINAL, resolved by the kernel, not through fd 0/1/2 — so
 * their prompts paint outside the app frame at whatever cursor the terminal
 * is on, and the tool hangs waiting for a human the TUI cannot see. Closing
 * or redirecting stdin cannot help: the child never asked fd 0 for anything.
 *
 * The one lever that reaches `/dev/tty` is removing the session's controlling
 * terminal before exec. Every child is therefore spawned through
 * `setsid -w`, which starts a new session with no terminal attached;
 * `/dev/tty` then fails at OPEN time (ENXIO, "No such device or address"),
 * so an interactive command exits immediately and its diagnostic —
 * `sudo: a password is required`, `Permission denied (publickey,password)` —
 * arrives on the stderr pipe this trait already captures and the renderer
 * already displays. Detach is a PREREQUISITE for the display fix, not an
 * alternative to it: a child that still holds the controlling terminal can
 * scribble on the screen at any time, and a renderer that paints by diffing
 * its own model of the screen has no way to know it happened. The plan's
 * `sudo -n` rides as a note rather than a flag: this trait cannot know which
 * argv belongs to sudo, and the detach makes sudo's -n outcome unconditional.
 *
 * {@see \SugarCraft\Crush\Support\ProcessContainment::env()} carries the
 * fail-fast half the detach cannot reach — prompts that consult the environment before touching a device.
 * Both halves apply to EVERY runCaptured() caller (Bash, Grep,
 * EnvironmentBlock share this one choke point), which is what "sweep the
 * behaviour, not the token" asked for.
 *
 * FALLBACK IS MEASURED, NOT ASSUMED: on a host with no usable `setsid(1)`
 * (macOS ships none) the env block still applies and the spawn proceeds
 * attached; {@see detachedSpawnBinary()} proves `-w` exit-status forwarding
 * by running it, because a wrapper that laundered every command's exit code
 * into the wrapper's own would trade a hang for a lie.
 *
 * LAYER C SEAM, BUILT (round 65): the PTY-backed interactive mode is the
 * optional `interactive` PARAMETER on Bash — a parameter, never a second
 * tool, because a second name splits every permission and hook rule in
 * two. The default is OFF, and the default path is runCaptured() exactly
 * as layer A shipped it: setsid-detached, stdin closed, fail-fast env
 * forced. When ON, {@see runCapturedInteractive()} routes the same
 * command through a pty THIS process allocates and captures from — the
 * child paints on a terminal the user never sees, and never on their
 * screen. Neither mode accepts secrets: env() forcing is identical on
 * both paths, and a program that blocks waiting for input is terminated
 * at the idle ceiling with its transcript and a refusal — an interactive
 * pty is a terminal, not a typist, and no askpass surface exists here.
 * InteractivePromptContainmentTest pinned this parameter's ABSENCE until
 * the mechanism landed; the flip of that pin is the chosen change.
 */
trait CapturesProcessOutput
{
    /**
     * Stands in for a successful command's discarded stderr.
     *
     * A constant rather than an inline string because {@see Bash::description()}
     * has to tell the model the same thing this marker says, and two
     * hand-maintained spellings of one fact drift.
     */
    private const SUPPRESSED_STDERR_MARKER =
        '... [stderr suppressed: the command succeeded and also wrote to stderr; re-run with 2>&1 to see it]';

    /**
     * $maxBytes bounds what is RETAINED per stream, not what is read: the
     * pipes are still drained to completion so the child never blocks on a
     * full buffer, but bytes past the bound are counted and discarded instead
     * of accumulated. Without it a `cat` of a multi-gigabyte file is an
     * out-of-memory kill of the agent itself, long before {@see
     * TruncatesOutput} would get a chance to clip the finished string. Null
     * keeps the capture unbounded for callers that want the whole thing.
     *
     * The discard counts are reported PER STREAM, not only as a total. Which
     * stream lost bytes is not bookkeeping trivia: {@see
     * mergeCapturedOutput()} routinely drops one stream from the result
     * entirely, and folding that stream's discards into the reported loss
     * labels a complete answer partial — bytes that were never going to
     * appear at any cap counted as if the cap had cost them. `truncatedBytes`
     * remains the sum for callers that genuinely want both.
     *
     * `stdoutMidLine`/`stderrMidLine` say whether the bound landed INSIDE a
     * line. The bound is a byte count, so it lands wherever it lands; the
     * retained text can therefore end on half a path or half a grep hit,
     * which the presentation layer has to repair (it cannot infer this from a
     * discard count alone, since a cut that happened to fall on a newline
     * needs no repair).
     *
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     truncatedBytes: int,
     *     stdoutDropped: int,
     *     stderrDropped: int,
     *     stdoutMidLine: bool,
     *     stderrMidLine: bool,
     * }
     */
    private function runCaptured(string $command, ?string $cwd = null, ?int $maxBytes = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Phase 9 layer A, routed through the choke point under E672: the
        // spec comes from ProcessContainment::spawnSpec() (a proven
        // `setsid -w` wrapper naming /bin/sh explicitly, because that is
        // exactly the shell PHP's string form runs, or the original command
        // where no usable detach exists) and the env from ::env(). ONE
        // proc_open call, with the spec chosen above, not a ternary of two:
        // DescriptorInheritanceGuardTest licenses spawn sites by name, and a
        // doubly-anonymous result would read as two unclassifiable exposed
        // children where one known site lives. The env block rides both
        // paths: detach is the half that needs a binary, fail-fast env needs
        // none.
        $spawnSpec = ProcessContainment::spawnSpec($command);
        $env = ProcessContainment::env();
        $process = @proc_open($spawnSpec, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return [
                'stdout' => '',
                'stderr' => 'Failed to start process',
                'exitCode' => 127,
                'truncatedBytes' => 0,
                'stdoutDropped' => 0,
                'stderrDropped' => 0,
                'stdoutMidLine' => false,
                'stderrMidLine' => false,
            ];
        }

        // Close stdin immediately: a command that reads from it (and nothing
        // supplies input) would otherwise block the agent forever.
        fclose($pipes[0]);

        // Non-blocking reads on both pipes. Draining only stdout first can
        // deadlock: a command producing more stderr than the pipe buffer holds
        // blocks writing it while we sit waiting on stdout.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $stdoutDropped = 0;
        $stderrDropped = 0;
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = array_filter([$pipes[1], $pipes[2]], static fn($p) => !feof($p));
            if ($read === []) {
                break;
            }
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout = self::appendBounded($stdout, $chunk, $maxBytes, $stdoutDropped);
                } else {
                    $stderr = self::appendBounded($stderr, $chunk, $maxBytes, $stderrDropped);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Decided BEFORE the rtrim: a stream that lost bytes but happened to
        // stop on a newline is intact, and repairing it anyway would throw
        // away a complete line for nothing.
        $stdoutMidLine = $stdoutDropped > 0 && !str_ends_with($stdout, "\n");
        $stderrMidLine = $stderrDropped > 0 && !str_ends_with($stderr, "\n");

        return [
            'stdout' => rtrim($stdout, "\n"),
            'stderr' => rtrim($stderr, "\n"),
            'exitCode' => $exitCode,
            'truncatedBytes' => $stdoutDropped + $stderrDropped,
            'stdoutDropped' => $stdoutDropped,
            'stderrDropped' => $stderrDropped,
            'stdoutMidLine' => $stdoutMidLine,
            'stderrMidLine' => $stderrMidLine,
        ];
    }

    /**
     * Idle ceiling for an interactive run: a program that emits NOTHING for
     * this long while still alive is waiting on a keystroke nobody is there
     * to send. Bounded refusal is the whole contract — an unbounded wait is
     * the hang Phase 9 exists to end, and a "success" laundered from a
     * frozen screen would be the second lie.
     */
    private const INTERACTIVE_IDLE_CEILING_SECONDS = 8.0;

    /**
     * Run $command attached to a pty this process allocates and captures —
     * the mechanism behind Bash's optional `interactive` parameter (Phase 9
     * layer C).
     *
     * The return shape is runCaptured()'s, and every semantic difference is
     * one the presentation layer already handles: a pty multiplexes
     * stdout, stderr and every escape sequence onto ONE stream, so the
     * transcript rides `stdout` and `stderr` stays empty — there is no
     * second channel to lose bytes to. `stdoutMidLine` says the byte cap
     * cut the transcript mid-line (same rule as the captured path).
     *
     * Three exits, each honest about which it is:
     *  - the program EXITS: transcript + its real status (pty children
     *    report through the same 0/signal-number convention the captured
     *    path already keeps);
     *  - it goes SILENT past the idle ceiling while alive: TERM the group,
     *    reap, exit 124, stderr names the refusal — the transcript up to
     *    the freeze still arrives so the model can read WHY (usually its
     *    own "[sudo] password" prompt);
     *  - the host CANNOT give a pty (or allocation itself fails): refusal
     *    before anything starts, exit 126, stdout empty. A degraded
     *    pipe-mode answer to an explicit interactive request would be the
     *    schema-flag lie the absent-pin was guarding against, in costume.
     *
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     truncatedBytes: int,
     *     stdoutDropped: int,
     *     stderrDropped: int,
     *     stdoutMidLine: bool,
     *     stderrMidLine: bool,
     * }
     */
    private function runCapturedInteractive(string $command, ?string $cwd = null, ?int $maxBytes = null, ?float $idleCeilingSec = null): array
    {
        $idle = $idleCeilingSec ?? self::INTERACTIVE_IDLE_CEILING_SECONDS;

        if (!ProcessContainment::interactiveAvailable()) {
            return self::interactiveRefusal(
                'interactive mode is unavailable on this host (no pty mechanism); the command was refused and never started',
            );
        }

        try {
            $pty = \SugarCraft\Pty\Pty::open();
            $child = $pty->spawn(
                ProcessContainment::interactiveSpawnCommand($command, $cwd),
                ProcessContainment::env(),
                80,
                24,
                true,
            );
        } catch (\Throwable $failure) {
            return self::interactiveRefusal(
                'interactive mode refused: the pty could not be allocated (' . $failure->getMessage() . ')',
            );
        }

        $transcript = '';
        $dropped = 0;
        $stuck = false;
        $lastProgressAt = \microtime(true);
        $hardDeadline = $lastProgressAt + (3.0 * $idle);

        try {
            while (true) {
                $chunk = $pty->read(8192, 0.05);
                if ($chunk !== null && $chunk !== '') {
                    $transcript = self::appendBounded($transcript, $chunk, $maxBytes, $dropped);
                    $lastProgressAt = \microtime(true);

                    continue;
                }

                if ($child->exited()) {
                    break;
                }

                $now = \microtime(true);
                if ($now - $lastProgressAt >= $idle || $now >= $hardDeadline) {
                    $stuck = true;
                    ProcessContainment::terminatePid($child->pid());
                    $child->wait();
                    $transcript = self::appendBounded($transcript, self::drainPty($pty), $maxBytes, $dropped);

                    break;
                }
            }

            // The exited() break can beat the last buffered screen repaint;
            // one more short drain so the transcript ends where the program
            // did, not one frame earlier.
            $transcript = self::appendBounded($transcript, self::drainPty($pty), $maxBytes, $dropped);
        } finally {
            $pty->close();
        }

        $exitCode = $stuck ? 124 : ($child->exitCode() ?? 0);
        $stderr = $stuck
            ? sprintf(
                'the interactive program went silent for %gs and was terminated — it was waiting for input on a terminal no one can type at, and no password is ever accepted here',
                round($idle, 3),
            )
            : '';

        return [
            'stdout' => \rtrim($transcript, "\r\n"),
            'stderr' => $stderr,
            'exitCode' => $exitCode,
            'truncatedBytes' => $dropped,
            'stdoutDropped' => $dropped,
            'stderrDropped' => 0,
            'stdoutMidLine' => $dropped > 0 && !\str_ends_with($transcript, "\n"),
            'stderrMidLine' => false,
        ];
    }

    /**
     * Read the pty master until it answers nothing twice in a row, bounded
     * so a program STILL writing (impossible right after exit/TERM, cheap
     * insurance anyway) cannot extend the drain forever.
     */
    private static function drainPty(\SugarCraft\Pty\Pty $pty, int $maxChunks = 32): string
    {
        $seen = '';
        $quiet = 0;
        while ($quiet < 2 && $maxChunks-- > 0) {
            $chunk = $pty->read(8192, 0.05);
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
     * The never-started answer: exit 126 (found-but-cannot-execute, the
     * shell's own number for this exact complaint), empty stdout, reason on
     * stderr so mergeCapturedOutput() surfaces it on the non-zero branch.
     *
     * @return array{stdout:string,stderr:string,exitCode:int,truncatedBytes:int,stdoutDropped:int,stderrDropped:int,stdoutMidLine:bool,stderrMidLine:bool}
     */
    private static function interactiveRefusal(string $reason): array
    {
        return [
            'stdout' => '',
            'stderr' => $reason,
            'exitCode' => 126,
            'truncatedBytes' => 0,
            'stdoutDropped' => 0,
            'stderrDropped' => 0,
            'stdoutMidLine' => false,
            'stderrMidLine' => false,
        ];
    }

    /**
     * DELEGATED TO THE CHOKE POINT (E672): the setsid probe and its memo now
     * live in {@see \SugarCraft\Crush\Support\ProcessContainment::detachedSpawnBinary()}
     * — one answer per process, not one per host class. Kept as trait entry
     * points because host tests reach them through `self::`.
     */
    private static function detachedSpawnBinary(): string
    {
        return ProcessContainment::detachedSpawnBinary();
    }

    /**
     * DELEGATED TO THE CHOKE POINT (E672) — {@see ProcessContainment::env()}.
     */
    private static function containmentEnv(): array
    {
        return ProcessContainment::env();
    }

    /**
     * Append as much of $chunk as the bound still allows, adding the remainder
     * to $discarded.
     *
     * The partial-append case matters: a bound reached mid-chunk keeps the
     * prefix rather than dropping the whole 8 KiB read, so the retained output
     * lands on the bound instead of somewhere up to a chunk short of it.
     */
    private static function appendBounded(string $buffer, string $chunk, ?int $maxBytes, int &$discarded): string
    {
        if ($maxBytes === null || $maxBytes <= 0) {
            return $buffer . $chunk;
        }

        $room = $maxBytes - strlen($buffer);
        if ($room <= 0) {
            $discarded += strlen($chunk);

            return $buffer;
        }

        if (strlen($chunk) <= $room) {
            return $buffer . $chunk;
        }

        $discarded += strlen($chunk) - $room;

        return $buffer . substr($chunk, 0, $room);
    }

    /**
     * Decide which captured streams belong in the result, and hand the
     * presentation layer everything it needs to bound them honestly.
     *
     * stderr is surfaced when the command FAILED (that is where the reason
     * lives) or when it succeeded silently on stdout but said something on
     * stderr. A successful command with real stdout keeps its output clean --
     * but not silently: that branch now carries a byte-count marker in place
     * of the text, because a dropped stream nobody mentions is the same
     * failure as a truncation nobody mentions.
     *
     * The return is a two-part shape rather than one joined string because
     * the two parts have different claims on a limited budget. `head` is the
     * bulk answer and `tail` is the trailing explanation; concatenating them
     * first and clipping from the front afterwards is precisely how the
     * explanation gets lost, since it is by construction the last thing in
     * the string. {@see TruncatesOutput::truncateMerged()} is what acts on
     * the distinction.
     *
     * `dropped` counts ONLY the streams that made it into this result.
     * Reporting the discards of a stream this merge just threw away tells the
     * model a complete answer is partial and sends it back to re-run a
     * command that already answered the question.
     *
     * @param array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     stdoutDropped?: int,
     *     stderrDropped?: int,
     *     stdoutMidLine?: bool,
     *     stderrMidLine?: bool,
     * } $run
     *
     * @return array{head: string, tail: string, dropped: int, headMidLine: bool, tailMidLine: bool}
     */
    private function mergeCapturedOutput(array $run): array
    {
        $stdoutDropped = $run['stdoutDropped'] ?? 0;
        $stderrDropped = $run['stderrDropped'] ?? 0;
        $stdoutMidLine = $run['stdoutMidLine'] ?? false;
        $stderrMidLine = $run['stderrMidLine'] ?? false;

        $onlyStdout = [
            'head' => $run['stdout'],
            'tail' => '',
            'dropped' => $stdoutDropped,
            'headMidLine' => $stdoutMidLine,
            'tailMidLine' => false,
        ];
        $onlyStderr = [
            'head' => $run['stderr'],
            'tail' => '',
            'dropped' => $stderrDropped,
            'headMidLine' => $stderrMidLine,
            'tailMidLine' => false,
        ];

        if ($run['stderr'] === '') {
            return $onlyStdout;
        }

        if ($run['stdout'] === '') {
            return $onlyStderr;
        }

        if ($run['exitCode'] !== 0) {
            return [
                'head' => $run['stdout'],
                'tail' => $run['stderr'],
                'dropped' => $stdoutDropped + $stderrDropped,
                'headMidLine' => $stdoutMidLine,
                'tailMidLine' => $stderrMidLine,
            ];
        }

        // A succeeding command's stderr still does not join the answer -- that
        // is the whole point of keeping the streams apart -- but its EXISTENCE
        // now does. Dropping it in total silence is how a green `phpunit`,
        // `composer` or compiler run reads as warning-free to a model that
        // never saw the warnings; the marker costs one line and names the
        // redirect that recovers them.
        //
        // Deliberately WITHOUT a byte count. The only figure available here is
        // `strlen($run['stderr']) + $stderrDropped`, and it is not stable
        // across the cap: `runCaptured()` rtrims the retained text, so an
        // UNCAPPED capture loses its trailing newline while a capped one --
        // whose retained slice ends mid-line -- does not. The same command
        // would then report two different totals depending on $maxOutputBytes,
        // which is a number travelling without its domain and would also break
        // the cap-invariance the two OutputTruncationTest chatty-stderr cases
        // pin. Presence plus the recovery instruction is the part that is true
        // at every cap.
        return [
            'head' => $run['stdout'],
            'tail' => self::SUPPRESSED_STDERR_MARKER,
            'dropped' => $stdoutDropped,
            'headMidLine' => $stdoutMidLine,
            'tailMidLine' => false,
        ];
    }
}
