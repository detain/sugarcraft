<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config;

use SugarCraft\Core\Util\Sanitize;

/**
 * The `statusLine` settings key: a user-supplied command whose stdout is
 * painted as one extra segment of the live status bar
 * ({@see \SugarCraft\Crush\Renderer::renderStatusBar()}).
 *
 * The shape is Claude Code's, so a `settings.json` written for that tool
 * carries over unchanged:
 *
 *     {"statusLine": {"type": "command", "command": "git branch --show-current"}}
 *
 * THIS IS A COMMAND-EXECUTION KEY AND THAT IS WHAT DECIDES ITS TIER. It is in
 * {@see LayeredSettings::LAYERED_KEYS} and deliberately NOT in
 * {@see LayeredSettings::PROJECT_TIER_KEYS}, so
 * {@see LayeredSettings::userTierOnlyKeys()} names it and
 * {@see LayeredSettings::projectLayer()}'s `only()` filter drops it out of
 * both project files before the merge ever sees them. The argument is the one
 * `PROJECT_TIER_KEYS` already makes for `provider` and `instructions`, only
 * shorter: those two let a checkout choose where a prompt is SENT and what
 * text is authoritative, and this one lets a checkout choose what RUNS. A
 * project-tier `statusLine` would be arbitrary code execution on
 * clone-and-launch, on a timer, with no tool call and no permission gate
 * anywhere in the path — the gate chain
 * ({@see \SugarCraft\Crush\Permissions\PermissionGate}) sits on the model's
 * tool calls, and nothing in it is reached from here.
 *
 * WHY THE RUNTIME STATE IS PROCESS-LEVEL rather than a {@see \SugarCraft\Crush\Chat}
 * field. `Chat` is immutable and its `view()` must be free of side effects, so
 * the command cannot be run where it is painted; the value therefore has to be
 * produced on the update path and read on the render path, and `Renderer` is a
 * static-only class holding no `Chat`-independent state of its own. The same
 * shape {@see \SugarCraft\Crush\Tui\Renderer::getTerminalSize()} uses for the
 * size cache, including its {@see reset()} counterpart so a test can put the
 * process back the way it found it.
 *
 * The three-way split is deliberate:
 *
 *  - {@see refresh()} is the ONLY side-effecting entry point, driven by
 *    {@see \SugarCraft\Crush\Chat::subscriptions()}'s tick;
 *  - {@see line()} is a pure read for the renderer;
 *  - {@see run()} is the bounded execution itself, callable on an instance
 *    with no process state involved at all, which is what makes the timeout,
 *    the clip and the sanitiser testable without a Chat or a Program.
 */
final class StatusLineCommand
{
    /**
     * The settings key this class answers.
     *
     * A CONSTANT because three places have to agree on the spelling —
     * {@see LayeredSettings::LAYERED_KEYS}, {@see fromSettings()} and
     * `docs/SETTINGS.md`'s table — and a key spelled twice is a key that can
     * be layered under one spelling and read under another, which is the
     * silent-drop failure this feature started as.
     */
    public const KEY = 'statusLine';

    /**
     * The only `type` this class runs.
     *
     * Claude Code's schema has exactly one today and names it in every entry,
     * so an entry that omits it or names something else is refused rather than
     * assumed: a future `{"type":"static","text":"…"}` must not be executed as
     * a shell command by a build that predates it.
     */
    public const TYPE_COMMAND = 'command';

    /**
     * How often the tick re-runs the command, in seconds of wall clock.
     *
     * The same 2.0 {@see \SugarCraft\Crush\Chat}'s background-session poll
     * uses, and for the reason stated there: fast enough that a readout is not
     * visibly stale, slow enough that a mostly-idle TUI is not repainting on a
     * hot timer. A status line is a HUMAN-READ figure — a branch name, a
     * context percentage, a clock to the minute — so a second either way is
     * not information.
     *
     * This is the interval between the STARTS of two refreshes only when the
     * command is fast. {@see refresh()} is called from a subscription tick and
     * runs synchronously, so a command that spends its whole budget makes the
     * effective period `REFRESH_SECONDS + TIMEOUT_SECONDS`. Stated because the
     * two are not the same number and the difference is the one a user chasing
     * a laggy TUI would measure.
     */
    public const REFRESH_SECONDS = 2.0;

    /**
     * Wall-clock budget for ONE run.
     *
     * DERIVED FROM {@see REFRESH_SECONDS}, NOT CHOSEN. Half the refresh period
     * is the largest budget for which a run cannot still be in progress when
     * the next tick arrives, with the same margin again to spare — so runs can
     * never overlap or pile up, and that property survives a future change to
     * the period without anyone having to remember this constant exists.
     *
     * NOT OPERATOR-CONFIGURABLE, unlike {@see \SugarCraft\Crush\Hooks\ScriptHook::DEFAULT_TIMEOUT_SECONDS},
     * whose entries may raise it with `timeout:`. That key is a per-tool-call
     * gate a user waits on once; this runs on a TIMER, so a configurable value
     * is multiplied by every tick of the session. The doctrine followed here is
     * {@see \SugarCraft\Crush\Commands\CommandSpec::SHELL_BUDGET_SECONDS}'s —
     * "SHORT AND NOT OPERATOR-CONFIGURABLE… every second of it is a second the
     * terminal is frozen" — with the bound tightened by an order of magnitude
     * because that one is spent once per `/command` and this one is spent
     * every {@see REFRESH_SECONDS}.
     *
     * WHAT A HANG COSTS, since that is the question the number exists to
     * answer: {@see refresh()} runs on the TUI's own thread, so a command that
     * never returns freezes the frame for exactly this long, once per tick, and
     * is then SIGTERMed and SIGKILLed ({@see terminateAndEscalate()}). The
     * previous run's text keeps being painted meanwhile — a hanging command
     * blanks the segment rather than blanking the bar, see {@see refresh()}.
     */
    public const TIMEOUT_SECONDS = self::REFRESH_SECONDS / 2.0;

    /**
     * Bytes of stdout kept before the child is killed.
     *
     * 16 KiB, which is
     * {@see \SugarCraft\Crush\Commands\CommandSpec::MAX_SUBSTITUTION_BYTES} —
     * the figure this codebase already uses for "a local program's output
     * crossing a bounded seam", and the same one
     * {@see \SugarCraft\Crush\Hooks\ScriptHook::MAX_DENY_REASON_BYTES} reused
     * rather than inventing a second.
     *
     * WHY A FIXED BYTE CAP AND NOT A WIDTH-DERIVED ONE, which is the obvious
     * objection to 16 KiB for something that paints at most a terminal row: a
     * grapheme cluster has no bounded byte length (a base character plus
     * arbitrarily many combining marks is ONE column), so no number of columns
     * implies a number of bytes. The cap therefore has to be stated in bytes
     * and be generous, and the row bound is enforced afterwards by
     * {@see \SugarCraft\Crush\Renderer}'s grapheme-aware clip. What this
     * constant actually prevents is a `cat /dev/urandom` spending the process's
     * memory and the segmentation cost before that clip is ever reached.
     */
    public const MAX_OUTPUT_BYTES = 16384;

    /**
     * Longest one {@see drain()} `stream_select()` waits before the deadline is
     * re-checked — {@see \SugarCraft\Crush\Hooks\ScriptHook::DRAIN_SLICE_SECONDS}'
     * 200ms, for its reason: nothing wakes a select when the only thing that
     * has changed is the clock, so a command that writes nothing at all has to
     * expire on time rather than on its first byte.
     */
    private const DRAIN_SLICE_SECONDS = 0.2;

    /**
     * Consecutive `stream_select()` failures tolerated before the drain gives
     * up — {@see \SugarCraft\Crush\Hooks\ScriptHook::DRAIN_SELECT_RETRIES}'
     * figure and reason. PHP's select is not installed with `SA_RESTART`, and
     * `Program` runs with `pcntl_async_signals()` on and a SIGWINCH handler
     * installed, so a terminal resize during a run returns false with EINTR
     * and must be retried rather than read as end-of-output.
     */
    private const DRAIN_SELECT_RETRIES = 128;

    /** {@see \SugarCraft\Crush\Hooks\ScriptHook::EXIT_POLL_MICROSECONDS}. */
    private const EXIT_POLL_MICROSECONDS = 10_000;

    /** {@see \SugarCraft\Crush\Hooks\ScriptHook::TERMINATE_GRACE_SECONDS}. */
    private const TERMINATE_GRACE_SECONDS = 0.5;

    /** {@see \SugarCraft\Crush\Hooks\ScriptHook::KILL_GRACE_SECONDS}. */
    private const KILL_GRACE_SECONDS = 0.5;

    /**
     * The configured command for this process, or null when the merged
     * settings named none. Null is the overwhelmingly common case and is what
     * keeps {@see \SugarCraft\Crush\Chat::subscriptions()} from arming a timer
     * nobody asked for.
     */
    private static ?self $active = null;

    /**
     * Working directory every run is started in, or null to inherit this
     * process's. Held beside {@see $active} rather than on the instance so
     * {@see run()} stays a pure function of the command plus its argument.
     */
    private static ?string $cwd = null;

    /**
     * The last run's sanitised, single-line output. Painted as-is; '' means no
     * segment at all.
     */
    private static string $line = '';

    /**
     * `microtime(true)` of the last completed run, or 0.0 when none has run
     * since the last {@see configure()}/{@see reset()}.
     */
    private static float $refreshedAt = 0.0;

    private function __construct(
        /**
         * The shell command, exactly as the settings file spelled it. Passed to
         * `proc_open()` as a STRING, so `/bin/sh -c` is the direct child — the
         * same contract {@see \SugarCraft\Crush\Hooks\ScriptHook} documents,
         * including its consequence: a command that backgrounds something
         * leaves that something orphaned when the run is killed.
         */
        public readonly string $command,
    ) {
    }

    /**
     * The `statusLine` entry in a merged settings array, or null when there is
     * none this class will run.
     *
     * Null — never a throw — for every malformed shape, matching
     * {@see LayeredSettings::readFile()}'s contract that this whole stack is
     * TOLERANT: it is read on a path with no channel to report through, and a
     * settings file that names a `statusLine` wrongly must cost the status line
     * and nothing else.
     *
     * @param array<string, mixed> $config the output of
     *        {@see \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()}
     */
    public static function fromSettings(array $config): ?self
    {
        $entry = $config[self::KEY] ?? null;
        if (!\is_array($entry)) {
            return null;
        }

        if (($entry['type'] ?? null) !== self::TYPE_COMMAND) {
            return null;
        }

        $command = $entry['command'] ?? null;
        if (!\is_string($command) || trim($command) === '') {
            return null;
        }

        return new self($command);
    }

    /**
     * Install (or clear) the process's status-line command and forget whatever
     * the previous one had produced.
     *
     * The cache is dropped rather than kept because the text belongs to the
     * command that produced it: carrying a previous command's branch name into
     * a session configured with a different command would paint a figure whose
     * domain is a settings file that is no longer in force.
     *
     * @param array<string, mixed> $config the merged settings
     * @param string|null $cwd the project root, so a `git` in the command means
     *        the repository the session was launched against and not whatever
     *        directory the binary happened to be started from
     */
    public static function configure(array $config, ?string $cwd = null): void
    {
        self::$active = self::fromSettings($config);
        self::$cwd = $cwd;
        self::$line = '';
        self::$refreshedAt = 0.0;
    }

    /**
     * Put the process back to "no status line configured".
     *
     * Exists for tests, and named for what it does rather than for who calls
     * it: {@see configure()} with an empty array is the same thing, but a test
     * that means "undo" should not have to spell it as a configuration.
     */
    public static function reset(): void
    {
        self::configure([]);
    }

    /** The configured command, or null. */
    public static function active(): ?self
    {
        return self::$active;
    }

    /**
     * The last run's text — a pure read, safe to call from `view()`.
     *
     * '' when nothing is configured, when nothing has run yet, or when the last
     * run produced nothing usable.
     */
    public static function line(): string
    {
        return self::$line;
    }

    /**
     * Run the configured command if {@see REFRESH_SECONDS} have passed since
     * the last run, and cache what it said.
     *
     * THE ONLY SIDE-EFFECTING ENTRY POINT. Called from
     * {@see \SugarCraft\Crush\Chat::update()} on a
     * {@see \SugarCraft\Crush\StatusLineTickMsg}, i.e. on the update path,
     * because `Model::view()` may not have side effects and a `proc_open()` per
     * frame would be one per keystroke besides.
     *
     * A run that produces nothing usable — no output, a hang, a non-zero exit —
     * BLANKS the segment rather than leaving the previous text up. The
     * alternative was considered and rejected: a status line whose whole
     * purpose is to report current state must not keep asserting a value the
     * command has stopped standing behind, and "the branch name froze twenty
     * minutes ago" is indistinguishable from "the branch has not changed".
     *
     * The TTL is re-armed from the moment the run ENDS, not the moment it
     * started, so a command that takes its whole budget is not immediately
     * re-run.
     */
    public static function refresh(): void
    {
        $command = self::$active;
        if ($command === null) {
            return;
        }

        $now = microtime(true);
        if (self::$refreshedAt > 0.0 && ($now - self::$refreshedAt) < self::REFRESH_SECONDS) {
            return;
        }

        self::$line = $command->run(self::$cwd);
        self::$refreshedAt = microtime(true);
    }

    /**
     * Run this command once and return its stdout as ONE sanitised line, or ''.
     *
     * No process state is touched, so this is the surface every property of the
     * runner is pinned through.
     *
     * THE ENVIRONMENT IS INHERITED (`null`), unlike
     * {@see \SugarCraft\Crush\Hooks\ScriptHook}, which hands its child a fixed
     * map. A hook is given exactly the four `CRUSH_*` variables it is
     * documented to receive; a status command is a one-liner a user wrote for
     * their own shell and needs `PATH` to find `git` at all. There is no
     * payload to stage, so none of ScriptHook's `MAX_ENV_ENTRY_BYTES` /
     * `proc_open()`-refused machinery applies here.
     *
     * STDIN IS CLOSED IMMEDIATELY. A command that reads stdin would otherwise
     * block until the timeout killed it, once per tick, which is the same
     * freeze the timeout exists to bound and is avoidable outright.
     */
    public function run(?string $cwd = null): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // `@` for ScriptHook's reason: a refused exec emits a warning that
        // lands mid-frame over whatever candy-core last painted, and under
        // `failOnWarning="true"` turns this suite's coverage of the refusal
        // path into a failure. A refusal is '' — the segment simply does not
        // appear — because there is no channel to report through from a
        // subscription tick and no reader who could act on it mid-session.
        $process = @proc_open($this->command, $descriptors, $pipes, $cwd, null);
        if (!\is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);

        // ONE DEADLINE FOR THE WHOLE RUN, armed before the drain and still in
        // force after it — ScriptHook's doctrine, and for the measurement it
        // records: the wait is in two halves (the drain, then `proc_close()`),
        // and bounding either one alone bounds nothing. A command that
        // redirects its own output closes both pipes at once, so the drain
        // returns immediately and every remaining second is spent inside
        // `proc_close()`.
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        [$output, $timedOut] = self::drain($pipes[1], $pipes[2], $deadline);

        if (!$timedOut) {
            $timedOut = !self::waitForExit($process, $deadline);
        }

        if ($timedOut) {
            // Kill BEFORE closing the pipes: closing this end only gives the
            // child EPIPE the next time it writes, and a command that is stuck
            // is by definition not writing.
            self::terminateAndEscalate($process);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // A NON-ZERO EXIT PAINTS NOTHING, and neither does a timeout. The
        // divergence from ScriptHook is deliberate and is about where the
        // bytes go: a hook's stderr becomes a DENY REASON the model reads, so
        // it has to survive; this is chrome. `git rev-parse` outside a
        // repository writes a usage message to stderr and exits 128, and
        // painting that into the status bar of every non-repository session
        // would be a permanent error message where a user asked for a branch
        // name. stderr is therefore drained (so it cannot wedge the stdout
        // pipe — see {@see drain()}) and discarded.
        if ($timedOut || $exitCode !== 0) {
            return '';
        }

        return self::oneLine($output);
    }

    /**
     * Foreign bytes reduced to something that can be painted inside trusted
     * chrome, on ONE row.
     *
     * Two strippings, and only the first is the obvious one:
     *
     *  - {@see Sanitize::untrusted()} removes every ANSI escape and the C0/C1
     *    control ranges. Without it a status command emitting raw SGR repaints
     *    the frame around it: the bar is the frame's LAST line, so an unclosed
     *    colour or a bare `\e[2J` is not a cosmetic problem.
     *  - WHITESPACE IS THEN COLLAPSED, because `Sanitize::untrusted()`
     *    DELIBERATELY PRESERVES TAB, LF and CR (see its own docblock, which
     *    lists them as preserved). A single LF in this string makes the bar two
     *    physical rows, and the bar is the one line {@see \SugarCraft\Crush\Renderer::renderStatusBar()}
     *    documents as unable to wrap — a wrapped bar makes the frame rows+1
     *    tall, which is exactly the absolute-`cursorTo` row collision the tail
     *    clip exists to prevent. A CR is worse than an LF: it returns the
     *    cursor to column 0 and repaints the row from the start, so
     *    `printf 'ok\rrm -rf /'` would show only the second half.
     *
     * COLLAPSED RATHER THAN CUT AT THE FIRST NEWLINE, which is the other
     * defensible reading and is what Claude Code does. Both produce one row;
     * the difference is what happens to line two. Cutting hides it, and a
     * mechanism that silently discards part of a command's output is the shape
     * a forgery hides behind — the round-35 report-line forgery worked because
     * a newline meant something structural. Collapsing means every byte the
     * command emitted is either on the row or visibly past the clip's ellipsis,
     * and a two-line script reads as obviously two-lines-jammed-together rather
     * than as a working one-liner.
     *
     * WHAT THIS DOES NOT DEFEND AGAINST, stated rather than papered over: the
     * bar's own segments are joined with ` · `, so a command emitting
     * `x · 100%` contributes something that reads as two segments. There is no
     * separator this class could reserve that a command could not also emit.
     * What bounds it is the TIER — see the class docblock: only the user's own
     * files can set this key, so the text is the user's, and the row it forges
     * is a row in the user's own status bar. Zone sentinels, which a project
     * CAN influence indirectly through model output elsewhere, are a different
     * matter and are stripped by {@see \SugarCraft\Crush\Renderer::untrusted()}
     * at the paint.
     */
    private static function oneLine(string $output): string
    {
        $clean = Sanitize::untrusted($output);

        // \s with /u also folds NBSP and the Unicode line separators, which are
        // not control characters and so survive the sweep above.
        $collapsed = preg_replace('/\s+/u', ' ', $clean);

        return trim($collapsed ?? $clean);
    }

    /**
     * Read both pipes without letting either wedge the other, bounded by
     * $deadline and capped at {@see MAX_OUTPUT_BYTES} of stdout.
     *
     * {@see \SugarCraft\Crush\Hooks\ScriptHook::drain()}'s method, kept
     * property for property — `stream_select()` over both pipes so a chatty
     * stderr cannot deadlock a parent blocked on stdout, non-blocking pipes so
     * a partial-buffer wake cannot block inside the `fread()`, a retried
     * `false` so an EINTR from SIGWINCH is not read as end-of-output, and the
     * slice so the deadline is reachable while the command is silent.
     *
     * ONE DEPARTURE: stderr is read and DISCARDED rather than returned. It is
     * drained because not draining it is the deadlock; it is discarded because
     * {@see run()} has no use for it (a failing command paints nothing at all,
     * see there). The second element is the timed-out flag rather than the
     * stderr buffer, because {@see run()} has to KILL the child on that path
     * and cannot infer it from what it read.
     *
     * THE CAP ENDS THE DRAIN. Once stdout has {@see MAX_OUTPUT_BYTES}, there is
     * nothing more a one-row readout can use, so the loop stops and the caller
     * kills the child rather than reading a runaway to EOF on the TUI's thread.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @return array{0: string, 1: bool} stdout (capped), timed-out-or-capped
     */
    private static function drain($stdout, $stderr, float $deadline): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $buffer = '';
        $open = [0 => $stdout, 1 => $stderr];
        $failures = 0;

        while ($open !== []) {
            if (\strlen($buffer) >= self::MAX_OUTPUT_BYTES) {
                // Reported as "timed out" so run() takes the kill path: the
                // child is still writing into a pipe nobody will read again,
                // and leaving it to proc_close() is the unbounded wait this
                // whole method exists to remove.
                return [substr($buffer, 0, self::MAX_OUTPUT_BYTES), true];
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return [substr($buffer, 0, self::MAX_OUTPUT_BYTES), true];
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            $slice = min($remaining, self::DRAIN_SLICE_SECONDS);
            $seconds = (int) $slice;
            $micros = (int) round(($slice - $seconds) * 1_000_000);

            if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                if (++$failures > self::DRAIN_SELECT_RETRIES) {
                    // Not a signal, then: something is wrong with the
                    // descriptors themselves, and retrying forever would wedge
                    // the CLI harder than the deadlock this method replaced.
                    break;
                }

                continue;
            }

            $failures = 0;

            foreach ($open as $slot => $pipe) {
                if (!\in_array($pipe, $read, true)) {
                    continue;
                }

                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    // feof() is the EOF test, not the empty read: a
                    // non-blocking pipe legitimately returns '' when the child
                    // has written nothing YET.
                    if (feof($pipe)) {
                        unset($open[$slot]);
                    }

                    continue;
                }

                // Slot 1 is stderr, drained to keep the child unblocked and
                // then dropped on the floor. See the docblock.
                if ($slot === 0) {
                    $buffer .= $chunk;
                }
            }
        }

        return [substr($buffer, 0, self::MAX_OUTPUT_BYTES), false];
    }

    /**
     * Poll `proc_get_status()` until the child is gone or $deadline passes;
     * true if it exited.
     *
     * {@see \SugarCraft\Crush\Hooks\ScriptHook::waitForExit()}, unchanged,
     * including the ordering note: the deadline is tested AFTER the status, so
     * an already-exited child is reported as exited even when the budget is
     * spent — which is the ordinary case on the drain-finished path.
     *
     * @param resource $process
     */
    private static function waitForExit($process, float $deadline): bool
    {
        while (true) {
            if ((proc_get_status($process)['running'] ?? false) !== true) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }

    /**
     * SIGTERM the child, wait a bounded moment, then signal 9 — so a command
     * that traps TERM cannot turn the expiry path back into an unbounded wait.
     *
     * Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant, which comes
     * from ext-pcntl: this path must not itself fatal on a build without it.
     * The same escalation and the same literal as
     * {@see \SugarCraft\Crush\Hooks\ScriptHook::terminateAndEscalate()}.
     *
     * @param resource $process
     */
    private static function terminateAndEscalate($process): void
    {
        proc_terminate($process);

        if (self::waitForExit($process, microtime(true) + self::TERMINATE_GRACE_SECONDS)) {
            return;
        }

        proc_terminate($process, 9);
        self::waitForExit($process, microtime(true) + self::KILL_GRACE_SECONDS);
    }
}
