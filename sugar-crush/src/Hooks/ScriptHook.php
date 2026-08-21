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
 *   0  ALLOW   — stdout becomes the result message. ON THIS CLASS ONLY; see
 *                below.
 *   1  DENY    — non-blocking deny. See the note below.
 *   2  DENY    — hard block.
 *   3  ASK     — stdout is the question put to the user, clipped at
 *                {@see MAX_ASK_PROMPT_BYTES}.
 *   4  MODIFY  — stdout is a JSON object replacing the tool's arguments, and it
 *                is REFUSED rather than clipped above its ceiling
 *                ({@see modifyOrDeny()}).
 *   *  DENY    — any other non-zero exit, as before, clipped at
 *                {@see MAX_DENY_REASON_BYTES}.
 *
 * "STDOUT BECOMES THE RESULT MESSAGE" IS TRUE OF THIS CLASS AND FALSE OF THE
 * LIVE PATH, and the difference matters because a reader who bounds the hook
 * output problem from this table bounds the wrong thing. An ALLOW's message
 * reaches nobody in a shipped run: {@see HookRegistry::executeHooks()} ends
 * `return $modified ?? $inertRewrite ?? HookResult::allow();`, which rebuilds a
 * permitting verdict with an EMPTY message, and both live gates
 * ({@see \SugarCraft\Crush\Runtime::gate()} and
 * {@see \SugarCraft\Crush\Chat::gateToolCall()}) interpolate
 * `$hookResult->message` only into `"Hook denied: …"`. MEASURED at afe3c26b:
 * a hook printing 200,000 bytes and exiting 0 produced a message of 0 bytes at
 * `HookManager::preToolUse()`. It survives only through {@see HookDispatcher},
 * which nothing in `src/` constructs.
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
final readonly class ScriptHook implements BoundedHookInterface
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
     * 60 seconds, because a hook legitimately shells out to a linter or a
     * policy checker over a whole repo. It is a DEFAULT and not a cap — an
     * entry that needs longer says so with `timeout:`, which is the reason the
     * value is configurable at all rather than being a private constant.
     *
     * THIS IS A PER-HOOK FIGURE AND IT IS NOT ON ITS OWN THE FREEZE BOUND.
     * The docblock used to reach for
     * {@see \SugarCraft\Crush\Commands\CommandSpec::SHELL_BUDGET_SECONDS} as
     * its benchmark ("60 rather than the 10 of…"), and that comparison was
     * category-wrong in the direction that flatters this number: those 10
     * seconds are a WHOLE-OPERATION budget — `expandTemplate()` holds them for
     * the entire expansion and charges each `` !`…` `` the wall time it took —
     * while 60 here is spent by ONE hook. {@see HookRegistry::executeHooks()}
     * runs every matching hook in the chain and re-scans it up to
     * {@see HookRegistry::MAX_REWRITE_PASSES} times, so the CLI freeze this
     * constant is written to bound was really hooks x passes x 60.
     *
     * That is now fixed where it belongs rather than argued away here:
     * `executeHooks()` holds a WHOLE-CHAIN deadline of its own — the sum of the
     * matching bounded hooks' declared timeouts, armed once and shared across
     * every re-scan — and charges each hook against it through
     * {@see BoundedHookInterface::withTimeoutSeconds()}. So the bound a user can
     * actually predict is "the total of what my entries asked for, once",
     * whatever the chain does about rewrites.
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

    /**
     * Bytes of the hook's own words a DENY may carry back.
     *
     * A DENY REASON GOES STRAIGHT INTO THE MODEL'S CONTEXT. Both live gates
     * ({@see \SugarCraft\Crush\Chat::gateToolCall()} and
     * {@see \SugarCraft\Crush\Runtime::gate()}) quote it verbatim into the tool
     * result, so it is prompt text paid for per token — and it is written by
     * the process this class has just decided it cannot trust to finish.
     * MEASURED at fc597e81: a hook that writes 200 KB to stderr and then wedges
     * produced a 200 KB deny message. The expiry path is the one where a
     * runaway hook is EXPECTED to be verbose, which is why it is the path that
     * found this, but the cap is applied to every deny for the plain reason
     * that `exit 2` with 200 KB of stderr costs exactly the same.
     *
     * 16 KiB, the figure
     * {@see \SugarCraft\Crush\Commands\CommandSpec::MAX_SUBSTITUTION_BYTES}
     * already uses for the closest thing in the codebase — the bounded seam a
     * local program's output crosses on its way into a prompt — rather than a
     * number invented here. Like that one, the clip announces itself, so a
     * truncated reason reads as truncated to the model instead of as a hook
     * that stopped talking mid-sentence.
     *
     * THE OTHER THREE EXITS ARE NOT GOVERNED BY THIS CONSTANT, and the
     * paragraph that used to stand here said they were governed by nothing —
     * which was wrong about two of them and wrong about the third for a reason
     * it never gave. Measured, and each now handled where its own failure mode
     * is:
     *
     * - `EXIT_ASK` was UNBOUNDED and is now clipped at its own
     *   {@see MAX_ASK_PROMPT_BYTES}. The old text declined to clip "a question
     *   put to a human"; what it did not weigh is that the same question also
     *   reaches the MODEL whole through
     *   {@see \SugarCraft\Crush\Runtime::settleAsk()}'s no-approver arm.
     * - `EXIT_MODIFY` was UNBOUNDED, and the old text is right that it must
     *   never be truncated — so it is REFUSED over a ceiling instead
     *   ({@see modifyOrDeny()}, {@see MIN_REWRITE_BYTES}). It was also
     *   mis-described: the unbounded quantity is `modifiedInput`, which is not
     *   prompt text at all but the ARGUMENTS THAT EXECUTE.
     * - `EXIT_ALLOW` carries nothing anywhere in a shipped run — see the class
     *   docblock. It is left as-is because there is no exposure to fix, not
     *   because its size is "what the author chose to emit".
     */
    private const MAX_DENY_REASON_BYTES = 16384;

    /**
     * Bytes of an {@see EXIT_ASK} script's question that reach a human and the
     * model.
     *
     * A SEPARATE DECISION FROM {@see MAX_DENY_REASON_BYTES}, and separate for a
     * reason even though the two numbers agree: a deny reason is an
     * explanation, while a question is a thing somebody is about to ANSWER, and
     * the old docblock on the deny constant declined to clip this path on
     * exactly that ground — "clipping it changes what the human is answering".
     *
     * That objection is answered rather than dropped. MEASURED at afe3c26b
     * through the live path (`HookManager::preToolUse()` →
     * {@see HookRegistry::executeHooks()}): a hook printing 200,000 bytes and
     * exiting 3 produced a 200,000-byte {@see HookResult::$message}, and it
     * lands in two places from there — the permission modal
     * {@see \SugarCraft\Crush\Chat} renders, and
     * {@see \SugarCraft\Crush\Runtime::settleAsk()}, which on a run with NO
     * approver attached interpolates it WHOLE into "Permission required and no
     * approver is attached to this run: …" and hands that to the model as the
     * tool result. Neither a modal nor a tool result is improved by 200 KB, and
     * the clip announces itself, so the reader sees that the question was cut
     * instead of seeing a question that merely stops. What clipping does NOT
     * fix is a question whose operative clause is at the end; that is the
     * accepted cost, and it is the one the deny path already accepts.
     */
    private const MAX_ASK_PROMPT_BYTES = 16384;

    /**
     * The floor under an {@see EXIT_MODIFY} rewrite's ceiling — see
     * {@see modifyOrDeny()}, which allows the LARGER of this and the byte
     * length of the arguments the rewrite replaces.
     *
     * THE CEILING IS DERIVED AND THIS IS ONLY ITS FLOOR, which is the whole
     * reason a rewrite bound is defensible at all. A flat cap breaks the
     * legitimate case outright: a sanitiser that changes the `file_path` of a
     * 300 KB `Write` and leaves the body alone has to print the body back, so
     * its rewrite is necessarily about the size of the call it replaces.
     * Bounding a rewrite BY that size stops a hook turning a 16-byte call into
     * a 200 KB one and leaves the large call the hook was written for alone.
     *
     * The floor exists so the common case does not depend on how long the
     * original happened to be — a hook rewriting a two-line `Bash` call gets
     * room to work. 16 KiB, the figure
     * {@see \SugarCraft\Crush\Commands\CommandSpec::MAX_SUBSTITUTION_BYTES}
     * already uses for a local program's output crossing into the agent, rather
     * than a number invented here.
     *
     * THE CEILING CANNOT RATCHET ACROSS THE RE-SCAN. {@see HookRegistry::executeHooks()}
     * feeds an accepted rewrite back as the next pass's `toolInput`, so pass N+1's
     * ceiling is `max(16384, len(rewrite_N))` — and `len(rewrite_N)` was itself
     * bounded by pass N's ceiling. The ceiling is therefore non-increasing above
     * the floor, and the whole chain is bounded by
     * `max(16384, len(the model's own arguments))` however many passes it takes.
     */
    private const MIN_REWRITE_BYTES = 16384;

    /**
     * A GUESS AT the longest `NAME=VALUE\0` one environment entry may be, used
     * for diagnosis and for nothing else.
     *
     * NOTHING BRANCHES ON THIS NUMBER ANY MORE, and that is the point.
     * {@see executeStaged()} offers the OS the real bytes and only moves a
     * payload out of the environment when the exec is ACTUALLY refused, so the
     * transport needs no per-platform table and cannot be wrong about a
     * platform nobody here has.
     *
     * WHERE THE FIGURE COMES FROM, and how narrowly it holds. On Linux with
     * 4 KiB pages `MAX_ARG_STRLEN` is `PAGE_SIZE * 32` = 131,072, and
     * `execve()` fails the whole call with `E2BIG` when any single entry
     * exceeds it. Because {@see execute()} used to hand the tool input over as
     * `CRUSH_TOOL_INPUT` and nothing else, that made the CEILING ON A HOOK'S
     * ENVIRONMENT a ceiling on the TOOL CALL: with any script hook registered, a
     * `Write` of a 128 KB file could not run. MEASURED at afe3c26b on THIS host
     * (`getconf PAGESIZE` = 4096) against a hook whose whole script is
     * `exit(0)`: 131,054 bytes of value allowed, 131,055 denied — exactly
     * `strlen('CRUSH_TOOL_INPUT') + 1 + value + 1 <= 131072` — and 200,000 and
     * 1,000,000 denied identically with "Hook audit could not be executed".
     * `CRUSH_TOOL_OUTPUT` has a name one byte longer, so its boundary is one
     * byte LOWER: 131,053 fits and 131,054 does not.
     *
     * That failed CLOSED, so it was never a hole — it was a daily-driver
     * blocker whose refusal named neither the size nor the cause.
     *
     * WHAT THIS FIGURE IS NOT — and every one of these is a reason not to
     * branch on it:
     * - Not the limit on a 64 KiB-page Linux (ppc64le, and the aarch64 kernels
     *   RHEL and SLES ship), where `PAGE_SIZE * 32` is 2 MiB. Substituting a
     *   marker at 131,055 there would hand a guard a marker on a call the
     *   kernel would have carried verbatim.
     * - Not the limit on macOS or the BSDs, which cap the whole environment
     *   instead of one entry (macOS: 256 KiB for argv and environ together).
     *   A payload pair that fits every per-entry check can still be refused
     *   there — which the retry handles, because it is triggered by the
     *   refusal and not by a size.
     * - Not the limit on Windows, which is SMALLER: 32,767 bytes for one
     *   variable. A guess this high would never fire; the refusal does.
     *
     * What it is still good for is saying WHICH payload probably broke the
     * exec when both routes are gone — see {@see stagePayloads()}'s `unbacked`
     * and {@see execRefused()}.
     */
    private const MAX_ENV_ENTRY_BYTES = 131072;

    /**
     * What an oversize `CRUSH_TOOL_INPUT` / `CRUSH_TOOL_OUTPUT` says instead of
     * carrying the bytes — see {@see stagePayloads()}.
     *
     * DELIBERATELY NOT A PREFIX OF THE VALUE, and deliberately not empty.
     * A prefix is the dangerous one: truncated JSON is not smaller JSON, and a
     * hook that decodes it leniently ends up judging a call that does not
     * exist. Empty is merely indistinguishable from "this event carries no
     * input" — `docs/HOOKS.md` already tells hook authors to read an absent
     * `CRUSH_*` as empty. A marker that cannot parse as JSON, and that names
     * both the byte count and the variable holding the bytes, is the only one
     * of the three a hook cannot mistake for the call.
     */
    private const OVERSIZE_ENV_MARKER = '@@CRUSH_PAYLOAD_IN_FILE@@';

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
     * reap — has to finish inside; see {@see DEFAULT_TIMEOUT_SECONDS}. A value
     * that is not positive AND FINITE is NOT "no timeout":
     * {@see timeoutSeconds()} reads it as "unset" and applies the default,
     * because the one thing this parameter must not be able to express is the
     * unbounded wait it was added to remove — and `INF` expressed it exactly,
     * being a float that is greater than zero.
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
            timeoutSeconds: (is_int($timeout) || is_float($timeout))
                && $timeout > 0
                && is_finite((float) $timeout)
                    ? (float) $timeout
                    : self::DEFAULT_TIMEOUT_SECONDS,
        );
    }

    /**
     * The wall clock one run of this hook gets, in seconds.
     *
     * FINITE AS WELL AS POSITIVE, and the finite half is load-bearing for a
     * caller that never went through {@see HookConfig::parse()}: `INF > 0.0` is
     * true, so `new ScriptHook(..., timeoutSeconds: INF)` produced a deadline of
     * `microtime(true) + INF` — an instant no clock reaches — and the unbounded
     * wait this whole class was changed to remove was back. `NAN` fails every
     * comparison and so reached the default by accident; it now reaches it by
     * this test, which is the same answer arrived at deliberately.
     */
    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds > 0.0 && is_finite($this->timeoutSeconds)
            ? $this->timeoutSeconds
            : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * The same hook with a shorter bound — see {@see BoundedHookInterface}.
     *
     * The clamp is here and not at the caller so that this class keeps the last
     * word on its own invariant: a chain may only ever SHORTEN a hook's budget,
     * and a non-finite or non-positive value can no more arrive through this
     * door than through the constructor.
     *
     * THE FLOOR IS NOT DECORATION. {@see timeoutSeconds()} reads a value of
     * zero as "unset" and answers {@see DEFAULT_TIMEOUT_SECONDS} — so a caller
     * that handed this method the nothing it had left would have been given
     * SIXTY SECONDS back, which is the fail-open direction. One
     * {@see EXIT_POLL_MICROSECONDS} is the shortest bound this class can tell
     * apart from zero anyway, so it is the floor rather than an invented epsilon.
     */
    public function withTimeoutSeconds(float $seconds): self
    {
        $floor = self::EXIT_POLL_MICROSECONDS / 1_000_000;
        $requested = is_finite($seconds) ? max($floor, $seconds) : $floor;

        return new self(
            $this->name,
            $this->event,
            $this->matcher,
            $this->command,
            $this->description,
            min($this->timeoutSeconds(), $requested),
        );
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

        // THE TWO PAYLOAD VARIABLES ARE STAGED, NOT ASSIGNED — see
        // stagePayloads(). The rest of the run lives in executeStaged() purely
        // so that every one of its returns — the two fail-closed denies, the
        // timeout, the verdict — passes through this `finally` and deletes the
        // files. Inlining it would put a `finally` around a hundred lines whose
        // early returns are the whole point of them.
        $payload = self::stagePayloads([
            'CRUSH_TOOL_INPUT' => $context->toolInput,
            'CRUSH_TOOL_OUTPUT' => $context->toolOutput,
        ]);

        try {
            return $this->executeStaged($context, $cwd, $payload);
        } finally {
            self::discardPayloadFiles($payload['files']);
        }
    }

    /**
     * {@see execute()} with the payload files already on disk and guaranteed to
     * be cleaned up after it, whatever it returns or throws.
     *
     * @param array{env: array<string, string>, fallback: ?array<string, string>, files: list<string>, unbacked: list<string>} $payload
     */
    private function executeStaged(HookContext $context, ?string $cwd, array $payload): HookResult
    {
        $fixed = [
            'CRUSH_SESSION_ID' => $context->sessionId,
            'CRUSH_TOOL_NAME' => $context->toolName,
            'CRUSH_MODEL' => $context->model,
            'CRUSH_PROVIDER' => $context->provider,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // THE REAL BYTES ARE OFFERED FIRST AND THE OS DECIDES — see
        // MAX_ENV_ENTRY_BYTES for why this is an attempt rather than a
        // calculation. `@` because the refusal is REPORTED, below, as a deny
        // that names the sizes: the bare call emits
        // `proc_open(): posix_spawn() failed: Argument list too long`, which in
        // the TUI lands mid-frame over whatever candy-core last painted, and
        // under `failOnWarning="true"` turns this suite's own coverage of the
        // refusal path into a failure.
        $process = @proc_open($this->command, $descriptors, $pipes, $cwd, $fixed + $payload['env']);

        if (!is_resource($process) && $payload['fallback'] !== null) {
            // The exec was refused with the payloads IN the environment, so try
            // again with everything that has a file moved OUT of it. The hook
            // has not run — `proc_open()` returning false means no child was
            // started — so this is a retry, never a second execution.
            $process = @proc_open($this->command, $descriptors, $pipes, $cwd, $fixed + $payload['fallback']);
        }

        if (!is_resource($process)) {
            // NAMES THE SIZES. This branch's old message — "Hook X could not be
            // executed" — was the whole of what a user saw when a 128 KB tool
            // call hit `MAX_ARG_STRLEN` (see stagePayloads()), and it reads as
            // "your hook is broken" rather than as a size. The two payload
            // figures are the only inputs to this call that scale, so they are
            // the two worth printing — and when a payload had no file to be
            // moved into, saying so is the difference between "your hook is
            // broken" and "your temp directory is".
            return HookResult::deny($this->execRefused($context, $payload['unbacked']));
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
                self::clip(
                    trim($errors) !== '' ? trim($errors) : trim($output),
                    self::MAX_DENY_REASON_BYTES,
                ),
            )));
        }

        $output = trim($output);
        $errors = trim($errors);

        return match ($exitCode) {
            // NOT CLIPPED, and that is a statement about where this value goes
            // rather than about how big it is allowed to be: on the live path
            // it goes NOWHERE. {@see HookRegistry::executeHooks()} rebuilds a
            // permitting verdict as `HookResult::allow()` with an empty
            // message, and both gates
            // ({@see \SugarCraft\Crush\Runtime::gate()} and
            // {@see \SugarCraft\Crush\Chat::gateToolCall()}) interpolate
            // `$hookResult->message` only into `"Hook denied: …"`. It survives
            // solely through {@see HookDispatcher}, which nothing in `src/`
            // constructs. See MAX_DENY_REASON_BYTES.
            self::EXIT_ALLOW => HookResult::allow($output),
            self::EXIT_ASK => HookResult::ask(
                $output !== ''
                    ? self::clip($output, self::MAX_ASK_PROMPT_BYTES)
                    : $this->defaultQuestion(),
            ),
            self::EXIT_MODIFY => $this->modifyOrDeny($output, strlen($context->toolInput)),
            default => HookResult::deny(
                self::clip($errors, self::MAX_DENY_REASON_BYTES) ?: "Hook exited with code $exitCode",
            ),
        };
    }

    /**
     * What a refused `proc_open()` says, with the two figures that scale.
     *
     * ONE MESSAGE FOR BOTH REFUSALS, because after the retry in
     * {@see executeStaged()} they are the same event: the OS would not start
     * the process with the environment we could build for it. The old pair
     * split "no file could be written" from "proc_open() refused" and only the
     * second named a size, which put the E65 complaint — a refusal naming
     * neither the size nor the cause — back on one of the two branches.
     *
     * @param list<string> $unbacked payload variables that had no file to be moved into
     */
    private function execRefused(HookContext $context, array $unbacked): string
    {
        $message = sprintf(
            'Hook %s could not be executed (the operating system refused to start it; tool input %d bytes, '
            . 'tool output %d bytes)',
            $this->name,
            strlen($context->toolInput),
            strlen($context->toolOutput),
        );

        if ($unbacked === []) {
            return $message;
        }

        // FAIL CLOSED, and say which half of the transport was missing. A hook
        // that was never shown the call has not approved it — the same rule an
        // unusable `cwd` follows.
        return $message . sprintf(
            '; no temporary file could be created for %s, so %s could not be moved out of the environment. '
            . 'A hook that has not seen the call has not allowed it.',
            implode(' or ', $unbacked),
            count($unbacked) === 1 ? 'it' : 'they',
        );
    }

    /**
     * Put the two payload variables where the child can reach them BOTH ways,
     * and build the environment to fall back to if the OS refuses the first.
     *
     * EVERY PAYLOAD GETS A FILE WHENEVER ONE CAN BE WRITTEN — not only the
     * oversize ones. "Present only when the value is large" is a conditional
     * contract, and a hook author who tested on small calls would ship a hook
     * that dereferences an unset path on exactly the calls this change exists
     * to make possible. The one case where the variable is absent is a temp
     * directory that will not take a file at all ({@see writePayloadFile()}
     * returning null); `docs/HOOKS.md` tells hook authors how to write a guard
     * that fails closed rather than blind when it happens.
     *
     * THE ENVIRONMENT VALUE IS ALWAYS THE REAL BYTES — that is the whole `env`
     * array, and it is what {@see executeStaged()} offers the OS first.
     * `CRUSH_TOOL_INPUT` is a documented public contract (`docs/HOOKS.md`) that
     * user-authored scripts already read; substituting a marker on a size THIS
     * code guessed at would be a silent breaking change on every platform whose
     * real limit is higher than the guess. So the substitution is not a guess:
     * `fallback` is only reached after an exec the OS actually refused.
     *
     * WHAT `fallback` REPLACES, and what it does not. Every payload that has a
     * file and something to say becomes {@see OVERSIZE_ENV_MARKER} — never a
     * prefix of itself, never the empty string, and never a payload that has no
     * file to be read from instead. Those are the `unbacked` ones: nothing can
     * shrink them, so if one of them is what the OS refused, the retry fails
     * too and {@see execRefused()} names it.
     *
     * THE HONEST COST, stated rather than buried: a hook that reads only
     * `CRUSH_TOOL_INPUT` and never the file sees a marker where it used to see
     * arguments — but only on a call the OS refused to start with the arguments
     * in the environment, which is a call that previously did not run at all.
     * `CRUSH_TOOL_NAME` and the matcher, which is what most guards actually key
     * on, are unaffected either way.
     *
     * @param array<string, string> $payloads variable name => the bytes it carries
     *
     * @return array{env: array<string, string>, fallback: ?array<string, string>, files: list<string>, unbacked: list<string>}
     *     [the environment carrying the real bytes; the same environment with
     *     every file-backed payload moved out of it, or null when there is
     *     nothing to move; every file to delete afterwards; the payloads that
     *     have no file to be moved into]
     */
    private static function stagePayloads(array $payloads): array
    {
        $env = [];
        $fallback = [];
        $files = [];
        $unbacked = [];
        $movable = false;

        foreach ($payloads as $name => $value) {
            $pathVariable = $name . '_FILE';
            $path = self::writePayloadFile($value);

            $env[$name] = $value;
            $fallback[$name] = $value;

            if ($path === null) {
                // No file to move it into. Only report it when it is big enough
                // to plausibly BE what a refused exec is complaining about —
                // MAX_ENV_ENTRY_BYTES is a diagnostic guess here and nothing
                // more, so a wrong guess costs a less specific message and
                // never a wrong verdict.
                if (strlen($name) + strlen($value) + 2 > self::MAX_ENV_ENTRY_BYTES) {
                    $unbacked[] = $name;
                }

                continue;
            }

            $files[] = $path;
            $env[$pathVariable] = $path;
            $fallback[$pathVariable] = $path;

            if ($value === '') {
                // Already as small as a value gets, and the file still exists
                // for a hook that reads only the file — see the `env` table in
                // `docs/HOOKS.md`, where the empty payload's `_FILE` is present
                // and the payload variable itself is not printed by `env`.
                continue;
            }

            $fallback[$name] = sprintf(
                '%s %d bytes; read $%s',
                self::OVERSIZE_ENV_MARKER,
                strlen($value),
                $pathVariable,
            );
            $movable = true;
        }

        return [
            'env' => $env,
            'fallback' => $movable ? $fallback : null,
            'files' => $files,
            'unbacked' => $unbacked,
        ];
    }

    /**
     * One payload file, or null when the filesystem would not give us one.
     *
     * `tempnam()` creates the file 0600 and returns a name nothing else holds,
     * which is what this needs: the file carries whatever the model asked to
     * write, so it is as sensitive as the tool call itself and it lives in a
     * directory every account on the box can list. The `chmod()` is not
     * redundant belt-and-braces — it is the only line that still holds if a
     * future caller swaps the name source.
     *
     * Failure is a NULL and not an exception, because a hook whose payload
     * fits in the environment does not need a file at all and must not be
     * blocked by a full `/tmp`.
     */
    private static function writePayloadFile(string $value): ?string
    {
        $path = @tempnam(sys_get_temp_dir(), 'crush-hook-payload-');

        if (!is_string($path) || $path === '') {
            return null;
        }

        @chmod($path, 0o600);

        if (@file_put_contents($path, $value) === false) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * Delete every staged payload file.
     *
     * Called from a `finally` in {@see execute()} so it runs on the timeout
     * path, the fail-closed paths and any throw as well as on the ordinary
     * return: a hook chain re-scanned {@see HookRegistry::MAX_REWRITE_PASSES}
     * times would otherwise leave a copy of the tool call in the shared temp
     * directory for every hook of every pass.
     *
     * @param list<string> $files
     */
    private static function discardPayloadFiles(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Bound a hook's own words before they become prompt text.
     *
     * $limit IS A REQUIRED ARGUMENT AND HAS NO DEFAULT, deliberately. Two
     * different bounds cross this method — {@see MAX_DENY_REASON_BYTES} for a
     * refusal and {@see MAX_ASK_PROMPT_BYTES} for a question — and they are
     * separate decisions that happen to hold the same number today. A default
     * would let a third caller inherit whichever one was written first and
     * quietly acquire a bound nobody chose for it.
     *
     * NOT EVERY NON-DENY PATH IS CLIPPED, and the exception is the important
     * one: an {@see EXIT_MODIFY} rewrite is REFUSED over its ceiling rather
     * than cut ({@see modifyOrDeny()}), because truncating machine-readable
     * arguments does not make smaller arguments — it makes invalid JSON, which
     * {@see HookResult::rewrittenArgs()} reports as null and every consumer
     * answers by running the ORIGINALS the rewrite existed to replace.
     *
     * The HEAD is kept and the tail dropped, because a hook explains itself
     * first and repeats itself afterwards: the sentence naming the file or the
     * rule is at the top of a linter's output, and the ten thousand lines under
     * it are instances of it. Cut on a byte boundary and not a character one,
     * deliberately — this is arbitrary program output, not guaranteed UTF-8,
     * and the marker that follows makes the seam visible either way.
     */
    private static function clip(string $text, int $limit): string
    {
        $length = strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit)
            . sprintf(
                ' … [hook output truncated: %d of %d bytes shown; this reason is PARTIAL]',
                $limit,
                $length,
            );
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
    private function modifyOrDeny(string $output, int $replacedBytes): HookResult
    {
        $ceiling = max(self::MIN_REWRITE_BYTES, $replacedBytes);
        $size = strlen($output);

        if ($size > $ceiling) {
            return HookResult::deny(sprintf(
                'Hook %s proposed a %d-byte rewrite of the tool input, over the %d-byte ceiling for '
                . 'this call (the larger of the %d bytes it replaces and %d); a rewrite cannot be '
                . 'truncated, so it is refused rather than cut.',
                $this->name,
                $size,
                $ceiling,
                $replacedBytes,
                self::MIN_REWRITE_BYTES,
            ));
        }

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
