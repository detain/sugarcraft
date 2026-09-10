<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

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
 * {@see NONINTERACTIVE_ENV} carries the fail-fast half the detach cannot
 * reach — prompts that consult the environment before touching a device.
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
 * LAYER C SEAM, RECORDED NOT BUILT: the settled design gives the PTY-backed
 * interactive mode as an optional `interactive` PARAMETER on Bash (never a
 * second tool, which would split every permission/hook rule across two
 * names). The parameter is deliberately absent until the mechanism exists —
 * a schema flag with no effect is a lie the model pays for — and
 * InteractivePromptContainmentTest pins its absence so the arrival is a
 * chosen change, not drift.
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
     * Fail-fast environment forced on every tool child. These are not
     * askpass (the askpass route was rejected as a credential-entry surface
     * driven by model output) — they are how a command refuses LOUDLY on the
     * captured stderr instead of hanging on an invisible prompt.
     *
     * GIT_TERMINAL_PROMPT=0: git's own switch for refusing terminal prompts.
     * GIT_ASKPASS=/bin/false: the plan left this value to the implementer
     * ("=/bin/false or empty"). An executed /bin/false fails every credential
     * prompt as a refusal git explains on stderr; an EMPTY value would point
     * git at the default askpass path and, where one exists, feed it an
     * empty secret — a wrong-credential ATTEMPT where a refusal was asked
     * for. SSH_ASKPASS=echo (the brief's value, not /bin/false, because ssh
     * 3-times a succeeding askprogram before dying "Permission denied" and
     * an EMPTY reply is the legible outcome there) is the same trade seen
     * from the other side: with no controlling terminal, OpenSSH ≥ 8.4 uses
     * the askprogram at all, so pinning it pins the refusal path.
     * DEBIAN_FRONTEND=noninteractive: apt's config/overwrite prompts.
     * PAGER / GIT_PAGER: kill the pager class of hang.
     * SYSTEMD_PAGER=/bin/cat — WHAT THE PLAN SAID: `SYSTEMD_PAGER=` (empty,
     * systemd's documented disable). WHAT IS TRUE NOW: PHP's proc_open env
     * array DROPS empty-string values (measured: `${VAR+x}` reads unset in
     * the child, and an inherited value is shadowed away), so the empty
     * spelling cannot reach a child at all — and a bare UNSET is worse than
     * the hang it prevents, because systemctl then falls back to its
     * built-in `less` default. WHY IT EARNS ITS PLACE: /bin/cat is a pager
     * that streams and exits, and unlike empty it also defeats a
     * user-exported SYSTEMD_PAGER=less rather than reviving it.
     *
     * No LC_ALL here: the phase text lists it only as "where the plan says",
     * and the plan says it nowhere. Output locale is the caller's business.
     */
    private const NONINTERACTIVE_ENV = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '/bin/false',
        'SSH_ASKPASS' => 'echo',
        'DEBIAN_FRONTEND' => 'noninteractive',
        'PAGER' => 'cat',
        'GIT_PAGER' => 'cat',
        'SYSTEMD_PAGER' => '/bin/cat',
    ];

    /**
     * Inherited names REMOVED from the child environment. SUDO_ASKPASS per
     * the plan's option (A) ("unset + sudo -n"): a user-wide askpass helper
     * must not be handed to a child that has no terminal to refuse it on.
     * GPG_TTY is deliberately NOT stripped — the plan does not name it; it
     * is carried as a lane finding because a stale GPG_TTY path can still be
     * opened by name from outside a session.
     */
    private const STRIPPED_ENV_NAMES = ['SUDO_ASKPASS'];

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

        // Phase 9 layer A: with a usable setsid the child runs in a NEW
        // SESSION — no controlling terminal — so every /dev/tty channel
        // (sudo, ssh, credential prompts, pagers) fails at open and reports
        // on the captured stderr. The array form names /bin/sh explicitly
        // because that is exactly the shell PHP's string form runs, and
        // `-w` keeps proc_close() reporting the COMMAND's status, not the
        // wrapper's. ONE proc_open call, with the spec chosen above, not a
        // ternary of two: DescriptorInheritanceGuardTest licenses spawn
        // sites by name, and a doubly-anonymous result would read as two
        // unclassifiable exposed children where one known site lives. The
        // env block rides both paths: detach is the half that needs a
        // binary, fail-fast env needs none.
        $setsid = self::detachedSpawnBinary();
        $spawnSpec = $setsid === ''
            ? $command
            : [$setsid, '-w', '--', '/bin/sh', '-c', $command];
        $env = self::containmentEnv();
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
     * Path of a `setsid(1)` proven to forward its child's exit status
     * through `-w`, or '' when this host offers no usable detach.
     *
     * The probe RUNS the claim it makes — `setsid -w -- /bin/sh -c 'exit 7'`
     * must come back 7 — because the two real failure modes are silent:
     * a binary predating `-w` (or a busybox lacking it) execs, the wrapper
     * exits 0 immediately, and every tool result on the box reports success
     * for commands that failed. Trading a hang for a lie is not containment.
     *
     * Located by a stat walk, never by spawning a probe of `setsid` itself
     * when it may be missing: PHP warns when proc_open targets an absent
     * binary and phpunit.xml sets failOnWarning — the same hazard
     * DetectsCapabilities documents for `rg --version`. The walk is that
     * primitive duplicated ON PURPOSE: a trait cannot call a sibling
     * trait's method (the host class is under no obligation to compose
     * both), and a dozen stat calls are cheaper than that coupling.
     *
     * Memoized in FUNCTION-LEVEL statics, not trait properties: the hosts
     * are `final readonly class`es and PHP refuses a readonly class that
     * composes a trait declaring any property — a static one included, the
     * exact trap DetectsCapabilities' doc-block warns about (which is why
     * its own memo lives on the boot factory, a class it can use at all).
     * Method statics are runtime scope storage, not property declarations,
     * so they survive that rule. The cost of the trade is honest: the probe
     * runs once per HOST CLASS (Bash, Grep, EnvironmentBlock), ~5 ms each,
     * against zero cost on an unshareable global — containment pays.
     */
    private static function detachedSpawnBinary(): string
    {
        static $probed = false;
        static $resolved = '';

        if ($probed) {
            return $resolved;
        }
        $probed = true;

        if (PHP_OS_FAMILY === 'Windows') {
            return $resolved = '';
        }

        $path = self::locateOnPath('setsid');
        if ($path === '') {
            return $resolved = '';
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $probe = @proc_open([$path, '-w', '--', '/bin/sh', '-c', 'exit 7'], $descriptors, $pipes);
        if (!is_resource($probe)) {
            return $resolved = '';
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return $resolved = proc_close($probe) === 7 ? $path : '';
    }

    /**
     * First executable named $binary on the process PATH, or '' when absent.
     *
     * An empty PATH element is skipped, not treated as '.': answering a
     * containment question from whatever the working directory happens to be
     * is the nondeterminism DetectsCapabilities exists to avoid, same rule.
     */
    private static function locateOnPath(string $binary): string
    {
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * The inherited environment with {@see NONINTERACTIVE_ENV} forced over
     * it and {@see STRIPPED_ENV_NAMES} removed.
     *
     * Built from a getenv() COPY, not a replacement: proc_open's $env is the
     * child's WHOLE environment, so a hand-rolled array would strip PATH
     * (every `bash -c` lookup dies) and HOME (git refuses to find its
     * config). Rebuilt per call, never cached: putenv() in this process —
     * tests and supervisors do it — must reach the next child, not be
     * frozen out by a stale snapshot.
     */
    private static function containmentEnv(): array
    {
        $env = getenv();
        foreach (self::STRIPPED_ENV_NAMES as $name) {
            unset($env[$name]);
        }

        return array_merge($env, self::NONINTERACTIVE_ENV);
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
