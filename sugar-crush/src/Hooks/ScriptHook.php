<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * A hook that executes an external script.
 *
 * EXIT-CODE CONTRACT (crush_code.md Phase 1 item 2). Until this, a script hook
 * could only ever say allow (0) or deny (anything else), which left two of
 * {@see HookResult}'s four actions — ASK and MODIFY — reachable only by
 * hand-writing a PHP {@see HookInterface} class. ASK in particular is what
 * drives the blocking permission prompt {@see \SugarCraft\Crush\Chat} and
 * {@see \SugarCraft\Crush\Renderer} already implement, so the entire prompt UI
 * was unreachable from configuration.
 *
 *   0  ALLOW   — stdout becomes the result message.
 *   1  DENY    — non-blocking deny. See the note below.
 *   2  DENY    — hard block.
 *   3  ASK     — stdout is the question put to the user.
 *   4  MODIFY  — stdout is a JSON object replacing the tool's arguments.
 *   *  DENY    — any other non-zero exit, as before.
 *
 * 0/1/2 are NOT this class's invention and were NOT free to renumber: they are
 * already the documented, tested contract of {@see HookDispatcher} and
 * {@see HookDispatchResult} (0 allow / 1 non-blocking deny / 2 hard block),
 * which is why ASK and MODIFY got 3 and 4 rather than crush_code.md's
 * suggested 2 and 3 — 2 was taken, and quietly redefining it would have turned
 * every existing "exit 2 to block" hook into a prompt.
 *
 * Exit 1 currently produces a plain DENY, identical to exit 2, rather than the
 * `[exit-1]` message prefix {@see HookDispatcher::determineExitCode()} looks
 * for. That prefix is only stripped again on the dispatcher path; the two live
 * gates ({@see \SugarCraft\Crush\Runtime::gate()} and
 * {@see \SugarCraft\Crush\Chat::gateToolCall()}) quote the message verbatim
 * into the model's tool result, so emitting it would leak a marker into the
 * transcript. Distinguishing the two is a change to those gates, not to this
 * class.
 *
 * A MODIFY whose stdout is not a JSON object is downgraded to a DENY rather
 * than to an ALLOW: a hook that meant to rewrite dangerous arguments and
 * failed must not have the ORIGINAL arguments run in its place.
 */
final readonly class ScriptHook implements HookInterface
{
    /** Action proceeds; stdout is the message. */
    public const EXIT_ALLOW = 0;

    /**
     * Non-blocking deny in {@see HookDispatcher}'s vocabulary. Blocks like
     * {@see self::EXIT_BLOCK} on the live gates — see the class docblock.
     */
    public const EXIT_DENY = 1;

    /** Hard block. */
    public const EXIT_BLOCK = 2;

    /** Defer to the user; stdout is the prompt text. */
    public const EXIT_ASK = 3;

    /** Rewrite the tool arguments; stdout is a JSON object. */
    public const EXIT_MODIFY = 4;

    /**
     * How many CONSECUTIVE `stream_select()` failures {@see drain()} rides out
     * before it gives up on a pipe.
     *
     * A signal is the expected cause and it is not an error, so the budget has
     * to be generous; a genuinely broken descriptor fails instantly and
     * forever, so it has to be finite. Any successful select resets it, which
     * means 128 is 128 signals with no readable byte in between rather than
     * 128 signals per hook.
     */
    private const DRAIN_SELECT_RETRIES = 128;

    /**
     * Wall-clock budget for ONE hook run when the entry names no `timeout:`.
     *
     * A HOOK RUNS ON THE TUI'S OWN THREAD. Both live gates —
     * {@see \SugarCraft\Crush\Chat::gateToolCall()} and
     * {@see \SugarCraft\Crush\Runtime::gate()} — reach this class
     * synchronously through {@see HookManager::preToolUse()} before any tool
     * call runs. (NOT through {@see HookDispatcher}, which nothing in this
     * package constructs.) So a hook that never finishes is not a slow hook —
     * it is a frozen CLI, with no spinner, no Escape and no recovery. Until this there was no bound of any kind: the drain's
     * `stream_select()` was passed a NULL timeout, and `proc_close()` below it
     * WAITS, so both halves were unbounded independently. MEASURED at
     * 4a4ecb98, each under a 5-second external clock and each returning
     * exit 124: `sleep 30` (the drain never wakes) and
     * `printf hi; exec 1>&- 2>&-; sleep 30` (the drain finishes at EOF on the
     * first iteration and `proc_close()` holds the CLI for the sleep).
     *
     * 60 seconds rather than the 10 of
     * {@see \SugarCraft\Crush\Commands\CommandSpec::SHELL_BUDGET_SECONDS}:
     * a command file's shell substitution is prompt decoration, while a hook
     * legitimately shells out to a linter or a policy checker over a whole
     * repo. It is a DEFAULT and not a cap — an entry that needs longer says so
     * with `timeout:`, which is the reason the value is configurable at all
     * rather than being a private constant.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 60.0;

    /**
     * Longest one {@see drain()} `stream_select()` waits before the deadline is
     * re-checked.
     *
     * The wait used to be NULL, i.e. zero wakeups for the whole of a hook that
     * is thinking, and this trades that for five wakeups a second — the same
     * trade {@see \SugarCraft\Crush\Commands\CommandSpec::runShellSubstitution()}
     * makes at the same 200ms, and for the same reason: a hook that produces no
     * output at all has to expire ON TIME rather than on its first byte, and
     * nothing wakes the select when the only thing that changed is the clock.
     */
    private const DRAIN_SLICE_SECONDS = 0.2;

    /** Grace given to SIGTERM before {@see terminateAndEscalate()} sends signal 9. */
    private const TERMINATE_GRACE_SECONDS = 0.5;

    /** Grace given to signal 9 before the handle is closed regardless. */
    private const KILL_GRACE_SECONDS = 0.5;

    /**
     * How often {@see waitForExit()} asks whether the child is gone. Ten
     * milliseconds is short enough that the usual case (already exited) costs
     * one poll and long enough that a full sixty-second budget is at most six
     * thousand `proc_get_status()` calls.
     */
    private const EXIT_POLL_MICROSECONDS = 10_000;

    /**
     * $timeoutSeconds is the wall clock this hook's whole run — drain AND
     * reap — has to finish inside; see {@see DEFAULT_TIMEOUT_SECONDS}. A
     * non-positive value is NOT "no timeout": {@see execute()} reads it as
     * "unset" and applies the default, because the one thing this parameter
     * must not be able to express is the unbounded wait it was added to
     * remove.
     */
    public function __construct(
        private string $name,
        private HookEvent $event,
        private string $matcher,
        private string $command,
        private string $description,
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    /**
     * Create a ScriptHook from a config array.
     *
     * `name` is honoured when {@see HookConfig} supplied one, falling back to
     * the command as before. {@see HookRegistry} keys its hooks by name, so
     * without a way to name them two config entries running the same command
     * on the same event silently collapsed into one.
     *
     * `timeout` is read LENIENTLY here — anything that is not a positive
     * number becomes {@see DEFAULT_TIMEOUT_SECONDS} — while
     * {@see HookConfig::parse()} refuses the same value loudly. That is the
     * same division of labour the rest of this method already has (it also
     * tolerates a missing `command`, which the parser refuses): the parser is
     * where a user's file is judged, and this constructor also serves callers
     * that never went through a file. What it must not do is read `timeout: 0`
     * as "wait forever", which is why the fallback is the default rather than
     * the value.
     */
    public static function fromConfig(array $config): self
    {
        $eventString = $config['event'] ?? 'PreToolUse';
        $event = HookEvent::tryFrom($eventString) ?? HookEvent::PreToolUse;

        $name = $config['name'] ?? null;
        $timeout = $config['timeout'] ?? null;

        return new self(
            name: is_string($name) && $name !== '' ? $name : ($config['command'] ?? uniqid('hook_')),
            event: $event,
            matcher: $config['matcher'] ?? '.*',
            command: $config['command'] ?? '',
            description: $config['description'] ?? '',
            timeoutSeconds: (is_int($timeout) || is_float($timeout)) && $timeout > 0
                ? (float) $timeout
                : self::DEFAULT_TIMEOUT_SECONDS,
        );
    }

    /** The wall clock one run of this hook gets, in seconds. */
    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds > 0.0 ? $this->timeoutSeconds : self::DEFAULT_TIMEOUT_SECONDS;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function event(): HookEvent
    {
        return $this->event;
    }

    public function matcher(): string
    {
        return $this->matcher;
    }

    /**
     * Run the script and turn its exit code into a verdict — see the class
     * docblock for the full exit-code table.
     *
     * A guard that could not RUN has approved nothing, so both failure modes
     * below fail CLOSED. `proc_open()` refuses to start a process whose `cwd`
     * does not exist, and {@see HookContext::$projectRoot} can legitimately
     * carry an unusable value — a mistyped `--root` threaded through
     * {@see \SugarCraft\Crush\Runtime}, or a caller with no root of its own
     * to give. Before this, that turned a DENYING hook into an allow: the
     * one direction a security gate must never fail in.
     *
     * A HOOK THAT DOES NOT FINISH IS THE THIRD FAILURE MODE, and it fails
     * closed for the same reason as the other two: it is killed at
     * {@see timeoutSeconds()} and reported as a DENY. That is a security
     * decision and not a convenience one — an expired hook has ANSWERED
     * NOTHING, and the only two other readings are worse. "Pass through"
     * hands the tool call to the model with the gate that was written to stop
     * it silently skipped, which is exactly the invisible-missing-guard
     * failure {@see HookConfig} refuses a malformed file to avoid; "ask" would
     * put a question to the user on behalf of a hook that never said anything,
     * and on the non-interactive and background-session paths there is nobody
     * to answer it. A denied call costs the model one retry and says why. The
     * cost lands on the observability events too — a PostToolUse hook that
     * expires denies as well — and that is free: both consumers
     * ({@see \SugarCraft\Crush\Runtime::settle()} and
     * {@see \SugarCraft\Crush\Chat::applyPostToolUse()}) discard the post
     * chain's verdict, so the only visible effect there is that the tool
     * result is no longer held hostage to the hook.
     */
    public function execute(HookContext $context): HookResult
    {
        // Inherit the process directory rather than refuse to run: the hook's
        // VERDICT is what matters, and running it one directory over is a far
        // smaller loss than not running it at all.
        $cwd = is_dir($context->projectRoot) ? $context->projectRoot : null;

        $env = [
            'CRUSH_SESSION_ID' => $context->sessionId,
            'CRUSH_TOOL_NAME' => $context->toolName,
            'CRUSH_TOOL_INPUT' => $context->toolInput,
            'CRUSH_TOOL_OUTPUT' => $context->toolOutput,
            'CRUSH_MODEL' => $context->model,
            'CRUSH_PROVIDER' => $context->provider,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $cwd,
            $env
        );

        if (!is_resource($process)) {
            return HookResult::deny("Hook {$this->name} could not be executed");
        }

        fclose($pipes[0]);

        // ONE DEADLINE FOR THE WHOLE RUN, started before the drain and still
        // in force after it, because the wait is in two halves and bounding
        // either one alone bounds nothing. See {@see DEFAULT_TIMEOUT_SECONDS}
        // for the measurement of both.
        $budget = $this->timeoutSeconds();
        $deadline = microtime(true) + $budget;

        [$output, $errors, $timedOut] = $this->drain($pipes[1], $pipes[2], $deadline);

        // THE DRAIN CAN END WITH THE CHILD STILL RUNNING and `proc_close()`
        // WAITS for it. A hook that redirects its own output — `hook.sh
        // >/dev/null 2>&1`, which is what a hook whose only product is its
        // exit code is written as — closes both pipes at once, so the drain
        // sees EOF immediately and every remaining second of the hook is spent
        // inside `proc_close()`. Same shape, and the same fix, as
        // {@see \SugarCraft\Crush\Commands\CommandSpec::runShellSubstitution()}'s
        // post-read wait.
        if (!$timedOut) {
            $timedOut = !self::waitForExit($process, $deadline);
        }

        if ($timedOut) {
            // Kill BEFORE closing the pipes and before `proc_close()`: closing
            // this end only gives the child EPIPE the next time it writes, and
            // a hook that is stuck is by definition not writing.
            self::terminateAndEscalate($process);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($timedOut) {
            // The exit code from a killed child says "signalled", never what
            // the hook meant, so it is not consulted — the verdict is the
            // timeout itself. Whatever the hook managed to say before it
            // wedged is carried through, since a half-written deny reason is
            // still the most useful thing on offer.
            return HookResult::deny(rtrim(sprintf(
                'Hook %s did not finish within %s seconds and was killed; a hook that '
                . 'has not answered has not allowed anything. %s',
                $this->name,
                rtrim(rtrim(number_format($budget, 3, '.', ''), '0'), '.'),
                trim($errors) !== '' ? trim($errors) : trim($output),
            )));
        }

        $output = trim($output);
        $errors = trim($errors);

        return match ($exitCode) {
            self::EXIT_ALLOW => HookResult::allow($output),
            self::EXIT_ASK => HookResult::ask($output !== '' ? $output : $this->defaultQuestion()),
            self::EXIT_MODIFY => $this->modifyOrDeny($output),
            default => HookResult::deny($errors ?: "Hook exited with code $exitCode"),
        };
    }

    /**
     * Read both pipes to EOF without letting either one wedge the other.
     *
     * `stream_get_contents($stdout)` followed by `stream_get_contents($stderr)`
     * deadlocks the moment a hook writes more than one pipe buffer (64 KiB on
     * Linux) to stderr: the parent is blocked reading stdout, the child is
     * blocked writing a full stderr pipe nobody is draining, and neither ever
     * moves. That hangs the whole CLI on a hook that did nothing worse than be
     * chatty about why it denied something — the exact case a security gate is
     * most likely to be verbose in.
     *
     * `stream_select()` over both, reading only what is ready, has no such
     * ordering. The pipes are switched to non-blocking so a select that wakes
     * on a partial buffer cannot block inside the `fread()` that follows it.
     *
     * A `false` from the select is RETRIED, not treated as end-of-output.
     * PHP's `stream_select()` is not installed with `SA_RESTART`, so any
     * signal delivered while it is parked returns false with EINTR — and
     * {@see \SugarCraft\Core\Program} turns on `pcntl_async_signals()` and
     * installs SIGWINCH/SIGINT handlers for the whole TUI, which makes a
     * terminal RESIZE during a hook a routine occurrence. Breaking on that
     * abandoned everything the hook had not written yet: a truncated deny
     * reason, a half an `exit 3` question, and — worst — a partial `exit 4`
     * rewrite, which is invalid JSON and so becomes a DENY of a call the hook
     * meant to permit with different arguments. The verdict itself always
     * survived (it comes from `proc_close()`), but the words did not.
     *
     * WHAT THE DOCBLOCK ABOVE NEVER ADDRESSED, and what $deadline adds: none
     * of that reasoning bounds the wait. Both properties are kept — the
     * select is still over both pipes, and a `false` is still retried rather
     * than read as end-of-output — but the wait is now sliced at
     * {@see DRAIN_SLICE_SECONDS} against a caller-supplied deadline, so a hook
     * that never closes its stdout ends the drain instead of ending the
     * session. A deadline expiry is reported as the third element rather than
     * by returning short, because {@see execute()} has to KILL the child on
     * that path and cannot infer it from the buffers.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @param float $deadline `microtime(true)`-based instant past which the
     *        drain gives up on whatever the hook has not written yet
     * @return array{0: string, 1: string, 2: bool} stdout, stderr, timed-out
     */
    private function drain($stdout, $stderr, float $deadline): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $buffers = ['', ''];
        $open = [0 => $stdout, 1 => $stderr];
        $failures = 0;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                // Sweep what is already buffered on the way out, the same as
                // the give-up branch below: the pipes are non-blocking, so
                // this cannot park, and a partial deny reason beats none.
                foreach ($open as $slot => $pipe) {
                    $rest = @stream_get_contents($pipe);
                    if (is_string($rest)) {
                        $buffers[$slot] .= $rest;
                    }
                }

                return [$buffers[0], $buffers[1], true];
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            // Waits for one of the pipes to become readable — which includes
            // the readable-at-EOF a closing child produces — or for the slice
            // to run out, whichever is first. The slice is what makes the
            // deadline above reachable: nothing wakes a select when the only
            // thing that has changed is the clock.
            $slice = min($remaining, self::DRAIN_SLICE_SECONDS);
            $seconds = (int) $slice;
            $micros = (int) round(($slice - $seconds) * 1_000_000);

            if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                if (++$failures > self::DRAIN_SELECT_RETRIES) {
                    // Not a signal, then: something is wrong with the
                    // descriptors themselves and retrying forever would wedge
                    // the CLI harder than the deadlock this method replaced.
                    // Sweep whatever is already buffered before letting go —
                    // the pipes are non-blocking, so this cannot park.
                    foreach ($open as $slot => $pipe) {
                        $rest = @stream_get_contents($pipe);
                        if (is_string($rest)) {
                            $buffers[$slot] .= $rest;
                        }
                    }

                    break;
                }

                continue;
            }

            $failures = 0;

            foreach ($open as $slot => $pipe) {
                if (!in_array($pipe, $read, true)) {
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

                $buffers[$slot] .= $chunk;
            }
        }

        return [$buffers[0], $buffers[1], false];
    }

    /**
     * Poll `proc_get_status()` until the child is gone or $deadline passes;
     * true if it exited.
     *
     * The same bounded poll as
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::waitForExit()} and
     * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::waitForExit()},
     * differing only in that those two take a BUDGET while this one takes the
     * absolute deadline the whole run already has to share: an unflagged wait
     * is the thing being removed, and there is no portable way to wait for a
     * `proc_open()` child with a deadline in PHP.
     * The exit code survives the polling — PHP caches it on the first
     * `proc_get_status()` that reaps the child, so `proc_close()` still
     * returns the real status rather than -1.
     *
     * @param resource $process
     */
    private static function waitForExit($process, float $deadline): bool
    {
        while (true) {
            if ((proc_get_status($process)['running'] ?? false) !== true) {
                return true;
            }

            // Tested AFTER the status, so an already-exited child is reported
            // as exited even when the caller's budget is already spent — which
            // is the ordinary case on the drain-finished path, where the
            // deadline may well have nothing left on it.
            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }

    /**
     * SIGTERM the child, wait a bounded moment, then signal 9 — so a hook that
     * traps TERM cannot turn the expiry path back into the unbounded wait it
     * exists to end.
     *
     * Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant: that
     * constant comes from ext-pcntl, and this is a path that must not itself
     * fatal on a build without it. Same escalation, and the same literal, as
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::stop()} and
     * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::terminateAndReap()}.
     *
     * This signals only the DIRECT child, which for a string command is the
     * shell: a hook that backgrounds something leaves that something orphaned.
     * What is guaranteed here is that `execute()` returns, not that the hook's
     * whole process tree is gone.
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
        // Unchecked on purpose: after signal 9 the only way to still be running
        // is an uninterruptible kernel wait, and `proc_close()` is then the
        // least-bad option left.
        self::waitForExit($process, microtime(true) + self::KILL_GRACE_SECONDS);
    }

    /**
     * Turn an EXIT_MODIFY script's stdout into a MODIFY, or refuse.
     *
     * Fails to DENY, never to ALLOW: a rewrite hook that produced garbage was
     * trying to change the arguments, and running the untouched originals in
     * its place is the one outcome it definitely did not ask for. A JSON list
     * or scalar is rejected for the same reason a syntax error is — the two
     * consumers of `modifiedInput` ({@see \SugarCraft\Crush\Runtime::gate()}
     * and {@see \SugarCraft\Crush\Chat::gateToolCall()}) both expect a
     * name => value argument map and silently fall back to the originals
     * otherwise, which would make a failed rewrite look like a successful one.
     *
     * The check is on the JSON TEXT, not on `array_is_list()` of the decoded
     * value, because PHP's decoder throws away exactly the distinction being
     * tested: `[]` and `{}` both decode to `[]`, and `{"0":"a","1":"b"}`
     * decodes to something `array_is_list()` calls a list. Reading the opening
     * brace gets all four right — `{}` is an explicit "run this tool with no
     * arguments", which is a rewrite the hook deliberately asked for, while
     * `[]` is the positional array the consumers cannot use.
     *
     * The `ltrim()` below is redundant against today's only caller, which has
     * already `trim()`ed the output — kept so the brace test is a property of
     * THIS method rather than of what happens to be upstream of it, since a
     * caller that ever forgets the trim would otherwise turn every rewrite
     * whose JSON is indented into a deny.
     */
    private function modifyOrDeny(string $output): HookResult
    {
        $decoded = json_decode($output, true);

        if (!is_array($decoded) || !str_starts_with(ltrim($output), '{')) {
            return HookResult::deny(
                "Hook {$this->name} asked to rewrite the tool input but did not print a JSON object",
            );
        }

        return HookResult::modify($output);
    }

    /**
     * The question asked when an EXIT_ASK script printed nothing. A prompt
     * with an empty body is unanswerable, and falling back to allow/deny would
     * make silence mean something the hook never said.
     */
    private function defaultQuestion(): string
    {
        return $this->description !== ''
            ? $this->description
            : "Hook {$this->name} requires your approval";
    }
}
