<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Context\SessionPromptMemo;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Events\ContextLedgerChanged;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\BuiltIn\RepeatCallCountHook;
use SugarCraft\Crush\Hooks\BuiltIn\RepeatCallGuardHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Providers\MarksPromptCache;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\RebindsModel;
use SugarCraft\Crush\Providers\ReportsServedModel;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\SiblingSpendLedger;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Bridges the chat-shell {@see Backend} seam to the full agent engine —
 * a {@see ProviderInterface} driven by the {@see Runtime}, with tools,
 * skills and hooks.
 *
 * This is what makes the merged product *work*: the tested {@see \SugarCraft\Crush\Chat}
 * Model keeps speaking its simple `complete(history): Message` contract,
 * while underneath each turn runs a bounded agentic loop — call the model,
 * execute any tool calls through the hook gate, feed the results back, and
 * repeat until the model stops calling tools (or {@see $maxSteps} is hit).
 *
 * The chassis works in the root {@see Message} value object; the engine
 * works in the typed {@see \SugarCraft\Crush\Messages\Message} hierarchy.
 * Conversion happens here at the seam.
 */
final class EngineBackend implements Backend, ReportsContextWindow, ReportsPromptSections, ObservesReasoning, InteractiveTurn, SummarisesWithCache
{
    /**
     * IDLE ceiling on a forked completion child in {@see completeAsync()} -
     * how long the parent will wait for the NEXT frame from the child, not
     * for the whole turn. It used to be a single wall-clock timer started
     * once for the entire fork, which SIGKILLed any turn whose legitimate
     * multi-step tool work ran past it ("Provider request timed out after
     * 120s" mid-flight, crush_feat.md §1 E1). Every frame the child streams
     * resets it, so a turn that is making visible progress stays alive
     * indefinitely while a genuinely hung provider still dies.
     *
     * WHAT THIS SAID: "so a turn that is making visible progress stays alive
     * indefinitely".
     * WHAT IS TRUE NOW: that is the property this constant NEEDS, and until
     * E456 the code did not have it. "Every frame resets it" was true; "every
     * kind of progress writes a frame" was not. Only content deltas did.
     * {@see \SugarCraft\Crush\Runtime::runStreaming()} gates its $onToken on
     * `$response->content !== ''`, and $onToken was the only thing in the child
     * that wrote to the socket between tool events - so a model that thought
     * for over two minutes before its first content byte, or a provider whose
     * chunks carried only tool-call structure or only usage figures, was killed
     * as hung while it was in fact working. A user lost a turn to it mid-think.
     * WHY THE CEILING STILL EARNS ITS PLACE: the timer was never the defect;
     * its definition of PROGRESS was. Raising the ceiling only relocates the
     * bug, and removing it resurrects the hang this constant exists to bound.
     * The fix was to make every chunk off the wire write a frame - see the
     * `reasoning` frame kind in {@see runCompleteInChild()}, whose `text` is
     * empty for the chunks that have nothing to show and are purely a sign of
     * life.
     *
     * STILL NOT COVERED, and stated so nobody reads the paragraph above as
     * unconditional: a NON-streaming provider (`supportsStreaming() === false`)
     * makes one blocking call per agentic step, so between two steps there is
     * genuinely nothing to announce and a slow batch provider still dies here.
      * Closing that needs a heartbeat raised on a timer rather than on a chunk.
      * E493 re-verified that gap at round 69; E524 measured why neither cheap
      * shape of the heartbeat works from this side: an async signal
      * (pcntl_alarm) does not dispatch inside the blocking C call the provider
      * response is waiting on, and a second writer pumping heartbeats into the
      * same socket would interleave with the length-prefixed frames — whose
      * drain discards a corrupt buffer whole, costing the turn. What does tick
      * through a stalled transfer is an HTTP progress callback, and its seam
      * lives in the providers, not below this ceiling.
      *
      * A DEFAULT SINCE N-P4a, not a fixed number: the `turnIdleTimeoutSeconds`
      * setting replaces it per turn ({@see turnIdleTimeoutSeconds()}), resolved
      * in the PARENT before the fork so the timer the parent arms and the
      * ceiling the child holds its parallel-group deadline under are the same
      * value. Public so the schema's default is this constant, not a copy.
      * Still an IDLE ceiling whatever it is set to — there is no total
      * deadline on a turn, and the setting cannot make one.
      */
    public const COMPLETE_TIMEOUT_SECONDS = 120;

    /**
     * Roadmap 4.7-2: the context-window share at which a delegated run
     * ({@see completeTranscript()}) is told to wrap up or hand off — Zed's
     * `TOKEN_USAGE_WARNING_THRESHOLD`. Measured on a request as SENT, after
     * the step's own relief (2.2-1 / 2.4-1) had its go, so it fires only when
     * that relief could not keep the run under its step budget.
     */
    public const SUB_AGENT_WRAP_UP_PERCENT = 80;

    /** Points past the wrap-up share at which a run already told is stopped (80 → 90). */
    public const WRAP_UP_STOP_MARGIN = 10;

    /** The one user row a run gets at its wrap-up share (`%1$d` used, stopped at `%2$d`). */
    public const WRAP_UP_NUDGE = 'This run has used about %1$d%% of its context window. Wrap up now: finish what you are '
        . 'doing and give your final report. If the task cannot be finished, hand off instead: report what is done, '
        . 'what remains, and what whoever continues needs to know. At %2$d%% the run is stopped.';

    /** Why a run past its stop share ended — the failure a delegating Task reports, resume id beside it. */
    public const WRAP_UP_STOPPED = 'it is nearing the end of its context window (%d%% used) and was stopped; '
        . 'resume it to have it wrap up or hand off its work';

    /**
     * The smallest `turnIdleTimeoutSeconds` honoured. Below it the watchdog
     * would kill a provider's ordinary time-to-first-token on a loaded
     * server, and it must stay above every silence the engine itself
     * produces: an exhausted retry sequence sleeps at most
     * {@see \SugarCraft\Crush\Providers\TransientFailure::maxTotalBackoffMicroseconds()}
     * with no frame written, which is held to half of this.
     */
    public const MIN_TURN_IDLE_TIMEOUT_SECONDS = 30;

    /** The settings key {@see turnIdleTimeoutSeconds()} reads. */
    private const TURN_IDLE_TIMEOUT_CONFIG_KEY = 'turnIdleTimeoutSeconds';

    /**
     * The idle ceiling the PARENT armed for the turn this process is the
     * forked child of — set only inside {@see completeAsync()}'s child branch,
     * so it exists only in that child's copy of memory and dies with it.
     *
     * Why the child needs the parent's number rather than its own read: the
     * parallel-group deadline must sit under the ceiling that will actually
     * kill the turn ({@see parallelToolDeadlineSeconds()}), and that ceiling
     * is the timer the parent armed. A settings save landing between the
     * fork and the child's own config read would otherwise let the two
     * disagree. Static rather than per-instance because a Task sub-agent's
     * engine runs inside the same child and is bounded by the same timer.
     */
    private static ?int $forkIdleCeilingSeconds = null;

    /**
     * The user message of {@see summariseStoppedTurn()}'s request when the
     * step budget ran out (`%d` = the ceiling). Model-facing, so English like
     * every other prompt layer; it names the budget so the model does not
     * read the stop as an error of its own.
     */
    private const BUDGET_EXHAUSTED_SUMMARY_PROMPT = 'This turn has used its whole budget of %d tool steps, so tools are now '
        . 'disabled. Do not call any tools. Reply to the user with a concise summary of: what has been done, what remains '
        . 'to be done, and the next step you would take.';

    /**
     * The same request when the repeat-call loop guard ended the turn
     * (`%s` = the tool, `%d` = {@see ToolCallLoopGuard::END_TURN_AT}). It asks
     * the model to SAY it was stopped, because the step-exhausted notice
     * does not fire for this exit and nothing else tells the operator why
     * the turn ended early.
     */
    private const LOOP_GUARD_SUMMARY_PROMPT = 'This turn was stopped because you called %s with identical arguments and '
        . 'got the identical result %d times. Tools are now disabled. Do not call any tools. Tell the user the turn was '
        . 'stopped for repeating that call, then summarise concisely: what has been done, what remains to be done, and '
        . 'what you would do differently next.';

    /**
     * The two escape hatches for {@see Runtime}'s concurrent tool dispatch,
     * and the ~/.sugar-crush/config.json keys that persist them. See
     * {@see parallelToolCallsEnabled()} / {@see parallelToolDeadlineSeconds()}.
     */
    private const PARALLEL_TOOL_CALLS_DISABLE_ENV = 'SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS';

    private const PARALLEL_TOOL_DEADLINE_ENV = 'SUGARCRUSH_PARALLEL_TOOL_DEADLINE';

    private const PARALLEL_TOOL_CALLS_CONFIG_KEY = 'parallelToolCalls';

    private const PARALLEL_TOOL_DEADLINE_CONFIG_KEY = 'parallelToolDeadlineSeconds';

    /**
     * E707 (round 81): the `maxOutputTokens` config key - the operator's
     * per-request OUTPUT ceiling. Config-only, deliberately: the parallel
     * tool knobs above have environment escape hatches because they tune
     * dispatch mechanics an operator might want per-shell; the money-side
     * ceiling belongs in the persisted settings file where the tiering rules
     * (user-tier, never project-tier - see
     * {@see \SugarCraft\Crush\Config\LayeredSettings}) can hold it, not in a
     * child process's environment.
     */
    private const MAX_OUTPUT_TOKENS_CONFIG_KEY = 'maxOutputTokens';

    /**
     * Upper bound on a single length-prefixed frame from the child. A frame
     * legitimately carries raw image bytes, so it has to be generous - but a
     * corrupt/truncated header must never make the parent try to buffer an
     * arbitrary length before it notices the stream is garbage.
     *
     * THIS IS THE ONLY PLACE IN `src/` THE NUMBER IS WRITTEN, AND THAT IS WHY
     * IT IS PUBLIC. The qualifier is not pedantry: two suites spell the
     * arithmetic deliberately, in
     * {@see \SugarCraft\Crush\Tests\MCP\McpFrameCapTest::testBothClassesDeclareTheSameCapAndItIsTheFrameCapNotTheStderrCap()}
     * and
     * {@see \SugarCraft\Crush\Tests\LSP\LspConnectionFrameCapTest::testTheCapIsTheOneTheClassDeclares()},
     * which is what makes moving this number a deliberate-change signal rather
     * than a silent one. Two other product classes frame a peer's output against the same
     * bound for the same reason -- {@see \SugarCraft\Crush\LSP\LspConnection}
     * and {@see \SugarCraft\Crush\ClaudeCodeMcpClient} -- and each now spells
     * `= EngineBackend::MAX_FRAME_BYTES` rather than repeating the arithmetic.
     * The stdio transport's cap left the product with it at phase-2a:
     * `sugarcraft/sugar-mcp` restates the same 64 MiB as a library-local
     * literal (a library cannot name a product constant), and
     * {@see \SugarCraft\Crush\Tests\MCP\McpFrameCapTest::testBothClassesDeclareTheSameCapAndItIsTheFrameCapNotTheStderrCap()}
     * pins that the restatement has not drifted from this one.
     *
     * WHAT WAS TRUE BEFORE: this constant was `private`, so PHP could not name
     * it from those files and all three carried their own `64 * 1024 * 1024`
     * under doc-blocks calling the value "inherited rather than invented". The
     * inheritance was PROSE. Raising this cap desynchronised the family
     * silently, and the only thing that could catch it was a reflection test
     * ({@see \SugarCraft\Crush\Tests\FrameCapFamilyTest}) comparing four
     * independent literals to each other.
     *
     * WHY THIS STILL EARNS ITS PLACE, i.e. why widening the visibility is not
     * merely convenience: the claimants no longer hold a COPY of the number, so
     * the family cannot disagree about it at all. The reflection test remains,
     * but its job changed -- it now pins that every member DERIVES rather than
     * that four literals happen to match.
     *
     * ⚠️ AND NARROWING THIS AGAIN FAILS LATE, NOT EARLY, WHICH IS THE ARGUMENT
     * FOR THE TEST RATHER THAN AGAINST IT. MEASURED on PHP 8.3.6: a class
     * constant whose initialiser names another class's constant is evaluated
     * LAZILY, on first access -- `class_exists()` on all three claimants still
     * answers true with this constant private, and what throws is the READ,
     * `Error: Cannot access private constant`. So the damage would not surface
     * at load; it would surface inside a framing path the moment one checked
     * its bound, which is the worst place to find out.
     *
     * ⚠️ AND THE DERIVATION BUYS AN AUTOLOAD EDGE, WHICH IS THE OBJECTION THIS
     * REPO HAS RECORDED BEFORE. `Runtime` deliberately does NOT read
     * `Chat::DENIED_ERROR_PREFIXES`, because that would autoload `Chat` on the
     * first gated tool call of every run including the `-p` path that exists to
     * avoid building one -- so the same question is owed an answer here.
     * MEASURED on PHP 8.3.6 in a fresh process: `class_exists()` on
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer} (pre-phase-2a, when it
     * still declared its own derived cap) declared two class-likes
     * and did NOT touch this file; READING its cap then pulled in four more --
     * this class, {@see \SugarCraft\Crush\Backend} and its two optional
     * interfaces. Before the derivation it pulled in none. The same edge holds
     * today for the two framers that still derive here.
     *
     * WHY THAT IS ACCEPTABLE HERE AND WAS NOT THERE: the read happens inside a
     * framing path, which is reached only once a child process is already
     * spawned and writing -- so the engine is being loaded on a path that has
     * paid for a process, not on a path that exists to avoid one. The `-p`
     * shape has no counterpart here. If a caller ever checks a frame cap
     * WITHOUT a child, this paragraph is the one to re-measure.
     *
     * ⚠️ PUBLIC HERE MEANS "READABLE BY THE FAMILY", NOT "TUNABLE". Moving this
     * number moves every framer that derives here at once, which is the
     * intent; the two suites named above will red — and the library's restated
     * cap goes red through McpFrameCapTest's equality row — that is the
     * deliberate-change signal,
     * not an obstacle. RE-MEASURED at this commit: raising this to 128 MiB
     * produces two failures, one in each of those files.
     *
     * ⚠️ AND THAT SAME MEASUREMENT IS THE BEFORE-AND-AFTER, which is the only
     * reason to trust the sentence above. Round 58 ran it on the tree as it
     * then stood and recorded the result in the two framers' doc-blocks; those
     * paragraphs were rewritten this round and the measurement went with them,
     * so it is restored here, once, rather than three times. WHAT IT SAID:
     *
     *     MEASURED on PHP 8.3.6 by raising the engine's constant to 128 MiB and
     *     running the two suites that exist to pin this bound … both stayed
     *     green. No whole-suite run was made under that mutation.
     *
     * WHY IT STILL EARNS ITS PLACE, GIVEN THE ANSWER HAS SINCE FLIPPED: green
     * then and red now is the whole argument. At the time all four classes held
     * their own literal, so moving this number moved ONE of them and the suites
     * that check the bound never compared the four to each other. The pair of
     * results is the evidence that the derivation changed something real, and a
     * reader who sees only today's red has no way to tell a guard that works
     * from a guard that was never able to fail.
     */
    public const MAX_FRAME_BYTES = 64 * 1024 * 1024;

    /**
     * B6: the rejection a turn settles with when the child's frame stream
     * stops being parseable - a header declaring an impossible length, a body
     * that does not decode, or a child gone with half a frame still on the
     * wire. One spelling for the three sites, because a test (and a user
     * reading the error) must be able to tell this apart from "exited
     * without a result": here the child may have finished its work and the
     * CHANNEL lost it.
     */
    private const FRAME_STREAM_CORRUPTED = 'Provider worker frame stream corrupted';

    /**
     * B6: the write timeout on the child's end of the frame socket, in
     * seconds. PHP applies `default_socket_timeout` (60s) to every blocking
     * socket write, and MEASURED on PHP 8.3.6 with it set to 1s a 4 MB frame
     * against a parent that stalled 3s was cut at 1,059,776 bytes - the write
     * gave up mid-frame and everything after it was unparseable. With this
     * timeout set, the same write simply waited and delivered all 4,000,000
     * bytes. A stalled-but-alive parent must apply BACKPRESSURE, not cost the
     * child its frame: a live parent either reads or tears down, and teardown
     * closes its end (EPIPE here, because the child closed its inherited copy
     * of that end - B3) and kills this child's tree. A year, not "forever",
     * only because the API takes a number.
     */
    private const CHILD_WRITE_TIMEOUT_SECONDS = 365 * 24 * 3600;

    /**
     * {@see reapChild()}'s bounded WNOHANG poll: 20 attempts x 5ms is a 100ms
     * ceiling on how long teardown may sit on the event loop. A SIGKILLed or
     * already-exiting child is reaped on the first attempt or two; the budget
     * exists only so an *unkillable* child (posix-less build, see
     * {@see reapChild()}) costs one dropped frame instead of the whole UI.
     */
    private const REAP_ATTEMPTS = 20;

    private const REAP_POLL_MICROSECONDS = 5_000;

    /**
     * How often {@see completeAsync()} asks whether the turn child is still
     * alive (B3). A death is noticed within this window instead of whenever
     * the last inherited copy of the frame socket closes; one WNOHANG syscall
     * per tick is the whole cost.
     */
    private const EXIT_POLL_SECONDS = 0.1;

    /**
     * PIDs {@see completeAsync()} forked that {@see reapChild()} has not yet
     * confirmed reaped, swept opportunistically at the top of the next turn.
     *
     * Needed because {@see reapChild()}'s budget is finite by design: a child
     * descheduled on a loaded box outlives its 100ms window, and the success
     * path does not SIGKILL first (the child is already writing its last
     * frame and exiting - killing it there would race the frame). One escaped
     * child per turn is a zombie per turn in a TUI that lives for hours, and
     * nothing else in this process sweeps: there is no SIGCHLD handler, and a
     * blanket `pcntl_waitpid(-1, ...)` would be actively harmful here because
     * {@see \SugarCraft\Crush\Chat::executeToolsParallel()} and
     * {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner} both wait on
     * their OWN pids in this same process and check the returned pid - a
     * blind sweep would steal their exit statuses. So track ours and sweep
     * only those.
     *
     * @var array<int, true>
     */
    private static array $unreapedChildren = [];

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool>   $tools
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly string $model,
        private readonly array $tools = [],
        private readonly array $skills = [],
        private readonly ?HookManager $hookManager = null,
        /**
         * The per-turn provider-call ceiling. 1000 (Goose's figure) since
         * WAVE_PLAN_2 §5 "maxToolSteps": the old 8 cut ordinary agentic work
         * off mid-task. A ceiling that high is only safe beside the brakes
         * that ship with it — the repeat-call loop guard
         * ({@see ToolCallLoopGuard}), which stops a model stuck on one call
         * long before step 1000 (the spend cap cannot: SGLang reports $0),
         * and the no-tools summary request ({@see summariseStoppedTurn()})
         * that makes an exhausted budget end in an answer rather than a
         * half-finished step. `maxToolSteps` in config.json overrides it.
         */
        private readonly int $maxSteps = 1000,
        private readonly bool $hooksDisabled = false,
        private readonly ?SkillRegistry $skillRegistry = null,
        private readonly ?InstructionFileLoader $instructionLoader = null,
        /**
         * The project root every turn this backend runs is anchored at —
         * `--root`'s value, forwarded onto {@see App::$root}. Null leaves the
         * App unrooted, which resolves to the process directory downstream.
         */
        private readonly ?string $root = null,
        /**
         * The 6-mode safety gate every tool call this backend dispatches is
         * evaluated against, installed onto the PreToolUse chain by
         * {@see resolveHookManager()}. Null leaves the built-in hooks as the
         * only gating layer, which is what every caller got before
         * crush_code.md Phase 1 item 2. @see withPermissionGate()
         */
        private readonly ?PermissionGate $permissionGate = null,
        /**
         * Answers a {@see \SugarCraft\Crush\Hooks\HookResult::ask()} raised by
         * any PreToolUse hook — the gate's Ask decisions included.
         * @see withPermissionApprover()
         */
        private readonly ?\Closure $permissionApprover = null,
        /**
         * The cross-session memory store whose PROJECT-scope notes are folded
         * into every system prompt this backend builds (crush_code.md Phase 5
         * item 9). Forwarded straight onto {@see App::$memoryStore}, which is
         * where {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} reads it.
         *
         * Null means no memory block at all, which is what every caller got
         * before this. @see withMemoryStore()
         */
        private readonly ?MemoryStore $memoryStore = null,
        /**
         * The rule packs the CURRENT SESSION turned off with `/rules`, forwarded
         * onto {@see App::$rulesState} on every {@see complete()} so the splice in
         * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} subtracts them.
         *
         * This is the per-turn carry the skills already make with
         * {@see $skills} at the `withEnabledSkills()` site below, and it exists for
         * the same structural reason: this backend rebuilds the App from scratch on
         * every turn, so anything that changes the prompt has to be re-attached
         * here or it silently stops applying after the first turn.
         *
         * Null means no pack is turned off, which is what every caller that predates
         * rulebooks gets. `withRulesState()` is how a launch installs one; the
         * object is shared by reference rather than copied — see
         * {@see \SugarCraft\Crush\Context\RulesState} for why a toggle made in
         * `Chat` has to be visible to the instance that builds the next turn.
         */
        private readonly ?RulesState $rulesState = null,
        /**
         * The session's dollar ceiling, copied IN by
         * {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()} on the
         * clone that dispatches a turn (E20). Null — what every construction
         * site that never calls {@see withSpendCap()} gets — means no mid-turn
         * check: the loop runs its bounded course exactly as it did before.
         *
         * This is the half of the spend cap that lives BELOW the fork. The
         * pre-flight refusal ({@see \SugarCraft\Crush\Chat::spendCapRefusal()})
         * can only stop a turn that has not started; a turn is up to
         * `$maxSteps` billed calls, and the per-step figures are produced
         * inside the pcntl-forked child, where nothing but this field and
         * {@see $sessionSpendAtStartUsd} can see them between steps. It is a
         * COPY, not a shared object, on purpose: the child must know the cap
         * at the instant of its own birth, and a by-reference session total
         * mutating under a running loop would make "which step was refused"
         * unanswerable.
         *
         * The check is a SPEND-ESTIMATE ABORT at step boundaries — never a
         * wall-clock kill. No provider call in flight is aborted, no total
         * timeout is armed, and the fork's idle-timeout discipline
         * (per-frame, reset by every token/reasoning/event frame) is
         * untouched: an in-flight HTTP request finishes on its own terms and
         * its cost lands in the sum before the next boundary is judged. That
         * is the standing rule against blanket LLM-call timeouts, honored
         * here rather than excepted.
         */
        private readonly ?float $spendCapUsd = null,
        /**
         * The session's reported spend WHEN THE TURN STARTED, paired with
         * {@see $spendCapUsd}: the cap is a SESSION ceiling, the loop only
         * sees this turn's steps, and the breach arithmetic needs the
         * baseline or every turn would restart its count at zero.
         */
        private readonly float $sessionSpendAtStartUsd = 0.0,
        /**
         * Set ONLY on the copy {@see turnTools()} binds into a
         * {@see \SugarCraft\Crush\Tools\DelegatesToEngine} tool: answers the
         * DELEGATING turn's session spend right now — its baseline plus every
         * step and every delegated run it has billed so far (audit B4).
         *
         * WHY: a Task sub-agent runs through this engine's own loop, and
         * before this it inherited the caller's {@see $sessionSpendAtStartUsd}
         * verbatim, so its cap check ignored everything the calling turn had
         * already spent — the steps before the Task call and any Task that
         * finished before it. The probe is read ONCE, when the delegated run
         * starts ({@see runTurn()}), which keeps the doctrine above: the run
         * knows the session's spend at the instant of its birth, as a copy,
         * and a figure moving under a running loop never decides a step.
         *
         * A forked sibling is the one thing it cannot see: parallel Task
         * calls of the same step each run in their own child, born at the
         * same instant, so none has another's spend in this figure. That
         * live part comes from {@see $siblingSpend} instead (audit B4-rem).
         */
        private readonly ?\Closure $turnSpendProbe = null,
        /**
         * Set ONLY on a delegated run that was forked as one member of a
         * concurrent group ({@see \SugarCraft\Crush\Runtime::executeConcurrently()},
         * via {@see \SugarCraft\Crush\Tools\SharesSiblingSpend}): the group's
         * shared spend file, seen as this member (audit B4-rem).
         *
         * {@see runTurn()} records every step it bills onto it AS IT IS BILLED,
         * and adds what the other members have recorded to every spend-cap
         * check. Before it, siblings were blind to each other: each started
         * from the same fork-instant baseline and counted only itself, so a
         * batch of N parallel Tasks could overshoot the cap by N-1 runs' worth
         * before the caller's own boundary caught it. The records are also
         * what the parent bills for a member whose child died before it could
         * report ({@see \SugarCraft\Crush\Runtime}'s crash arm).
         *
         * This is the one figure that moves under a running loop, and that is
         * deliberate: it is the siblings' spend, which by construction is not
         * in the baseline copy. The doctrine above still holds for the
         * baseline — it is read once, at birth.
         */
        private readonly ?SiblingSpendLedger $siblingSpend = null,
        /**
         * The compaction budgets every turn's {@see App} is built with —
         * today read for the per-skill and combined budgets that decide which
         * enabled skill bodies {@see Runtime::planEnabledSkills()} splices
         * into the system prompt and which it defers (audit R1).
         *
         * Before this field the per-turn App was built with NO compactor
         * config at all, so the engine's skill budget was whatever
         * {@see Runtime} fell back to, decided in a different file from the
         * launch notice that names the deferred skills
         * ({@see \SugarCraft\Crush\Cli\Bootstrap}'s prompt-budget report) and
         * from {@see \SugarCraft\Crush\Chat}'s own compactor: three readers
         * that agreed only because each happened to default to
         * {@see CompactorConfig::new()}. The `compaction.*` and
         * `contextPruning.*` settings keys (roadmap N-P4b) feed it now: a
         * launch hands its session's config through
         * {@see withCompactorConfig()}, and null resolves to
         * {@see CompactorConfig::fromSettings()} over the merged user config
         * at each read ({@see compactorConfig()}) — the same keys
         * `Bootstrap::chat()` reads, so `-p`, a server session and an
         * embedder price their turns as the TUI does.
         */
        private readonly ?CompactorConfig $compactorConfig = null,
        /**
         * The prompt-cache health diagnostic's state (P10.S3): the streak of
         * zero-cache step reports and whether its one-time notice has gone
         * out. One instance per session, SHARED by every clone — mutate()
         * carries the reference forward — because the streak spans turns and
         * a notice one clone raised must not be raised by the next. Fed only
         * while the provider marks the model's requests
         * ({@see observeCacheHealth()}); carried across the fork on the
         * result frame.
         */
        private readonly CacheHealthWatch $cacheHealth = new CacheHealthWatch(),
        /**
         * Told each provider response's usage the moment {@see runTurn()}
         * bills it — the same instant {@see $siblingSpend} records it. Set
         * only on a delegated run, by
         * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}, so its Agents
         * row can count tokens while the run works instead of reading 0 until
         * it ends. Display-only: nothing it does feeds a cap decision.
         *
         * @var (\Closure(?Usage): void)|null
         */
        private readonly ?\Closure $stepUsageObserver = null,
        /**
         * Step 0.16: how many delegated runs (`Task` members of one parallel
         * group) each turn's {@see Runtime} may have alive at once — the rest
         * queue for a slot. Null takes
         * {@see \SugarCraft\Crush\Agents\AgentPoolConfig::$maxConcurrent}'s
         * default (5). @see withMaxConcurrentDelegations()
         */
        private readonly ?int $maxConcurrentDelegations = null,
        /**
         * Step 0.13-a: the session the turns this backend runs belong to,
         * stamped per dispatch by
         * {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()} so it
         * follows `/resume`, `/branch` and Ctrl+Tab. Forwarded onto every
         * turn's {@see App::$sessionId}, from where it reaches the hook
         * chain's `sessionId` and every provider request's
         * {@see \SugarCraft\Crush\Providers\CompleteRequest::$sessionId}
         * (the session-affinity header). Null — no session — leaves both
         * empty, as before. @see withSessionId()
         */
        private readonly ?string $sessionId = null,
        /**
         * Roadmap 2.4-2: the model every summary this backend writes goes to
         * — the in-turn step summary and {@see summariseAsync()} — or null
         * for the turn's own model, which is what makes the summary's prefix
         * a cached one. Resolved ONCE, at launch, from
         * `$SUGARCRUSH_SUMMARY_MODEL` then the `summaryModel` key
         * ({@see \SugarCraft\Crush\Cli\Bootstrap::summaryModel()}), so a turn
         * never re-reads a key documented as read at launch.
         * @see withSummaryModel()
         */
        private readonly ?string $summaryModel = null,
        /**
         * Roadmap 2.2-2: the session's {@see \SugarCraft\Crush\Context\Pruning\ContextLedger}
         * as the host holds it before this turn — what earlier turns pruned
         * or summarised out of the model's view, and the refs their tool
         * results keep. Seeded onto every turn's App ({@see sessionApp()}),
         * so the first request is projected through it and the turn's own
         * over-budget relief extends it rather than starting over; the turn
         * hands back the ledger it ended with on its reply
         * ({@see Message::$contextLedger}). Null — a host that keeps no
         * ledger (`-p`, a background run) — runs exactly as before: every
         * turn starts from an empty one and nothing comes back.
         * Never inherited by a delegated sub-agent ({@see turnTools()}).
         * @see withContextLedger()
         */
        private readonly ?\SugarCraft\Crush\Context\Pruning\ContextLedger $contextLedger = null,
        /**
         * Step 4.2: set ONLY on the copy {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}
         * runs a delegated sub-agent on — the hook that holds each of that
         * run's calls to the preset's own declaration, argument halves
         * included. {@see resolveHookManager()} registers it ahead of the
         * permission gate, on a `withoutHooks()` turn too. Null — every
         * top-level turn — adds nothing. @see withSubAgentGrant()
         */
        private readonly ?\SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook $subAgentGrant = null,
        /**
         * Roadmap P-D1: set ONLY on the copy
         * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} runs a delegated
         * sub-agent on — that agent's {@see MailboxTurnInbox}, drained at the
         * top of every step of {@see completeTranscript()} and
         * {@see complete()} so a message sent to the running agent reaches it
         * at its next step boundary. Null — every top-level turn, whose
         * mid-turn messages arrive on the fork's socket instead
         * ({@see SocketSteerInbox}) — drains nothing. @see withTurnInbox()
         */
        private readonly ?TurnInbox $turnInbox = null,
        /**
         * Roadmap 4.7-2: the context-window share at which a turn tells the
         * model to wrap up or hand off, and {@see WRAP_UP_STOP_MARGIN} points
         * past it is stopped. Null — not chosen — is the entry point's
         * default: on for {@see completeTranscript()}, the delegated runs'
         * entry, at {@see SUB_AGENT_WRAP_UP_PERCENT}; off for the session's
         * own turns. 0 is off. @see withWrapUpAt()
         */
        private readonly ?int $wrapUpAtPercent = null,
        /**
         * Step 1.A-2: the session's prompt memo — the PerSession system-prompt
         * layers (static `<env>`, repo map, project memory, standing
         * instruction slab) read once per session and frozen until a refresh
         * point. ONE per backend, SHARED by every clone (mutate() carries the
         * reference forward, as it does {@see $cacheHealth}), and on the
         * PARENT side of the fork: {@see completeAsync()} primes it before
         * forking, so every turn's child inherits the warm entries instead of
         * re-walking the repository. Each turn's {@see Runtime} reads it
         * through {@see Runtime::withSessionPromptMemo()}.
         */
        private readonly SessionPromptMemo $sessionPromptMemo = new SessionPromptMemo(),
        /**
         * Roadmap 4.1-1: the reasoning effort every request of this backend's
         * turns asks for — set ONLY on the copy
         * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} runs a sub-agent
         * on, from its preset's `effort:`. It reaches the wire through each
         * turn's {@see App::$reasoningEffort} and
         * {@see \SugarCraft\Crush\Providers\CompleteRequest::$reasoningEffort},
         * the per-call tier the provider resolves before its own. Null — every
         * top-level turn — asks nothing. @see withReasoningEffort()
         */
        private readonly string|float|null $reasoningEffort = null,
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self($provider, $model);
    }

    /**
     * Rebuild with the named constructor fields replaced — the ONE path every
     * wither takes.
     *
     * The withers used to each spell out the full positional argument list,
     * and two of them (withMemoryStore, withPermissionApprover) were never
     * extended when the spend-cap pair joined the constructor, so calling
     * either after {@see withSpendCap()} silently took the mid-turn cap off
     * (audit B5). Carrying every promoted field forward by NAME means a new
     * constructor parameter is preserved by every wither automatically, and a
     * misspelled key in $changes is an "Unknown named parameter" Error rather
     * than a silently-ignored change.
     *
     * get_object_vars() is the field roster because every instance property
     * of this class is constructor-promoted (the only other state is static);
     * a future non-promoted instance property would fail loudly here, not
     * corrupt a rebuild.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * The real context window of the model this backend completes against —
     * the one number {@see \SugarCraft\Crush\Chat}'s context tiers are
     * percentages of (crush_code.md Phase 5 item 4).
     *
     * Every provider has implemented this correctly for a long time and
     * nothing could read it: `Chat` holds a {@see Backend}, and `Backend`
     * exposes only `complete()`/`completeAsync()`. This backend is the one
     * that owns a {@see ProviderInterface}, so it is the one that can answer,
     * and it answers by delegating rather than caching — a provider's window
     * is model-dependent (see {@see \SugarCraft\Crush\Providers\OpenAIProvider})
     * and this class must not hold a second opinion about it.
     */
    public function contextWindow(): int
    {
        return $this->provider->contextWindow();
    }

    /**
     * The session's prompt memo (step 1.A-2) — shared by every clone of this
     * backend, primed before each turn's fork. Exposed so an owner can drop a
     * session's layers explicitly ({@see SessionPromptMemo::forget()}) on
     * top of the refresh points the backend detects itself.
     */
    public function sessionPromptMemo(): SessionPromptMemo
    {
        return $this->sessionPromptMemo;
    }

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool> $tools
     */
    public function withTools(array $tools): self
    {
        return $this->mutate(['tools' => $tools]);
    }

    /**
     * The model this backend's requests actually go to, when its provider
     * talks to one other than {@see $model} and has learned which (audit
     * 15b-35) - the SGLang server's served model on a default-id launch.
     * Null otherwise, and for any provider that cannot say.
     *
     * The parent learns it in one of two ways: the provider's own discovery
     * (the first frame's {@see contextWindow()} normally runs it in this
     * process), or the forked turn child reporting it on its result frame
     * ({@see settleFromResultFrame()}). Never performs I/O.
     */
    public function servedModel(): ?string
    {
        return $this->provider instanceof ReportsServedModel ? $this->provider->servedModel() : null;
    }

    /**
     * The model id this backend was BUILT with — the configured one, which
     * {@see servedModel()} overrides when the provider has learned it talks to
     * another. Read by `Renderer`'s status-bar model segment as the fallback,
     * the same order `Tui\Renderer::modelLabel()` applies (audit 15b-35).
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * A copy that sends every request to `$model` (roadmap N-P3b; roadmap
     * 4.1-1 reuses it for a sub-agent's model).
     *
     * The id reaches the wire through {@see App::$model}, which every turn's
     * App is built from ({@see sessionApp()}). THE PROVIDER MOVES WITH IT when
     * it can ({@see RebindsModel}): {@see contextWindow()} — the denominator
     * of every context tier — and any rate the provider reads off its own
     * configured id then answer for `$model`, not for the model it was built
     * with. A provider whose model is fixed by its server (SGLang) cannot
     * rebind and keeps answering for what it serves; a caller that must not
     * relabel such a model compares {@see servedModel()} first, as `Task`
     * does. `Chat`'s `/model <provider> <model>` still rebuilds through the
     * launch factory first and applies this on top, so the explicit choice
     * wins even where `--model` or `$SUGARCRUSH_MODEL` outrank the persisted
     * one.
     *
     * @throws \InvalidArgumentException for an empty or blank id
     */
    public function withModel(string $model): self
    {
        if (trim($model) === '') {
            throw new \InvalidArgumentException('A model id cannot be empty.');
        }

        if ($model === $this->model) {
            return $this;
        }

        return $this->mutate([
            'model' => $model,
            'provider' => $this->provider instanceof RebindsModel ? $this->provider->withModel($model) : $this->provider,
        ]);
    }

    /**
     * The same engine, asking every request for `$effort` (roadmap 4.1-1) —
     * see {@see $reasoningEffort}. Null asks nothing.
     */
    public function withReasoningEffort(string|float|null $effort): self
    {
        return $this->mutate(['reasoningEffort' => $effort]);
    }

    /** @see $reasoningEffort */
    public function reasoningEffort(): string|float|null
    {
        return $this->reasoningEffort;
    }

    /**
     * Whether a request's {@see \SugarCraft\Crush\Providers\CompleteRequest::$reasoningEffort}
     * reaches this backend's provider at all. Only SGLang reads it today
     * (bare, or as a fallback chain's primary, which every request goes to
     * first); every other provider drops the field, so `Task` leaves a
     * preset's effort off there and says so rather than run it as if
     * honoured.
     */
    public function honoursReasoningEffort(): bool
    {
        return $this->primaryProvider() instanceof \SugarCraft\Crush\Providers\SglangProvider;
    }

    /**
     * Whether this backend's provider talks to a server that runs ONE model
     * whatever a request names (SGLang, bare or as a fallback chain's
     * primary), so a different model id can never be honoured on it — the
     * request would reach the same model under a wrong label, or be refused
     * by the server. Answered from the provider's type alone: no I/O.
     * `Task` runs a delegation that asks for another model on the session's
     * own model there (see {@see servedModel()} for the name it serves).
     * Every other provider names its model per request and may accept an id
     * no table here knows.
     */
    public function servesOneModel(): bool
    {
        return $this->primaryProvider() instanceof \SugarCraft\Crush\Providers\SglangProvider;
    }

    /** The provider every request goes to first: a fallback chain's primary, else the provider itself. */
    private function primaryProvider(): ProviderInterface
    {
        return $this->provider instanceof \SugarCraft\Crush\Providers\FallbackProvider
            ? $this->provider->primary()
            : $this->provider;
    }

    /**
     * The session this backend's turns run as, or null — see {@see $sessionId}.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * The provider this engine completes against — read by
     * {@see \SugarCraft\Crush\Agents\EngineExecutor} to refuse a workflow stage
     * on the offline echo fallback rather than pass echoed text off as work.
     */
    public function provider(): ProviderInterface
    {
        return $this->provider;
    }

    /**
     * The tools this engine was built with, UNBOUND — the list a delegated
     * sub-agent inherits when its preset declares no grant of its own.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function withSkills(array $skills): self
    {
        return $this->mutate(['skills' => $skills]);
    }

    /**
     * Attach the discovered {@see SkillRegistry} (built-in + user + project +
     * foreign-imported skills, see {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()})
     * so it reaches {@see App::$availableSkills} on every {@see complete()}
     * call — the seam {@see \SugarCraft\Crush\Cli\Bootstrap} uses to make
     * skills discovered from ~/.claude/skills, {project}/.claude/skills,
     * {project}/.opencode/skills, and ~/.config/opencode/skills (see {@see
     * \SugarCraft\Crush\Skills\ForeignSkillDiscovery}) actually visible to a
     * real `bin/sugarcrush` run instead of only to their own unit tests.
     */
    public function withSkillRegistry(SkillRegistry $skillRegistry): self
    {
        return $this->mutate(['skillRegistry' => $skillRegistry]);
    }

    /**
     * Attach the session's shared {@see InstructionFileLoader} so it reaches
     * {@see App::$instructionLoader} on every {@see complete()} call — the
     * seam that makes a repo-root CLAUDE.md/AGENTS.md actually reach the
     * model's system prompt on a real `bin/sugarcrush` run rather than only
     * on an on-touch Read/Edit/Glob of that same directory.
     */
    public function withInstructionLoader(InstructionFileLoader $instructionLoader): self
    {
        return $this->mutate(['instructionLoader' => $instructionLoader]);
    }

    public function withHooks(HookManager $hookManager): self
    {
        // An explicit hook manager always wins and clears any prior opt-out.
        return $this->mutate(['hookManager' => $hookManager, 'hooksDisabled' => false]);
    }

    /**
     * Attach the 6-mode {@see PermissionGate} — crush_code.md Phase 1 item 2's
     * consolidation seam, sibling to {@see withHooks()}.
     *
     * Before this, the gate (with its rules, its Auto-mode 3-strike circuit
     * breaker and its unconditional `rm -rf /` refusal) had exactly ONE
     * consumer, {@see \SugarCraft\Crush\Agents\AgentManager}'s sub-agents,
     * while the main loop got only {@see HookManager}'s built-ins. Attaching
     * it here makes ONE gating layer serve both paths, without deleting the
     * built-ins: they stay registered as an additional, narrower check layer
     * (see {@see PermissionGateHook} for the ordering rationale and why both
     * orders are fail-closed).
     *
     * Installed onto the PreToolUse chain rather than consulted directly by
     * this class, because the hook chain is already the single point BOTH live
     * tool pipelines gate on — and it is the only one of the two that already
     * knows how to suspend a call on an ASK.
     *
     * Deliberately does NOT clear {@see withoutHooks()}'s opt-out the way
     * {@see withHooks()} does: `->withoutHooks()->withPermissionGate($g)` is a
     * coherent request for gate-only guarding, and silently re-registering the
     * built-ins would be this method answering a question it was not asked.
     */
    public function withPermissionGate(PermissionGate $permissionGate): self
    {
        return $this->mutate(['permissionGate' => $permissionGate]);
    }

    /**
     * The gate this backend was built with, or null when it has none.
     *
     * Exists so a caller REPLACING the backend can carry the launch's one gate
     * across: {@see \SugarCraft\Crush\Chat}'s Ctrl+P "Switch model" builds a
     * whole new backend, and without a reader for this it had no way to hand
     * the new one anything but a freshly-constructed second gate — which
     * resets the Auto-mode strike counters and, if the config changed
     * underneath the session, puts the two live tool paths on two different
     * modes. A bare accessor rather than a `get` prefix, per the project
     * convention.
     */
    public function permissionGate(): ?PermissionGate
    {
        return $this->permissionGate;
    }

    /**
     * Hold every call the turns of this copy make to a delegated sub-agent's
     * own tool declaration (step 4.2) — see {@see \SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook} for why
     * the name-narrowed roster alone left `Bash(git *)` meaning "any Bash".
     *
     * A SECOND GATE, NEVER A REPLACEMENT: the session's {@see PermissionGate}
     * still judges every call the grant admits, and a call either refuses is
     * denied. Like the gate it survives {@see withoutHooks()}. Null removes it.
     */
    public function withSubAgentGrant(?\SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook $grant): self
    {
        return $this->mutate(['subAgentGrant' => $grant]);
    }

    /**
     * Attach the approver that settles an ASK — from {@see PermissionGateHook}
     * or from any other PreToolUse hook — into a real allow/deny.
     *
     * {@see Runtime::run()} has carried this parameter since the blocking
     * permission prompt was built, but this class passed a hard-coded `null`
     * for it, so on the engine path EVERY ask resolved to "Permission required
     * and no approver is attached to this run" (see {@see Runtime::settleAsk()}).
     * That is fail-closed and correct, but it also meant an Ask-producing
     * permission mode was indistinguishable from a deny-everything one.
     *
     * The approver answers with literal `true` (allow once) or an
     * {@see \SugarCraft\Crush\Permissions\ApprovalVerdict}; anything else is
     * a feedback-less refusal — see {@see Runtime::settleAsk()} on why a
     * truthy cast is not enough. A verdict carries the two facts a bool
     * cannot: whether anybody answered (a question the turn ended underneath
     * reaches the model as `Permission required:`, not as a refusal) and the
     * model-visible text about a refusal (a user's note, a grandchild's
     * reason), which reaches the model through
     * {@see \SugarCraft\Crush\Hooks\HookManager::resolveAsk()}'s feedback.
     *
     * WHO CALLS THIS — measured, not assumed
     * (`grep -rn withPermissionApprover src/ bin/`):
     *
     * 1. THE CONSOLE PATHS.
     *    {@see \SugarCraft\Crush\Cli\NonInteractive::consoleBackend()} — the
     *    `-p` one-shot's only route to a backend — builds it through
     *    {@see \SugarCraft\Crush\Cli\Bootstrap::backend()} with
     *    `$consolePermissionPrompt: true`, attaching
     *    {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt}. So does the
     *    background-session daemon
     *    ({@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::backend()}),
     *    where the same tty probe resolves the other way — its fd 0 is
     *    `/dev/null` from the spawn site — and the ASK becomes an explicit
     *    refusal naming the tool, the mode and the remedies in the session's
     *    log rather than an opaque one.
     * 2. NOT THE TUI, AND IT DOES NOT NEED TO. {@see \SugarCraft\Crush\Chat}
     *    starts its engine turns through {@see completeInteractive()} (1.C-1
     *    built the channel, 1.C-2 wired the caller): the forked child attaches
     *    a {@see ChildChannel} approver IN PLACE OF whatever approver this
     *    backend carries, puts each ASK to the parent as a
     *    {@see \SugarCraft\Crush\Events\PermissionAsked} frame, and settles
     *    it with the {@see \SugarCraft\Crush\Permissions\ApprovalVerdict} the
     *    modal's answer comes back as. The parent owns the policy, so an
     *    approver attached here never sees a TUI turn's asks.
     *
     * An attached approver works on every SYNCHRONOUS {@see complete()} caller
     * (the two above, embedders, {@see completeAsyncBlocking()}'s no-pcntl
     * fallback, tests) and on a {@see completeAsync()} turn, whose child
     * inherits it; only {@see completeInteractive()} replaces it.
     *
     * @param \Closure(\SugarCraft\Crush\Tools\ToolCall, \SugarCraft\Crush\Hooks\HookResult): (bool|\SugarCraft\Crush\Permissions\ApprovalVerdict) $approver
     */
    public function withPermissionApprover(\Closure $approver): self
    {
        return $this->mutate(['permissionApprover' => $approver]);
    }

    /**
     * Escape hatch for callers that deliberately want an UNGUARDED engine —
     * no built-in hooks, no custom manager. Everything else is safe-by-default
     * (see {@see resolveHookManager()}), so opting out is an explicit choice.
     */
    public function withoutHooks(): self
    {
        return $this->mutate(['hookManager' => null, 'hooksDisabled' => true]);
    }

    /**
     * Anchor this backend's turns at $root, so {@see Runtime}'s environment
     * block and its {@see \SugarCraft\Crush\Hooks\HookContext}s name the same
     * directory the tools {@see withTools()} received are jailed to.
     *
     * Separate from {@see withWorktreeRoot()} on purpose: that one also
     * re-jails the tools and registers a Bash-escape guard, and sets this
     * same root as part of confining a sub-agent (roadmap 4.9), while this
     * one is purely the reported/gated root and leaves the tools as built.
     */
    public function withRoot(?string $root): self
    {
        return $this->mutate(['root' => $root]);
    }

    /**
     * The memory store whose project-scope notes reach the model's system
     * prompt. @see App::$memoryStore
     */
    public function withMemoryStore(?MemoryStore $memoryStore): self
    {
        return $this->mutate(['memoryStore' => $memoryStore]);
    }

    /**
     * The session's rulebook toggle set. @see App::$rulesState
     *
     * A reader as well as a writer because one instance has to be reachable from
     * both owners of the same set: {@see \SugarCraft\Crush\Chat} builds the box (or
     * is handed the launch's) and this object reads it per turn, and a
     * Ctrl+P provider switch rebuilds the backend wholesale — without a reader the
     * new copy could not be given the old copy's set, and every pack the user
     * turned off would come back on mid-session. Mirrors
     * {@see permissionGate()}'s reason exactly.
     */
    public function rulesState(): ?RulesState
    {
        return $this->rulesState;
    }

    /**
     * Install the launch's rulebook toggle set, for the per-turn carry
     * {@see complete()} makes onto the App.
     */
    public function withRulesState(?RulesState $rulesState): self
    {
        return $this->mutate(['rulesState' => $rulesState]);
    }

    /**
     * The compaction budgets this backend's turns are priced against.
     * Null restores the default ({@see CompactorConfig::new()}).
     * @see $compactorConfig
     */
    public function withCompactorConfig(?CompactorConfig $compactorConfig): self
    {
        return $this->mutate(['compactorConfig' => $compactorConfig]);
    }

    /**
     * The compaction budgets the next turn's {@see App} is built with — the
     * configured one, else the one the `compaction.*` / `contextPruning.*`
     * settings describe ({@see CompactorConfig::fromSettings()}, roadmap
     * N-P4b; {@see CompactorConfig::new()} when none is set) — with the
     * per-model absolute caps for this backend's model applied
     * ({@see CompactorConfig::forModel()}, roadmap 2.9). With no override
     * naming the model that is the configured instance itself.
     *
     * The settings fallback is what a backend nobody handed a config gets —
     * `-p`, a server session, an embedder — so they price their turns against
     * the same settings the TUI's `Bootstrap::chat()` hands its session.
     */
    public function compactorConfig(): CompactorConfig
    {
        return ($this->compactorConfig ?? CompactorConfig::fromSettings(self::userConfig()))
            ->forModel($this->model, $this->provider->name());
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return $this->mutate(['maxSteps' => max(1, $maxSteps)]);
    }

    /**
     * Roadmap 4.7-2 (Zed's sub-agent context guard): once a request this
     * backend's turn SENT used $percent of the context window or more, the
     * model is told once to wrap up or hand off; a request at
     * {@see WRAP_UP_STOP_MARGIN} points past it after that stops the run.
     * 0 turns it off; {@see completeTranscript()} uses
     * {@see SUB_AGENT_WRAP_UP_PERCENT} unless this chose otherwise.
     */
    public function withWrapUpAt(int $percent): self
    {
        return $this->mutate(['wrapUpAtPercent' => max(0, min(100, $percent))]);
    }

    /**
     * The same engine, delivering $inbox's messages into its turns at each
     * step boundary — see {@see $turnInbox}. Bound by
     * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} on the run it
     * delegates; null takes it off.
     */
    public function withTurnInbox(?TurnInbox $inbox): self
    {
        return $this->mutate(['turnInbox' => $inbox]);
    }

    /**
     * The per-dispatch install of E20's mid-turn spend cap: the dollar
     * ceiling plus the session spend the turn STARTS at, as a pair. Returns a
     * clone; the cap is a launch-again decision, never a mutation of the
     * backend a previous turn is still running.
     *
     * Called by {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()} on
     * the EngineBackend clone it is about to dispatch, and by nothing else in
     * `src/` — which is the whole point of the PAIR: a cap copied without the
     * baseline would compare each turn's fresh steps against a session
     * ceiling and under-count, and a baseline without a cap answers nothing.
     * Pass null to take the check off (a capped-but-escaped session never
     * dispatches anyway; the pre-flight refusal stops it first).
     */
    public function withSpendCap(?float $capUsd, float $sessionSpendAtStartUsd = 0.0): self
    {
        return $this->mutate(['spendCapUsd' => $capUsd, 'sessionSpendAtStartUsd' => $sessionSpendAtStartUsd]);
    }

    /**
     * The same engine, recording each step's spend onto $ledger and reading
     * its siblings' spend off it at every cap check — see {@see $siblingSpend}.
     * Bound by {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} on the run it
     * delegates; null takes it off.
     */
    public function withSiblingSpend(?SiblingSpendLedger $ledger): self
    {
        return $this->mutate(['siblingSpend' => $ledger]);
    }

    /**
     * The same engine, reporting each step's usage to $observer as it is
     * billed — see {@see $stepUsageObserver}. Null takes it off.
     *
     * @param (\Closure(?Usage): void)|null $observer
     */
    public function withStepUsageObserver(?\Closure $observer): self
    {
        return $this->mutate(['stepUsageObserver' => $observer]);
    }

    /**
     * The same engine, capping each turn's concurrent delegated runs at
     * $slots (clamped to at least 1) — see {@see $maxConcurrentDelegations}.
     * Null restores the {@see \SugarCraft\Crush\Agents\AgentPoolConfig}
     * default.
     */
    public function withMaxConcurrentDelegations(?int $slots): self
    {
        return $this->mutate(['maxConcurrentDelegations' => $slots === null ? null : max(1, $slots)]);
    }

    /**
     * The session's own agent's mailbox (roadmap 4.4), drained at the main
     * turn's step boundaries so a sub-agent's `SendMessage` reply reaches it
     * without a `Subagents list|wait` — or null with no session, no owned
     * home, or a session id no mailbox can be named by.
     */
    public function mainMailbox(): ?MailboxTurnInbox
    {
        if ($this->sessionId === null) {
            return null;
        }
        $inbox = \SugarCraft\Crush\Agents\Live\AgentInbox::forSession($this->sessionId);
        if ($inbox === null) {
            return null;
        }
        try {
            return MailboxTurnInbox::forMain($inbox);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The same engine, running its turns as session $sessionId — see
     * {@see $sessionId}. A blank id is no session.
     */
    public function withSessionId(?string $sessionId): self
    {
        return $this->mutate(['sessionId' => $sessionId === '' ? null : $sessionId]);
    }

    /**
     * The same engine, running its next turn over the session ledger $ledger
     * (roadmap 2.2-2) — see {@see $contextLedger}. Null runs without one.
     */
    public function withContextLedger(?\SugarCraft\Crush\Context\Pruning\ContextLedger $ledger): self
    {
        return $this->mutate(['contextLedger' => $ledger]);
    }

    /** The session ledger the next turn starts from, or null — see {@see $contextLedger}. */
    public function contextLedger(): ?\SugarCraft\Crush\Context\Pruning\ContextLedger
    {
        return $this->contextLedger;
    }

    /**
     * Confine this backend to a sub-agent's git worktree: every path-resolving
     * tool is re-jailed to `$worktreeRoot`, and BashEscapeDenyHook is
     * registered so Bash commands that name paths outside it are refused.
     *
     * THE TOOLS (audit F-J5). Glob, Grep, Lsp, Read, Edit, Write and Bash each
     * take an optional {@see AgentPathJail}, but the tool list this backend
     * holds was built once, on the main checkout's root, so a jail that only
     * reached the Bash HOOK left every one of them answering from — and
     * writing to — the main checkout: a sub-agent's Grep found stale or
     * foreign files, and its Edit then targeted paths its own tree lacks.
     * Each {@see AcceptsWorktreeJail} tool is now swapped for a copy confined
     * to the worktree; any other tool (Task, WebFetch, an MCP bridge) resolves
     * no workspace path and is kept as it is. This half applies even when the
     * hooks are disabled: containment of a tool's own path resolution is not a
     * hook, and `withoutHooks()` is not a request to search the wrong tree.
     *
     * THE HOOK. Without it Bash is confined only by the `cd $worktreeRoot`
     * prefix, which does NOT prevent escape via `cd /` or `..` traversal
     * within the command string itself. The returned backend owns a CLONE of
     * the hook manager (audit F-J5): the worktree-scoped guard belongs to the
     * sub-agent's backend only, and registering on the shared instance in
     * place gave the parent's chain the sub-agent's root as a deny boundary
     * too — a `with*()` that mutated its receiver.
     *
     * THE ROOT (roadmap 4.9). The backend's {@see withRoot()} root becomes the
     * worktree too, so what the run is TOLD matches where it works: the
     * environment block's directory and git fields, the instruction files,
     * and every hook context's `cwd` name the worktree rather than the main
     * checkout the parent session runs in.
     *
     * Wired by {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} for an
     * agent whose preset says `isolation: worktree` (crush_report Part II
     * #23), on a tree {@see \SugarCraft\Crush\Agents\WorktreeManager}
     * created for that run.
     *
     * @see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook
     */
    public function withWorktreeRoot(string $worktreeRoot): self
    {
        $jail = new AgentPathJail($worktreeRoot, new PathJailConfig());
        $changes = [
            'root' => $worktreeRoot,
            'tools' => array_map(
                static fn(mixed $tool): mixed => $tool instanceof AcceptsWorktreeJail
                    ? $tool->withWorktreeJail($jail)
                    : $tool,
                $this->tools,
            ),
        ];

        if (!$this->hooksDisabled) {
            $manager = $this->hookManager !== null
                ? clone $this->hookManager
                : new HookManager(new HookRegistry());
            $manager->registerBuiltIns();
            $manager->register(new BashEscapeDenyHook($worktreeRoot));
            $changes['hookManager'] = $manager;
            $changes['hooksDisabled'] = false;
        }

        return $this->mutate($changes);
    }

    /**
     * @param ?callable $onEvent Tool-lifecycle observer threaded straight into
     *                           {@see Runtime::run()} so every tool call this
     *                           bounded loop makes — including the ones on
     *                           intermediate steps whose messages get folded
     *                           back into $app and never reach the caller — is
     *                           observable while the turn is still running
     *                           (crush_feat.md §1 E1). Without it the caller
     *                           sees only $lastAssistant's text.
     *
     * @param ?callable $onToken Incremental-text observer, threaded straight
     *                           into {@see Runtime::run()} so it fires per
     *                           provider chunk while the turn runs. It used to
     *                           be called exactly ONCE here, after the whole
     *                           bounded loop had finished, with the finished
     *                           reply — which is why streaming was
     *                           indistinguishable from no streaming
     *                           (crush_code.md Phase 0 item 13).
     *
     *                           Deltas span the WHOLE turn, every step of the
     *                           agentic loop included, so a consumer that
     *                           concatenates them can end up with more text
     *                           than the returned Message (which is only the
     *                           LAST step's assistant content — the earlier
     *                           steps' prose is superseded by the tool results
     *                           it introduced). Consumers that render the
     *                           accumulation live are expected to reset it
     *                           when a {@see ToolStarted} arrives; see
     *                           {@see \SugarCraft\Crush\Chat::pumpLiveToolEvents()}.
     *
     * @param ?callable $onReasoning Optional live observer of the model's
     *                           reasoning and of bare progress, signature
     *                           `function(string $delta): void`. Threaded
     *                           straight into {@see \SugarCraft\Crush\Runtime::run()}
     *                           as its `$onProgress`, so a NON-empty delta is
     *                           thinking to paint and the EMPTY string is a
     *                           chunk that carried nothing showable. Kept out
     *                           of $onToken on purpose - see E456 on
     *                           {@see COMPLETE_TIMEOUT_SECONDS}, and the note
     *                           beside `$progressSink` below.
     *
     * @param ?callable $onHeartbeat Optional batch-progress heartbeat, signature
     *                           `function(): void`, threaded untouched into
     *                           {@see \SugarCraft\Crush\Runtime::run()}'s
     *                           `$onHeartbeat` and from there onto
     *                           {@see \SugarCraft\Crush\Providers\CompleteRequest::$onHeartbeat}
     *                           (E493's consumer half). THIS METHOD NEVER FIRES
     *                           IT and arms nothing — the provider's HTTP layer
     *                           does, from inside the blocking batch transfer,
     *                           at most once per second. Its only in-tree caller
     *                           that passes one is {@see runCompleteInChild()},
     *                           where each beat writes a bare `reasoning` frame
     *                           so the parent's idle deadline survives a batch
     *                           turn; sync callers without that deadline have no
     *                           reason to pass one, and leaving it null keeps
     *                           this call byte-identical to what it did before
     *                           E493 landed.
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onHeartbeat = null): Message
    {
        $transcript = [];
        $attachmentNotice = null;
        // Step 1.A-2: the in-process path observes refresh points and primes
        // the session memo exactly as the forked one does before its fork.
        $this->beginSessionTurn($history);
        $typed = $this->toTypedMessages($history, $attachmentNotice);

        // Audit 15b-15: an attachment the provider could not carry is reported
        // on the reply itself, so it reaches Chat's settle arm on BOTH paths -
        // returned here in-process, or across the fork's result frame
        // ({@see runCompleteInChild()}'s `attachmentNotice` key).
        // Roadmap 3.B-2: the turn starts from the session ledger with the
        // turn-start strategies applied — on this path as on the forked one.
        $engine = $this->contextLedger === null ? $this : $this->withContextLedger($this->turnStartLedger($typed));

        return $engine->runTurn($typed, $onToken, $onEvent, $onReasoning, $onHeartbeat, $transcript, inbox: $this->turnInbox)
            ->withAttachmentNotice($attachmentNotice);
    }

    /**
     * {@see complete()} over TYPED history, answering with the turn's whole
     * transcript as well as its reply — every assistant step with its tool
     * calls and every tool result, which the root-{@see Message} history
     * {@see complete()} takes cannot carry. It exists so a delegated run can
     * be RESUMED: {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} persists
     * the transcript of a run that ended without a report and feeds it back
     * here to continue exactly where it stopped.
     *
     * A failure mid-turn is re-thrown as {@see TurnInterrupted}, carrying the
     * transcript up to the last step that COMPLETED (a half-finished step's
     * calls are dropped, so the resumed model redoes that step rather than
     * reading results for calls it cannot see).
     *
     * `$onToken` receives the turn's prose deltas across EVERY step, the same
     * contract {@see complete()}'s has — which is what lets a delegated run be
     * shown live ({@see \SugarCraft\Crush\Agents\EngineExecutor::executeStream()}).
     *
     * `$onStep` receives the turn's {@see \SugarCraft\Crush\Events\StepStarted}
     * and {@see \SugarCraft\Crush\Events\UsageUpdated} events (see
     * {@see runTurn()}), which is how a delegated run can report its step,
     * context pressure and spend while it works.
     *
     * Roadmap 4.7-2: every caller of this method is a delegated run (a Task
     * sub-agent, a workflow or pool agent), so the context guard is ON here
     * unless {@see withWrapUpAt()} chose otherwise — at
     * {@see SUB_AGENT_WRAP_UP_PERCENT} of the window the run is told to wrap
     * up or hand off, and ten points later it is stopped. The stop is a
     * failure ({@see WRAP_UP_STOPPED}) on purpose: it arrives as a
     * {@see TurnInterrupted} with the transcript, so the delegating Task
     * reports it with the run's partial output and the resume id (4.7-1) —
     * resuming it is the hand-off.
     *
     * @param list<TypedMessage> $messages
     *
     * @throws TurnInterrupted
     */
    public function completeTranscript(array $messages, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onHeartbeat = null, ?callable $onToken = null, ?callable $onStep = null): TranscriptTurn
    {
        $transcript = $messages;
        $engine = $this->wrapUpAtPercent === null ? $this->withWrapUpAt(self::SUB_AGENT_WRAP_UP_PERCENT) : $this;

        try {
            // P-D1: a delegated run's mailbox, when TaskTool bound one.
            $reply = $engine->runTurn($messages, $onToken, $onEvent, $onReasoning, $onHeartbeat, $transcript, $onStep, inbox: $this->turnInbox);
        } catch (\Throwable $failure) {
            throw new TurnInterrupted($transcript, $failure);
        }

        return new TranscriptTurn($reply, $transcript);
    }

    /**
     * The bounded agentic loop behind {@see complete()} and
     * {@see completeTranscript()}. `$transcript` is kept current after every
     * completed step, so it is meaningful even when this throws.
     *
     * `$onStep`, signature `function(StepStarted|UsageUpdated $event): void`
     * (roadmap 1.C-4 / P-B1), is told twice per step: a
     * {@see \SugarCraft\Crush\Events\StepStarted} just before the step's
     * provider call, and a {@see \SugarCraft\Crush\Events\UsageUpdated}
     * the moment its response is billed. StepStarted carries the step's
     * {@see \SugarCraft\Crush\Context\ContextPressure} (roadmap 2.1): this
     * loop is where a turn's requests grow, step by step, so it is the only
     * place pressure can be seen before the request that overflows is sent.
     *
     * `$stopRequested` (roadmap 1.C-4, `cancel_soft`) is asked at every step
     * boundary once the step's tools have settled; true ends the turn there,
     * as a deliberate exit — no step-ceiling notice and no summary request,
     * because the person asked it to stop.
     *
     * `$inbox` (roadmap 1.C-3) carries messages that arrive while the turn
     * runs: drained at the top of every step and appended before the step's
     * provider call, and probed by the Runtime before each sequential tool
     * call, which skips the step's unstarted calls once one is waiting.
     *
     * @param list<TypedMessage> $messages
     * @param list<TypedMessage> $transcript
     * @param ?\Closure(): bool  $stopRequested
     */
    private function runTurn(array $messages, ?callable $onToken, ?callable $onEvent, ?callable $onReasoning, ?callable $onHeartbeat, array &$transcript, ?callable $onStep = null, ?\Closure $stopRequested = null, ?TurnInbox $inbox = null): Message
    {
        $transcript = $messages;

        // EVERY step's usage, not the last one's. This loop makes up to
        // $maxSteps provider calls per turn and each reports its own figure,
        // so the turn's cost is the SUM: a readout fed from $lastAssistant
        // alone would bill a five-tool turn as if it were the one final call
        // that answered without tools (crush_code.md Phase 5 item 7). A tool
        // that billed a provider itself (a Task sub-agent, audit B4) lands
        // here too, as it settles — see delegatedSpend().
        /** @var list<?Usage> $stepUsages */
        $stepUsages = [];

        // The session spend this turn is judged from: the copy Chat installed
        // ({@see withSpendCap()}), or — on a delegated run — the delegating
        // turn's spend as of NOW, read once (see $turnSpendProbe).
        $sessionSpendAtStartUsd = $this->turnSpendProbe !== null
            ? (float) ($this->turnSpendProbe)()
            : $this->sessionSpendAtStartUsd;
        // A forked group member also counts what its siblings have billed so
        // far (audit B4-rem) — read live, because none of it is in the
        // baseline above: they were all born at the same instant.
        $siblingSpend = $this->siblingSpend;
        $spentSoFarUsd = static function () use ($sessionSpendAtStartUsd, &$stepUsages, $siblingSpend): float {
            $spent = $sessionSpendAtStartUsd;
            foreach ($stepUsages as $stepUsage) {
                $spent += $stepUsage?->costUsd ?? 0.0;
            }

            return $spent + ($siblingSpend?->spentByOthers()?->costUsd ?? 0.0);
        };

        // Read once and hand to both resolvers, so a turn touches the config
        // file at most one time however many settings are resolved off it.
        $userConfig = self::userConfig();

        // @region build
        // One ledger per turn: a new turn is a new plan, so repeats are
        // never carried across the user's prompts (see ToolCallLoopGuard).
        $loopGuard = ToolCallLoopGuard::new();

        // Step 1.A-2: the session's prompt memo, so the PerSession layers are
        // the ones the parent primed (or the previous in-process turn read),
        // and the turn-context row is persisted by the step loop below rather
        // than re-appended to each request by Runtime::run().
        $runtime = $this->newRuntime(
            $this->resolveHookManager($loopGuard),
            self::parallelToolCallsEnabled($userConfig),
            self::parallelToolDeadlineSeconds($userConfig),
            self::maxOutputTokens($userConfig),
        )->withTurnContextPersisted();
        if ($inbox !== null) {
            $runtime = $runtime->withTurnInbox($inbox);
        }

        // Roadmap 3.B-3 / 3.D-2: the turn's own PreCompact chain, for the
        // compactions made INSIDE it — a ledger tool's call, a step summary.
        // The chain the Runtime gates with (one per turn), run here in the
        // turn's process: it is already off the render loop. Answers why it
        // refused (an ask fails closed: nobody can be asked from here), or
        // null to go on; a chain that throws refuses, as Chat's does.
        $compactionHooks = $this->resolveHookManager($loopGuard);
        $preCompact = function (string $trigger, string $focus) use ($compactionHooks): ?string {
            $event = \SugarCraft\Crush\Hooks\HookEvent::PreCompact;
            if (!$compactionHooks->hasHooksFor($event, $event->value)) {
                return null;
            }
            try {
                $verdict = $compactionHooks->preCompact(HookManager::eventContext(
                    $event,
                    ['trigger' => $trigger, 'custom_instructions' => $focus],
                    $this->sessionId ?? '',
                    $this->root ?? '',
                    $this->model,
                    $this->provider->name(),
                ));
            } catch (\Throwable $e) {
                return 'a PreCompact hook failed: ' . $e->getMessage();
            }

            return \SugarCraft\Crush\Host\CompactionService::preCompactRefusal($verdict);
        };
        // Roadmap 3.B-4: `/compact --self` — the person previews the model's
        // summary before its Compress call applies (a question on this turn's
        // own copy of the chain; no other turn's call is previewed).
        if (\SugarCraft\Crush\Tools\BuiltIn\Compress::isSelfCompaction(array_values($messages))) {
            $compactionHooks->register(new \SugarCraft\Crush\Context\Pruning\CompressPreviewHook());
        }
        // Its observe-only pair, once a step summary was applied (3.D-2):
        // `compact_summary` is what the model now reads in place of the rows.
        // Nothing it says is read back, and a chain that throws costs nothing.
        $postCompact = function (string $trigger, string $summary) use ($compactionHooks): void {
            $event = \SugarCraft\Crush\Hooks\HookEvent::PostCompact;
            if (!$compactionHooks->hasHooksFor($event, $event->value)) {
                return;
            }
            try {
                $compactionHooks->postCompact(HookManager::eventContext(
                    $event,
                    ['trigger' => $trigger, 'compact_summary' => $summary],
                    $this->sessionId ?? '',
                    $this->root ?? '',
                    $this->model,
                    $this->provider->name(),
                ));
            } catch (\Throwable) {
                // Observe-only: the summary already stands.
            }
        };

        $app = $this->sessionApp()
            // Roadmap 4.1-1: a sub-agent's preset effort, per request.
            ->withReasoningEffort($this->reasoningEffort)
            ->withTools(self::gatedLedgerTools(
                $this->turnTools($onReasoning, $onHeartbeat, $onEvent, $spentSoFarUsd, $contextLedger, $app, \SugarCraft\Crush\Tools\BuiltIn\Compress::isTriggered(array_values($messages))),
                $preCompact,
                $messages,
                $this->compactorConfig()->offersCompressUnprompted(),
            ))
            ->withMessages($messages);

        $lastAssistant = null;
        $lastImageBytes = null;
        $lastImageProtocol = null;

        // E707 (round 81): ORed across the turn's steps the way $stepUsages
        // sums across them - one step stopping at the output ceiling marks
        // the whole turn, because the reply the operator reads is the whole
        // turn's answer, not its last step's.
        $lengthStopped = false;

        // F2 (spawn-latency plan): WHICH break ended the loop decides whether
        // the turn was TRUNCATED. Before F2 the exhaustion exit — every step
        // spent while tool results were still pending — returned the last
        // assistant text SILENTLY, indistinguishable from a finished answer.
        // Both deliberate exits (model answered without tools; spend cap,
        // which writes its own notice) set a flag; no flag means the loop ran
        // out, and the message carries `stepsTruncated` so Chat can say so.
        $answeredWithoutTools = false;
        $stoppedBySpendCap = false;

        // The third deliberate exit: the repeat-call loop guard saw one call
        // reach its turn-ending repeat. Not a truncation in F2's sense — the
        // budget did not run out, the model was stuck — so it does not set
        // `stepsTruncated` (whose notice tells the operator to raise
        // `maxToolSteps`, the wrong remedy for a loop).
        $stoppedByLoopGuard = false;

        // The fourth: the person asked the turn to stop at the next step
        // boundary (`cancel_soft`, roadmap 1.C-4) — deliberate, so no
        // step-ceiling notice and no summary request.
        $stoppedSoftly = false;

        // Whether the runtime managed to emit anything incrementally, so the
        // end-of-turn fallback below stays a FALLBACK rather than a duplicate:
        // firing it after a stream that already delivered the same bytes would
        // paint the reply twice.
        $streamed = false;
        $tokenSink = $onToken === null ? null : static function (string $delta) use ($onToken, &$streamed): void {
            if ($delta === '') {
                return;
            }
            $streamed = true;
            $onToken($delta);
        };

        // E456's channel, threaded whole rather than filtered here: the EMPTY
        // delta is meaningful on this one (it is the heartbeat for a chunk with
        // nothing to show - see {@see \SugarCraft\Crush\Runtime::run()}'s
        // $onProgress), so the `$delta === ''` early return $tokenSink makes is
        // exactly wrong for it. It deliberately does not touch $streamed
        // either: $streamed suppresses the end-of-turn one-shot re-delivery of
        // the assistant's TEXT, and a turn that thought but never spoke must
        // still get that fallback.
        $progressSink = $onReasoning;

        // Step 0.10: how many times this turn has already recovered from a
        // reply that said nothing — see the `no-tools` region below.
        $reasoningOnlyNudges = 0;
        $emptyReplyRetries = 0;

        // Step 1.A-2: read once, on the first step's turn-context row; the
        // row last built, reused after a step that could not have written.
        $contextWindow = null;
        $turnContext = null;
        // @endregion build

        // Bounded agentic loop: keep running while the model asks for tools.
        // The Runtime resolves one assistant turn + its tool calls per run();
        // we feed the results back and re-run until the model answers without
        // tools — or we hit the step ceiling (guards against runaway loops,
        // which neither sugar-crush nor candy-crush had).
        for ($step = 0; $step < $this->maxSteps; $step++) {
            // @region step-top
            $assistant = null;
            $toolResults = [];
            // 4.7-2: this step's sent request, as the observer below measures it.
            $sentPressure = null;

            // Roadmap 1.C-3: what the user sent while the turn ran is read
            // HERE, at the step boundary, ahead of the step's request — the
            // previous step's tools have all settled (any that had not started
            // when it arrived were skipped), so the model reads the message
            // before deciding what to do next.
            if ($inbox !== null) {
                $incoming = $inbox->drain($step + 1);
                if ($incoming !== []) {
                    $app = $app->withMessages([...$app->messages, ...$incoming]);
                }
            }

            // Step 1.A-2: the volatile `<turn-context>` row is PERSISTED into
            // the history, here at the top of the step and only when its
            // bytes changed, so step k+1 sends step k's request unchanged and
            // appends to it — the whole request is a cacheable prefix of the
            // next, not everything but its last row. It rides the transcript
            // back to Chat as a hidden row (1.B-2), so the next turn's first
            // step sends no new row either while nothing moved.
            //
            // The git half is re-polled only on the first step and after a
            // step that could have written (the P3.S5 write signal): after a
            // read-only step the work tree is as the agent last saw it, so
            // the previous row's git state is reused — no subprocess, and no
            // diff-less copy of the row that would differ from its neighbours
            // only by the diffs it withheld. A change made outside the agent
            // between two read-only steps shows on the next write or turn.
            // The stale-read paragraph (3.I-2) is re-read on every step,
            // reused row or not: it is a stat per read file, and a file that
            // changed under a read-only step must say so before the next.
            //
            // Roadmap 2.6: the first step after a compaction — the host's
            // (its `[summary] ` rows) or one this turn made (the ledger's
            // step-summary block) — re-injects what the compaction took out
            // of view: the files the agent was working on, re-read now, and
            // the skill bodies it had loaded, on this row, with the git half
            // re-polled whatever the last step did. Read HERE, in the turn's
            // own process, never on the host's render loop; recognised off
            // the history, so no flag crosses the fork. One-shot: the row
            // stamps the compaction's cycle, and a later step's row, built
            // without it, supersedes it.
            $reinjection = \SugarCraft\Crush\Context\Compaction\ReinjectionPlan::pendingIn($app->messages, $contextLedger ?? $app->contextLedger);
            $turnContext = $turnContext === null || $lastAssistant === null || $reinjection !== null || Runtime::stepRequestedAWrite($lastAssistant->toolCalls())
                ? $runtime->turnContext($app)
                : $turnContext->withRecentlyModifiedFiles(TurnContextBlock::recentlyModifiedIn($app->messages))
                    ->withChangedSinceRead(\SugarCraft\Crush\Tools\ReadLedger::in($app->tools)?->notice() ?? '');
            $turnContext = $turnContext->withContextPercent(
                $this->contextPercentAtStepTop($app->messages, $pressureAnchor ?? [null, 0], $contextWindow),
            )->withInvokedSkills(\SugarCraft\Crush\Context\Compaction\ReinjectionPlan::skillNamesIn($app->messages));
            $contextRow = $reinjection === null
                ? $turnContext
                : $turnContext->withReinjection($reinjection->render($app->root ?? $this->root, $app->tools, $contextWindow ?? 0));
            if ($contextRow->changedSince($app->messages)) {
                $app = $app->withMessages([...$app->messages, $contextRow->message()]);
            }

            // Roadmap 3.C: the todo list is re-shown between steps too, not
            // only at dispatch (Host\TurnRunner), so a turn running past
            // TodoReminder::INTERVAL_STEPS steps still sees it. The row rides
            // the transcript back as a hidden user row, like the one above.
            // Roadmap 2.6: and right after a compaction, whatever its age —
            // the copy the model last saw may be in what was condensed.
            $todos = \SugarCraft\Crush\Todo\TodoReminder::latestIn($app->messages);
            if ($todos !== null && (
                \SugarCraft\Crush\Todo\TodoReminder::due($todos, $app->messages)
                || ($reinjection !== null && $todos->hasOpenItems())
            )) {
                $app = $app->withMessages([...$app->messages, new UserMessage(\SugarCraft\Crush\Todo\TodoReminder::render($todos))]);
            }

            // Roadmap 2.1: the step-level pressure check. Chat judges the
            // conversation once, at submit, and its estimate leaves out the
            // system prompt and the tool schemas; a turn then grows by every
            // tool result it reads, so the request that overflows is one no
            // tier ever saw. Each step's request is measured HERE, built and
            // not yet sent (Runtime::run()'s $onRequest), against
            // min(80% of the window, window - maxOutputTokens - reserve) and
            // the automatic-compaction tier's absolute cap when one is set
            // (2.9) — anchored on the provider's own count for the previous
            // step plus an estimate of the rows added since. Sub-agents get
            // it too: TaskTool and EngineExecutor run through
            // completeTranscript(). Measured on EVERY step, observer or not,
            // because the verdict now acts (2.2-1 / 2.4-1, below).
            // The window contextPercentAtStepTop() just read (0 when the
            // provider could not say, which the budget resolves to the shared
            // fallback) — one provider question per turn, not two.
            $contextBudget ??= \SugarCraft\Crush\Context\ContextBudget::forCompactor(
                $contextWindow ?? 0,
                self::maxOutputTokens($userConfig),
                $this->compactorConfig(),
            );
            $pressureAnchor ??= [null, 0];
            // Roadmap 2.2-1: what this turn has taken out of the model's view.
            // Local to the turn (2.2-2 carries it across turns); the App
            // carries it so every request — this step's, a step summary's,
            // the stopped-turn summary's — is projected through it.
            $contextLedger ??= $app->contextLedger ?? \SugarCraft\Crush\Context\Pruning\ContextLedger::new();
            $maxSteps = $this->maxSteps;
            // Roadmap 3.B-3 (DCP §13.2 G): every change to the turn's ledger —
            // a `Prune` call's, an over-budget relief's — is shown to the host
            // as it happens (the live `ledger` frame on the forked path), so
            // the transcript dims the rows while the turn still runs. Only
            // where a host keeps the session's ledger: a delegated run's is its
            // own and ends with it. The turn's final ledger still arrives
            // whole on the reply, so a shown delta is only ever an early view.
            $ledgerShown ??= $contextLedger;
            $showLedger ??= $onEvent === null || $this->contextLedger === null
                ? static function (string $toolCallId): void {
                }
                : static function (string $toolCallId) use (&$contextLedger, &$ledgerShown, $onEvent): void {
                    if ($contextLedger === $ledgerShown) {
                        return;
                    }
                    $delta = $contextLedger->deltaSince($ledgerShown);
                    $ledgerShown = $contextLedger;
                    if (!$delta->isEmpty()) {
                        $onEvent(new ContextLedgerChanged($delta, $toolCallId));
                    }
                };

            // Roadmap 3.B-4 (DCP §13.2 E): remind the model to manage its
            // context once it is filling up — ANCHORED on the newest tool
            // result or prompt, a row this request sends for the first time,
            // and re-rendered there on every later request, so a reminder
            // never rewrites bytes the provider already cached. Only where a
            // host keeps the session's ledger, in the `auto` mode; cleared
            // after a successful Prune / Compress (the cooldown).
            if ($this->contextLedger !== null) {
                [$anchorTokens, $anchorRows] = $pressureAnchor;
                $nudge = $this->compactorConfig()->nudgePolicy()->decide(
                    $anchorTokens !== null
                        ? $anchorTokens + \SugarCraft\Crush\Context\ContextPressure::ofMessages(\array_slice($app->messages, $anchorRows))
                        : \SugarCraft\Crush\Context\ContextPressure::ofMessages($app->messages),
                    $app->messages,
                    $contextLedger,
                );
                if (!$nudge->isEmpty()) {
                    $contextLedger = $contextLedger->apply($nudge);
                    $app = $app->withContextLedger($contextLedger);
                    $showLedger('');
                }
            }

            // Roadmap 2.2-1 / 2.4-1: an over-budget request is relieved before
            // it is sent. The observer sees the request fully built and, while
            // relief remains, refuses it (StepOverBudget, thrown before any
            // provider call or yield); the step is then rebuilt over a
            // relieved ledger. First the deterministic prune — the age rule
            // and the superseded `<turn-context>` rows, made only when it
            // frees enough to be worth a cache rewrite — then, if the request
            // is still over, a step summary of everything the model has
            // already been sent. Each at most once per step; whatever is left
            // is sent as it stands.
            $pruneTried = false;
            $summaryTried = false;
            // Roadmap 2.7-1b: a request the provider refused as too long for
            // the context window is relieved as far as the machinery goes and
            // sent ONCE more per step — see the ContextOverflow catch below.
            $overflowRetried = false;
            // A step summary is a provider call billed like a step: on the
            // turn's usage sum, the sibling ledger and the step observers.
            $billSummary = function (AssistantMessage $summary) use (&$stepUsages, $onStep, $step): void {
                $stepUsages[] = $summary->usage();
                $this->siblingSpend?->record($summary->usage());
                if ($this->stepUsageObserver !== null) {
                    ($this->stepUsageObserver)($summary->usage());
                }
                if ($onStep !== null) {
                    $onStep(new \SugarCraft\Crush\Events\UsageUpdated($step + 1, $summary->usage(), Usage::sum($stepUsages)));
                }
                $this->observeCacheHealth($summary->usage());
            };
            // Liveness only: an empty reasoning delta is the frame the
            // parent's idle deadline counts, and a summary's own text is
            // never painted.
            $summaryLiveness = $progressSink === null ? null : static function () use ($progressSink): void {
                $progressSink('');
            };
            // Roadmap 2.7-2: a reply cut at the output ceiling with no tool
            // call is CONTINUED, up to ReplyContinuation::MAX_LENGTH_CONTINUATIONS
            // times, before the step is over: the partial reply goes back as
            // a prefill (or with a "continue" row, for a provider that takes
            // none) and the answer is joined onto it. $continuing is the reply
            // so far while a continuation is being asked for.
            $continuing = null;
            $continuePrefill = false;
            $prefillRejected = false;
            $lengthContinuations = 0;
            while (true) {
                $requestRows = $app->messages;
                $runApp = $continuing === null
                    ? $app
                    : $app->withMessages([
                        ...$app->messages,
                        ...\SugarCraft\Crush\Providers\ReplyContinuation::rows($continuing, $continuePrefill, \SugarCraft\Crush\Providers\ReplyContinuation::LENGTH_PROMPT),
                    ]);
                $wireRows = $runApp->messages;
                $mayRelieve = !($pruneTried && $summaryTried);
                $observeRequest = static function (\SugarCraft\Crush\Providers\CompleteRequest $request) use ($contextBudget, $wireRows, $pressureAnchor, $onStep, $step, $maxSteps, $mayRelieve, &$sentPressure): void {
                    $pressure = \SugarCraft\Crush\Context\ContextPressure::measure($contextBudget, $request, $wireRows, $pressureAnchor[0], $pressureAnchor[1]);
                    if ($mayRelieve && $pressure->isOverBudget()) {
                        throw new \SugarCraft\Crush\Context\Pruning\StepOverBudget($pressure);
                    }
                    // 4.7-2: what the step actually sent, relief and all.
                    $sentPressure = $pressure;
                    if ($onStep !== null) {
                        $onStep(new \SugarCraft\Crush\Events\StepStarted($step + 1, $maxSteps, $pressure));
                    }
                };

                try {
                    foreach ($runtime->run($runApp, $onEvent, $this->permissionApprover, $tokenSink, $progressSink, $onHeartbeat, $observeRequest) as $message) {
                        if ($message instanceof AssistantMessage) {
                            $assistant = $message;
                            // 2.1: the next step's anchor — this request as the
                            // provider counted it, and how many rows it was built
                            // from, so the delta is only what this step adds.
                            $pressureAnchor = [\SugarCraft\Crush\Context\ContextPressure::promptTokensOf($assistant->usage()), count($requestRows)];
                            // 2.4-1: the rows the model has now been sent; a
                            // step summary covers these and never the rows after.
                            $sentRows = count($requestRows);
                            // Counted ON ARRIVAL, before this step's tools run: a Task
                            // call among them reads $spentSoFarUsd as its sub-agent's
                            // cap baseline, and the step that asked for it is paid.
                            $stepUsages[] = $assistant->usage();
                            // On the shared ledger the moment it is billed, so a
                            // sibling's next cap check sees it — and so it survives
                            // this process dying before the run reports (B4-rem).
                            $this->siblingSpend?->record($assistant->usage());
                            if ($this->stepUsageObserver !== null) {
                                ($this->stepUsageObserver)($assistant->usage());
                            }
                            // 1.C-4: the same instant, to the turn's own observer —
                            // on the forked path this is the `usage` frame, so the
                            // UI's cost moves while the turn runs.
                            if ($onStep !== null) {
                                $onStep(new \SugarCraft\Crush\Events\UsageUpdated($step + 1, $assistant->usage(), Usage::sum($stepUsages)));
                            }
                            // Per PROVIDER RESPONSE, not per turn: the cache buckets
                            // describe one request's prefix, and a turn's sum would
                            // hide a zero step behind a cached one.
                            $this->observeCacheHealth($assistant->usage());
                        } elseif ($message instanceof ToolResultMessage) {
                            $toolResults[] = $message;
                            // 3.B-3: a ledger tool applied its delta as it ran.
                            $showLedger($message->toolCallId());
                            // Folded AS IT SETTLES, not after the step: a sequential
                            // Task later in this same step reads $spentSoFarUsd when
                            // it starts, and must see this one's dollars.
                            if ($message->usage() !== null) {
                                $stepUsages[] = self::delegatedSpend($message->usage());
                                $this->siblingSpend?->record(self::delegatedSpend($message->usage()));
                            }
                            // Last image-bearing tool result of the whole turn wins -
                            // W1.G2 reachability fix: this is the only point left
                            // with access to the typed ToolResultMessage before only
                            // the root Message survives back to Chat/Renderer.
                            if ($message->hasImage()) {
                                $lastImageBytes = $message->imageBytes();
                                $lastImageProtocol = $message->imageProtocol();
                            }
                        }
                    }

                    // 2.7-2: the continuation's answer is the rest of the
                    // reply it was asked for — one reply, joined.
                    if ($continuing !== null && $assistant !== null) {
                        $assistant = \SugarCraft\Crush\Providers\ReplyContinuation::merge($continuing, $assistant, $continuePrefill);
                    }
                    $continuing = null;
                    if ($assistant !== null
                        && $toolResults === []
                        && $assistant->lengthStopped()
                        && \SugarCraft\Crush\Providers\ReplyContinuation::continuable($assistant)
                        && $lengthContinuations < \SugarCraft\Crush\Providers\ReplyContinuation::MAX_LENGTH_CONTINUATIONS
                        // Another provider call: not past the spend cap, and
                        // not once the person asked the turn to stop.
                        && ($this->spendCapUsd === null || $spentSoFarUsd() < $this->spendCapUsd)
                        && ($stopRequested === null || !$stopRequested())
                    ) {
                        $lengthContinuations++;
                        $continuing = $assistant;
                        $continuePrefill = !$prefillRejected
                            && \SugarCraft\Crush\Providers\ReplyContinuation::prefills($this->provider, $app->model);
                        $assistant = null;

                        continue;
                    }

                    break;
                } catch (\SugarCraft\Crush\Context\Pruning\StepOverBudget) {
                    $relieved = null;
                    if (!$pruneTried) {
                        $pruneTried = true;
                        $delta = \SugarCraft\Crush\Context\Pruning\EmergencyPrune::propose(
                            \SugarCraft\Crush\Context\Pruning\ContextProjector::new()->project($app->messages, $contextLedger)->messages,
                            $contextLedger,
                            \SugarCraft\Crush\Context\Pruning\PruningPolicy::new(),
                        );
                        $relieved = $delta === null ? null : $contextLedger->apply($delta);
                    }
                    if ($relieved === null && !$summaryTried) {
                        $summaryTried = true;
                        // Not past the spend cap: a summary is a provider call,
                        // and the cap refuses the next one by definition.
                        // Roadmap 3.D-2: and not when a PreCompact hook refuses
                        // compactions — asked before the summary is paid for;
                        // the request then goes out as it stands.
                        if (($this->spendCapUsd === null || $spentSoFarUsd() < $this->spendCapUsd)
                            && $preCompact('auto', '') === null
                        ) {
                            // Roadmap 2.11: the memory flush — one silent step,
                            // only the Memory tool may run, in which the model
                            // saves what should outlive the session before the
                            // summary drops the rows it would read it from. Once
                            // per compaction cycle, counted on the session's
                            // ledger (ContextLedger::memoryFlushDue()), so a
                            // summary that fails and is retried a step — or a
                            // turn, or a `/compact` — later does not flush
                            // again. On its own copy of the chain (a fresh loop
                            // guard, so the turn's repeat counts never see it);
                            // a failed flush costs the summary nothing.
                            // Only when the summary will be asked for: a turn
                            // with nothing to condense is never sent a flush.
                            if ($contextLedger->memoryFlushDue()
                                && \SugarCraft\Crush\Context\Compaction\MemoryFlush::available($app->tools)
                                && \SugarCraft\Crush\Context\Compaction\StepSummarizer::wouldSummarise($app, $contextLedger, $sentRows ?? count($app->messages))
                            ) {
                                $contextLedger = $contextLedger->withMemoryFlushed();
                                $app = $app->withContextLedger($contextLedger);
                                try {
                                    $flushHooks = $this->resolveHookManager(ToolCallLoopGuard::new());
                                    $flushHooks->register(\SugarCraft\Crush\Context\Compaction\MemoryFlush::new());
                                    \SugarCraft\Crush\Context\Compaction\MemoryFlush::run(
                                        $this->newRuntime(
                                            $flushHooks,
                                            self::parallelToolCallsEnabled($userConfig),
                                            self::parallelToolDeadlineSeconds($userConfig),
                                            self::maxOutputTokens($userConfig),
                                        )->withTurnContextPersisted(),
                                        $app,
                                        $billSummary,
                                        $summaryLiveness,
                                        $onHeartbeat,
                                    );
                                } catch (\Throwable) {
                                    // Best effort: the compaction goes ahead.
                                }
                            }
                            $block = \SugarCraft\Crush\Context\Compaction\StepSummarizer::summarise(
                                $runtime,
                                $app,
                                $contextLedger,
                                // Everything is "sent" on a turn's first step:
                                // the history came from earlier requests.
                                $sentRows ?? count($app->messages),
                                $billSummary,
                                $summaryLiveness,
                                $onHeartbeat,
                                $this->summaryModel,
                            );
                            $relieved = $block === null ? null : $contextLedger->withBlock($block);
                            if ($block !== null) {
                                $postCompact('auto', $block->summary);
                            }
                        }
                    }
                    if ($relieved !== null) {
                        $contextLedger = $relieved;
                        $showLedger('');
                        $app = $app->withContextLedger($contextLedger);
                        // The provider counted the request BEFORE the ledger
                        // moved; the next figure is a fresh estimate until a
                        // response re-anchors it.
                        $pressureAnchor = [null, 0];

                        // Roadmap 2.6: a step summary just took the rows the
                        // model read files and skills from out of view — the
                        // request this step now sends re-injects them, as
                        // the step-top row does after a host compaction.
                        $reinjection = \SugarCraft\Crush\Context\Compaction\ReinjectionPlan::pendingIn($app->messages, $contextLedger);
                        if ($reinjection !== null) {
                            $turnContext = $runtime->turnContext($app)
                                ->withContextPercent($turnContext?->contextPercent())
                                ->withInvokedSkills(\SugarCraft\Crush\Context\Compaction\ReinjectionPlan::skillNamesIn($app->messages));
                            $contextRow = $turnContext->withReinjection($reinjection->render($app->root ?? $this->root, $app->tools, $contextWindow ?? 0));
                            $reinjected = [];
                            if ($contextRow->changedSince($app->messages)) {
                                $reinjected[] = $contextRow->message();
                            }
                            $todos = \SugarCraft\Crush\Todo\TodoReminder::latestIn($app->messages);
                            if ($todos !== null && $todos->hasOpenItems()) {
                                $reinjected[] = new UserMessage(\SugarCraft\Crush\Todo\TodoReminder::render($todos));
                            }
                            $app = $app->withMessages([...$app->messages, ...$reinjected]);
                        }
                    }
                } catch (\Throwable $failure) {
                    // 2.7-2: a continuation is best effort — the reply it was
                    // continuing already stands. A provider that refuses the
                    // prefill itself is asked again with a "continue" row;
                    // any other failure ends the step on the reply as it was,
                    // and its length-stop notice says it was cut short.
                    if ($continuing !== null
                        && !\SugarCraft\Crush\Providers\ContextOverflow::matches($failure)
                    ) {
                        if ($continuePrefill && \SugarCraft\Crush\Providers\ReplyContinuation::rejectsPrefill($failure)) {
                            $continuePrefill = false;
                            $prefillRejected = true;
                            $assistant = null;
                            $toolResults = [];

                            continue;
                        }
                        $assistant = $continuing;
                        $continuing = null;
                        $toolResults = [];

                        break;
                    }

                    // Roadmap 2.7-1b: the provider refused the request as too
                    // long for its window (ContextOverflow, 2.7-1a) — the one
                    // permanent failure a smaller request fixes. The budget
                    // check above runs on an estimate, and a provider can
                    // count differently or reserve more than it says; the
                    // refusal is the provider's own count, so it is answered
                    // with everything the in-turn machinery has, at once:
                    // every older tool output pruned (no protected tail, no
                    // batching floor — the request cannot be sent as it is),
                    // then a step summary of what the model was already sent,
                    // on the turn's cached prefix and the launch's summary
                    // model (`summaryModel`, else the turn's own). Then the step
                    // goes out ONCE more; a second refusal, a refusal nothing
                    // could relieve, or any other failure propagates as
                    // before. Never after the step produced its reply: a
                    // failure past that point is not this request's size.
                    // The originals are untouched — only what the requests
                    // carry shrinks, as the ledger always does.
                    if ($overflowRetried
                        || $assistant !== null
                        || !\SugarCraft\Crush\Providers\ContextOverflow::matches($failure)
                    ) {
                        throw $failure;
                    }
                    $overflowRetried = true;

                    $relieved = $contextLedger;
                    $delta = \SugarCraft\Crush\Context\Pruning\EmergencyPrune::propose(
                        \SugarCraft\Crush\Context\Pruning\ContextProjector::new()->project($app->messages, $contextLedger)->messages,
                        $contextLedger,
                        \SugarCraft\Crush\Context\Pruning\PruningPolicy::new()
                            ->withProtectTokens(0)
                            ->withProtectUserTurns(0)
                            ->withMinFreedTokens(0),
                    );
                    if ($delta !== null) {
                        $relieved = $relieved->apply($delta);
                    }
                    // Not past the spend cap: the summary is a provider call.
                    // Nor past a PreCompact refusal (3.D-2): the prune above
                    // is overflow protection, the summary is a compaction.
                    if (($this->spendCapUsd === null || $spentSoFarUsd() < $this->spendCapUsd)
                        && $preCompact('auto', '') === null
                    ) {
                        $block = \SugarCraft\Crush\Context\Compaction\StepSummarizer::summarise(
                            $runtime,
                            $app->withContextLedger($relieved),
                            $relieved,
                            $sentRows ?? count($app->messages),
                            $billSummary,
                            $summaryLiveness,
                            $onHeartbeat,
                            // The launch-time summary model, as the 85% tier
                            // above passes it: a live re-read of the variable
                            // ignored the `summaryModel` key and moved with an
                            // environment the launch had already resolved.
                            $this->summaryModel,
                        );
                        if ($block !== null) {
                            $relieved = $relieved->withBlock($block);
                            $postCompact('auto', $block->summary);
                        }
                    }
                    if ($relieved === $contextLedger) {
                        // Nothing could be taken out: the identical request
                        // would be refused again.
                        throw $failure;
                    }
                    $contextLedger = $relieved;
                    $showLedger('');
                    $app = $app->withContextLedger($contextLedger);
                    $pressureAnchor = [null, 0];
                }
            }

            // The step completed: whichever break below ends the loop, this
            // is what the conversation now holds.
            $transcript = [
                ...$app->messages,
                ...($assistant !== null ? [$assistant] : []),
                ...$toolResults,
            ];

            if ($assistant !== null) {
                $lastAssistant = $assistant;
                $lengthStopped = $lengthStopped || $assistant->lengthStopped();
            }

            // @endregion step-top

            // @region no-tools
            // Step 3.D-2: the chain the Runtime gated this step's calls with
            // (resolveHookManager() keeps one per turn).
            $turnHooks ??= $this->resolveHookManager($loopGuard);

            // A hook asked the RUN to stop — a script hook's JSON
            // `"continue": false` on a tool call (3.D-1). That verdict already
            // refused its call; this ends the turn at the step boundary, once
            // the step's other calls have settled and before another provider
            // call is made. Deliberate, like the soft cancel: no step-ceiling
            // notice and no summary request — the hook said stop.
            //
            // The stop reason rides the reply, where the operator reads it
            // (and a delegating Task reads why its sub-agent stopped); the
            // step's own row in the transcript keeps the model's words as
            // they were.
            $halt = $turnHooks->turnHalt();
            if ($halt !== null) {
                $notice = HookManager::stopNotice($halt);
                $said = $lastAssistant?->content() ?? '';
                $lastAssistant = new AssistantMessage(
                    $said === '' ? $notice : $said . "\n\n" . $notice,
                    $lastAssistant?->toolCalls(),
                    $lastAssistant?->reasoning(),
                    $lastAssistant?->usage(),
                    $lastAssistant?->lengthStopped() ?? false,
                );
                $stoppedSoftly = true;
                break;
            }

            if ($toolResults === []) {
                // Step 0.10: a reply with no tool calls AND no text is not an
                // answer — the turn used to end there silently, handing the
                // operator (or a delegating Task) an empty reply. A reply that
                // only THOUGHT gets one nudge, appended as a user row so the
                // model sees why it is asked again (HistorySanitizer drops the
                // empty assistant row on the wire); a reply with nothing at
                // all is simply re-requested, up to twice, appending nothing.
                // Every extra call needs a step left and must clear the spend
                // cap first — the same `>=` test the after-step check makes.
                // Once these run out the turn ends exactly as before, so
                // TaskTool's "ended without a final report" still fires.
                $replyText = trim($assistant?->content() ?? '');
                $reasoningOnly = $replyText === '' && trim($assistant?->reasoning() ?? '') !== '';
                $recover = $replyText === '' && ($reasoningOnly ? $reasoningOnlyNudges < 1 : $emptyReplyRetries < 2);

                if ($recover
                    && $step + 1 < $this->maxSteps
                    && ($this->spendCapUsd === null || $spentSoFarUsd() < $this->spendCapUsd)
                ) {
                    if ($reasoningOnly) {
                        $reasoningOnlyNudges++;
                        $nudge = new UserMessage(
                            'Your last reply contained only reasoning, with no answer and no tool call. '
                            . 'Continue the task: call a tool if you need one, or give your final answer now.',
                        );
                        $app = $app->withMessages([
                            ...$app->messages,
                            ...($assistant !== null ? [$assistant] : []),
                            $nudge,
                        ]);
                        $transcript = $app->messages;
                    } else {
                        $emptyReplyRetries++;
                        $transcript = $app->messages;
                    }

                    continue;
                }

                // Step 3.D-2: the Stop chain — SubagentStop when this turn is
                // a delegated run (a Task sub-agent carries its grant; a
                // workflow stage's agent runs inside
                // HookManager::runAsSubagent()). Only when something is wired,
                // so an unhooked turn ends exactly as before. Run here, in the
                // turn's own process (the forked turn child on the TUI path).
                $subagent = $this->subAgentGrant !== null
                    ? ['agent_id' => $this->subAgentGrant->subAgent()->id, 'agent_type' => $this->subAgentGrant->subAgent()->agent->name]
                    : HookManager::currentSubagent();
                $stopEvent = $subagent !== null
                    ? \SugarCraft\Crush\Hooks\HookEvent::SubagentStop
                    : \SugarCraft\Crush\Hooks\HookEvent::Stop;
                $stopContinuations ??= 0;
                if ($turnHooks->hasHooksFor($stopEvent, $stopEvent->value)) {
                    $stopContext = HookManager::eventContext(
                        $stopEvent,
                        [
                            'stop_hook_active' => $stopContinuations > 0,
                            'last_assistant_message' => $assistant?->content() ?? '',
                            ...($subagent ?? []),
                        ],
                        $this->sessionId ?? '',
                        $this->root ?? '',
                        $this->model,
                        $this->provider->name(),
                    );
                    // The chain sends the parent nothing while a hook works,
                    // so a Stop hook that waits (3.H's test run) beats
                    // through the turn's idle ceiling instead of racing it.
                    $verdict = $subagent !== null
                        ? $turnHooks->subagentStop($stopContext)
                        : $turnHooks->stop($stopContext, self::throttledHeartbeat($onHeartbeat, $onReasoning));

                    // `"continue": false`: the turn ends, as it was about to,
                    // and the reply says why.
                    if ($verdict->haltsTurn()) {
                        $notice = HookManager::stopNotice($verdict);
                        $said = $assistant?->content() ?? '';
                        $stopped = new AssistantMessage(
                            $said === '' ? $notice : $said . "\n\n" . $notice,
                            $assistant?->toolCalls(),
                            $assistant?->reasoning(),
                            $assistant?->usage(),
                            $assistant?->lengthStopped() ?? false,
                        );
                        // The reply IS this step's row, so the row carries
                        // the notice too and the reply is not sent twice.
                        if ($assistant !== null) {
                            $transcript[array_key_last($transcript)] = $stopped;
                        } else {
                            $transcript[] = $stopped;
                        }
                        $lastAssistant = $stopped;
                        $answeredWithoutTools = true;
                        break;
                    }

                    // A refusal keeps the turn going: the answer stands in
                    // the history and the hook's reason follows it as the
                    // next prompt. Each one is a step — so it shares the step
                    // ceiling and the spend cap with every other call — and
                    // at most MAX_STOP_CONTINUATIONS a turn.
                    if (!$verdict->permitsExecution()
                        && $stopContinuations < HookManager::MAX_STOP_CONTINUATIONS
                        && $step + 1 < $this->maxSteps
                        && ($this->spendCapUsd === null || $spentSoFarUsd() < $this->spendCapUsd)
                        && ($stopRequested === null || !$stopRequested())
                    ) {
                        $stopContinuations++;
                        $app = $app->withMessages([
                            ...$app->messages,
                            ...($assistant !== null ? [$assistant] : []),
                            new UserMessage(HookManager::stopFeedback($verdict, $stopEvent)),
                        ]);
                        $transcript = $app->messages;

                        continue;
                    }
                }

                $answeredWithoutTools = true;
                break; // model answered without calling tools — done
            }
            // @endregion no-tools

            // @region after-step

            // E20: THE MID-TURN SPEND CAP, checked HERE and nowhere else —
            // this is the only point in the app that stands between a step
            // the session has already paid for and the NEXT provider call it
            // could still refuse. Below the break (a turn that answered
            // without tools is over; there is no next call to price) and
            // above the prompt assembly (the refusal must not pay for
            // building a prompt it will never send).
            //
            // The arithmetic is the SAME shape as Chat::spendCapReached():
            // baseline session spend + this turn's billed steps, breached at
            // `>=`. A step that reported nothing contributes 0.0 — the
            // fail-open direction the cap has always had: an unreported
            // session cannot prove a breach, and the mid-turn check inherits
            // that, overspending rather than falsely stopping.
            //
            // NO wall-clock kill, no abort of the call in flight, and the
            // fork's idle-timeout discipline untouched — an in-flight
            // provider call finishes on its own terms and lands in the sum
            // at the boundary above. What this refuses is the DECISION to
            // make another call, which is the only spend decision this loop
            // actually owns (standing rule against blanket LLM timeouts).
            if ($this->spendCapUsd !== null) {
                // Includes every delegated run this step settled (audit B4),
                // forked siblings too — the check that bounds what a parallel
                // batch of Tasks could not see of each other.
                $sessionSpendAtBoundary = $spentSoFarUsd();

                if ($sessionSpendAtBoundary >= $this->spendCapUsd) {
                    // On the tool-event channel, in wire order with the turn's
                    // other events, so the transcript can say which call was
                    // refused — and a consumer that ignores it costs the turn
                    // nothing (see the event's own docblock). $step is 0-based,
                    // so the calls made number $step + 1.
                    if ($onEvent !== null) {
                        $onEvent(new SpendCapBreached($step + 1, $sessionSpendAtBoundary, $this->spendCapUsd));
                    }

                    $stoppedBySpendCap = true;
                    break;
                }
            }

            // P3.S5: the per-step half of P3.S2's lever. This loop is the only
            // thing in `src/` that sees one step of the agentic loop end and
            // the next one's prompt get assembled, so it is where the write
            // signal is derived. After a step whose assistant turn asked for
            // Edit/Write/Bash/mcp__*, the next `run()` renders the working
            // diff; after a step that only read, it renders three git
            // subprocesses instead of five and no diff sections at all — see
            // {@see Runtime::markWriteSinceLastRender()} and
            // {@see \SugarCraft\Crush\Context\EnvironmentBlock::withWriteSinceLastRender()}.
            //
            // BELOW THE `break`, ON PURPOSE: a step with no tool results is the
            // last step of the turn, and nothing will assemble another prompt
            // from this Runtime, so marking there would set a field nobody
            // reads. The FIRST prompt of the turn is unaffected for the mirror
            // reason — `$runtime` is built fresh above and this line has not
            // run yet, so step 0 renders in `EnvironmentBlock`'s default emit
            // state. That is the whole of the cross-turn behaviour available on
            // this path: the Runtime and its memoised block die with the turn,
            // and on {@see completeAsync()} with the forked child that ran it.
            //
            // `$assistant === null` FAILS SAFE, AND IT IS DORMANT — said
            // rather than left for a reader to assume either way. Reaching it
            // needs tool results with no assistant message beside them, and
            // {@see \SugarCraft\Crush\Runtime::runStreaming()} and
            // {@see \SugarCraft\Crush\Runtime::runBatch()} both yield the
            // AssistantMessage BEFORE dispatching its calls, so nothing
            // produces that shape today. It resolves to "a write happened"
            // regardless, because the alternative is to hide a diff on the
            // strength of tool calls this loop could not read: over-showing
            // costs the bytes of one section pair, under-showing withholds the
            // state the model needs to continue.
            $runtime->markWriteSinceLastRender(
                $assistant === null || Runtime::stepRequestedAWrite($assistant->toolCalls()),
            );

            $app = $app->withMessages([
                ...$app->messages,
                ...($assistant !== null ? [$assistant] : []),
                ...$toolResults,
            ]);

            // Checked LAST in the step, after the spend cap (a breached cap
            // stops the turn without paying for a summary) and after the App
            // carries this step's results, so the summary request below sees
            // the refusal that tripped it. The guard trips inside this step's
            // PreToolUse chain; the step's other calls still settle first.
            if ($loopGuard->endsTurn()) {
                $stoppedByLoopGuard = true;
                break;
            }

            // Roadmap 1.C-4: the soft cancel. Asked HERE, after the step's
            // tools have all settled and the App carries their results, so
            // nothing the step started is cut off — the turn stops exactly
            // where the next provider call would have been made. Hard cancel
            // (Esc Esc) is still the parent's SIGKILL of the whole tree.
            if ($stopRequested !== null && $stopRequested()) {
                $stoppedSoftly = true;
                break;
            }

            // Roadmap 4.7-2 (Zed's sub-agent context guard): judged on the
            // request this step SENT — after its prune and summary had their
            // go — so it fires only when that relief could not hold the run
            // under its budget. At the wrap-up share the model is told once,
            // in a row the next step sends, to wrap up or hand off; a request
            // past the stop share after that ends the run as a failure with
            // its transcript (TurnInterrupted, via completeTranscript()), so
            // the delegating Task returns its partial output and resume id.
            $wrapUpAt = $this->wrapUpAtPercent ?? 0;
            $sentPercent = $sentPressure?->percentOfWindow();
            if ($wrapUpAt > 0 && $sentPercent !== null) {
                $stopAt = min(100, $wrapUpAt + self::WRAP_UP_STOP_MARGIN);
                if (($wrapUpNudged ?? false) && $sentPercent >= $stopAt) {
                    throw new \RuntimeException(sprintf(self::WRAP_UP_STOPPED, $sentPercent));
                }
                if (!($wrapUpNudged ?? false) && $sentPercent >= $wrapUpAt) {
                    $wrapUpNudged = true;
                    $app = $app->withMessages([...$app->messages, new UserMessage(sprintf(self::WRAP_UP_NUDGE, $sentPercent, $stopAt))]);
                }
            }
            // @endregion after-step
        }

        // @region return
        // F2: neither deliberate break fired, so the LAST step still ended
        // with tool results pending and the ceiling, not the model, ended the
        // turn. One flag on the DTO; the transcript notice is Chat's settle
        // arm's job (sibling of the E707 length-stopped notice).
        $stepsTruncated = !$answeredWithoutTools && !$stoppedBySpendCap && !$stoppedByLoopGuard && !$stoppedSoftly;

        // WAVE_PLAN_2 §5: a turn the harness stopped — budget exhausted or a
        // loop the guard ended — gets ONE more request with tools disabled,
        // asking the model what is done, what remains and what comes next.
        // Without it the turn's reply is whatever prose rode along with the
        // last tool-calling step, typically a half-sentence like "Now let me
        // check…", and the operator is left to reconstruct a thousand steps
        // from the tool log. Not after the spend cap: that exit refuses the
        // next provider call by definition, and a summary is one.
        if ($stepsTruncated || $stoppedByLoopGuard) {
            $summary = $this->summariseStoppedTurn(
                $runtime,
                $app,
                $transcript,
                $stoppedByLoopGuard ? $loopGuard->endedBy() : null,
                $onEvent,
                $tokenSink,
                $progressSink,
                $onHeartbeat,
                $stepUsages,
            );
            // An EMPTY summary does not displace the last step's prose: the
            // reply then reads exactly as it did before the summary existed,
            // and TaskTool's "ended without a final report" refusal still
            // fires for a sub-agent that had nothing to say.
            if ($summary !== null && trim($summary->content()) !== '') {
                $lastAssistant = $summary;
                $lengthStopped = $lengthStopped || $summary->lengthStopped();
            }
        }

        $content = $lastAssistant?->content() ?? '';
        // Only when the turn produced no deltas at all — a provider whose
        // stream yielded nothing but that still resolved to content. Keeping
        // the one-shot for that case means a consumer is never left with an
        // empty screen and a finished turn.
        //
        // MEASURED, and worth knowing before anyone reasons from this branch:
        // no such provider can exist through {@see \SugarCraft\Crush\Runtime}
        // today, so this is dormant. The assistant's content IS the stream —
        // runStreaming()'s $buffer is the concatenation of exactly the chunks
        // it handed $tokenSink, and runBatch() emits its whole reply as one
        // delta — so a non-empty $content implies $streamed. Deliberately kept
        // rather than deleted: this guard can only ever suppress a duplicate,
        // never invent a delivery, so being unreachable costs nothing while
        // being absent would cost a consumer its reply the day a provider path
        // resolves content it did not stream.
        // {@see \SugarCraft\Crush\Tests\Backend\ReasoningProgressTest::testEveryByteOfTheReplyReachesTheTokenChannel()}
        // pins the Runtime-side invariant this dormancy rests on.
        if ($onToken !== null && !$streamed && $content !== '') {
            $onToken($content);
        }

        // Roadmap 1.B-2: the rows this turn added to the conversation, step by
        // step, so the NEXT turn can replay it as it happened. Before this the
        // reply below was all that came back: every step's narration was lost
        // and Chat replayed each tool result as prose the assistant had said.
        // Each assistant row opens a step; its results share its id. The step
        // ids are fresh per turn, so no two turns of a session share one.
        // The last step travels as the reply itself when it IS the reply (a
        // tool-free answer), so the transcript never carries it twice. Tool
        // results stay shown (Chat matches them to the rows its live events
        // drew); everything else is the model's record and hidden.
        $turnKey = bin2hex(random_bytes(4));
        $stepNumber = 0;
        $stepId = null;
        $replyStepId = null;
        $callNames = [];
        $turnRows = [];
        $added = \array_slice($transcript, \count($messages));
        $lastAdded = $added === [] ? null : $added[array_key_last($added)];
        foreach ($added as $row) {
            if ($row instanceof AssistantMessage) {
                $stepId = sprintf('s_%s_%d', $turnKey, ++$stepNumber);
                if ($row === $lastAdded && $row === $lastAssistant && ($row->toolCalls() ?? []) === []) {
                    $replyStepId = $stepId;

                    continue;
                }
                $calls = [];
                foreach ($row->toolCalls() ?? [] as $call) {
                    if ($call instanceof \SugarCraft\Crush\Tools\ToolCall) {
                        $callNames[$call->id()] = $call->name();
                        $calls[] = \SugarCraft\Crush\ToolCall::fromEngineCall($call);
                    }
                }
                $turnRows[] = Message::assistant($row->content(), reasoning: $row->reasoning())
                    ->withToolCalls($calls)
                    ->withStepId($stepId)
                    ->withUserVisible(false);
            } elseif ($row instanceof ToolResultMessage) {
                $id = $row->toolCallId();
                $name = $callNames[$id] ?? 'tool';
                $turnRows[] = Message::assistant($row->isError() ? 'Tool error: ' . $row->content() : $row->content())
                    ->withToolResults([$row->isError()
                        ? new \SugarCraft\Crush\ToolResult($name, '', $row->content(), $id)
                        : new \SugarCraft\Crush\ToolResult($name, $row->content(), null, $id)])
                    ->withStepId($stepId);
            } elseif ($row instanceof UserMessage) {
                // A harness-written prompt: the reasoning-only nudge, or the
                // stopped turn's summary request.
                $turnRows[] = Message::user($row->content())->withUserVisible(false);
            } elseif ($row instanceof SystemMessage) {
                $turnRows[] = Message::system($row->content())->withUserVisible(false);
            }
        }

        // Thread the reasoning ReasoningExtractor already split out (§12 D3)
        // across the typed-Message -> root-Message seam instead of dropping
        // it here - it's the last point in this call path that still has
        // access to $lastAssistant before only the plain-string Message DTO
        // survives back to Chat/Renderer. withImage() does the same for an
        // image-bearing tool result (W1.G2 reachability fix).
        return Message::assistant($content, reasoning: $lastAssistant?->reasoning())
            ->withImage($lastImageBytes, $lastImageProtocol)
            ->withUsage(Usage::sum($stepUsages))
            ->withLengthStopped($lengthStopped)
            ->withStepsTruncated($stepsTruncated)
            // The guard's own exit, named so Chat can say which loop it ended.
            ->withLoopGuardStoppedBy($stoppedByLoopGuard ? $loopGuard->endedBy() : null)
            ->withStepId($replyStepId)
            ->withTurnTranscript($turnRows)
            // Roadmap 2.2-2: the session ledger as this turn leaves it, its
            // results' provisional refs fixed in the order the model read
            // them — the refs every request of the turn already showed.
            ->withContextLedger($this->contextLedger === null ? null : ($app->contextLedger ?? $this->contextLedger)->withRefsAssigned($transcript));
        // @endregion return
    }

    /**
     * The turn's {@see Runtime}: the ONE construction site in `src/`, shared
     * by {@see runTurn()} and the parent-side prime
     * ({@see beginSessionTurn()}), and always reading the session's
     * {@see $sessionPromptMemo}. The per-turn settings arrive resolved —
     * runTurn() reads them off its one config read, and the prime needs none
     * of them (the memo's layers do not depend on dispatch settings).
     */
    private function newRuntime(
        HookManager $hooks,
        bool $parallelToolCalls = true,
        int $parallelToolDeadlineSeconds = Runtime::PARALLEL_TOOL_DEADLINE_SECONDS,
        ?int $maxOutputTokens = null,
    ): Runtime {
        return (new Runtime(
            $this->provider,
            $hooks,
            parallelToolCalls: $parallelToolCalls,
            parallelToolDeadlineSeconds: $parallelToolDeadlineSeconds,
            maxOutputTokens: $maxOutputTokens,
            maxConcurrentDelegations: $this->maxConcurrentDelegations,
        ))->withSessionPromptMemo($this->sessionPromptMemo);
    }

    /**
     * The App every turn of this session is built on, before its tools and
     * messages — everything the system prompt's PerSession layers are keyed
     * by, so the parent's prime and the child's turn fill and read the same
     * memo slots.
     */
    private function sessionApp(): App
    {
        return App::new($this->provider, $this->model)
            ->withEnabledSkills($this->skills)
            // The P6.S3 rulebook toggle set, on the same per-turn carry the enabled
            // skills ride. Read here rather than cached into the App at
            // construction because this App is rebuilt every turn: the set is
            // session state that `Chat` mutates between turns, and a value copied
            // once at launch would freeze it for the whole session.
            ->withRulesState($this->rulesState)
            ->withAvailableSkills($this->skillRegistry ?? new SkillRegistry())
            ->withInstructionLoader($this->instructionLoader)
            ->withRoot($this->root)
            ->withMemoryStore($this->memoryStore)
            // Audit R1: the skill budget the prompt splice applies, from the
            // same source the launch notice priced it against.
            ->withCompactorConfig($this->compactorConfig())
            // Step 0.13-a: before this, every engine-path hook was handed
            // `sessionId: ''` and no request named its session.
            ->withSessionId($this->sessionId)
            // Roadmap 2.2-2: the session's ledger, so the turn's first request
            // is projected as the last one was and its relief extends it.
            ->withContextLedger($this->contextLedger);
    }

    /**
     * The next request's system prompt per layer (roadmap 5.6, `/context`),
     * built on the same session App and prompt memo a turn reads — so after
     * the first turn's prime it reuses the session's layers rather than
     * re-walking the repository map and instruction files.
     */
    public function promptSectionSizes(): array
    {
        return $this->newRuntime(new HookManager(new HookRegistry()))
            ->promptSectionSizes($this->sessionApp());
    }

    /**
     * Step 1.A-2, in the PARENT before a turn runs: decide whether this turn
     * is a prompt refresh point, then prime the session's prompt memo.
     *
     * A refresh point — `/clear`, a compaction, a rewind, a session switch —
     * is read off the history itself ({@see SessionPromptMemo::observeTurn()}):
     * the memo forgets the session's layers and the shared instruction loader
     * drops what it read ({@see InstructionFileLoader::refresh()}), so an
     * edited CLAUDE.md, a new memory note or a changed repository layout
     * reaches the prompt there and not on any ordinary step.
     *
     * Then the layers are read HERE, where they outlive the turn: a forked
     * child inherits them warm, where before every turn's child re-walked the
     * repository map, re-read memory and CLAUDE.md, and re-captured the date
     * — and any byte that had moved rewrote the middle of message 0. A prime
     * that fails is left to the child, which builds the same layers itself
     * and reports the failure on the turn it belongs to.
     *
     * @param list<Message> $history
     */
    private function beginSessionTurn(array $history): void
    {
        $rowKeys = [];
        foreach (Message::agentVisible($history) as $row) {
            $rowKeys[] = hash('xxh128', $row->role->value . "\0" . $row->content);
        }

        if ($this->sessionPromptMemo->observeTurn($this->sessionId, $rowKeys)) {
            $this->instructionLoader?->refresh();
        }

        try {
            $this->newRuntime(new HookManager(new HookRegistry()))
                ->primeSessionPrompt($this->sessionApp());
        } catch (\Throwable) {
            // See the docblock: the child rebuilds and owns the error.
        }
    }

    /**
     * The context-window share the step about to be sent will use, for the
     * `<turn-context>` row ({@see TurnContextBlock::withContextPercent()}),
     * or null when the window is unknown.
     *
     * The previous step's prompt as its provider counted it plus an estimate
     * of the rows added since — the anchor the 2.1 pressure check uses — and
     * on a turn's first step, before any count, the estimate of the whole
     * history. FLOORED TO 5%: the row is re-sent whenever its bytes change,
     * and a figure that moved every step would add a row every step.
     *
     * @param list<TypedMessage>  $rows
     * @param array{?int, int}    $anchor previous step's prompt tokens and row count
     */
    private function contextPercentAtStepTop(array $rows, array $anchor, ?int &$window): ?int
    {
        if ($window === null) {
            try {
                $window = $this->contextWindow();
            } catch (\Throwable) {
                $window = 0;
            }
        }
        if ($window <= 0) {
            return null;
        }

        [$anchorTokens, $anchorRows] = $anchor;
        $tokens = $anchorTokens !== null
            ? $anchorTokens + \SugarCraft\Crush\Context\ContextPressure::ofMessages(\array_slice($rows, $anchorRows))
            : \SugarCraft\Crush\Context\ContextPressure::ofMessages($rows);

        return intdiv(intdiv($tokens * 100, $window), 5) * 5;
    }

    /**
     * The final no-tools request of a turn the harness stopped (WAVE_PLAN_2 §5),
     * answering the summary's assistant message — or null when the provider
     * produced none.
     *
     * One more request over the turn's whole transcript plus a user message
     * naming why the turn stopped, saying not to call tools, and asking for
     * done / remaining / next. Since roadmap 2.4-1 it is
     * {@see \SugarCraft\Crush\Context\Compaction\StepSummarizer::summaryStep()},
     * the request every engine-side summary makes: the tools stay ADVERTISED,
     * so the request is the previous step's request plus one row and its whole
     * prefix is already in the provider's cache — a tool-less request changed
     * the tool block and re-prefilled the turn — and they can never RUN,
     * because the reply is taken the moment the assistant message arrives,
     * before Runtime dispatches a call, and any calls it asked for anyway are
     * dropped from it. It streams on the turn's own token channel, so the
     * operator sees it arrive like any other reply, and its usage is billed
     * into the turn like any other step.
     *
     * The exchange is appended to `$transcript`: it IS what the conversation
     * now holds, and a resumed delegated run ({@see completeTranscript()})
     * must see that it already summarised rather than replay a dangling
     * tool-result tail. With its calls dropped, the reply leaves no call
     * without a result — and no call runs, so `$onEvent` has nothing to carry.
     *
     * @param list<TypedMessage> $transcript
     * @param list<?Usage>       $stepUsages
     */
    private function summariseStoppedTurn(
        Runtime $runtime,
        App $app,
        array &$transcript,
        ?string $loopedTool,
        ?callable $onEvent,
        ?callable $tokenSink,
        ?callable $progressSink,
        ?callable $onHeartbeat,
        array &$stepUsages,
    ): ?AssistantMessage {
        $request = new UserMessage($loopedTool === null
            ? sprintf(self::BUDGET_EXHAUSTED_SUMMARY_PROMPT, $this->maxSteps)
            : sprintf(self::LOOP_GUARD_SUMMARY_PROMPT, $loopedTool, ToolCallLoopGuard::END_TURN_AT));

        $assistant = \SugarCraft\Crush\Context\Compaction\StepSummarizer::summaryStep(
            $runtime,
            $app->withMessages($transcript),
            $request,
            function (AssistantMessage $assistant) use (&$stepUsages): void {
                $stepUsages[] = $assistant->usage();
                $this->siblingSpend?->record($assistant->usage());
                if ($this->stepUsageObserver !== null) {
                    ($this->stepUsageObserver)($assistant->usage());
                }
                // A request like any other step's, carrying the same marks.
                $this->observeCacheHealth($assistant->usage());
            },
            $tokenSink,
            $progressSink,
            $onHeartbeat,
        );

        $transcript = [
            ...$transcript,
            $request,
            ...($assistant !== null ? [$assistant] : []),
        ];

        return $assistant;
    }

    /**
     * Hand one provider response's usage to the prompt-cache health
     * diagnostic ({@see \SugarCraft\Crush\Providers\CacheBreakpoints::observeCacheHealth()}, P10.S3) —
     * but only while the provider marks this model's requests
     * ({@see MarksPromptCache}). "Nothing is being cached" is only a fault for
     * a request that asked to be cached: `openai` and `sglang` cache without
     * marks, Gemini on `vertex` caches on its own, and the `promptCache`
     * setting or `SUGARCRUSH_DISABLE_PROMPT_CACHE` may have turned the marks
     * off, so none of those is ever warned. The notice itself is raised once,
     * by the watch.
     *
     * Roadmap 3.B-5 (DCP §13.2 P2-10): every step's split also feeds the
     * watch's BREAK tracker ({@see CacheHealthWatch::observeReuse()}) — for
     * every provider that reports one, marks or not — which counts a request
     * that lost the prefix the one before it had cached, and says so once
     * when the cache does not recover after a context rewrite. A turn runs
     * on one backend instance from its first step to its last, so the
     * instance names the conversation: a delegated run's steps, observed on
     * the same shared watch, are never compared with its parent's.
     */
    private function observeCacheHealth(?Usage $usage): void
    {
        $this->cacheHealth->observeReuse($usage, conversation: (string) spl_object_id($this));

        if (!$this->provider instanceof MarksPromptCache || !$this->provider->marksPromptCache($this->model)) {
            return;
        }

        $this->cacheHealth->observe($usage);
    }

    /**
     * How many prompt-cache breaks this session has seen — requests that read
     * under half of the prefix the request before them had cached
     * ({@see CacheHealthWatch::observeReuse()}, roadmap 3.B-5, DCP §13.2
     * P2-10). Read by `/context`. Every clone of this backend shares one
     * watch, and a forked turn's count rides home on its result frame, so
     * the hosted Chat's copy answers for every turn the session ran.
     */
    public function cacheBreaks(): int
    {
        return $this->cacheHealth->cacheBreaks();
    }

    /**
     * The newest cache break as the cached share before and after it, in
     * whole percent, or null when there has been none — see {@see cacheBreaks()}.
     *
     * @return array{from: int, to: int}|null
     */
    public function lastCacheBreak(): ?array
    {
        return $this->cacheHealth->lastCacheBreak();
    }

    /**
     * The part of a tool's own provider spend that is folded into the calling
     * turn's {@see Usage}: tokens, dollars and the unpriced signal — and NOT
     * the prompt-side buckets (audit B4).
     *
     * WHY SPEND-ONLY. The turn's Usage is read two ways. As SPEND —
     * {@see \SugarCraft\Crush\Chat}'s session tracker, `/cost`, and the cap
     * check above — where a delegated run's tokens and dollars are as real as
     * the caller's own and must be counted. And as CONTEXT SIZE — Chat's E17
     * estimate calibration pairs {@see Usage::promptTokens()} against the
     * prompt THIS conversation sent, and Renderer's cache-hit readout divides
     * cacheReadTokens by it. A sub-agent's prompt is a different conversation
     * with a different context; its input/cache buckets summed into the
     * caller's would make both readings describe a prompt nobody sent.
     * {@see Usage::plus()} keeps the caller's reported bucket when the other
     * side's is null, so leaving the buckets unreported here leaves the
     * caller's promptTokens() exactly as its own steps reported it.
     *
     * outputTokens/reasoningTokens are left out too: the completion-side
     * buckets would then describe the caller's input against everyone's
     * output, a split no reader could interpret. The full sub-agent usage
     * stays on the {@see \SugarCraft\Crush\Tools\ToolResult} for any reader
     * that wants the whole record.
     *
     * The tokens are also marked as the turn's DELEGATED share
     * ({@see Usage::$delegatedTokens}, audit B4-rem). Chat's calibration
     * falls back to totalTokens when the provider reported no prompt buckets,
     * and delegated tokens widen that figure into something no prompt was;
     * {@see Usage::ownTokens()} is the total without them, for that fallback
     * to read. Dropping the tokens here instead would under-report the
     * session total.
     */
    private static function delegatedSpend(Usage $usage): ?Usage
    {
        return Usage::reported(
            $usage->totalTokens,
            $usage->costUsd,
            unpricedModel: $usage->unpricedModel,
            delegatedTokens: $usage->totalTokens,
        );
    }

    /**
     * The turn's "still alive" beat for work that waits in the turn's own
     * process ({@see turnTools()}' delegated runs, the `Stop` chain's hooks):
     * the bare batch beat (`$onHeartbeat`), else an EMPTY reasoning delta —
     * the "alive, nothing to show" frame E456 established, so neither channel
     * paints anything. At most one beat a second, and bound to the current pid
     * (a forked tool child must not write onto its parent's socket). Null when
     * the turn has neither channel.
     *
     * @return (\Closure(): void)|null
     */
    private static function throttledHeartbeat(?callable $onHeartbeat, ?callable $onReasoning): ?\Closure
    {
        if ($onHeartbeat === null && $onReasoning === null) {
            return null;
        }
        $pid = getmypid();
        $lastBeat = 0.0;

        return static function () use ($pid, $onHeartbeat, $onReasoning, &$lastBeat): void {
            if (getmypid() !== $pid) {
                return;
            }
            $now = microtime(true);
            if ($now - $lastBeat < 1.0) {
                return;
            }
            $lastBeat = $now;
            $onHeartbeat !== null ? $onHeartbeat() : $onReasoning('');
        };
    }

    /**
     * This turn's tool list, with every {@see DelegatesToEngine} tool bound to
     * THIS engine — see that interface for why the binding has to happen here,
     * per turn, rather than at construction.
     *
     * The heartbeat is {@see throttledHeartbeat()}: rate-limited to one beat a
     * second because a delegated run reports every provider chunk, and bound
     * to the current pid because a forked tool child must not write onto a
     * socket its parent is also writing to (the parent beats for its forked
     * group itself — {@see Runtime}'s concurrent wait loop).
     *
     * The sub-agent emitter gets the same pid binding for the same socket-
     * corruption reason, but NO rate limit: TaskTool throttles its own beats
     * (tool-boundary frames always, reasoning frames at most one a second),
     * and the events it is rare enough to be worth every one. It exists
     * because the delegated run happens inside THIS process when the turn
     * itself was forked — without a wire back, the parent's AgentManager never
     * learns a sub-agent exists ({@see SubAgentActivity}). A NESTED `Task`
     * (roadmap 4.7-3) keeps the emitter it was handed instead: the
     * delegating run's, which is the one that reaches the parent.
     *
     * The bound engine carries `$spentSoFarUsd` as its {@see $turnSpendProbe},
     * so the delegated run's spend cap starts from what this turn has really
     * spent by then, not from this turn's starting baseline (audit B4).
     *
     * Roadmap 3.B-3: every {@see \SugarCraft\Crush\Tools\MutatesContextLedger}
     * tool (the model's `Prune`, `Compress` and `Recall`) is bound to THE
     * TURN'S OWN ledger — the caller's `$turnLedger` and `$turnApp`
     * variables, taken by reference, so what the tool applies is what the
     * turn's next request is projected through, with no copy to fold back
     * after the step. Bound to the current pid like the heartbeat: a write
     * from any other process would land in a copy of the turn and vanish.
     * Offered only where this engine runs over a ledger ({@see $contextLedger})
     * whose mode lets the model prune
     * ({@see \SugarCraft\Crush\Context\Pruning\PruningMode::allowsModelPruning()}),
     * or — `Compress` alone — on a `/compress` turn ($compressTriggered) in a
     * `manual` session; anywhere else — `-p`, a session in `off`, `Prune` in
     * `manual` — the tool is left out of the turn, so its schema is never
     * sent. That ledger is a host's session ledger or — roadmap 3.B-5 — the
     * EPHEMERAL one {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} gives a
     * delegated run granted a ledger tool in the `auto` mode; the engine
     * bound below for a delegated run never carries this one.
     *
     * Roadmap N-P4c: every tool first passes through
     * {@see \SugarCraft\Crush\Tools\ToolLimits::applyTo()} with the bounds the
     * merged config sets now (output caps, Read's page, Glob's count,
     * WebFetch's timeout and memory bound, and — roadmap N-P4f — the
     * session's `Task` its delegation depth and concurrency caps), so a saved
     * limit reaches the tools the launch built from the next turn on. Unset
     * keys leave each tool as it was built.
     *
     * @param \Closure(): float $spentSoFarUsd
     *
     * @return list<Tool>
     */
    private function turnTools(?callable $onReasoning, ?callable $onHeartbeat, ?callable $onEvent, ?\Closure $spentSoFarUsd = null, ?\SugarCraft\Crush\Context\Pruning\ContextLedger &$turnLedger = null, ?App &$turnApp = null, bool $compressTriggered = false): array
    {
        $heartbeat = self::throttledHeartbeat($onHeartbeat, $onReasoning);

        $subAgentEmitter = null;
        if ($onEvent !== null) {
            $pid = getmypid();
            $subAgentEmitter = static function (SubAgentActivity $activity) use ($pid, $onEvent): void {
                if (getmypid() !== $pid) {
                    return;
                }
                $onEvent($activity);
            };
        }

        $ledgerRead = null;
        $ledgerApply = null;
        // A `/compress` turn binds `Compress` in `manual` too: the person
        // asked for that one compaction, which is what `manual` leaves them.
        // `Prune` stays the `auto` mode's alone.
        $pruningMode = $this->contextLedger?->effectiveMode();
        $modelPrunes = $pruningMode?->allowsModelPruning() ?? false;
        $explicitCompress = $compressTriggered && $pruningMode === \SugarCraft\Crush\Context\Pruning\PruningMode::Manual;
        if ($this->contextLedger !== null && ($modelPrunes || $explicitCompress)) {
            $sessionLedger = $this->contextLedger;
            $ledgerPid = getmypid();
            $ledgerRead = static function () use (&$turnLedger, &$turnApp, $sessionLedger): array {
                return [$turnLedger ?? $turnApp?->contextLedger ?? $sessionLedger, $turnApp?->messages ?? []];
            };
            $ledgerApply = static function (\SugarCraft\Crush\Context\Pruning\LedgerDelta $delta) use (&$turnLedger, &$turnApp, $sessionLedger, $ledgerPid): ?\SugarCraft\Crush\Context\Pruning\ContextLedger {
                if (getmypid() !== $ledgerPid) {
                    return null;
                }
                $turnLedger = ($turnLedger ?? $turnApp?->contextLedger ?? $sessionLedger)->apply($delta);
                $turnApp = $turnApp?->withContextLedger($turnLedger);

                return $turnLedger;
            };
        }

        $limits = \SugarCraft\Crush\Tools\ToolLimits::fromConfig(self::userConfig());

        $bound = null;
        $tools = [];
        foreach ($this->tools as $tool) {
            $tool = $limits->applyTo($tool);
            if ($tool instanceof \SugarCraft\Crush\Tools\MutatesContextLedger) {
                if ($ledgerRead !== null && $ledgerApply !== null && ($modelPrunes || $tool instanceof \SugarCraft\Crush\Tools\BuiltIn\Compress)) {
                    $tools[] = $tool->withLedger($ledgerRead, $ledgerApply);
                }

                continue;
            }
            if (!$tool instanceof DelegatesToEngine) {
                $tools[] = $tool;

                continue;
            }
            // The bound copy never inherits THIS run's sibling ledger: a run it
            // delegates is billed back into this one as a tool result (and
            // recorded from here), so recording it a second time under the
            // same member would count it twice.
            // Nor this session's context ledger (roadmap 2.2-2): a delegated
            // run's conversation is its own, and its refs and prunes must
            // never be read against — or handed back as — the parent's.
            // Nor this run's mailbox (P-D1): a message sent to this run is
            // not one sent to the run it delegates, which binds its own.
            $bound ??= $spentSoFarUsd === null
                ? $this->mutate(['siblingSpend' => null, 'contextLedger' => null, 'turnInbox' => null])
                : $this->mutate(['turnSpendProbe' => $spentSoFarUsd, 'siblingSpend' => null, 'contextLedger' => null, 'turnInbox' => null]);
            // Roadmap 4.7-3: a nested `Task` (one a delegated run handed its
            // sub-agent) arrives already carrying the emitter that reaches
            // the parent — the delegating run's. It keeps it: this turn's
            // $onEvent is that run's own tool-event callback, which neither
            // takes a SubAgentActivity nor could carry one anywhere.
            $emitter = $tool instanceof \SugarCraft\Crush\Tools\BuiltIn\TaskTool && $tool->delegationDepth() > 0
                ? $tool->subAgentEmitter()
                : $subAgentEmitter;
            $boundTool = $tool->withEngine($bound, $heartbeat, $emitter);
            // Roadmap 5.7-2: `AskUser` and `PlanExit` put their question to the
            // turn's own approver — the 1.C modal in the TUI child (which binds
            // its channel's approver here before the turn), `permission.requested`
            // under `serve`. `Task` relays its run's asks through its own
            // channel and is left alone; a headless run has already marked the
            // two tools ({@see \SugarCraft\Crush\Cli\NonInteractive}), so they
            // still refuse.
            if ($boundTool instanceof \SugarCraft\Crush\Tools\RelaysPermissionAsks
                && !$boundTool instanceof \SugarCraft\Crush\Tools\BuiltIn\TaskTool
                && $this->permissionApprover !== null) {
                $boundTool = $boundTool->withPermissionApprover($this->permissionApprover);
            }
            $tools[] = $boundTool;
        }

        return $tools;
    }

    /**
     * $tools with every ledger tool `turnTools()` bound to the turn's ledger
     * also bound to the turn's PreCompact gate (roadmap 3.B-3, DCP §13.2 F):
     * the model's `Prune` and `Compress` are compactions, and a hook that
     * refuses compactions refuses them. Every other tool is returned as it was.
     *
     * Roadmap 3.B-4: `Compress` is MANUAL by default
     * ({@see \SugarCraft\Crush\Tools\BuiltIn\Compress::MODE_DEFAULT}) — it is
     * offered only on a turn the person started with `/compress`
     * ({@see \SugarCraft\Crush\Tools\BuiltIn\Compress::isTriggered()}), and
     * that turn may make one successful call. On every other turn it is left
     * out, so its schema is never sent — unless the person set
     * `contextPruning.compress: auto` ($unprompted,
     * {@see CompactorConfig::offersCompressUnprompted()}): then a `Compress`
     * {@see turnTools()} bound (the pruning mode's `auto`, where `Prune` is
     * offered too) stays on every turn, with no one-call allowance.
     *
     * @param list<Tool>                                     $tools
     * @param \Closure(string $trigger, string $focus): ?string $preCompact
     * @param list<TypedMessage>                             $messages the turn's incoming rows
     *
     * @return list<Tool>
     */
    private static function gatedLedgerTools(array $tools, \Closure $preCompact, array $messages = [], bool $unprompted = false): array
    {
        $triggered = \SugarCraft\Crush\Tools\BuiltIn\Compress::isTriggered(array_values($messages));
        $budget = 1;
        $allowance = static function (bool $spend) use (&$budget): bool {
            if ($budget <= 0) {
                return false;
            }
            if ($spend) {
                $budget--;
            }

            return true;
        };

        $gated = [];
        foreach ($tools as $tool) {
            if ($tool instanceof \SugarCraft\Crush\Tools\BuiltIn\Compress) {
                if ($triggered) {
                    $tool = $tool->withAllowance($allowance);
                } elseif (!$unprompted) {
                    continue;
                }
            }
            $gated[] = $tool instanceof \SugarCraft\Crush\Tools\MutatesContextLedger
                ? $tool->withCompactionGate($preCompact)
                : $tool;
        }

        return $gated;
    }

    /**
     * Whether this run may fan a same-turn batch of
     * {@see \SugarCraft\Crush\Tools\ParallelSafe} calls out concurrently.
     *
     * {@see Runtime} has carried the switch since crush_code.md Phase 0 item
     * 14, but nothing outside its own tests ever passed it: a user hitting a
     * bad interaction with concurrency had no way to turn it off short of
     * editing this file. This is that way.
     *
     * Both routes follow conventions the lib already has rather than inventing
     * a mechanism: a `SUGARCRUSH_DISABLE_*` presence flag with exactly the
     * semantics {@see \SugarCraft\Crush\Chat}'s `SUGARCRUSH_DISABLE_MOUSE`
     * uses (set and neither empty nor "0"), and a key in the same
     * ~/.sugar-crush/config.json {@see Bootstrap::readUserConfig()} already
     * owns. Precedence mirrors {@see Bootstrap::backend()}'s: the env var is
     * the per-invocation override and wins over the persisted preference.
     *
     * Only a literal `false` in the config file disables — a missing key, and
     * anything that is not a bool, means "unset", so a typo cannot silently
     * turn concurrency off.
     *
     * @param ?array<string, mixed> $config the already-read user config;
     *                                      null reads it, which is what the
     *                                      resolver's own tests want
     */
    private static function parallelToolCallsEnabled(?array $config = null): bool
    {
        $flag = getenv(self::PARALLEL_TOOL_CALLS_DISABLE_ENV);
        if ($flag !== false && $flag !== '' && $flag !== '0') {
            return false;
        }

        $config ??= self::userConfig();

        return ($config[self::PARALLEL_TOOL_CALLS_CONFIG_KEY] ?? null) !== false;
    }

    /**
     * The persisted user config, or nothing.
     *
     * Guarded because reading it is the only filesystem access {@see
     * complete()} performs, and a missing, unreadable or malformed config must
     * cost the DEFAULT dispatch settings, never the turn.
     *
     * @return array<string, mixed>
     */
    private static function userConfig(): array
    {
        try {
            return Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The turn's idle ceiling in whole seconds: the `turnIdleTimeoutSeconds`
     * setting, else {@see COMPLETE_TIMEOUT_SECONDS} - roadmap N-P4a.
     *
     * Read by the PARENT, once per turn, before the fork
     * ({@see completeAsync()}, {@see summariseAsync()}), which is what makes
     * the key next-turn with no plumbing: a save lands in the merged config
     * and the next fork arms the new timer. The running turn keeps the timer
     * it was born with.
     *
     * IT BOUNDS SILENCE, NEVER A TOTAL: the timer is re-armed on every frame
     * the child writes, so a larger value buys a slow provider more quiet
     * between chunks, not a deadline on the turn. There is no "off": a turn
     * whose child hangs must still die, so values under
     * {@see MIN_TURN_IDLE_TIMEOUT_SECONDS}, non-numbers and non-finite floats
     * fall back to the default rather than being clamped (the same doctrine
     * as {@see parallelToolDeadlineSeconds()}). A fraction is truncated: the
     * timer the parent arms takes whole seconds of silence as well as any.
     *
     * @param ?array<string, mixed> $config the already-read user config;
     *                                      null reads it
     */
    public static function turnIdleTimeoutSeconds(?array $config = null): int
    {
        $config ??= self::userConfig();
        $raw = $config[self::TURN_IDLE_TIMEOUT_CONFIG_KEY] ?? null;

        if (is_string($raw)) {
            $raw = is_numeric($raw) ? $raw + 0 : null;
        }

        if (!is_int($raw) && !(is_float($raw) && is_finite($raw))) {
            return self::COMPLETE_TIMEOUT_SECONDS;
        }

        // Judged before the cast, on the value's own magnitude: an
        // overflowing (int) would wrap rather than saturate.
        if ($raw < self::MIN_TURN_IDLE_TIMEOUT_SECONDS || (is_float($raw) && $raw >= (float) PHP_INT_MAX)) {
            return self::COMPLETE_TIMEOUT_SECONDS;
        }

        return (int) $raw;
    }

    /**
     * The wall-clock budget one concurrent group gets, as configured.
     *
     * The ceiling is not a preference: the group deadline is enforced INSIDE
     * the forked completion child, and no frame reaches the parent while a
     * group is executing, so a group allowed to outlive the turn's idle
     * ceiling ({@see turnIdleTimeoutSeconds()}; {@see COMPLETE_TIMEOUT_SECONDS}
     * by default) would have the whole turn SIGKILLed from above — losing
     * every sibling's result — instead of the one stuck call being reported
     * as a failed call. A configured value at or past that ceiling therefore
     * cannot be honoured, and neither can a zero or negative one.
     *
     * THE CEILING IS THE ONE THE PARENT ARMED (N-P4a): inside a forked turn
     * it is {@see $forkIdleCeilingSeconds}, the value the parent resolved
     * before the fork; anywhere else (a sync turn, a test) it is resolved off
     * the same config this reads. And the fallback is held under it too: an
     * operator who lowered the idle ceiling below
     * {@see Runtime::PARALLEL_TOOL_DEADLINE_SECONDS} gets a group deadline one
     * second under their ceiling, not a default that would outlive it.
     *
     * Nonsense falls back to {@see Runtime::PARALLEL_TOOL_DEADLINE_SECONDS}
     * rather than being clamped, matching how
     * {@see \SugarCraft\Crush\Providers\Concerns\HttpClientDefaults} treats an
     * out-of-range `SUGARCRUSH_CONNECT_TIMEOUT`: an operator who asked for
     * something impossible is better served by the documented default than by
     * a silently different number they never chose.
     *
     * A REJECTED env value falls through to the config exactly as an absent
     * one does, rather than jumping straight to the default. Both sources are
     * independent statements of intent, and the env var only outranks the
     * config when it actually says something: `SUGARCRUSH_PARALLEL_TOOL_DEADLINE=abc`
     * silently discarding a deliberately persisted `45` is the same bug shape
     * {@see parallelToolCallsEnabled()} already avoids by treating a
     * not-really-set flag as unset.
     *
     * @param ?array<string, mixed> $config the already-read user config;
     *                                      null reads it, which is what the
     *                                      resolver's own tests want
     */
    private static function parallelToolDeadlineSeconds(?array $config = null): int
    {
        $config ??= self::userConfig();
        $ceiling = self::$forkIdleCeilingSeconds ?? self::turnIdleTimeoutSeconds($config);

        $env = getenv(self::PARALLEL_TOOL_DEADLINE_ENV);
        $seconds = self::honourableDeadline($env === false ? null : $env, $ceiling);
        if ($seconds !== null) {
            return $seconds;
        }

        return self::honourableDeadline($config[self::PARALLEL_TOOL_DEADLINE_CONFIG_KEY] ?? null, $ceiling)
            ?? min(Runtime::PARALLEL_TOOL_DEADLINE_SECONDS, $ceiling - 1);
    }

    /**
     * One source's proposed deadline, or null if this source has not usably
     * asked for anything — the shared shape that lets env and config be judged
     * by identical rules.
     *
     * Any finite number is accepted and truncated toward zero, whatever type
     * carried it: `SUGARCRUSH_PARALLEL_TOOL_DEADLINE=45.7` and a JSON
     * `"parallelToolDeadlineSeconds": 45.9` are the same request expressed in
     * the two ways the two sources can express it, and both mean 45. (An env
     * var has no type but string, so rejecting the float in JSON while
     * honouring it in the environment was an artifact of where the value came
     * from, not a judgement about the value.) Sub-second precision is dropped
     * rather than honoured because {@see Runtime} takes whole seconds.
     *
     * @param int $ceiling the turn's idle ceiling; the deadline must be under it
     */
    private static function honourableDeadline(mixed $raw, int $ceiling = self::COMPLETE_TIMEOUT_SECONDS): ?int
    {
        if (is_string($raw)) {
            // "" is the shape an env var that is set-but-empty arrives in, and
            // means unset here as everywhere else in this lib.
            $raw = is_numeric($raw) ? $raw + 0 : null;
        }

        // is_float excludes bool/null/array; NAN and INF are excluded because
        // casting a non-finite float to int is undefined.
        if (!is_int($raw) && !(is_float($raw) && is_finite($raw))) {
            return null;
        }

        // Compared before the cast, so an out-of-range float is rejected on its
        // own value rather than on whatever an overflowing (int) produced.
        if ($raw < 1 || $raw >= $ceiling) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The operator's per-request OUTPUT ceiling in tokens, or null when no
     * usable one was configured - E707 (round 81).
     *
     * NULL IS A REAL ANSWER, NOT A FALLBACK: it reaches
     * {@see CompleteRequest::$maxTokens} unset, every provider's existing
     * `?? default` line then applies, and an operator who never set the key
     * gets byte-identical requests forever. This resolver must never grow a
     * default of its own; the defaults live with the providers that
     * documented them.
     *
     * Nonsense answers null rather than clamping, the same doctrine
     * {@see parallelToolDeadlineSeconds()} records for impossible values -
     * but note the different fallback: an impossible deadline still needs A
     * number, while an impossible ceiling needs no number at all. Accepted:
     * any finite positive numeric (a JSON int, or a numeric string from a
     * hand-edited file), truncated toward zero like the deadline parser,
     * because a sub-token fraction is not a request parameter. (The step
     * ceiling in `Bootstrap::resolvedMaxToolSteps()` refuses fractions
     * instead, because docs/SETTINGS.md promises that for its key; nothing
     * documents the opposite here, and `2047.9` asking for 2047 tokens is
     * nearer the operator's intent than silently sending no override at all.)
     * There is no upper bound here: the maximum is model- and
     * provider-specific, and the server's own rejection is the honest
     * authority on it — clamping silently here would guess a ceiling this
     * class cannot know.
     *
     * "NO UPPER BOUND" STOPS AT THE INT TYPE (audit 15d-16): a float at or
     * past 2**63 - `1e19`, or a numeric string too long for an int, which
     * `+ 0` turns into one - has no int to become, and `(int)` of it WRAPS
     * rather than saturating, so `1e19` used to put a NEGATIVE `max_tokens`
     * on the wire and draw a provider 400 on every request. No server can be
     * the authority on a value the request cannot even carry, so such a value
     * answers null, exactly as INF already did, rather than being clamped to
     * a ceiling this class would have to invent.
     *
     * @param ?array<string, mixed> $config the already-read user config;
     *                                      null reads it
     */
    private static function maxOutputTokens(?array $config = null): ?int
    {
        $config ??= self::userConfig();
        $raw = $config[self::MAX_OUTPUT_TOKENS_CONFIG_KEY] ?? null;

        if (is_string($raw)) {
            $raw = is_numeric($raw) ? $raw + 0 : null;
        }

        if (!is_int($raw) && !(is_float($raw) && is_finite($raw))) {
            return null;
        }

        // Judged before the cast, on the value's own magnitude. (float)
        // PHP_INT_MAX is exactly 2**63, the first float past the int range;
        // an int $raw is in range by construction and must not be compared
        // against it (int-vs-float comparison would reject PHP_INT_MAX).
        if ($raw < 1 || (is_float($raw) && $raw >= (float) PHP_INT_MAX)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Runs {@see complete()} - one or more real, blocking provider HTTP calls
     * plus any tool execution the agentic loop drives - in a forked child so
     * the caller's event loop (the TUI's render/input loop) never blocks on
     * it. Without this, `Program`'s `futureTick()`-scheduled Cmd execution
     * calls this method's factory closure directly on the loop: the old
     * implementation wrapped a *synchronous* {@see complete()} call in a
     * `React\Promise\Promise` whose executor runs immediately (that's the
     * Promise constructor's contract, not deferred), so the "async" call was
     * really just a blocking one wearing a Promise - the whole terminal
     * froze (no spinner animation, no keystrokes, no Ctrl+C) for the full
     * duration of every provider round-trip. Forking moves that blocking
     * work off the loop entirely; the parent only watches a non-blocking
     * socket via {@see Loop::addReadStream()} for the result, so rendering
     * and input keep flowing while a turn is in flight - same rationale as
     * {@see \SugarCraft\Crush\Chat::executeToolsParallel()}'s R14b fork fix,
     * extended here to cross the ReactPHP loop boundary rather than just
     * fanning out sibling tool calls.
     *
     * The child does not batch: it writes each {@see ToolStarted}/{@see
     * ToolFinished} as its own length-prefixed frame the moment the event
     * fires, each chunk of assistant text as a `token` frame the moment the
     * provider's stream produces it, every OTHER chunk off the wire as a
     * `reasoning` frame (E456 - the model's thinking when the chunk carries
     * any, an empty `text` when it carries only tool-call structure or only
     * usage figures), one more of those same empty-`text` `reasoning` frames
     * per heartbeat when a BATCH turn's transport can fire one from inside the
     * blocking call (E493 - the child's frame-writer threaded through
     * {@see complete()}; see {@see \SugarCraft\Crush\Providers\CompleteRequest::$onHeartbeat}
     * for which transports can and which cannot), and the final result as the
     * last frame. The parent drains
     * whatever whole frames have arrived on every readable edge and hands each
     * straight to $onEvent/$onToken/$onReasoning, so a turn running
     * eight rounds of tools renders them as they happen instead of showing
     * nothing but a "thinking" spinner until the very end (crush_feat.md §1
     * E1), and the reply itself appears as it is written rather than all at
     * once at the end (crush_code.md Phase 0 item 13).
     *
     * Text and events share this one channel deliberately. Their relative
     * order is meaningful — it is the difference between "the model explained
     * itself and then ran a command" and "it ran a command and then explained"
     * — and two parallel channels could not preserve it.
     *
     * @param ?callable $onReasoning Optional live observer of the model's
     *                           reasoning, signature
     *                           `function(string $delta): void`. Purely
     *                           additive: the timer fix E456 exists for does
     *                           not depend on anyone passing one, because the
     *                           frame is written by the child and the deadline
     *                           is reset by the parent whether or not this is
     *                           null. Pass one to PAINT the thinking; leave it
     *                           null and the turn simply survives quietly.
     * @param bool $interactive  Roadmap 1.C-1: put every ASK the turn raises
     *                           to `$onEvent` and wait for the answer, rather
     *                           than settling it in the child. Callers spell
     *                           this {@see completeInteractive()}, which is
     *                           the contract ({@see InteractiveTurn}); it is a
     *                           parameter here only because this method's
     *                           body is the one place the fork, the socket and
     *                           the timers all live.
     * @param ?callable $onStep  Roadmap 1.C-4: observer of the turn's `step`
     *                           and `usage` frames, signature
     *                           `function(StepStarted|UsageUpdated $event): void`
     *                           — the step number and context pressure before
     *                           each provider call, and each response's usage
     *                           (with the turn's running total) as it is
     *                           billed. Display only, like $onReasoning.
     *
     * Soft cancel (roadmap 1.C-4): once `$cancellation->isSoftCancelled()`,
     * the parent writes ONE `cancel_soft` frame down to the child, which lets
     * the step's tools finish and ends the turn at the next step boundary;
     * the turn then settles normally, with its reply. A hard cancel
     * (`isCancelled()`) still tears the whole tree down at once.
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null, bool $interactive = false, ?callable $onStep = null): PromiseInterface
    {
        $interactive = $interactive && $onEvent !== null;
        $deferred = new Deferred();

        // Costs one WNOHANG syscall per tracked straggler and buys back every
        // child an earlier turn's bounded reap had to give up on. See
        // self::$unreapedChildren.
        self::sweepUnreapedChildren();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        // Step 1.A-2: in the parent, before the fork — see beginSessionTurn().
        $this->beginSessionTurn($history);

        // Roadmap 5.5-5: the session's symbol-level repo map, captured HERE
        // in the parent once per session (Runtime::primeSymbolMap() holds it
        // in the session memo until a refresh point), so every turn's child
        // inherits the same bytes and the system prompt never moves for it.
        // The capture is bounded and degrades to no map (SymbolMapBlock);
        // `SUGARCRUSH_DISABLE_SYMBOL_MAP` turns it off.
        if (!\SugarCraft\Crush\Context\SymbolMapBlock::disabledByEnvironment()) {
            try {
                $this->newRuntime(new HookManager(new HookRegistry()))->primeSymbolMap($this->sessionApp());
            } catch (\Throwable) {
                // No map this turn; the child assembles without the slot.
            }
        }

        // Roadmap 5.3-2: this turn's memory recall, ranked HERE in the parent
        // before the fork, so the note reads and the one embedding request
        // happen once per turn and the child's `<turn-context>` row reads the
        // ranking warm (Runtime::memoryRecall()). Embeddings are opt-in
        // (`embeddingModel`): without one the ranking is keyword-only, and a
        // configured model that fails degrades to keyword-only and is
        // reported once per process rather than silently.
        if ($this->memoryStore !== null) {
            $embeddingModel = self::userConfig()['embeddingModel'] ?? null;
            $embeddingModel = \is_string($embeddingModel) ? trim($embeddingModel) : '';
            $embedder = null;
            if ($embeddingModel !== '') {
                $provider = $this->provider;
                $vectors = \SugarCraft\Crush\Memory\EmbeddingCache::new();
                $embedder = static fn (array $texts): array => $vectors->vectors(
                    $embeddingModel,
                    $texts,
                    static fn (array $batch): array => $provider->embeddings(
                        new \SugarCraft\Crush\Providers\EmbeddingsRequest($embeddingModel, $batch),
                    )->embeddings,
                );
            }
            try {
                $recall = $this->newRuntime(new HookManager(new HookRegistry()))->primeMemoryRecall(
                    $this->sessionApp(),
                    \SugarCraft\Crush\Context\MemoryRecallBlock::queryFrom($history),
                    $embedder,
                );
                $degraded = $recall->degradedReason();
                if ($degraded !== null && \SugarCraft\Crush\Context\MemoryRecallBlock::firstReportOf($degraded)) {
                    \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn(sprintf(
                        'Memory recall: embedding model "%s" failed (%s); ranking memory notes by keyword only.',
                        $embeddingModel,
                        $degraded,
                    ));
                }
            } catch (\Throwable) {
                // Left to the child, which ranks keyword-only on first use.
            }
        }

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning, $interactive);
        }

        // N-P4a: this turn's idle ceiling, resolved HERE in the parent before
        // the fork — the timer below is armed with it and the child holds its
        // parallel-group deadline under it ($forkIdleCeilingSeconds), so a
        // save mid-turn applies to the next turn and never splits the two.
        $idleSeconds = self::turnIdleTimeoutSeconds();

        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning, $interactive);
        }

        [$parentSocket, $childSocket] = $sockets;
        // O-2a: the runtime-notice sink this turn belongs to — the session's,
        // which a host running several selects with RuntimeNoticeSink::using()
        // around this call — read HERE, in the parent, at the moment of the
        // fork, and pinned in the child below.
        $noticeSink = \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::current();
        // E692 (Phase 9 scope call, lane bc): this pcntl_fork is an exec-free
        // in-process fork, deliberately OUTSIDE ProcessContainment's remit — the
        // choke point contains COMMAND children (spawn→env→detach), while a fork
        // of this process runs our own code with no argv, no PATH lookup, and no
        // interactive-prompt surface to fail fast against.
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);

            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning, $interactive);
        }

        if ($pid === 0) {
            // O-3a: under `sugarcrush serve` this child inherited the
            // listener and every browser socket. They go before anything
            // else: held here, the listener keeps the port bound after the
            // server closes it and accepts into a backlog nobody reads
            // (o0-spikes (c)). A no-op in the TUI and `-p`, which register
            // nothing.
            \SugarCraft\Crush\Support\ForkedChild::closeInheritedServerFds();
            // B3. The parent's end goes FIRST: a copy of it held here would
            // keep the socket half-open after the parent closes its own, so a
            // child whose parent is gone would never see EPIPE. Then the
            // child's end is made close-on-exec, so no command the turn spawns
            // (Bash, Grep, hooks) inherits the write end and holds the parent's
            // EOF hostage for as long as a backgrounded `npm run dev &` lives.
            // Forks still inherit it — CLOEXEC is an exec rule — which is why
            // the parent also watches the pid (see $exitTimer below).
            fclose($parentSocket);
            ProcessContainment::closeOnExec($childSocket);
            // B6: backpressure, not a timed-out half frame - see the constant.
            stream_set_timeout($childSocket, self::CHILD_WRITE_TIMEOUT_SECONDS);
            // O-2a: before any turn code runs, this process becomes the turn's
            // notice WRITER. Pinning the sink keeps a parser's warning on the
            // session that started the turn, and forgetting the inherited read
            // watcher means nothing in the child can wake the parent's
            // Chat-side drain here and read the parent's inbox dry (see
            // RuntimeNoticeSink::enterForkedChild()).
            \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::enterForkedChild($noticeSink);
            self::$forkIdleCeilingSeconds = $idleSeconds;
            $this->runCompleteInChild($childSocket, $history, $interactive);
        }

        self::$unreapedChildren[$pid] = true;

        fclose($childSocket);
        // Same rule for the read end: anything the TUI parent spawns while
        // this turn is in flight (an MCP server, the status line) must not
        // walk away holding a copy of it.
        ProcessContainment::closeOnExec($parentSocket);
        stream_set_blocking($parentSocket, false);

        $loop = Loop::get();
        $buffer = '';
        $settled = false;
        $result = null;
        // B6: set when the frame stream stopped being parseable, so the
        // settle reports a broken channel rather than a missing result.
        $streamCorrupt = false;
        // Set once $exitTimer has reaped the child: its pid may then be
        // reused, and nothing may signal it any more.
        $childReaped = false;
        // Set once any token frame has been forwarded, so the result frame's
        // own one-shot $onToken call is suppressed rather than repeating the
        // reply the caller has already been handed chunk by chunk.
        $streamed = false;

        // $timeoutTimer/$cancelTimer are assigned below, AFTER $teardown is
        // built (each timer's own callback needs to call $teardown) - they're
        // captured by reference here specifically so $teardown still sees
        // the real TimerInterface once addTimer()/addPeriodicTimer() below
        // assign into these same variables. $timeoutTimer is additionally
        // REPLACED on every frame by $resetTimeout, which is the whole point
        // of the idle-timeout change.
        $timeoutTimer = null;
        $cancelTimer = null;
        $exitTimer = null;

        // 1.C-1: the parent's WRITE half of the channel. Questions the child
        // has put and nobody has answered yet, by askId — while this is
        // non-empty the idle ceiling is paused (see $resetTimeout). And the
        // bytes of parent→child frames not yet accepted by the socket: the
        // socket is non-blocking, so a reply is buffered and drained by a
        // write watcher rather than ever stalling the TUI's loop.
        /** @var array<string, PendingAsk> $pendingAsks */
        $pendingAsks = [];
        $outbox = '';
        $writing = false;

        // Every open question settles before the turn does, as `cancelled`:
        // the child is being killed or is already gone, so nobody can act on
        // an answer any more, and the receiver needs the PermissionResolved
        // to take its prompt down. Called with $settled already true, so no
        // settlement can write a frame or re-arm a timer.
        $cancelPendingAsks = static function (string $reason) use (&$pendingAsks): void {
            foreach ($pendingAsks as $pending) {
                $pending->cancel($reason);
            }
            $pendingAsks = [];
        };

        // Shared teardown for the failure ways this can end (timeout,
        // cancellation): stop watching the socket, cancel BOTH timers
        // (critical for $cancelTimer, a periodic timer that would otherwise
        // keep polling forever after settling via a different path), kill and
        // reap the child so it never zombies.
        $teardown = function (string $rejectMessage) use (&$settled, $loop, $parentSocket, $pid, $deferred, &$timeoutTimer, &$cancelTimer, &$exitTimer, &$writing, $cancelPendingAsks): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $cancelPendingAsks($rejectMessage);
            if ($writing) {
                $loop->removeWriteStream($parentSocket);
                $writing = false;
            }
            $loop->removeReadStream($parentSocket);
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            if ($cancelTimer !== null) {
                $loop->cancelTimer($cancelTimer);
            }
            if ($exitTimer !== null) {
                $loop->cancelTimer($exitTimer);
            }
            // B2/F-E2: the whole tree, not just the turn child. The child's
            // Bash runs are setsid'd into their own groups and a parallel Task
            // sub-agent is a fork below it; a SIGKILL of $pid alone left all
            // of them running for nobody. killTreeAsync() falls back to
            // exactly that direct kill where /proc or ext-posix is missing.
            //
            // R3: on the loop, not on it. This closure runs inside a loop
            // callback (the cancel poll, the idle timer, a corrupt frame), and
            // the synchronous killTree() + reapChild() pair held that callback
            // ~110 ms - MEASURED, almost all of it the /proc walk - so the
            // Escape that cancelled a turn froze the frame it was cancelling.
            // The root is SIGSTOPped before this returns; the walk, the kill
            // and the bounded reap then take a loop tick each, and the turn
            // settles only after all three, so "settled" still means "tree
            // signalled and child reaped" (or handed to the straggler sweep).
            ProcessContainment::killTreeAsync($pid, $loop)
                ->then(static fn(): PromiseInterface => self::reapChildAsync($pid, $loop))
                ->then(static function () use ($deferred, $rejectMessage): void {
                    $deferred->reject(new \RuntimeException($rejectMessage));
                });
        };

        // The success path: the child delivered its result frame, hung up,
        // or was seen to exit by $exitTimer. Same cleanup as $teardown minus the kill (the child is
        // already on its way out), then settle from whatever result frame
        // arrived - a child that died before writing one is still a failure.
        $finalize = function () use (&$settled, &$result, &$streamed, &$buffer, &$streamCorrupt, $loop, $parentSocket, $pid, $deferred, $onToken, &$timeoutTimer, &$cancelTimer, &$exitTimer, &$writing, $cancelPendingAsks): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $cancelPendingAsks(ChildChannel::PARENT_GONE);
            if ($writing) {
                $loop->removeWriteStream($parentSocket);
                $writing = false;
            }
            $loop->removeReadStream($parentSocket);
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            if ($cancelTimer !== null) {
                $loop->cancelTimer($cancelTimer);
            }
            if ($exitTimer !== null) {
                $loop->cancelTimer($exitTimer);
            }
            self::reapChild($pid);
            // B6. A child that is gone with bytes still unframed in $buffer
            // wrote a frame it never finished (or a header that lied about
            // its length): the result frame, if it ever wrote one, is buried
            // inside that remainder. "Exited without a result" would blame
            // the child for losing work it may well have finished, so the
            // turn says what actually happened to the stream instead.
            if ($result === null && ($streamCorrupt || $buffer !== '')) {
                $deferred->reject(new \RuntimeException(self::FRAME_STREAM_CORRUPTED));

                return;
            }
            $this->settleFromResultFrame($result, $deferred, $streamed ? null : $onToken);
        };

        // Restart the idle clock. Called once up front and again for every
        // frame the child streams, so the ceiling measures silence rather
        // than total turn length.
        $resetTimeout = function () use (&$settled, $loop, &$timeoutTimer, $teardown, &$pendingAsks, $idleSeconds): void {
            if ($settled) {
                return;
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
                $timeoutTimer = null;
            }
            // 1.C-1: a child blocked on a question is silent BY DESIGN, and
            // a person reading a diff for two minutes is not a hung
            // provider. The clock stays stopped until the last open question
            // is settled, which calls back in here to re-arm it.
            if ($pendingAsks !== []) {
                return;
            }
            $timeoutTimer = $loop->addTimer($idleSeconds, static function () use ($teardown, $idleSeconds): void {
                $teardown('Provider request timed out after ' . $idleSeconds . 's without progress');
            });
        };
        $resetTimeout();

        // Drain $outbox into the non-blocking socket, parking a write watcher
        // for whatever it did not accept. A write that fails outright means
        // the child's end is gone; the frame is dropped, because the read
        // edge (EOF) or $exitTimer settles the turn from that same fact.
        $flush = function () use (&$outbox, &$writing, &$flush, $loop, $parentSocket): void {
            while ($outbox !== '' && is_resource($parentSocket)) {
                $n = @fwrite($parentSocket, $outbox);
                if ($n === false) {
                    $outbox = '';
                    break;
                }
                if ($n === 0) {
                    break;
                }
                $outbox = (string) substr($outbox, $n);
            }
            if (!is_resource($parentSocket)) {
                $outbox = '';
            }
            if ($outbox === '' && $writing) {
                $loop->removeWriteStream($parentSocket);
                $writing = false;
            } elseif ($outbox !== '' && !$writing) {
                $writing = true;
                $loop->addWriteStream($parentSocket, static function () use (&$flush): void {
                    $flush();
                });
            }
        };

        // One parent→child frame, on the same 4-byte length + serialize()
        // framing the child writes. Nothing is written once the turn has
        // settled: the socket is closed or about to be.
        $sendToChild = function (array $frame) use (&$settled, &$outbox, $flush): void {
            if ($settled) {
                return;
            }
            $body = serialize($frame);
            $outbox .= pack('N', strlen($body)) . $body;
            $flush();
        };

        // Where every question settles, whoever settles it. A reply goes
        // down to the child as `ask_reply`; a cancellation writes nothing (it
        // only ever happens as the turn settles). Either way the receiver is
        // told, and the idle clock restarts once nothing is left open.
        // `$softCancelSent` is shared with the cancel poll below: a reply given
        // as "reject and stop" raises the soft cancel first, and its frame has
        // to reach the child AHEAD of the reply, or the child could reach its
        // step boundary before the next poll tick sends it.
        $softCancelSent = false;
        $settleAsk = function (\SugarCraft\Crush\Events\PermissionResolved $resolution) use (&$pendingAsks, $sendToChild, $resetTimeout, $onEvent, $interactive, $cancellation, &$softCancelSent): void {
            unset($pendingAsks[$resolution->askId]);
            if (!$resolution->cancelled) {
                if (!$softCancelSent && $cancellation?->isSoftCancelled() === true && !$cancellation->isCancelled()) {
                    $softCancelSent = true;
                    $sendToChild(['kind' => ChildChannel::CANCEL_SOFT]);
                }
                $sendToChild([
                    'kind' => ChildChannel::ASK_REPLY,
                    'askId' => $resolution->askId,
                    'reply' => $resolution->reply?->value,
                    'note' => $resolution->note,
                ]);
            }
            if ($interactive && $onEvent !== null) {
                $onEvent($resolution);
            }
            if ($pendingAsks === []) {
                $resetTimeout();
            }
        };

        // An `ask` frame: the child is now blocked until it hears back. Only
        // an interactive turn attaches the channel in the child, so a
        // non-interactive one never sends this — but if one ever arrives it
        // is answered `reject` at once rather than left to hang the turn.
        // A frame too broken to rebuild still gets a reject when it names
        // its askId; one that does not cannot be answered, and leaves the
        // idle ceiling running to bound it.
        $handleAsk = function (array $frame) use (&$pendingAsks, $settleAsk, $sendToChild, $resetTimeout, $onEvent, $interactive): void {
            $pending = PendingAsk::fromFrame($frame, $settleAsk);
            if ($pending === null) {
                $askId = $frame['askId'] ?? null;
                if (is_string($askId)) {
                    $sendToChild([
                        'kind' => ChildChannel::ASK_REPLY,
                        'askId' => $askId,
                        'reply' => \SugarCraft\Crush\Permissions\PermissionReply::Reject->value,
                        'note' => 'the permission request could not be read',
                    ]);
                }

                return;
            }

            $pendingAsks[$pending->askId] = $pending;
            $resetTimeout();

            if (!$interactive || $onEvent === null) {
                $pending->reply(\SugarCraft\Crush\Permissions\PermissionReply::Reject, 'no approver is attached to this run');

                return;
            }

            $onEvent(new \SugarCraft\Crush\Events\PermissionAsked($pending));
        };

        // Escape-Escape abort (see Chat::update()'s Escape handling): the
        // cancellation flag can flip at any point after this call returns,
        // long after the closures below were built, so it has to be polled
        // rather than checked once up front.
        // 1.C-4: the first Escape is a SOFT cancel. Polled on the same tick:
        // one `cancel_soft` frame goes down the moment the flag flips, and the
        // child stops at its next step boundary; a later hard cancel still
        // wins, through $teardown, whatever the child is doing.
        // 1.C-3: on the same tick, each message the user steered into the
        // running turn goes down as a `steer` frame; the child reads it at
        // its next step boundary.
        $cancelTimer = $cancellation === null ? null : $loop->addPeriodicTimer(0.1, function () use ($cancellation, $teardown, $sendToChild, &$softCancelSent): void {
            if ($cancellation->isCancelled()) {
                $teardown('Request cancelled');

                return;
            }
            foreach ($cancellation->takeSteers() as $steer) {
                $sendToChild(['kind' => ChildChannel::STEER] + $steer);
            }
            // 1.C-4b: each running call the user stopped, as `cancel_tool`,
            // ahead of the `cancel_soft` the same Escape raised.
            foreach ($cancellation->takeToolCancels() as $callId) {
                $sendToChild(['kind' => ChildChannel::CANCEL_TOOL, 'callId' => $callId]);
            }
            // P-E1: each delegated run the user hard-stopped from the Agent
            // View, as `agent_cancel` — SIGTERM, then SIGKILL, in the child.
            foreach ($cancellation->takeAgentCancels() as $request) {
                $sendToChild(['kind' => ChildChannel::AGENT_CANCEL] + $request);
            }
            if (!$softCancelSent && $cancellation->isSoftCancelled()) {
                $softCancelSent = true;
                $sendToChild(['kind' => ChildChannel::CANCEL_SOFT]);
            }
        });

        // Frame dispatch for one chunk off the socket, shared by the read edge
        // below and by $exitTimer's final drain so the two cannot disagree
        // about what a frame means.
        $consume = function (string $chunk) use (&$buffer, &$result, &$streamed, &$streamCorrupt, &$childReaped, $onToken, $onEvent, $onReasoning, $onStep, $finalize, $resetTimeout, $teardown, $handleAsk, $cancellation): void {
            $buffer .= $chunk;
            $corrupt = false;
            $frames = self::drainFrames($buffer, $corrupt);
            // B6. The frames decoded BEFORE the bad header are whole and real,
            // so they are still delivered below; only after them does the
            // stream stop meaning anything. Nothing past that point can be
            // re-synchronised (a length-prefixed stream has no delimiter to
            // scan for), so the turn is torn down - child tree killed - the
            // moment the last good frame has been handed on.
            foreach ($frames as $frame) {
                // Progress of any kind pushes the idle deadline out.
                $resetTimeout();

                if (($frame['kind'] ?? null) === 'result') {
                    $result = $frame;
                    // The result frame is the last one by construction, so
                    // settle now rather than waiting for the child's EOF -
                    // one less round-trip of latency on every turn.
                    $finalize();

                    return;
                }

                // 1.C-1: a question from the child. Handled HERE, in frame
                // order, so the receiver sees it between the tool events it
                // actually sits between.
                if (($frame['kind'] ?? null) === ChildChannel::ASK) {
                    $handleAsk($frame);

                    continue;
                }

                // 1.C-3: a steer landed — recorded on the turn's token.
                if (($frame['kind'] ?? null) === ChildChannel::STEER_ACK) {
                    $steerId = $frame['steerId'] ?? null;
                    $step = $frame['step'] ?? null;
                    if (is_string($steerId) && is_int($step)) {
                        $cancellation?->acknowledgeSteer($steerId, $step);
                    }

                    continue;
                }

                // 1.C-4: the step boundary and the step's bill. A frame too
                // broken to rebuild is dropped: it is display only, and the
                // turn's accounting still arrives whole on `result`.
                if (($frame['kind'] ?? null) === ChildChannel::STEP || ($frame['kind'] ?? null) === ChildChannel::USAGE) {
                    $stepEvent = $frame['kind'] === ChildChannel::STEP
                        ? \SugarCraft\Crush\Events\StepStarted::fromArray($frame)
                        : \SugarCraft\Crush\Events\UsageUpdated::fromArray($frame);
                    if ($stepEvent !== null && $onStep !== null) {
                        $onStep($stepEvent);
                    }

                    continue;
                }

                // E456. The deadline is already pushed out by $resetTimeout()
                // above - EVERY frame does that, which is why a bare heartbeat
                // needs no branch of its own and carries an empty `text`. This
                // branch exists for the other half: reasoning that has
                // something to show has to reach the caller so it can be
                // painted as it arrives.
                //
                // It deliberately does NOT set $streamed.
                //
                // WHAT THIS SAID: that the latch suppresses the result frame's
                // one-shot re-delivery of the assistant's TEXT, and that "a
                // turn that thought at length and then answered with a single
                // un-streamed content block still needs that fallback".
                // WHAT IS TRUE NOW: that turn does not exist. The child's
                // complete() runs its OWN `!$streamed && $content !== ''`
                // one-shot before it writes the result frame, so an unstreamed
                // reply crosses as a `token` frame and $streamed is set here by
                // the branch below - MEASURED, replacing the whole
                // `$streamed ? null : $onToken` at the settle site with a bare
                // null is green across the ENTIRE suite, not merely the tests
                // that name this method. The fallback this non-latch protects is
                // dormant, and
                // {@see \SugarCraft\Crush\Tests\Backend\ReasoningProgressTest::testEveryByteOfTheReplyReachesTheTokenChannel()}
                // is what will say so the day it stops being.
                // WHY THE NON-LATCH STILL EARNS ITS PLACE: the two directions
                // are not symmetrical. Setting $streamed here can only ever
                // SUPPRESS a delivery; leaving it clear can only ever permit
                // one that a second guard then declines. So the wrong choice
                // costs a lost reply and the right one costs nothing, on a
                // branch whose whole subject - reasoning - is display-only and
                // is never the assistant's text.
                if (($frame['kind'] ?? null) === 'reasoning') {
                    $text = $frame['text'] ?? null;
                    if ($onReasoning !== null && is_string($text) && $text !== '') {
                        $onReasoning($text);
                    }

                    continue;
                }

                if (($frame['kind'] ?? null) === 'token') {
                    $text = $frame['text'] ?? null;
                    if (!is_string($text) || $text === '') {
                        continue;
                    }
                    // Latched even when nobody is listening: the child streamed
                    // this turn either way, and the result frame's one-shot
                    // would then be a second delivery of the same reply.
                    $streamed = true;
                    if ($onToken !== null) {
                        $onToken($text);
                    }

                    continue;
                }

                $event = self::decodeEvent($frame);
                if ($event !== null && $onEvent !== null) {
                    $onEvent($event);
                }
            }

            if ($corrupt) {
                $streamCorrupt = true;
                // Once $exitTimer has REAPED the child its pid is free for
                // reuse, so teardown's killTree() must not run against it;
                // the child is gone anyway and finalize() settles with the
                // same corruption verdict.
                $childReaped ? $finalize() : $teardown(self::FRAME_STREAM_CORRUPTED);
            }
        };

        $loop->addReadStream($parentSocket, function ($stream) use ($finalize, $consume): void {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                $finalize();

                return;
            }

            $consume($chunk);
        });

        // B3. EOF is not proof of life or death: every process holding a copy
        // of the child's end keeps it open, and a fork the turn made (a
        // parallel tool, a Task sub-agent) inherits it whatever its
        // close-on-exec flag says. A child that died WITHOUT a result frame
        // used to leave the turn "in flight" until the last such holder exited
        // or the idle ceiling fired. So the pid is watched as well: once it is
        // gone, everything it ever wrote is already in the socket buffer, a
        // non-blocking drain collects it, and the turn settles from whatever
        // that drain held — a result frame normally, the no-result rejection
        // otherwise. No killTree() on that branch: the root is dead, so its
        // descendants have already been reparented and are no longer a tree
        // anyone can walk from here.
        $exitTimer = $loop->addPeriodicTimer(self::EXIT_POLL_SECONDS, function () use (&$settled, &$childReaped, $pid, $parentSocket, $consume, $finalize): void {
            if ($settled) {
                return;
            }
            if (!self::childHasExited($pid)) {
                return;
            }
            $childReaped = true;

            while (!$settled && is_resource($parentSocket)) {
                $chunk = @fread($parentSocket, 65536);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $consume($chunk);
            }

            $finalize();
        });

        return $deferred->promise();
    }

    /**
     * {@see InteractiveTurn}: {@see completeAsync()} with the turn's ASKs put
     * to `$onEvent` as {@see \SugarCraft\Crush\Events\PermissionAsked} and
     * answered through their {@see PendingAsk} (roadmap 1.C-1, Appendix O
     * §5.1). The child attaches a {@see ChildChannel} approver in place of
     * whatever approver this backend carries; the parent owns the policy.
     * `$onStep` takes the turn's `step`/`usage` frames (roadmap 1.C-4), as on
     * {@see completeAsync()}.
     */
    public function completeInteractive(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onStep = null): PromiseInterface
    {
        return $this->completeAsync($history, $onToken, $cancellation, $onEvent, $onReasoning, true, $onStep);
    }

    /**
     * A copy whose summaries — the in-turn step summary (roadmap 2.4-1) and
     * {@see summariseAsync()} — go to $model instead of the turn's own (see
     * {@see $summaryModel}). Null or blank is the turn's own model, the
     * default, because that is the one whose prefix the provider has cached.
     */
    public function withSummaryModel(?string $model): self
    {
        $model = $model === null ? null : trim($model);

        return $this->mutate(['summaryModel' => $model === '' ? null : $model]);
    }

    /** The model summaries go to, or null for the turn's own. */
    public function summaryModel(): ?string
    {
        return $this->summaryModel;
    }

    /**
     * {@see SummarisesWithCache}: the request a turn of $history would send —
     * this backend's system prompt, its tool schemas, the history converted
     * the way {@see completeAsync()} converts it — plus $instruction as the
     * final user row, answered by the first assistant message (roadmap 2.4-2).
     *
     * It is {@see \SugarCraft\Crush\Context\Compaction\StepSummarizer::summaryStep()},
     * the request the in-turn step summary already makes, run off the render
     * loop in a forked child exactly as a turn is: the same idle ceiling
     * ({@see turnIdleTimeoutSeconds()} of SILENCE, reset by every frame the
     * child writes — each streamed chunk and each transport heartbeat), no
     * total deadline, the same hard-cancel and tree teardown, the same reap.
     * So a summary is never killed sooner, or later, than a turn of the same
     * length would be.
     *
     * NOT A TURN, and that is the safety property: no hook runs (the runtime
     * is built on an empty hook manager), no tool runs (the reply is taken
     * before {@see Runtime::run()} dispatches a call, and any call it asked
     * for is dropped), so a compaction can raise no permission prompt. The
     * spend cap is the caller's to check before asking, as it was for the
     * tool-less summary backend this replaces.
     *
     * $flushMemory (roadmap 2.11) puts the pre-compaction memory flush
     * ({@see \SugarCraft\Crush\Context\Compaction\MemoryFlush}) in front of the
     * summary, in the same child: one silent step over the same conversation
     * in which only the `Memory` tool runs and every permission question is
     * answered no — so still no prompt. Its usage is added to the reply's.
     * Whether this compaction cycle has flushed already is the caller's to
     * know ({@see \SugarCraft\Crush\Context\Pruning\ContextLedger::memoryFlushDue()}):
     * the host keeps the session's ledger, this copy of the engine does not.
     * An optional parameter beyond {@see SummarisesWithCache}'s, so no
     * implementation of the interface has to grow it.
     *
     * The session's prompt memo is primed, never OBSERVED: observing would
     * record this request as a turn of its own and make the next real turn
     * look like a session switch, which forgets the very layers whose bytes
     * the cache depends on.
     *
     * @param list<Message> $history
     *
     * @return PromiseInterface<Message>
     */
    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null, bool $flushMemory = false): PromiseInterface
    {
        $deferred = new Deferred();
        self::sweepUnreapedChildren();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        try {
            $this->newRuntime(new HookManager(new HookRegistry()))->primeSessionPrompt($this->sessionApp());
        } catch (\Throwable) {
            // The child builds the same layers itself and owns the error.
        }

        $sockets = function_exists('pcntl_fork') && function_exists('pcntl_waitpid')
            ? @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)
            : false;
        $noticeSink = \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::current();
        // N-P4a: the same per-turn idle ceiling a turn gets, read pre-fork.
        $idleSeconds = self::turnIdleTimeoutSeconds();
        $pid = $sockets === false ? -1 : pcntl_fork();
        if ($pid === -1) {
            // A loop, not an `if`: the fork-exit guard reads the branches
            // after a fork as the -1 and the 0 one, and a nested `if` here
            // would hide the child's branch from it.
            foreach ($sockets === false ? [] : $sockets as $end) {
                fclose($end);
            }
            try {
                $deferred->resolve($this->summaryReply($history, $instruction, flushMemory: $flushMemory));
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }

            return $deferred->promise();
        }

        [$parentSocket, $childSocket] = $sockets;
        if ($pid === 0) {
            // The child's half of completeAsync()'s fork, in the same order
            // and for the same reasons (see there).
            \SugarCraft\Crush\Support\ForkedChild::closeInheritedServerFds();
            fclose($parentSocket);
            ProcessContainment::closeOnExec($childSocket);
            stream_set_timeout($childSocket, self::CHILD_WRITE_TIMEOUT_SECONDS);
            \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::enterForkedChild($noticeSink);
            $this->summariseInChild($childSocket, $history, $instruction, $flushMemory);
        }

        self::$unreapedChildren[$pid] = true;
        fclose($childSocket);
        ProcessContainment::closeOnExec($parentSocket);
        stream_set_blocking($parentSocket, false);

        $loop = Loop::get();
        $buffer = '';
        $settled = false;
        $result = null;
        $childReaped = false;
        $timeoutTimer = null;
        $cancelTimer = null;
        $exitTimer = null;

        $release = static function () use ($loop, $parentSocket, &$timeoutTimer, &$cancelTimer, &$exitTimer): void {
            $loop->removeReadStream($parentSocket);
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            foreach ([$timeoutTimer, $cancelTimer, $exitTimer] as $timer) {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                }
            }
        };
        $teardown = static function (string $reason) use (&$settled, $release, $loop, $pid, $deferred): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $release();
            ProcessContainment::killTreeAsync($pid, $loop)
                ->then(static fn (): PromiseInterface => self::reapChildAsync($pid, $loop))
                ->then(static function () use ($deferred, $reason): void {
                    $deferred->reject(new \RuntimeException($reason));
                });
        };
        $finalize = function () use (&$settled, &$result, &$buffer, $release, $pid, $deferred): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $release();
            self::reapChild($pid);
            if ($result === null && $buffer !== '') {
                $deferred->reject(new \RuntimeException(self::FRAME_STREAM_CORRUPTED));

                return;
            }
            $this->settleFromResultFrame($result, $deferred, null);
        };
        $resetTimeout = static function () use (&$settled, $loop, &$timeoutTimer, $teardown, $idleSeconds): void {
            if ($settled) {
                return;
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            $timeoutTimer = $loop->addTimer($idleSeconds, static function () use ($teardown, $idleSeconds): void {
                $teardown('Provider request timed out after ' . $idleSeconds . 's without progress');
            });
        };
        $resetTimeout();

        $cancelTimer = $cancellation === null ? null : $loop->addPeriodicTimer(0.1, static function () use ($cancellation, $teardown): void {
            if ($cancellation->isCancelled()) {
                $teardown('Request cancelled');
            }
        });

        // Only two frame kinds cross: `reasoning` (progress — the text is
        // never shown, a summary is not painted) and the final `result`.
        $consume = static function (string $chunk) use (&$buffer, &$result, &$childReaped, $resetTimeout, $finalize, $teardown): void {
            $buffer .= $chunk;
            $corrupt = false;
            foreach (self::drainFrames($buffer, $corrupt) as $frame) {
                $resetTimeout();
                if (($frame['kind'] ?? null) === 'result') {
                    $result = $frame;
                    $finalize();

                    return;
                }
            }
            if ($corrupt) {
                $childReaped ? $finalize() : $teardown(self::FRAME_STREAM_CORRUPTED);
            }
        };

        $loop->addReadStream($parentSocket, static function ($stream) use ($finalize, $consume): void {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                $finalize();

                return;
            }
            $consume($chunk);
        });

        $exitTimer = $loop->addPeriodicTimer(self::EXIT_POLL_SECONDS, static function () use (&$settled, &$childReaped, $pid, $parentSocket, $consume, $finalize): void {
            if ($settled || !self::childHasExited($pid)) {
                return;
            }
            $childReaped = true;
            while (!$settled && is_resource($parentSocket)) {
                $chunk = @fread($parentSocket, 65536);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $consume($chunk);
            }
            $finalize();
        });

        return $deferred->promise();
    }

    /**
     * The forked child's half of {@see summariseAsync()}: run the summary,
     * writing an empty `reasoning` frame for every streamed chunk and every
     * transport heartbeat (the parent's idle ceiling measures silence), then
     * the result frame in {@see settleFromResultFrame()}'s shape, then exit.
     * A memory flush ($flushMemory, roadmap 2.11) runs here first, so its
     * Memory writes land from the child as a turn's would, and its streamed
     * chunks keep the same idle ceiling alive.
     *
     * @param resource      $childSocket
     * @param list<Message> $history
     */
    private function summariseInChild($childSocket, array $history, string $instruction, bool $flushMemory = false): never
    {
        $beat = static function () use ($childSocket): void {
            self::writeFrame($childSocket, ['kind' => 'reasoning', 'text' => '']);
        };
        try {
            $reply = $this->summaryReply($history, $instruction, static function (string $delta) use ($beat): void {
                $beat();
            }, $beat, $flushMemory);
            $payload = [
                'kind' => 'result',
                'ok' => true,
                'content' => $reply->content,
                'usage' => $reply->usage?->toArray(),
                'lengthStopped' => $reply->lengthStopped,
            ];
        } catch (\Throwable $e) {
            $payload = ['kind' => 'result', 'ok' => false, 'error' => $e->getMessage()];
        }
        $payload['servedModel'] = $this->servedModel();
        $payload['cacheHealth'] = $this->cacheHealth->state();

        self::writeFrame($childSocket, $payload);
        fclose($childSocket);
        \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
    }

    /**
     * The summary request itself, in whichever process runs it: the turn's
     * runtime and session App (tools advertised through {@see turnTools()},
     * as a turn binds them, so the schemas are byte-for-byte a turn's), the
     * history converted by {@see toTypedMessages()}, $instruction last.
     *
     * With $flushMemory (roadmap 2.11, the host's compactions) the
     * pre-compaction memory flush runs first over the same App — the
     * conversation's own model, before any summary model is applied, so its
     * prefix is the cached one — exactly as the in-turn flush does before a
     * step summary: its own copy of the hook chain carrying
     * {@see \SugarCraft\Crush\Context\Compaction\MemoryFlush}, every ask
     * refused, nothing of it in the summary request. Its usage is summed into
     * the reply's, so the caller bills it with the summary; a failed flush
     * costs the summary nothing.
     *
     * @param list<Message> $history
     */
    private function summaryReply(array $history, string $instruction, ?callable $onProgress = null, ?callable $onHeartbeat = null, bool $flushMemory = false): Message
    {
        $userConfig = self::userConfig();
        // Persisted turn context: the history already carries the rows the
        // turns wrote, and a fresh one appended here would change the bytes
        // after the cached prefix for nothing.
        $runtime = $this->newRuntime(
            new HookManager(new HookRegistry()),
            self::parallelToolCallsEnabled($userConfig),
            self::parallelToolDeadlineSeconds($userConfig),
            self::maxOutputTokens($userConfig),
        )->withTurnContextPersisted();

        $app = $this->sessionApp()
            ->withTools($this->turnTools(null, null, null))
            ->withMessages($this->toTypedMessages($history));

        $flushUsages = [];
        if ($flushMemory && \SugarCraft\Crush\Context\Compaction\MemoryFlush::available($app->tools)) {
            try {
                $flushHooks = $this->resolveHookManager(ToolCallLoopGuard::new());
                $flushHooks->register(\SugarCraft\Crush\Context\Compaction\MemoryFlush::new());
                \SugarCraft\Crush\Context\Compaction\MemoryFlush::run(
                    $this->newRuntime(
                        $flushHooks,
                        self::parallelToolCallsEnabled($userConfig),
                        self::parallelToolDeadlineSeconds($userConfig),
                        self::maxOutputTokens($userConfig),
                    )->withTurnContextPersisted(),
                    $app,
                    function (AssistantMessage $assistant) use (&$flushUsages): void {
                        $flushUsages[] = $assistant->usage();
                        $this->observeCacheHealth($assistant->usage());
                    },
                    $onProgress,
                    $onHeartbeat,
                );
            } catch (\Throwable) {
                // Best effort: the compaction goes ahead.
            }
        }

        if ($this->summaryModel !== null) {
            $app = $app->withModel($this->summaryModel);
        }

        $usage = null;
        $assistant = \SugarCraft\Crush\Context\Compaction\StepSummarizer::summaryStep(
            $runtime,
            $app,
            new UserMessage($instruction),
            function (AssistantMessage $assistant) use (&$usage): void {
                $usage = $assistant->usage();
                $this->observeCacheHealth($assistant->usage());
            },
            null,
            $onProgress,
            $onHeartbeat,
        );
        if ($assistant === null) {
            throw new \RuntimeException('The provider returned no summary.');
        }

        return Message::assistant($assistant->content())
            ->withUsage($flushUsages === [] ? $usage : Usage::sum([...$flushUsages, $usage]))
            ->withLengthStopped($assistant->lengthStopped());
    }

    /**
     * Reaps the forked completion child WITHOUT ever blocking the event loop.
     *
     * `pcntl_waitpid($pid, $status)` with no flags blocks until the child
     * actually exits. That was harmless whenever the SIGKILL above landed -
     * but `posix_kill()` is guarded precisely because ext-posix is not
     * guaranteed (minimal `php:cli-alpine`-style images routinely ship
     * ext-pcntl without it), and in exactly that build the child is still
     * wedged inside a blocking provider read with nothing to kill it. The
     * unflagged waitpid then blocked *inside a ReactPHP timer callback*,
     * freezing the entire loop - in the cancel path the user reached for to
     * escape a hung request in the first place.
     *
     * `WNOHANG` polls instead, over a bounded window that is generous for a
     * killed or already-exiting child and still finite for one that will
     * never die. Giving up hands the pid to {@see sweepUnreapedChildren()}
     * rather than leaking it; a permanently frozen UI would be strictly worse
     * than one deferred reap. The `function_exists()` guard mirrors the
     * `posix_kill()` one at the call site - redundant with {@see
     * completeAsync()}'s own entry check today, deliberately kept so the
     * helper stays safe for any future caller.
     */
    private static function reapChild(int $pid): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }

        $status = 0;
        for ($attempt = 0; $attempt < self::REAP_ATTEMPTS; $attempt++) {
            // 0 means "still running, nothing reaped yet"; $pid means reaped,
            // -1 means unwaitable (already reaped, or never ours) - both of
            // the latter are terminal.
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset(self::$unreapedChildren[$pid]);

                return;
            }
            usleep(self::REAP_POLL_MICROSECONDS);
        }
    }

    /**
     * {@see reapChild()} on the loop (audit R3): the same {@see REAP_ATTEMPTS}
     * x {@see REAP_POLL_MICROSECONDS} window and the same give-up rule - a
     * child that outlives it stays in self::$unreapedChildren for
     * {@see sweepUnreapedChildren()} - but the polls are a timer, so the
     * cancel teardown that runs it never holds the loop thread.
     *
     * @return PromiseInterface<null>
     */
    private static function reapChildAsync(int $pid, LoopInterface $loop): PromiseInterface
    {
        return ProcessContainment::reapAsync(
            [$pid],
            self::REAP_ATTEMPTS * self::REAP_POLL_MICROSECONDS / 1_000_000,
            self::REAP_POLL_MICROSECONDS / 1_000_000,
            $loop,
        )->then(static function (array $unreaped) use ($pid): void {
            if ($unreaped === []) {
                unset(self::$unreapedChildren[$pid]);
            }
        });
    }

    /**
     * One WNOHANG look at the turn child for {@see completeAsync()}'s exit
     * watch (B3): true once it is gone, reaping it on the way.
     *
     * 0 means still running. $pid means it exited and is reaped now; -1 means
     * it is no longer waitable (reaped by someone else, e.g. an embedder's
     * SIGCHLD=SIG_IGN) — gone either way. Never blocks, for the same reason
     * {@see reapChild()} never does: this runs inside a loop timer.
     */
    private static function childHasExited(int $pid): bool
    {
        $status = 0;
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            return false;
        }
        unset(self::$unreapedChildren[$pid]);

        return true;
    }

    /**
     * One non-blocking pass over the children {@see reapChild()} ran out of
     * budget on, so a straggler from turn N is collected at turn N+1 instead
     * of sitting as a zombie for the life of the TUI.
     *
     * Deliberately does not sleep or retry: anything still running here gets
     * looked at again next turn. See self::$unreapedChildren for why this
     * walks a tracked list rather than calling `pcntl_waitpid(-1, ...)`.
     */
    private static function sweepUnreapedChildren(): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }

        $status = 0;
        foreach (array_keys(self::$unreapedChildren) as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset(self::$unreapedChildren[$pid]);
            }
        }
    }

    /**
     * The forked child's half of {@see completeAsync()}: run the real
     * (blocking) engine loop in isolation, streaming each tool event back
     * over the socket as its own frame as it fires and the outcome as the
     * final frame, then exit. Never returns.
     *
     * 1.C-1: on an INTERACTIVE turn the socket also carries questions. The
     * turn runs on a copy of this backend whose approver is a
     * {@see ChildChannel}: each ASK goes up as an `ask` frame and the child
     * blocks for the parent's `ask_reply`. Built here, in the child, so the
     * channel's owner pid is the turn child's own — a parallel Task
     * grandchild that inherits the approver is refused rather than allowed
     * to interleave frames on this stream (see {@see ChildChannel}).
     *
     * 1.C-4: EVERY forked turn carries the channel, interactive or not. The
     * turn loop's `$onStep` writes a `step` frame before each provider call
     * and a `usage` frame after each response, and the loop asks the channel
     * at each step boundary whether the parent sent `cancel_soft`. Only the
     * approver stays interactive-only.
     *
     * @param array<int, Message> $history
     */
    private function runCompleteInChild($childSocket, array $history, bool $interactive = false): never
    {
        try {
            $engine = $this;
            $channel = ChildChannel::new(
                $childSocket,
                static function (array $frame) use ($childSocket): void {
                    self::writeFrame($childSocket, $frame);
                },
                static function (string &$inbound, bool &$corrupt): array {
                    return self::drainFrames($inbound, $corrupt);
                },
                $this->permissionGate?->mode()->value ?? '',
                $this->root,
            );
            if ($interactive) {
                $engine = $this->withPermissionApprover($channel->approver());
            }
            // 1.C-4: the step boundary and the step's bill, each as its own
            // frame the moment it happens (Appendix O §5.1 `step` / `usage`).
            $onStep = static function (\SugarCraft\Crush\Events\StepStarted|\SugarCraft\Crush\Events\UsageUpdated $event) use ($channel): void {
                $channel->send(
                    $event instanceof \SugarCraft\Crush\Events\StepStarted ? ChildChannel::STEP : ChildChannel::USAGE,
                    $event->toArray(),
                );
            };
            $stopRequested = static fn (): bool => $channel->softCancelRequested();
            // 1.C-4b: `cancel_tool{callId}` stops one running call. The
            // code that can stop it — the concurrent reap loop, a lone Task's
            // progress hook — asks by call id; this is where it learns.
            // P-E1: `agent_cancel{agentId, callId}` hard-stops one delegated
            // run. The reap loop asks AgentCancelRequests for a concurrent
            // member (SIGTERM, SIGKILL after the grace); the call id also
            // joins the tool cancels, so a lone Task — which runs in this
            // process and cannot be signalled — stops at its next boundary.
            \SugarCraft\Crush\Support\AgentCancelRequests::listen(
                static fn (): array => $channel->takeAgentCancels(),
            );
            \SugarCraft\Crush\Support\ToolCancelRequests::listen(
                static fn (): array => [
                    ...$channel->takeToolCancels(),
                    ...\SugarCraft\Crush\Support\AgentCancelRequests::takeNewCallIds(),
                ],
            );
            // complete()'s body, inlined so the loop gets the channel's two
            // per-step hooks: complete() is the frozen Backend contract
            // (BackendContractWideningTest) and cannot grow parameters.
            $transcript = [];
            $attachmentNotice = null;
            $typed = $engine->toTypedMessages($history, $attachmentNotice);
            // Roadmap 3.B-2: the turn-start strategies run here, in the child,
            // so the parent's update loop never projects a conversation.
            if ($engine->contextLedger !== null) {
                $engine = $engine->withContextLedger($engine->turnStartLedger($typed));
            }
            // This is a forked child, so invoking the caller's callback
            // in-process would write into a copy of its state and vanish on
            // exit - the event has to cross the socket. It goes out
            // IMMEDIATELY rather than into an end-of-turn batch, because the
            // batch is exactly what made a multi-tool turn look like a silent
            // "thinking" spinner (and what made the parent's single
            // wall-clock timer kill turns that were in fact making progress).
            $message = $engine->runTurn(
                $typed,
                // Assistant text crosses the fork on the SAME channel and by
                // the same rule as the tool events: a plain in-process
                // closure here would write into the child's COPY of the
                // parent's state and vanish on exit, so a delta is a frame or
                // it is nothing. One frame per chunk, unbatched, because a
                // batch is precisely the re-buffering that made streaming
                // fake (crush_code.md Phase 0 item 13). Interleaved with the
                // event frames in wire order, which is what lets the parent
                // reconstruct "said this, then called that".
                static function (string $delta) use ($childSocket): void {
                    self::writeFrame($childSocket, ['kind' => 'token', 'text' => $delta]);
                },
                static function (ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|ContextLedgerChanged $event) use ($childSocket): void {
                    self::writeFrame($childSocket, self::encodeEvent($event));
                },
                // E456. The child's third sink, and the one that exists for the
                // PARENT'S TIMER as much as for the screen: the parent's idle
                // ceiling measures silence on this socket, so a chunk that
                // writes nothing here is indistinguishable from a hung
                // provider. `$delta` is the model's reasoning when there is any
                // and '' for a chunk with nothing to show (tool-call structure
                // only, usage figures only); the frame is written either way,
                // because both are progress and only one is paintable.
                //
                // A distinct frame kind, never a reused `token` frame:
                // {@see \SugarCraft\Crush\Runtime::runStreaming()} accumulates
                // $onToken's bytes into the AssistantMessage that is fed back to
                // the model and checkpointed, so reasoning arriving on that
                // channel would corrupt the conversation rather than merely the
                // display.
                static function (string $delta) use ($childSocket): void {
                    self::writeFrame($childSocket, ['kind' => 'reasoning', 'text' => $delta]);
                },
                // E493's consumer half, and the reason it lives HERE rather than
                // with any caller: on a BATCH turn the child blocks inside one
                // provider HTTP call for as long as the server thinks, and the
                // parent's idle deadline measures silence on this socket. The
                // provider's transport fires this closure from libcurl's own
                // progress callback - the one carrier E524 measured as running
                // INSIDE the blocking transfer, which no signal handler does -
                // at most once per second, and each beat crosses as a bare
                // `reasoning` frame: the SAME shape E456 already established for
                // "a chunk with nothing to show". No new frame kind, so nothing
                // on the parent side changes - every frame already resets the
                // deadline, and the reasoning branch already drops an empty text
                // before it could reach a painter. Not a timeout: this arms
                // nothing and bounds nothing (standing rule); if the provider's
                // transport cannot fire (the SDK-owned ones), no beat comes, no
                // frame comes, and the ceiling bounds the turn exactly as it did
                // before this argument existed.
                static function () use ($childSocket): void {
                    self::writeFrame($childSocket, ['kind' => 'reasoning', 'text' => '']);
                },
                $transcript,
                $onStep,
                $stopRequested,
                // 1.C-3: the user's mid-turn messages, as `steer` frames;
                // roadmap 4.4: the replies its sub-agents send the session's
                // own agent, from the `main` mailbox.
                CompositeTurnInbox::of(SocketSteerInbox::new($channel), $engine->mainMailbox()),
            )->withAttachmentNotice($attachmentNotice);
            $transcriptRows = [];
            foreach ($message->turnTranscript as $row) {
                $transcriptRows[] = ['usage' => $row->usage?->toArray()] + $row->jsonSerialize();
            }
            // Every tool result in it already crossed once, on its `finished`
            // frame, and the parent pairs these rows with the rows those drew
            // (Message::settleTurnTranscript()). So past a quarter of the frame
            // cap the result text stays behind and a marker crosses instead: a
            // turn long enough to hit MAX_FRAME_BYTES must not lose its whole
            // result frame to the copy it does not need.
            if (\strlen(serialize($transcriptRows)) > intdiv(self::MAX_FRAME_BYTES, 4)) {
                foreach ($transcriptRows as $k => $row) {
                    $result = $row['toolResults'][0] ?? null;
                    if (!\is_array($result)) {
                        continue;
                    }
                    $omitted = '[tool output not carried: this turn outgrew the result frame]';
                    $result[($result['error'] ?? null) === null ? 'result' : 'error'] = $omitted;
                    $transcriptRows[$k]['toolResults'] = [$result];
                    $transcriptRows[$k]['content'] = $omitted;
                }
            }
            // imageBytes/imageProtocol survive this fork boundary too - PHP's
            // serialize()/unserialize() (unlike JSON) round-trip arbitrary
            // binary strings natively, so no base64 step is needed here the
            // way Chat::storeToolResult()'s JSON-over-temp-file IPC needs one
            // (W1.G2 reachability fix).
            $payload = [
                'kind' => 'result',
                'ok' => true,
                'content' => $message->content,
                'reasoning' => $message->reasoning,
                'imageBytes' => $message->imageBytes,
                'imageProtocol' => $message->imageProtocol,
                // As a plain array, not the object: the parent unserializes
                // with `allowed_classes => false` (see encodeEvent()), so an
                // object here would arrive as __PHP_Incomplete_Class and the
                // turn would lose its accounting silently.
                'usage' => $message->usage?->toArray(),
                // E707 (round 81): a plain bool rides the same rule - no
                // object, and a frame without the key settles false, the
                // same "old child, new parent" tolerance $usage's ?? shows.
                'lengthStopped' => $message->lengthStopped,
                // F2: plain bool on the same rule — a pre-F2 frame settles
                // false, "the child did not say the ceiling bit".
                'stepsTruncated' => $message->stepsTruncated,
                // Plain ?string on the same rule; a frame without it settles
                // "the guard did not end this turn".
                'loopGuardStoppedBy' => $message->loopGuardStoppedBy,
                // Audit 15b-15: plain ?string on the same rule; a frame
                // without it settles "every attachment went out as attached".
                'attachmentNotice' => $message->attachmentNotice,
                // Roadmap 1.B-2: the step the reply is (or null), and the rows
                // the turn added, each in Message::jsonSerialize()'s shape -
                // plain arrays the parent rebuilds with Message::fromArray(),
                // usage included as its own array (see 'usage' above).
                'stepId' => $message->stepId,
                'transcript' => $transcriptRows,
                // Roadmap 2.2-2: the session ledger the turn ended with, as
                // the plain array toArray() writes (allowed_classes => false,
                // see 'usage'). Absent when the parent handed none.
                'contextLedger' => $message->contextLedger?->toArray(),
            ];
        } catch (\Throwable $e) {
            $payload = ['kind' => 'result', 'ok' => false, 'error' => $e->getMessage()];
        }
        // Audit 15b-35: the served model this child's provider discovered
        // while it ran the turn, on success AND failure (a failed turn still
        // asked the server who it serves). Plain ?string on the frame rule;
        // the parent hands it to its own provider, whose copy of the memo
        // never saw this child's discovery.
        $payload['servedModel'] = $this->servedModel();
        // P10.S3: the cache-health streak and its one-time bit, on success
        // AND failure (a step that reported before the turn failed still
        // counted). Plain array on the frame rule; without it the parent's
        // streak would never advance on this path and the notice would be
        // raised again by every later turn's child.
        $payload['cacheHealth'] = $this->cacheHealth->state();
        // Roadmap 3.I-2: what the model saw and wrote this turn, on success
        // AND failure (a failed turn's reads still happened). The tools'
        // ledger here is this child's copy and dies with it; plain arrays on
        // the frame rule. Without it every turn would start from the parent's
        // ledger as it stood before the turn, blind to the reads it made and
        // to its own last write.
        $payload['readLedger'] = \SugarCraft\Crush\Tools\ReadLedger::in($this->tools)?->toArray();

        self::writeFrame($childSocket, $payload);
        fclose($childSocket);
        \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
    }

    /**
     * Write one length-prefixed frame to the child's end of the socket.
     *
     * The 4-byte big-endian prefix is what lets the parent tell frames apart
     * in a byte stream that has no other structure: a serialized payload can
     * contain any byte, so there is no delimiter to scan for, and a single
     * fwrite() is not guaranteed to be atomic or complete - hence the loop.
     *
     * WHAT THIS SAID: that a dead parent "ends the write rather than
     * spinning; the child is about to exit anyway". WHAT IS TRUE (B6): the
     * write ended by RETURNING, silently, possibly mid-frame - and the child
     * was not about to exit at all. It went on running the agent loop, every
     * later frame landed inside the truncated frame's declared length, and
     * even a finished turn's result frame was lost (repro: 1,059,776 bytes
     * read, ZERO frames decoded). A short write means the stream is dead and
     * possibly holds half a frame, so nothing written after it can ever be
     * parsed; the only honest move is to stop, at once. Continuing would
     * keep running tools - Bash, Edit, Write - for a turn nobody can receive.
     * The child exits through {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()} (the fork's exit
     * convention, a self-SIGKILL); the parent then sees either the stream
     * corruption or the child's exit and settles the turn as a failure.
     *
     * CHILD-ONLY BY CONSTRUCTION: every caller is {@see runCompleteInChild()}
     * and the sinks it builds, which only ever run in the forked child - this
     * must never be called from the TUI parent, where exitNow() would kill
     * the app. A stalled-but-alive parent no longer reaches this branch: the
     * child's socket carries {@see CHILD_WRITE_TIMEOUT_SECONDS}, so the write
     * waits instead of timing out.
     *
     * @param resource             $socket
     * @param array<string, mixed> $frame
     */
    private static function writeFrame($socket, array $frame): void
    {
        $body = serialize($frame);
        $out = pack('N', strlen($body)) . $body;
        $total = strlen($out);

        for ($written = 0; $written < $total;) {
            $n = @fwrite($socket, substr($out, $written));
            if ($n === false || $n === 0) {
                \SugarCraft\Crush\Support\ForkedChild::exitNow(1);
            }
            $written += $n;
        }
    }

    /**
     * Pull every COMPLETE frame out of the parent's read buffer, leaving any
     * trailing partial frame in $buffer for the next readable edge - a frame
     * can and does span two reads once a tool result carries image bytes.
     *
     * A frame whose declared length is nonsensical, or whose body does not
     * decode to an array, means the stream is no longer parseable. WHAT THIS
     * SAID: the buffer was dropped "and the turn then fails via the
     * missing-result path". WHAT IS TRUE (B6): parsing simply continued on
     * the next read, so every later frame - the result frame included - was
     * read against a byte offset that meant nothing, and the turn ended as
     * "exited without a result" (or idled out) with nobody told the channel
     * broke. Now the buffer is still dropped, but $corrupt is set so the
     * caller can tear the turn down; the frames decoded BEFORE the bad one
     * are returned as usual, because they were whole.
     *
     * @param bool $corrupt set true when the stream stopped being parseable;
     *                      never reset to false here, so one flag can span
     *                      several calls
     *
     * @return list<array<string, mixed>>
     */
    private static function drainFrames(string &$buffer, bool &$corrupt = false): array
    {
        $frames = [];

        while (strlen($buffer) >= 4) {
            $header = unpack('N', substr($buffer, 0, 4));
            $length = is_array($header) ? (int) ($header[1] ?? 0) : 0;
            if ($length <= 0 || $length > self::MAX_FRAME_BYTES) {
                $buffer = '';
                $corrupt = true;
                break;
            }
            if (strlen($buffer) < 4 + $length) {
                break;
            }

            $body = substr($buffer, 4, $length);
            $buffer = substr($buffer, 4 + $length);

            // allowed_classes => false: a hostile/corrupt payload must never
            // be able to instantiate anything (see encodeEvent()).
            $decoded = @unserialize($body, ['allowed_classes' => false]);
            if (!is_array($decoded)) {
                // A whole-length body that is not a frame: the length prefix
                // and the bytes disagree, so the offset is no longer trusted.
                $buffer = '';
                $corrupt = true;
                break;
            }
            $frames[] = $decoded;
        }

        return $frames;
    }

    /**
     * Settle $deferred from the child's final result frame - resolving with
     * the real content or rejecting with its error message. A null frame
     * (child crashed before writing one) is reported as a failure rather than
     * silently resolving empty.
     *
     * $onToken here is the FALLBACK delivery only, and {@see completeAsync()}
     * passes null once any token frame has arrived: a turn the child streamed
     * has already handed the caller these bytes, and repeating them whole
     * would double the reply on screen. It survives for the child that
     * produced no deltas (a provider whose stream yielded nothing), matching
     * {@see complete()}'s own fallback — which is where the honest note
     * belongs: that child cannot arise today, because complete()'s fallback
     * fires first and turns exactly that case into a `token` frame. See the
     * paragraph on that guard, and on the reasoning branch in
     * {@see completeAsync()} that must not latch $streamed, for why both are
     * kept rather than reduced to the one that can fire.
     *
     * @param ?array<string, mixed> $data
     */
    private function settleFromResultFrame(?array $data, Deferred $deferred, ?callable $onToken): void
    {
        if ($data === null) {
            $deferred->reject(new \RuntimeException('Provider worker process exited without a result'));

            return;
        }

        // Audit 15b-35: learned before the verdict, so a failed turn's
        // discovery still reaches the labels. The provider object is shared
        // by every clone of this backend, the hosted Chat's included.
        $servedModel = $data['servedModel'] ?? null;
        if (is_string($servedModel) && $this->provider instanceof ReportsServedModel) {
            $this->provider->noteServedModel($servedModel);
        }

        // P10.S3: the child's cache-health streak, before the verdict for the
        // same reason. The watch is shared by every clone of this backend.
        $this->cacheHealth->adopt($data['cacheHealth'] ?? null);

        // Roadmap 3.I-2: the child's read ledger, before the verdict for the
        // same reason. Merged into the tools' shared ledger, last-recorded-wins
        // per path; a frame without the key (an older child) merges nothing.
        \SugarCraft\Crush\Tools\ReadLedger::in($this->tools)?->merge($data['readLedger'] ?? null);

        if (($data['ok'] ?? false) !== true) {
            $deferred->reject(new \RuntimeException((string) ($data['error'] ?? 'Provider worker process failed')));

            return;
        }

        $content = (string) ($data['content'] ?? '');
        if ($onToken !== null && $content !== '') {
            $onToken($content);
        }

        $reasoning = $data['reasoning'] ?? null;
        $imageBytes = $data['imageBytes'] ?? null;
        $imageProtocol = $data['imageProtocol'] ?? null;

        // Roadmap 1.B-2: the turn's rows, rebuilt with the tolerant reader the
        // transcript store uses. A frame without the key (an older child) or
        // with garbage in it settles "no transcript", which is the reply
        // replayed as it always was; a row that is not an array is skipped.
        $turnRows = [];
        foreach (\is_array($data['transcript'] ?? null) ? $data['transcript'] : [] as $row) {
            if (\is_array($row)) {
                $turnRows[] = Message::fromArray($row);
            }
        }

        $deferred->resolve(
            Message::assistant($content, reasoning: is_string($reasoning) ? $reasoning : null)
                ->withImage(
                    is_string($imageBytes) ? $imageBytes : null,
                    is_string($imageProtocol) ? $imageProtocol : null,
                )
                // Usage::fromArray() rejects anything that is not the shape
                // toArray() wrote, so a corrupt frame costs the turn its
                // accounting rather than resolving with a fabricated bill.
                ->withUsage(Usage::fromArray($data['usage'] ?? null))
                // E707 (round 81): strict `=== true` — a frame that predates
                // the key, or carries garbage in it, settles "the child did
                // not say the ceiling bit", which is the flag's honest false.
                ->withLengthStopped(($data['lengthStopped'] ?? false) === true)
                // F2: strict `=== true`, same pre-key tolerance as above.
                ->withStepsTruncated(($data['stepsTruncated'] ?? false) === true)
                ->withLoopGuardStoppedBy(is_string($data['loopGuardStoppedBy'] ?? null) ? $data['loopGuardStoppedBy'] : null)
                // Audit 15b-15: same ?string rule as the line above.
                ->withAttachmentNotice(is_string($data['attachmentNotice'] ?? null) ? $data['attachmentNotice'] : null)
                ->withStepId(is_string($data['stepId'] ?? null) ? $data['stepId'] : null)
                ->withTurnTranscript($turnRows)
                // Roadmap 2.2-2: rebuilt leniently (ContextLedger::fromArray()
                // skips what it cannot read). A frame without the key — an
                // older child, or a turn handed no ledger — carries none.
                ->withContextLedger(\is_array($data['contextLedger'] ?? null)
                    ? \SugarCraft\Crush\Context\Pruning\ContextLedger::fromArray($data['contextLedger'])
                    : null)
        );
    }

    /**
     * Flatten one tool event for the fork payload.
     *
     * Plain nested arrays, not the objects themselves, because the parent
     * unserializes with `allowed_classes => false` (a hostile/corrupt payload
     * must never be able to instantiate anything) - so the objects are rebuilt
     * on the other side by {@see decodeEvent()} instead of round-tripped.
     *
     * @return array<string, mixed>
     */
    private static function encodeEvent(ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|ContextLedgerChanged $event): array
    {
        if ($event instanceof ContextLedgerChanged) {
            // Roadmap 3.B-3: the live `ledger` frame — what a Prune (or an
            // over-budget relief) just took out of the model's view, as the
            // delta's own plain-array form, so the host can dim the rows
            // while the turn still runs.
            return ['kind' => 'ledger'] + $event->toArray();
        }

        if ($event instanceof SpendCapBreached) {
            return [
                'kind' => 'spend_cap',
                'calls' => $event->completedCalls,
                'spent' => $event->spentUsd,
                'cap' => $event->capUsd,
            ];
        }

        if ($event instanceof SubAgentActivity) {
            // The v2 shape (P-B1) is the DTO's own: the grandchild relay and
            // the server's agent.* events share it, so it lives in one place.
            return ['kind' => 'subagent'] + $event->toArray();
        }

        if ($event instanceof ToolStarted) {
            return [
                'kind' => 'started',
                'id' => $event->toolCallId,
                'name' => $event->toolName,
                'arguments' => $event->arguments,
            ];
        }

        return [
            'kind' => 'finished',
            'id' => $event->toolCallId,
            'name' => $event->toolName,
            'content' => $event->result->content(),
            'isError' => $event->result->isError(),
            'durationMs' => $event->result->durationMs(),
            'imageBytes' => $event->result->imageBytes(),
            'imagePath' => $event->result->imagePath(),
            'imageProtocol' => $event->result->imageProtocol(),
            'diff' => $event->result->diff(),
            // Parity with the sync path's event (audit B4); the turn's
            // ACCOUNTING does not ride here — it is summed in the child and
            // crosses on the result frame's Message usage.
            'usage' => $event->result->usage()?->toArray(),
            // Audit F-P8: the refusal kind is STRUCTURE, not text, so it has
            // to cross this frame or every real refusal reaches the TUI as an
            // ordinary error row. Its backing value — a plain string, safe
            // under allowed_classes => false.
            'denial' => $event->result->denial()?->value,
        ];
    }

    /**
     * A beat's running totals, each 0 when absent or out of shape — kept as
     * the codec's public entry point; the rules live on
     * {@see SubAgentActivity::totals()} with the rest of the v2 validator.
     *
     * @param array<string, mixed> $encoded
     * @return array{tokensUsed: int, costUsd: float, lines: int, model: string, contextTokens: int, calls: list<array{id: string, label: string, state: string, at: int}>}
     */
    public static function subAgentTotals(array $encoded): array
    {
        return SubAgentActivity::totals($encoded);
    }

    /**
     * Rebuild a tool event flattened by {@see encodeEvent()}, or null when the
     * entry is not a shape this version wrote (a partial write, or a payload
     * from a mismatched build) - one unrecognizable event is skipped rather
     * than failing the whole turn.
     *
     * @param array<string, mixed> $encoded
     */
    private static function decodeEvent(array $encoded): ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|ContextLedgerChanged|null
    {
        // THE KIND IS READ BEFORE THE IDENTITY, and the order is the fix, not
        // a style choice: a spend_cap frame carries no toolCallId/name pair at
        // all, and the old leading guard rejected anything without them — it
        // would have silently dropped every breach report off an otherwise
        // healthy socket, on the fork path only, with the sync path green.
        $kind = $encoded['kind'] ?? null;

        if ($kind === 'spend_cap') {
            $calls = is_int($encoded['calls'] ?? null) ? $encoded['calls'] : null;
            $spent = $encoded['spent'] ?? null;
            $cap = $encoded['cap'] ?? null;
            if ($calls === null || !(is_float($spent) || is_int($spent)) || !(is_float($cap) || is_int($cap))) {
                return null;
            }

            return new SpendCapBreached($calls, (float) $spent, (float) $cap);
        }

        if ($kind === 'ledger') {
            // Lenient like the ledger's own reader: entries it cannot read
            // are skipped, and a delta with nothing left is no event at all.
            return ContextLedgerChanged::fromArray($encoded);
        }

        if ($kind === 'subagent') {
            // A delegation beat is complete or it is dropped, one frame at a
            // time, on the same partial-write tolerance as every other arm:
            // an out-of-shape beat costs the dashboard one row-update, not
            // the turn. `task` rides only on started but is validated as a
            // string always — encodeEvent writes it on every op (empty after
            // started), so a frame missing it predates this build. A v1 frame
            // (no `v`) decodes with the v2 fields at their defaults.
            return SubAgentActivity::fromArray($encoded);
        }

        $id = is_string($encoded['id'] ?? null) ? $encoded['id'] : null;
        $name = is_string($encoded['name'] ?? null) ? $encoded['name'] : null;
        if ($id === null || $name === null) {
            return null;
        }

        if ($kind === 'started') {
            return new ToolStarted($id, $name, is_array($encoded['arguments'] ?? null) ? $encoded['arguments'] : []);
        }

        if ($kind !== 'finished') {
            return null;
        }

        return new ToolFinished($id, $name, new ToolResult(
            toolCallId: $id,
            content: (string) ($encoded['content'] ?? ''),
            isError: (bool) ($encoded['isError'] ?? false),
            durationMs: is_int($encoded['durationMs'] ?? null) ? $encoded['durationMs'] : null,
            imageBytes: is_string($encoded['imageBytes'] ?? null) ? $encoded['imageBytes'] : null,
            imagePath: is_string($encoded['imagePath'] ?? null) ? $encoded['imagePath'] : null,
            imageProtocol: is_string($encoded['imageProtocol'] ?? null) ? $encoded['imageProtocol'] : null,
            diff: is_string($encoded['diff'] ?? null) ? $encoded['diff'] : null,
            usage: Usage::fromArray($encoded['usage'] ?? null),
            // Absent (an older frame) or not a known kind: not a refusal.
            denial: is_string($encoded['denial'] ?? null) ? DenialKind::tryFrom($encoded['denial']) : null,
        ));
    }

    /**
     * Fallback for an environment without pcntl/stream_socket_pair support:
     * the old synchronous-under-a-Promise behaviour. Blocks the caller for
     * the duration of the request instead of freezing the whole program
     * silently - a real capability gap, not a bug to hide.
     *
     * 1.C-1: an INTERACTIVE turn here has no child to block and no loop to
     * wait on, so a question can only be answered synchronously, from inside
     * the `$onEvent` call that delivers its {@see \SugarCraft\Crush\Events\PermissionAsked}.
     * One left open settles `cancelled` at once and the backend's own
     * synchronous approver, when one is attached, answers instead; with none
     * it is refused, exactly as a non-interactive turn's would be.
     */
    private function completeAsyncBlocking(array $history, ?callable $onToken, Deferred $deferred, ?callable $onEvent = null, ?callable $onReasoning = null, bool $interactive = false): PromiseInterface
    {
        try {
            $engine = $this;
            if ($interactive && $onEvent !== null) {
                $fallback = $this->permissionApprover;
                $mode = $this->permissionGate?->mode()->value ?? '';
                $engine = $this->withPermissionApprover(static function (\SugarCraft\Crush\Tools\ToolCall $call, \SugarCraft\Crush\Hooks\HookResult $ask) use ($onEvent, $fallback, $mode): bool {
                    $pending = PendingAsk::fromFrame(
                        PendingAsk::describe($call, $ask, $mode),
                        static function (\SugarCraft\Crush\Events\PermissionResolved $resolution) use ($onEvent): void {
                            $onEvent($resolution);
                        },
                    );
                    if ($pending === null) {
                        return false;
                    }
                    $onEvent(new \SugarCraft\Crush\Events\PermissionAsked($pending));
                    if (!$pending->isSettled()) {
                        $pending->cancel('this host has no ext-pcntl, so a question can only be answered while it is being asked');

                        return $fallback !== null && $fallback($call, $ask) === true;
                    }

                    return $pending->resolution()?->permits() === true;
                });
            }

            // No fork here, so tool events reach the caller LIVE on this path
            // (mid-turn, as each call starts/ends) rather than replayed.
            //
            // $onReasoning is the one callback that must NOT be handed
            // through untouched. On the forked path the parent drops a frame
            // whose `text` is empty (that frame exists for the idle timer, not
            // for the screen), so a caller of completeAsync() is never invoked
            // with ''. There is no socket here and therefore no timer, so the
            // heartbeat has no job at all on this path - and passing it on
            // would make completeAsync()'s callback contract depend on whether
            // this host has ext-pcntl, which is not a difference a consumer
            // painting live thinking can be expected to know about.
            $paintable = $onReasoning === null ? null : static function (string $delta) use ($onReasoning): void {
                if ($delta !== '') {
                    $onReasoning($delta);
                }
            };
            $deferred->resolve($engine->complete($history, $onToken, $onEvent, $paintable));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    /**
     * Resolve the hook manager that gates every tool call this turn.
     *
     * Safe-by-default: a backend constructed without an explicit
     * {@see withHooks()} call still registers the built-in hooks
     * ({@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\AuditHook}) so Bash/Edit/Write
     * tools never run unguarded. Callers opt out explicitly via
     * {@see withoutHooks()}.
     *
     * A {@see withPermissionGate()} gate is registered LAST, after the
     * built-ins — see {@see PermissionGateHook} for why that order (both are
     * fail-closed; the order picks which message wins). Registration mutates
     * the manager in place (unlike {@see withWorktreeRoot()}, which clones it
     * because it ADDS a hook its receiver must not gain):
     * {@see \SugarCraft\Crush\Hooks\HookRegistry} keys hooks by name, so
     * re-running this per turn REPLACES the same entry rather than stacking
     * gates with independent circuit-breaker state.
     */
    private function resolveHookManager(ToolCallLoopGuard $loopGuard): HookManager
    {
        // ONE manager per turn (step 3.D-2): the turn's loop guard is made
        // once per turn, so it keys the copy built for it. The step loop asks
        // again for the chain the Runtime gates with — to read a hook's
        // "continue": false off it and to run the Stop chain on it — and must
        // get that same instance, not a fresh clone with an empty halt record.
        static $perTurn = null;
        $perTurn ??= new \WeakMap();
        if (isset($perTurn[$loopGuard])) {
            return $perTurn[$loopGuard];
        }

        $manager = $this->hookManager;

        if ($manager === null) {
            $manager = new HookManager(new HookRegistry());
            if (!$this->hooksDisabled) {
                $manager->registerBuiltIns();
            }
        }

        // The sub-agent's own grant (step 4.2), AHEAD of the session gate and
        // outside the `hooksDisabled` guard like it: a preset's declaration
        // is not something the hooks opt-out may widen. On a per-turn COPY of
        // a shared manager, never on the shared one itself — it binds ONE
        // delegated run, and left on the launch's manager it would hold the
        // caller's next turn to that run's grant. Ahead of the gate so a call
        // outside the grant is refused for that reason, never first put to
        // the user as a question: the chain lets a deny outrank an ask in
        // either order, but the reason the model reads is the first refusal.
        if ($this->subAgentGrant !== null) {
            if ($manager === $this->hookManager) {
                $manager = clone $manager;
            }
            $manager->register($this->subAgentGrant);
        }

        if ($this->permissionGate !== null) {
            $manager->register(new PermissionGateHook($this->permissionGate));
        }

        // The repeat-call loop guard's pair, on a per-turn COPY when the
        // manager is the launch's shared one: the guard's ledger lives one
        // turn, and registering it on the shared manager would leave the
        // previous turn's ledger armed on every later caller of it (Chat's
        // own hook lookups included) until the next turn overwrote it. A
        // clone gets its own registry and shares the hook objects
        // ({@see HookManager::__clone()}), so the chain is otherwise the
        // same one. Registered LAST — after the gate — and on a
        // `withoutHooks()` turn too: it is a brake on runaway loops, not a
        // permission guard, so the opt-out does not drop it.
        if ($manager === $this->hookManager) {
            $manager = clone $manager;
        }
        $manager->register(new RepeatCallGuardHook($loopGuard));
        $manager->register(new RepeatCallCountHook($loopGuard));

        return $perTurn[$loopGuard] = $manager;
    }

    /**
     * Convert the chassis's root Message history into the engine's typed
     * message hierarchy.
     *
     * UI-only rows are dropped here as well as in Chat (audit 15b-03): this is
     * the last seam before a provider request, so a caller that hands this
     * backend a raw transcript - an embedder, a test, a future dispatch site -
     * still cannot put `/help`'s output or a queued-prompt notice on the wire.
     *
     * ATTACHMENTS REACH THE WIRE HERE (audit 15b-15). Every attachment a
     * user row carries is mapped onto the typed message with
     * {@see UserMessage::withAttachment()}; the provider's encoder then sends
     * files as inlined text and images as its own image part
     * ({@see \SugarCraft\Crush\Providers\AttachmentEncoding}). The one
     * decision made HERE rather than in each encoder is whether an image may
     * go as an image at all: a provider whose
     * {@see ProviderInterface::supportsVision()} answers false is never handed
     * one. Its image becomes a named text placeholder on that turn - so the
     * model knows something was attached and the conversation still reads -
     * and, for the turn's OWN prompt (the last user row; older turns were
     * reported when they were sent), `$attachmentNotice` says so for the
     * user. Never a silent drop.
     *
     * The capability is asked lazily, once, and only when an image is
     * present: SGLang answers it from the server's `/model_info`, and a turn
     * with no image has no reason to wait on that.
     *
     * TOOL HISTORY IS REPLAYED AS IT HAPPENED (roadmap 1.B-2). Every row a
     * finished turn recorded carries its step's id ({@see Message::$stepId},
     * folded in by {@see Message::settleTurnTranscript()}), and the rows of
     * one step are rebuilt together, at the step's first row: its assistant
     * row as an {@see AssistantMessage} with its tool calls, narration and
     * reasoning, then one {@see ToolResultMessage} per result under its call
     * id. Zed's two rules keep the pairing valid: an empty result is sent as
     * `<Tool returned an empty string>`, and a call no row answers (a cancel,
     * a row since compacted) as `Tool canceled by user`. A result whose step
     * has no assistant row left, or whose call that row does not make, is
     * read back as prose rather than sent as an orphan. A row with no step id
     * - every row of a transcript saved before steps were recorded - is
     * replayed exactly as before: tool output as assistant prose.
     *
     * @param array<int, Message> $history
     * @return array<int, TypedMessage>
     */
    private function toTypedMessages(array $history, ?string &$attachmentNotice = null): array
    {
        $attachmentNotice = null;
        $visible = Message::agentVisible($history);
        $lastUser = null;
        // Each recorded step's assistant row and results, gathered first so a
        // step is rebuilt whole wherever its rows sit.
        $steps = [];
        foreach ($visible as $i => $msg) {
            if ($msg->role->value === 'user') {
                // A harness-written prompt (hidden) never owns the turn's
                // attachment notice; the prompt the user typed does.
                if ($msg->userVisible) {
                    $lastUser = $i;
                }

                continue;
            }
            if ($msg->stepId === null || $msg->role->value !== 'assistant') {
                continue;
            }
            if ($msg->toolResults !== []) {
                $steps[$msg->stepId]['results'][] = $msg;
            } else {
                $steps[$msg->stepId]['assistant'] ??= $msg;
            }
        }

        $vision = null;
        $out = [];
        $replayed = [];
        foreach ($visible as $i => $msg) {
            if ($msg->role->value === 'user') {
                $rowNotice = null;
                $out[] = $this->typedUserMessage($msg, $vision, $rowNotice);
                if ($i === $lastUser) {
                    $attachmentNotice = $rowNotice;
                }

                continue;
            }
            if ($msg->role->value !== 'assistant') {
                $out[] = new SystemMessage($msg->content);

                continue;
            }
            if ($msg->stepId === null) {
                $out[] = new AssistantMessage($msg->content);

                continue;
            }
            if (isset($replayed[$msg->stepId])) {
                continue;
            }
            $replayed[$msg->stepId] = true;

            $step = $steps[$msg->stepId];
            $assistant = $step['assistant'] ?? null;
            $calls = [];
            foreach ($assistant?->toolCalls ?? [] as $call) {
                if ($call instanceof \SugarCraft\Crush\ToolCall) {
                    $calls[] = $call->toEngineCall();
                }
            }
            $made = [];
            foreach ($calls as $call) {
                $made[$call->id()] = true;
            }

            $answers = [];
            $answered = [];
            foreach ($step['results'] ?? [] as $row) {
                foreach ($row->toolResults as $result) {
                    $id = $result->id ?? $result->name;
                    if (!isset($made[$id])) {
                        $answers[] = new AssistantMessage($row->content);

                        continue;
                    }
                    $content = $result->isError() ? (string) $result->error : $result->result;
                    $answers[] = new ToolResultMessage(
                        $id,
                        $content === '' ? '<Tool returned an empty string>' : $content,
                        $result->isError(),
                    );
                    $answered[$id] = true;
                }
            }
            foreach ($calls as $call) {
                if (!isset($answered[$call->id()])) {
                    $answers[] = new ToolResultMessage($call->id(), 'Tool canceled by user', true);
                }
            }

            if ($assistant !== null) {
                $out[] = new AssistantMessage($assistant->content, $calls === [] ? null : $calls, $assistant->reasoning);
            }
            array_push($out, ...$answers);
        }

        return $out;
    }

    /**
     * The session ledger this turn starts from (roadmap 3.B-2): the one the
     * host handed in, with {@see \SugarCraft\Crush\Context\Pruning\TurnStartPruning}'s
     * batch applied when the session's mode runs the strategies. A turn
     * boundary is the deliberate point to rewrite (DCP §13.2 D): the cache
     * breaks once there and every step of the turn reuses the rewritten
     * prefix, where a prune between steps would break it again and again.
     * Null without a ledger.
     *
     * @param list<TypedMessage> $typed the turn's conversation, unprojected
     */
    private function turnStartLedger(array $typed): ?\SugarCraft\Crush\Context\Pruning\ContextLedger
    {
        $ledger = $this->contextLedger;
        if ($ledger === null || !$ledger->effectiveMode()->runsStrategies()) {
            return $ledger;
        }

        $delta = \SugarCraft\Crush\Context\Pruning\TurnStartPruning::propose(
            \SugarCraft\Crush\Context\Pruning\ContextProjector::new()->project($typed, $ledger)->messages,
            $ledger,
            \SugarCraft\Crush\Context\Pruning\PruningPolicy::new(),
        );

        return $delta === null ? $ledger : $ledger->apply($delta);
    }

    /**
     * One user row as a {@see UserMessage}, its attachments carried - see
     * {@see toTypedMessages()}.
     */
    private function typedUserMessage(Message $msg, ?bool &$vision, ?string &$notice): UserMessage
    {
        $content = $msg->content;
        $kept = [];
        $withheld = [];
        foreach ($msg->attachments as $attachment) {
            if (!$attachment instanceof Attachment) {
                continue;
            }
            if ($attachment->type === AttachmentType::Image && $attachment->data !== null) {
                $vision ??= $this->provider->supportsVision();
                if (!$vision) {
                    // One line however the file was named: the notice is a
                    // transcript row, and a name is whatever a filename holds.
                    $withheld[] = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $attachment->name())) ?: '(unnamed)';
                    $content .= ($content === '' ? '' : "\n\n") . '[Image attachment '
                        . str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $attachment->path)
                        . ' was not sent: the active model does not accept images.]';

                    continue;
                }
            }
            $kept[] = $attachment;
        }

        $typed = new UserMessage($content);
        foreach ($kept as $attachment) {
            $typed = $typed->withAttachment($attachment);
        }

        if ($withheld !== []) {
            $notice = sprintf(
                '%s %s not sent: %s does not accept images, so the model was told %s attached but cannot see %s. '
                . 'Switch to a vision-capable model to send %s.',
                count($withheld) === 1 ? 'Image' : 'Images',
                implode(', ', $withheld) . (count($withheld) === 1 ? ' was' : ' were'),
                $this->provider->name() . ' (' . ($this->servedModel() ?? $this->model) . ')',
                count($withheld) === 1 ? 'it was' : 'they were',
                count($withheld) === 1 ? 'it' : 'them',
                count($withheld) === 1 ? 'it' : 'them',
            );
        }

        return $typed;
    }
}
