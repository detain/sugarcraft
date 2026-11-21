<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Concerns;

use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Async\StdinStagingException;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Code\Support\ProcessReaper;
use SugarCraft\Code\Support\PtyBackend;
use SugarCraft\Code\Support\WindowsShell;
use SugarCraft\Code\Support\WindowsTaskKill;

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
 * {@see \SugarCraft\Code\Support\ProcessContainment::env()} carries the
 * fail-fast half the detach cannot reach — prompts that consult the environment before touching a device.
 * Both run paths take it through {@see \SugarCraft\Code\Support\ProcessContainment::scrubbedEnv()},
 * which also drops credential-shaped variables (audit F-E1): this trait's
 * output is the model's to read, so a provider key in the child's env is
 * one `env` away from the transcript.
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
 *
 * ## OUTPUT CAP — the default when a caller names none
 *
 * Every captured stream is RETAINED up to {@see DEFAULT_RETAINED_BYTES} (8 MiB)
 * when the caller passes no bound, or a zero or negative one. Bytes past that are
 * read, counted as dropped per stream, and discarded; they are never concatenated.
 * The old no-bound path appended every chunk unboundedly, so a runaway child could
 * grow the agent's memory without limit. The cap is the same figure
 * TestRunner::MAX_CAPTURE_BYTES uses for its own capture, so the two read alike.
 * A caller that needs a different ceiling passes its own positive $maxBytes.
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
     * Bytes RETAINED per captured stream when the caller names no bound (null,
     * zero or negative). Output past it is counted as dropped, never kept.
     */
    public const DEFAULT_RETAINED_BYTES = 8 * 1024 * 1024;

    /**
     * The deadline a run gets when the caller names none: far past any real
     * command, so ChildRun's deadline is always set and the wait is still
     * unbounded in practice, as the historical no-timeout path was.
     */
    private const UNBOUNDED_TIMEOUT_SECONDS = 86400.0 * 365;

    /** The shortest deadline ChildRun accepts; a zero or negative caller bound becomes this. */
    private const MINIMUM_TIMEOUT_SECONDS = 0.001;

    /** How often a runCaptured() caller's $onWait is beaten while the child runs. */
    private const WAIT_BEAT_SECONDS = 0.2;

    /**
     * Grace budgets for the deadline kill's 15→9 ladder: StatusLineCommand's
     * 0.5 s each rather than the reaper's 1 s defaults, because the caller
     * that set a deadline has already spent its patience. The ladder is the
     * tail of that budget, not a second one of the same size.
     */
    private const DEADLINE_TERMINATE_GRACE_SECONDS = 0.5;
    private const DEADLINE_KILL_GRACE_SECONDS = 0.5;

    /**
     * Appended to stderr when ChildRun stopped reading because the pipes failed
     * (stream_select() kept failing). Without it the result is a bare exit code
     * from the kill, which reads as the command's own answer.
     */
    private const PIPE_FAILED_NOTE =
        '[output capture failed: reading the command\'s output kept failing, so the capture stopped early '
        . 'and the command was killed if it was still running; the output above may be incomplete, '
        . 'and the exit code may be the kill\'s rather than the command\'s own]';

    /**
     * $maxBytes bounds what is RETAINED per stream, not what is read: ChildRun
     * drains the pipes to completion so the child never blocks on a full buffer,
     * but bytes past the bound are counted and discarded instead of accumulated.
     * Without it a `cat` of a multi-gigabyte file is an out-of-memory kill of the
     * agent itself, long before {@see TruncatesOutput} would get a chance to clip
     * the finished string. Null, or zero or less, takes {@see DEFAULT_RETAINED_BYTES}:
     * there is no unbounded capture, so a caller that wants more than the default
     * passes it.
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
     * $timeoutSeconds is an OPTIONAL wall-clock bound (audit 15d-14). Null — the
     * default — keeps the historical unbounded wait for callers that pass none
     * (Grep): it becomes {@see UNBOUNDED_TIMEOUT_SECONDS}, a figure no run reaches.
     * Bash passes its `timeout` parameter (default 120 s, item 0.4). A caller that
     * cannot afford to wait, the prompt assembly's git reads above all, passes one.
     * On expiry ChildRun kills the child's whole process group on the 15→9 ladder,
     * reaps it, and the result is exit 124 (timeout(1)'s number) with `timedOut`
     * true and whatever output arrived before the deadline. `timedOut` is the
     * discriminator to read — an exit code of 124 can also be a command's own
     * answer. A backgrounded grandchild that keeps the pipes open is not waited on
     * past the deadline either: ChildRun runs with no exit grace here, so the held
     * pipes run the clock out and the same kill follows.
     *
     * `groupKilled` is true only when `timedOut` is AND the process group the child
     * led was measured empty after the kill. A child that was not started behind a
     * containment wrapper leads no group of its own, so it is never true for one.
     * A kill that could not be confirmed is never narrated as one (ISS-014).
     *
     * Windows has no process group, and ChildRun's ladder ends at proc_terminate(),
     * which kills only the direct child. There the kill seam walks the child's tree
     * with {@see WindowsTaskKill::tree()} before the ladder's first signal, while
     * the child still roots the tree, and `groupKilled` is taskkill's own verdict.
     * The deadline kill runs on 0.5 s graces, not ChildRun's 1 s defaults.
     *
     * A pipe failure (ChildRun's PIPE_FAILED: stream_select() kept failing) ends
     * the read early and kills the child. The result then carries a note on stderr
     * saying the capture failed, so the exit code is not read as the command's own.
     *
     * $envOverrides is handed to {@see ProcessContainment::scrubbedEnv()} and
     * wins over the inherited environment — and over the credential scrub,
     * which runs before it; the default `[]` is the inherited environment
     * minus credentials (audit F-E1). EnvironmentBlock passes `GIT_OPTIONAL_LOCKS=0` this way
     * (audit 15d-12) so the lock rule stays scoped to its own git reads.
     *
     * $onWait is called on ChildRun's heartbeat, at most every 200 ms, for as
     * long as the child runs — including the wait for its exit after its output
     * has closed, which is where a `cmd >/dev/null 2>&1` spends its time. The hook
     * a sequential tool's heartbeat rides (item 0.4-b, {@see
     * \SugarCraft\Code\Tools\AcceptsHeartbeat}), so a long-running command reads
     * as alive to the turn's idle watchdog. It is only ever called from this
     * process; the caller throttles.
     *
     * $stdinFile is read into ChildRun's stdin payload before the spawn. A file
     * that cannot be read refuses the run the same way a failed spawn does.
     *
     * @param string|list<string> $command the shell string, or — since
     *        C-TUI-6.a, for the Windows Bash path — the direct-exec argv the
     *        array form of proc_open takes; {@see
     *        ProcessContainment::spawnSpec()} types both and hands the array
     *        unchanged on hosts with no detach wrapper
     * @param array<string, string> $envOverrides
     * @param (\Closure(): void)|null $onWait
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     truncatedBytes: int,
     *     stdoutDropped: int,
     *     stderrDropped: int,
     *     stdoutMidLine: bool,
     *     stderrMidLine: bool,
     *     timedOut: bool,
     *     groupKilled: bool,
     * }
     */
    private function runCaptured(string|array $command, ?string $cwd = null, ?int $maxBytes = null, ?float $timeoutSeconds = null, array $envOverrides = [], ?\Closure $onWait = null, ?\Closure $onChunk = null, ?string $stdinFile = null, array $envUnset = []): array
    {
        // $stdinFile feeds the child from a file (git's --stdin readers) without
        // a shell `<` redirect. ChildRun takes a payload, not a descriptor, so the
        // file is read whole; the readers this serves are fed path lists, which
        // are small. $envUnset removes inherited names before the overrides land,
        // which is what a shell's `unset` did before `VAR=val`.
        $stdin = null;
        if ($stdinFile !== null) {
            $stdin = @file_get_contents($stdinFile);
            if ($stdin === false) {
                return self::spawnRefused($command, 'its stdin file ' . $stdinFile . ' could not be read', null);
            }
        }

        // Phase 9 layer A: the spec comes from ProcessContainment::spawnSpec() (a
        // proven `setsid -w` wrapper naming /bin/sh explicitly, or the original
        // command where no usable detach exists) and the env from
        // ::scrubbedEnv() — ::env() minus credentials (audit F-E1), because what
        // this child prints is handed to the model. Both run paths take both.
        $spec = ProcessContainment::spawnSpec($command);
        $env = ProcessContainment::scrubbedEnv($envOverrides, $envUnset);

        try {
            $run = \is_array($spec) ? ChildRun::new(array_values($spec)) : ChildRun::commandLine($spec);
        } catch (\InvalidArgumentException $refused) {
            return self::spawnRefused($command, 'the command is empty, so there was nothing to run', null);
        }

        // The spawn, the pumps, the deadline and the kill ladder are ChildRun's. A
        // wrapped spec makes the child its own group leader, so the group is recorded
        // from its pid at spawn and survives its leader exiting first.
        // The cap is the same figure TestRunner::MAX_CAPTURE_BYTES uses for its own
        // capture, so the two read alike. Held pipes are not given an exit grace:
        // with none, a grandchild holding the output runs the clock out to the
        // deadline, which is what the byte-for-byte historical wait did.
        $started = false;
        $leadsGroup = ProcessContainment::groupsSpawns();
        $groupPid = null;
        $run = $run
            ->withCwd($cwd)
            ->withEnv($env)
            ->withMaxBytes($maxBytes === null || $maxBytes <= 0 ? self::DEFAULT_RETAINED_BYTES : $maxBytes)
            ->withTimeout($timeoutSeconds === null ? self::UNBOUNDED_TIMEOUT_SECONDS : max(self::MINIMUM_TIMEOUT_SECONDS, $timeoutSeconds))
            ->withExitGrace(null)
            ->withKillGrace(self::DEADLINE_TERMINATE_GRACE_SECONDS, self::DEADLINE_KILL_GRACE_SECONDS)
            ->withGroupLeader($leadsGroup)
            ->withStdin($stdin)
            ->withOnSpawn(static function (int $pid) use (&$started, &$groupPid, $leadsGroup): void {
                $started = true;
                // An unwrapped child shares this process's group, so only a child that leads its own is recorded.
                if ($leadsGroup) {
                    $groupPid = $pid;
                }
            });
        if ($onChunk !== null) {
            $run = $run->withOnChunk(static function (int|string $slot, string $chunk) use ($onChunk): void {
                $onChunk($chunk);
            });
        }
        if ($onWait !== null) {
            $run = $run->withHeartbeat(self::WAIT_BEAT_SECONDS, $onWait);
        }
        // Windows: no group to signal, and proc_terminate() reaches the direct child
        // only. The tree is walked from the kill seam, before the ladder's first
        // signal, because taskkill /T needs the root alive to find its descendants.
        $treeKilled = false;
        if (WindowsShell::isWindows()) {
            $run = $run->withOnKill(static function (int $pid) use (&$treeKilled): void {
                $treeKilled = WindowsTaskKill::tree($pid);
            });
        }

        // ChildRun raises RuntimeException for a refused spawn only. Once the child
        // has started, a throw is a caller's own (a callback), and it must reach
        // the caller rather than be reported as a spawn failure.
        error_clear_last();
        try {
            $r = $run->run();
        } catch (\RuntimeException $failure) {
            if ($started) {
                throw $failure;
            }

            return self::startFailed($command, $failure, error_get_last()['message'] ?? null);
        }

        // ChildRun's ladder reports whether it reaped the child, not whether the group
        // it signalled is empty, so the group is measured now. A kill that left a
        // member alive is never narrated as a group kill (ISS-014). On Windows the
        // verdict is taskkill's, from the kill seam above.
        $groupKilled = $r->timedOut()
            && (($groupPid !== null && BoundedShutdown::groupEmpty($groupPid)) || $treeKilled);

        return self::capturedResult($r, $groupKilled);
    }

    /**
     * runCaptured()'s answer for a run ChildRun finished: the streams, the exit, the
     * per-stream drops, and the pipe-failure note when the read failed.
     *
     * @return array{stdout:string,stderr:string,exitCode:int,truncatedBytes:int,stdoutDropped:int,stderrDropped:int,stdoutMidLine:bool,stderrMidLine:bool,timedOut:bool,groupKilled:bool}
     */
    private static function capturedResult(ChildResult $r, bool $groupKilled): array
    {
        $timedOut = $r->timedOut();
        $stdoutDropped = $r->droppedBytes - $r->droppedStderrBytes;
        $stderrDropped = $r->droppedStderrBytes;

        // The note goes on stderr, the tail the presentation layer keeps under any
        // cap. It ends the stream on a whole line, so a cap that cut stderr mid-line
        // no longer marks its end for repair: the repair would drop the note.
        $stderr = rtrim($r->stderr, "\n");
        $stderrMidLine = $stderrDropped > 0 && !str_ends_with($r->stderr, "\n");
        if ($r->status === ChildResult::PIPE_FAILED) {
            $stderr .= ($stderr === '' ? '' : "\n") . self::PIPE_FAILED_NOTE;
            $stderrMidLine = false;
        }

        // Decided BEFORE the rtrim: a stream that lost bytes but happened to
        // stop on a newline is intact, and repairing it anyway would throw
        // away a complete line for nothing.
        return [
            'stdout' => rtrim($r->stdout, "\n"),
            'stderr' => $stderr,
            // A signal death reads -1 from ChildRun's poll, which is not the number the
            // result reports: the signal is, as proc_close() gives it on an unpolled child.
            'exitCode' => $timedOut ? 124 : ($r->signal ?? $r->exitCode ?? -1),
            'truncatedBytes' => $r->droppedBytes,
            'stdoutDropped' => $stdoutDropped,
            'stderrDropped' => $stderrDropped,
            'stdoutMidLine' => $stdoutDropped > 0 && !str_ends_with($r->stdout, "\n"),
            'stderrMidLine' => $stderrMidLine,
            'timedOut' => $timedOut,
            'groupKilled' => $groupKilled,
        ];
    }

    /**
     * runCaptured()'s answer for a run ChildRun refused to start. A stdin payload
     * the Windows runtime could not stage to a temp file is a temp-directory
     * problem, reported as one and without the process-limit hint; its cause is the
     * tempnam()/write warning. Every other refusal is proc_open() refusing the
     * spawn, which keeps the hint.
     *
     * @param string|list<string> $command
     * @return array{stdout:string,stderr:string,exitCode:int,truncatedBytes:int,stdoutDropped:int,stderrDropped:int,stdoutMidLine:bool,stderrMidLine:bool,timedOut:bool,groupKilled:bool}
     */
    private static function startFailed(string|array $command, \RuntimeException $failure, ?string $cause): array
    {
        if ($failure instanceof StdinStagingException) {
            $dir = $failure->tempDir();

            return self::spawnRefused(
                $command,
                'its stdin could not be staged: no temp file could be created and written in '
                    . ($dir ?? 'the temp directory'),
                $cause,
            );
        }

        return self::spawnRefused($command, 'proc_open() failed', $cause, true);
    }

    /**
     * The never-started answer for runCaptured(): exit 127, empty stdout, the
     * reason on stderr. The run was never started, so nothing ran and nothing
     * was read.
     *
     * $systemFailure marks a spawn the system refused (proc_open() failed), and
     * only that case carries the process-limit hint. An empty command or an
     * unreadable stdin file is a problem with the request, and the hint would
     * point the reader at the wrong cause.
     *
     * @param string|list<string> $command
     * @return array{stdout:string,stderr:string,exitCode:int,truncatedBytes:int,stdoutDropped:int,stderrDropped:int,stdoutMidLine:bool,stderrMidLine:bool,timedOut:bool,groupKilled:bool}
     */
    private static function spawnRefused(string|array $command, string $why, ?string $cause, bool $systemFailure = false): array
    {
        $text = \is_array($command) ? implode(' ', $command) : $command;
        $shown = strlen($text) > 120 ? substr($text, 0, 117) . '...' : $text;

        return [
            'stdout' => '',
            'stderr' => 'Could not start the command' . ($shown === '' ? '' : ' ' . $shown) . ' — ' . $why
                . ($cause === null ? '' : ' (' . $cause . ')')
                . '; the command was never run.'
                . ($systemFailure
                    ? ' This is a system-level spawn failure (process-table exhaustion or a denied fork), not a problem with the command text; check `ulimit -u` / system process limits and retry.'
                    : ''),
            'exitCode' => 127,
            'truncatedBytes' => 0,
            'stdoutDropped' => 0,
            'stderrDropped' => 0,
            'stdoutMidLine' => false,
            'stderrMidLine' => false,
            'timedOut' => false,
            'groupKilled' => false,
        ];
    }

    /**
     * Idle ceiling for an interactive run: a program that emits NOTHING for
     * this long while still alive is waiting on a keystroke nobody is there
     * to send. Bounded refusal is the whole contract — an unbounded wait is
     * the hang Phase 9 exists to end, and a "success" laundered from a
     * frozen screen would be the second lie.
     *
     * The default of `bashInteractiveIdleSeconds` (roadmap N-P4c),
     * which is read when an interactive run starts
     * ({@see \SugarCraft\Code\Tools\ToolLimits::current()}); the setting's
     * range keeps it a bound, never "wait forever".
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
     *  - it goes SILENT past the idle ceiling while alive, OR outlives the
     *    wall bound while still painting: run the reaper's signal-15→9
     *    ladder over its group, reap, exit 124, stderr names which bound
     *    fired — the transcript up to the stop still arrives so the model
     *    can read WHY (usually its own "[sudo] password" prompt);
     *  - the host CANNOT give a pty (or allocation itself fails): refusal
     *    before anything starts, exit 126, stdout empty. A degraded
     *    pipe-mode answer to an explicit interactive request would be the
     *    schema-flag lie the absent-pin was guarding against, in costume.
     *
     * $timeoutSeconds is the WALL bound beside the idle ceiling — Bash's
     * `timeout` parameter, so one model-chosen number bounds both modes. The
     * idle ceiling answers "is anyone going to type?", the wall bound "how
     * long may this run at all?"; a program that keeps repainting a progress
     * bar never trips the first. Null keeps the historical 3x-idle bound.
     * `timedOut` is true whichever bound fired, matching runCaptured().
     * $onWait is runCaptured()'s wait beat, called on every 50 ms read pass.
     *
     * @param (\Closure(): void)|null $onWait
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     truncatedBytes: int,
     *     stdoutDropped: int,
     *     stderrDropped: int,
     *     stdoutMidLine: bool,
     *     stderrMidLine: bool,
     *     timedOut: bool,
     *     groupKilled: bool,
     * }
     */
    private function runCapturedInteractive(string $command, ?string $cwd = null, ?int $maxBytes = null, ?float $idleCeilingSec = null, ?float $timeoutSeconds = null, ?\Closure $onWait = null, ?\Closure $onChunk = null): array
    {
        $idle = $idleCeilingSec
            ?? \SugarCraft\Code\Tools\ToolLimits::current()->seconds(\SugarCraft\Code\Tools\ToolLimits::INTERACTIVE_IDLE_KEY)
            ?? self::INTERACTIVE_IDLE_CEILING_SECONDS;

        if (!ProcessContainment::interactiveAvailable()) {
            return self::interactiveRefusal(
                'interactive mode is unavailable on this host (no pty mechanism); the command was refused and never started',
            );
        }

        if (ProcessContainment::interactiveBashMissing()) {
            // ConPTY would open; the script it would carry has no interpreter.
            // §6.3: the refusal names the install, never a cmd.exe fallback.
            return self::interactiveRefusal(WindowsShell::BASH_MISSING_NOTICE);
        }

        $master = null;
        try {
            // The backend seam asks the platform factory (C-TUI-6.d): ConPTY
            // pair on Windows, the same canonical POSIX pair the facade used
            // everywhere else.
            $pair = PtyBackend::open();
            $master = $pair->master();
            $child = $pair->slave()->spawn(
                ProcessContainment::interactiveSpawnCommand($command, $cwd),
                // Scrubbed for the same reason as runCaptured(): the pty's
                // transcript is the model's to read (audit F-E1).
                ProcessContainment::scrubbedEnv(),
                80,
                24,
                // Same capability answer as PtyRun's session spawn: the ConPTY
                // pair throws on a hard-wired true, the POSIX map's absence
                // keeps the historical request (LANE-CC, 2026-11-11).
                PtyBackend::honoursControllingTerminal(),
            );
        } catch (\Throwable $failure) {
            // spawn() throwing AFTER open() succeeded would strand the master
            // handle — the run loop's finally below is never reached from this
            // path. open() itself throwing leaves $master null.
            if ($master !== null) {
                try {
                    $master->close();
                } catch (\Throwable) {
                    // Nothing to escalate a failure to; the refusal is the answer.
                }
            }

            return self::interactiveRefusal(
                'interactive mode refused: the pty could not be allocated (' . $failure->getMessage() . ')',
            );
        }

        $transcript = '';
        $dropped = 0;
        $stuck = false;
        $ladderConfirmed = false;
        $wallExpired = false;
        $lastProgressAt = \microtime(true);
        $wall = $timeoutSeconds !== null && $timeoutSeconds > 0.0 ? $timeoutSeconds : 3.0 * $idle;
        $hardDeadline = $lastProgressAt + $wall;

        try {
            while (true) {
                if ($onWait !== null) {
                    $onWait();
                }
                $chunk = $master->read(8192, 0.05);
                if ($chunk !== null && $chunk !== '') {
                    $transcript = self::appendBounded($transcript, $chunk, $maxBytes, $dropped);
                    $lastProgressAt = \microtime(true);
                    // WEB-4: the pty's live paint rides the same tail feed as
                    // the pipe capture; the bounded final drains stay out of
                    // it — tool.finished carries those bytes regardless.
                    if ($onChunk !== null) {
                        $onChunk($chunk);
                    }

                    // A program that never stops painting never reaches the
                    // idle check below, so the wall bound is checked here too.
                    if ($lastProgressAt < $hardDeadline) {
                        continue;
                    }
                }

                if ($child->exited()) {
                    break;
                }

                $now = \microtime(true);
                if ($now - $lastProgressAt >= $idle || $now >= $hardDeadline) {
                    $stuck = true;
                    $wallExpired = $now >= $hardDeadline;

                    // THE REAPER'S LADDER, not a lone TERM: the old shape
                    // signalled 15 and then called `wait()` — an UNBOUNDED
                    // poll — so a child that trapped SIGTERM turned the
                    // bounded refusal back into the exact hang Phase 9
                    // exists to end (E676's doctrine: one shared ladder,
                    // caller keeps its budgets; the idle ceiling already
                    // spent this run's patience, so the ladder's default
                    // graces ride). terminatePid() is the group-first twin
                    // of the reaper's own rung helper — the pty child leads
                    // its session, and killing only the shell would orphan
                    // the program behind it.
                    $ladderConfirmed = ProcessReaper::escalate(
                        static fn(int $signal): bool => ProcessContainment::terminatePid($child->pid(), $signal),
                        static fn(): bool => $child->exited(),
                    );

                    // Bounded EITHER WAY — the property the ladder exists to
                    // guarantee. When it reports the child gone, `wait()`
                    // reaps from its fast path; when even signal 9 missed
                    // (an uninterruptible kernel wait), not waiting is the
                    // point: the answer still leaves at 124 on the clock and
                    // the handle's non-blocking destructor reaps late.
                    if ($ladderConfirmed) {
                        $child->wait();
                    }

                    $transcript = self::appendBounded($transcript, self::drainPty($master), $maxBytes, $dropped);

                    break;
                }
            }

            // The exited() break can beat the last buffered screen repaint;
            // one more short drain so the transcript ends where the program
            // did, not one frame earlier.
            $transcript = self::appendBounded($transcript, self::drainPty($master), $maxBytes, $dropped);
        } finally {
            $master->close();
        }

        $exitCode = $stuck ? 124 : ($child->exitCode() ?? 0);
        $stderr = match (true) {
            !$stuck => '',
            $wallExpired => sprintf(
                'the interactive program was still running after %gs and was terminated at its timeout',
                round($wall, 3),
            ),
            default => sprintf(
                'the interactive program went silent for %gs and was terminated — it was waiting for input on a terminal no one can type at, and no password is ever accepted here',
                round($idle, 3),
            ),
        };

        return [
            'stdout' => \rtrim($transcript, "\r\n"),
            'stderr' => $stderr,
            'exitCode' => $exitCode,
            'truncatedBytes' => $dropped,
            'stdoutDropped' => $dropped,
            'stderrDropped' => 0,
            'stdoutMidLine' => $dropped > 0 && !\str_ends_with($transcript, "\n"),
            'stderrMidLine' => false,
            'timedOut' => $stuck,
            // The pty child LEADS its session and terminatePid() signals
            // the group first, so the ladder's confirmation of the child's
            // exit is as much group-kill proof as this path can honestly
            // produce (ISS-014's claim rule).
            'groupKilled' => $stuck && $ladderConfirmed,
        ];
    }

    /**
     * Read the pty master until it answers nothing twice in a row, bounded
     * so a program STILL writing (impossible right after exit/TERM, cheap
     * insurance anyway) cannot extend the drain forever.
     */
    private static function drainPty(\SugarCraft\Pty\Contract\MasterPty $pty, int $maxChunks = 32): string
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
     * @return array{stdout:string,stderr:string,exitCode:int,truncatedBytes:int,stdoutDropped:int,stderrDropped:int,stdoutMidLine:bool,stderrMidLine:bool,timedOut:bool,groupKilled:bool}
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
            'timedOut' => false,
            'groupKilled' => false,
        ];
    }

    /**
     * DELEGATED TO THE CHOKE POINT (E672): the setsid probe and its memo now
     * live in {@see \SugarCraft\Code\Support\ProcessContainment::detachedSpawnBinary()}
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
        // No unbounded append: a null or non-positive bound takes the default cap,
        // so every captured stream stays bounded whatever the caller passed.
        if ($maxBytes === null || $maxBytes <= 0) {
            $maxBytes = self::DEFAULT_RETAINED_BYTES;
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
        // across the cap: `runCaptured()` rtrims the retained text, so a capture
        // that stayed under DEFAULT_RETAINED_BYTES loses its trailing newline
        // while one that reached the cap -- whose retained slice ends mid-line --
        // does not. The same command
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
