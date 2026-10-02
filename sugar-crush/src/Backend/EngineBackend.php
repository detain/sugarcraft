<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\ProcessContainment;
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
final class EngineBackend implements Backend, ReportsContextWindow, ObservesReasoning
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
      */
    private const COMPLETE_TIMEOUT_SECONDS = 120;

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
        private readonly int $maxSteps = 8,
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
         * same instant, so none counts another's spend. The caller's next
         * step boundary does — every sibling's usage is folded in there — so
         * the overshoot is bounded by one step's worth of parallel runs.
         */
        private readonly ?\Closure $turnSpendProbe = null,
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
     * @param array<int, \SugarCraft\Crush\Tools\Tool> $tools
     */
    public function withTools(array $tools): self
    {
        return $this->mutate(['tools' => $tools]);
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
     * The approver must return literal `true` to grant — see
     * {@see Runtime::settleAsk()} on why a truthy cast is not enough.
     *
     * WHO CALLS THIS — measured, not assumed
     * (`grep -rn withPermissionApprover src/ bin/`):
     *
     * 1. THE CONSOLE PATHS DO, and this used to say nothing did.
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
     * 2. THE TUI STILL DOES NOT, and that is the limit that remains — a seam
     *    rather than something papered over. {@see \SugarCraft\Crush\Chat}
     *    owns the blocking prompt UI, but its prompt is a `Deferred` settled
     *    by a later `Msg`, not a function that returns a verdict; and
     *    {@see completeAsync()} runs {@see complete()} inside a
     *    `pcntl_fork()`ed child whose only channel back to the parent is a
     *    one-way frame stream, so a closure attached from the TUI could not
     *    put its question on screen from in there even if the shapes did
     *    match. That needs a request/response protocol on the socket. Until
     *    it lands, an ASK raised on the engine path from inside a TUI session
     *    still settles as {@see Runtime::settleAsk()}'s no-approver refusal —
     *    which is why the shipped default mode is still
     *    {@see \SugarCraft\Crush\Permissions\PermissionMode::BypassPermissions}.
     *
     * An attached approver works on every SYNCHRONOUS {@see complete()} caller
     * (the two above, embedders, {@see completeAsyncBlocking()}'s no-pcntl
     * fallback, tests) — which is exactly the set of callers that can be
     * served before the socket work, and is why the parameter was threaded
     * ahead of it.
     *
     * @param \Closure(\SugarCraft\Crush\Tools\ToolCall, \SugarCraft\Crush\Hooks\HookResult): bool $approver
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
     * Separate from {@see withWorktreeRoot()} on purpose: that one registers
     * a Bash-escape guard and says nothing about what the model is told,
     * while this one is purely the reported/gated root.
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

    public function withMaxSteps(int $maxSteps): self
    {
        return $this->mutate(['maxSteps' => max(1, $maxSteps)]);
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
     * Register BashEscapeDenyHook with the given worktree root to prevent Bash
     * commands from referencing paths outside the worktree.
     *
     * This wires the heuristic PreToolUse hook so that Bash commands are
     * checked before execution. Without this, Bash is confined only by the
     * `cd $worktreeRoot` prefix which does NOT prevent escape via `cd /` or
     * `..` traversal within the command string itself.
     *
     * The returned backend owns a CLONE of the hook manager (audit F-J5): the
     * worktree-scoped guard belongs to the sub-agent's backend only, and
     * registering on the shared instance in place gave the parent's chain the
     * sub-agent's root as a deny boundary too — a `with*()` that mutated its
     * receiver.
     *
     * Not wired in production yet: it waits on worktree isolation (crush_report
     * Part II #23), which is what will give a sub-agent a root of its own.
     *
     * @see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook
     */
    public function withWorktreeRoot(string $worktreeRoot): self
    {
        if ($this->hooksDisabled) {
            return $this;
        }

        $manager = $this->hookManager !== null
            ? clone $this->hookManager
            : new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new BashEscapeDenyHook($worktreeRoot));

        return $this->mutate(['hookManager' => $manager, 'hooksDisabled' => false]);
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

        return $this->runTurn($this->toTypedMessages($history), $onToken, $onEvent, $onReasoning, $onHeartbeat, $transcript);
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
     * @param list<TypedMessage> $messages
     *
     * @throws TurnInterrupted
     */
    public function completeTranscript(array $messages, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onHeartbeat = null, ?callable $onToken = null): TranscriptTurn
    {
        $transcript = $messages;

        try {
            $reply = $this->runTurn($messages, $onToken, $onEvent, $onReasoning, $onHeartbeat, $transcript);
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
     * @param list<TypedMessage> $messages
     * @param list<TypedMessage> $transcript
     */
    private function runTurn(array $messages, ?callable $onToken, ?callable $onEvent, ?callable $onReasoning, ?callable $onHeartbeat, array &$transcript): Message
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
        $spentSoFarUsd = static function () use ($sessionSpendAtStartUsd, &$stepUsages): float {
            $spent = $sessionSpendAtStartUsd;
            foreach ($stepUsages as $stepUsage) {
                $spent += $stepUsage?->costUsd ?? 0.0;
            }

            return $spent;
        };

        // Read once and hand to both resolvers, so a turn touches the config
        // file at most one time however many settings are resolved off it.
        $userConfig = self::userConfig();

        $runtime = new Runtime(
            $this->provider,
            $this->resolveHookManager(),
            parallelToolCalls: self::parallelToolCallsEnabled($userConfig),
            parallelToolDeadlineSeconds: self::parallelToolDeadlineSeconds($userConfig),
            maxOutputTokens: self::maxOutputTokens($userConfig),
        );

        $app = App::new($this->provider, $this->model)
            ->withTools($this->turnTools($onReasoning, $onHeartbeat, $onEvent, $spentSoFarUsd))
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

        // Bounded agentic loop: keep running while the model asks for tools.
        // The Runtime resolves one assistant turn + its tool calls per run();
        // we feed the results back and re-run until the model answers without
        // tools — or we hit the step ceiling (guards against runaway loops,
        // which neither sugar-crush nor candy-crush had).
        for ($step = 0; $step < $this->maxSteps; $step++) {
            $assistant = null;
            $toolResults = [];

            foreach ($runtime->run($app, $onEvent, $this->permissionApprover, $tokenSink, $progressSink, $onHeartbeat) as $message) {
                if ($message instanceof AssistantMessage) {
                    $assistant = $message;
                    // Counted ON ARRIVAL, before this step's tools run: a Task
                    // call among them reads $spentSoFarUsd as its sub-agent's
                    // cap baseline, and the step that asked for it is paid.
                    $stepUsages[] = $assistant->usage();
                } elseif ($message instanceof ToolResultMessage) {
                    $toolResults[] = $message;
                    // Folded AS IT SETTLES, not after the step: a sequential
                    // Task later in this same step reads $spentSoFarUsd when
                    // it starts, and must see this one's dollars.
                    if ($message->usage() !== null) {
                        $stepUsages[] = self::delegatedSpend($message->usage());
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

            if ($toolResults === []) {
                $answeredWithoutTools = true;
                break; // model answered without calling tools — done
            }

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

        // Thread the reasoning ReasoningExtractor already split out (§12 D3)
        // across the typed-Message -> root-Message seam instead of dropping
        // it here - it's the last point in this call path that still has
        // access to $lastAssistant before only the plain-string Message DTO
        // survives back to Chat/Renderer. withImage() does the same for an
        // image-bearing tool result (W1.G2 reachability fix).
        // F2: neither deliberate break fired, so the LAST step still ended
        // with tool results pending and the ceiling, not the model, ended the
        // turn. One flag on the DTO; the transcript notice is Chat's settle
        // arm's job (sibling of the E707 length-stopped notice).
        $stepsTruncated = !$answeredWithoutTools && !$stoppedBySpendCap;

        return Message::assistant($content, reasoning: $lastAssistant?->reasoning())
            ->withImage($lastImageBytes, $lastImageProtocol)
            ->withUsage(Usage::sum($stepUsages))
            ->withLengthStopped($lengthStopped)
            ->withStepsTruncated($stepsTruncated);
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
     * KNOWN RESIDUE: Chat's calibration falls back to totalTokens when the
     * provider reported no prompt buckets. That figure was already a sum over
     * every step of a tool-using turn, not a prompt size; delegated tokens
     * widen it further (the ratio is clamped, and only ever tightens the
     * context tiers). The fix belongs to that fallback in Chat, not here —
     * dropping the tokens would under-report the session total instead.
     */
    private static function delegatedSpend(Usage $usage): ?Usage
    {
        return Usage::reported(
            $usage->totalTokens,
            $usage->costUsd,
            unpricedModel: $usage->unpricedModel,
        );
    }

    /**
     * This turn's tool list, with every {@see DelegatesToEngine} tool bound to
     * THIS engine — see that interface for why the binding has to happen here,
     * per turn, rather than at construction.
     *
     * The heartbeat prefers the bare batch beat (`$onHeartbeat`) and falls back
     * to an EMPTY reasoning delta, which is the same "alive, nothing to show"
     * frame E456 established, so neither channel paints anything. Rate-limited
     * to one beat a second because a delegated run reports every provider
     * chunk, and bound to the current pid because a forked tool child must not
     * write onto a socket its parent is also writing to (the parent beats for
     * its forked group itself — {@see Runtime}'s concurrent wait loop).
     *
     * The sub-agent emitter gets the same pid binding for the same socket-
     * corruption reason, but NO rate limit: TaskTool throttles its own beats
     * (tool-boundary frames always, reasoning frames at most one a second),
     * and the events it is rare enough to be worth every one. It exists
     * because the delegated run happens inside THIS process when the turn
     * itself was forked — without a wire back, the parent's AgentManager never
     * learns a sub-agent exists ({@see SubAgentActivity}).
     *
     * The bound engine carries `$spentSoFarUsd` as its {@see $turnSpendProbe},
     * so the delegated run's spend cap starts from what this turn has really
     * spent by then, not from this turn's starting baseline (audit B4).
     *
     * @param \Closure(): float $spentSoFarUsd
     *
     * @return list<Tool>
     */
    private function turnTools(?callable $onReasoning, ?callable $onHeartbeat, ?callable $onEvent, ?\Closure $spentSoFarUsd = null): array
    {
        $heartbeat = null;
        if ($onHeartbeat !== null || $onReasoning !== null) {
            $pid = getmypid();
            $lastBeat = 0.0;
            $heartbeat = static function () use ($pid, $onHeartbeat, $onReasoning, &$lastBeat): void {
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

        $bound = null;
        $tools = [];
        foreach ($this->tools as $tool) {
            if (!$tool instanceof DelegatesToEngine) {
                $tools[] = $tool;

                continue;
            }
            $bound ??= $spentSoFarUsd === null ? $this : $this->mutate(['turnSpendProbe' => $spentSoFarUsd]);
            $tools[] = $tool->withEngine($bound, $heartbeat, $subAgentEmitter);
        }

        return $tools;
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
     * The wall-clock budget one concurrent group gets, as configured.
     *
     * The ceiling is not a preference: the group deadline is enforced INSIDE
     * the forked completion child, and no frame reaches the parent while a
     * group is executing, so a group allowed to outlive
     * {@see COMPLETE_TIMEOUT_SECONDS} would have the whole turn SIGKILLed from
     * above — losing every sibling's result — instead of the one stuck call
     * being reported as a failed call. A configured value at or past that
     * ceiling therefore cannot be honoured, and neither can a zero or negative
     * one.
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
        $env = getenv(self::PARALLEL_TOOL_DEADLINE_ENV);
        $seconds = self::honourableDeadline($env === false ? null : $env);
        if ($seconds !== null) {
            return $seconds;
        }

        $config ??= self::userConfig();

        return self::honourableDeadline($config[self::PARALLEL_TOOL_DEADLINE_CONFIG_KEY] ?? null)
            ?? Runtime::PARALLEL_TOOL_DEADLINE_SECONDS;
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
     */
    private static function honourableDeadline(mixed $raw): ?int
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
        if ($raw < 1 || $raw >= self::COMPLETE_TIMEOUT_SECONDS) {
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
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null): PromiseInterface
    {
        $deferred = new Deferred();

        // Costs one WNOHANG syscall per tracked straggler and buys back every
        // child an earlier turn's bounded reap had to give up on. See
        // self::$unreapedChildren.
        self::sweepUnreapedChildren();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning);
        }

        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning);
        }

        [$parentSocket, $childSocket] = $sockets;
        // E692 (Phase 9 scope call, lane bc): this pcntl_fork is an exec-free
        // in-process fork, deliberately OUTSIDE ProcessContainment's remit — the
        // choke point contains COMMAND children (spawn→env→detach), while a fork
        // of this process runs our own code with no argv, no PATH lookup, and no
        // interactive-prompt surface to fail fast against.
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);

            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning);
        }

        if ($pid === 0) {
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
            $this->runCompleteInChild($childSocket, $history);
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

        // Shared teardown for the failure ways this can end (timeout,
        // cancellation): stop watching the socket, cancel BOTH timers
        // (critical for $cancelTimer, a periodic timer that would otherwise
        // keep polling forever after settling via a different path), kill and
        // reap the child so it never zombies.
        $teardown = function (string $rejectMessage) use (&$settled, $loop, $parentSocket, $pid, $deferred, &$timeoutTimer, &$cancelTimer, &$exitTimer): void {
            if ($settled) {
                return;
            }
            $settled = true;
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
            // of them running for nobody. killTree() falls back to exactly
            // that direct kill where /proc or ext-posix is missing.
            ProcessContainment::killTree($pid);
            self::reapChild($pid);
            $deferred->reject(new \RuntimeException($rejectMessage));
        };

        // The success path: the child delivered its result frame, hung up,
        // or was seen to exit by $exitTimer. Same cleanup as $teardown minus the kill (the child is
        // already on its way out), then settle from whatever result frame
        // arrived - a child that died before writing one is still a failure.
        $finalize = function () use (&$settled, &$result, &$streamed, &$buffer, &$streamCorrupt, $loop, $parentSocket, $pid, $deferred, $onToken, &$timeoutTimer, &$cancelTimer, &$exitTimer): void {
            if ($settled) {
                return;
            }
            $settled = true;
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
        $resetTimeout = function () use (&$settled, $loop, &$timeoutTimer, $teardown): void {
            if ($settled) {
                return;
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            $timeoutTimer = $loop->addTimer(self::COMPLETE_TIMEOUT_SECONDS, static function () use ($teardown): void {
                $teardown('Provider request timed out after ' . self::COMPLETE_TIMEOUT_SECONDS . 's without progress');
            });
        };
        $resetTimeout();

        // Escape-Escape abort (see Chat::update()'s Escape handling): the
        // cancellation flag can flip at any point after this call returns,
        // long after the closures below were built, so it has to be polled
        // rather than checked once up front.
        $cancelTimer = $cancellation === null ? null : $loop->addPeriodicTimer(0.1, function () use ($cancellation, $teardown): void {
            if ($cancellation->isCancelled()) {
                $teardown('Request cancelled');
            }
        });

        // Frame dispatch for one chunk off the socket, shared by the read edge
        // below and by $exitTimer's final drain so the two cannot disagree
        // about what a frame means.
        $consume = function (string $chunk) use (&$buffer, &$result, &$streamed, &$streamCorrupt, &$childReaped, $onToken, $onEvent, $onReasoning, $finalize, $resetTimeout, $teardown): void {
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
     * @param array<int, Message> $history
     */
    private function runCompleteInChild($childSocket, array $history): never
    {
        try {
            // This is a forked child, so invoking the caller's callback
            // in-process would write into a copy of its state and vanish on
            // exit - the event has to cross the socket. It goes out
            // IMMEDIATELY rather than into an end-of-turn batch, because the
            // batch is exactly what made a multi-tool turn look like a silent
            // "thinking" spinner (and what made the parent's single
            // wall-clock timer kill turns that were in fact making progress).
            $message = $this->complete(
                $history,
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
                static function (ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity $event) use ($childSocket): void {
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
            );
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
            ];
        } catch (\Throwable $e) {
            $payload = ['kind' => 'result', 'ok' => false, 'error' => $e->getMessage()];
        }

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
    private static function encodeEvent(ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity $event): array
    {
        if ($event instanceof SpendCapBreached) {
            return [
                'kind' => 'spend_cap',
                'calls' => $event->completedCalls,
                'spent' => $event->spentUsd,
                'cap' => $event->capUsd,
            ];
        }

        if ($event instanceof SubAgentActivity) {
            return [
                'kind' => 'subagent',
                'op' => $event->op,
                'id' => $event->id,
                'name' => $event->name,
                'task' => $event->task,
                'seq' => $event->seq,
                'tail' => $event->tail,
            ];
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
     * Rebuild a tool event flattened by {@see encodeEvent()}, or null when the
     * entry is not a shape this version wrote (a partial write, or a payload
     * from a mismatched build) - one unrecognizable event is skipped rather
     * than failing the whole turn.
     *
     * @param array<string, mixed> $encoded
     */
    private static function decodeEvent(array $encoded): ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|null
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

        if ($kind === 'subagent') {
            // A delegation beat is complete or it is dropped, one frame at a
            // time, on the same partial-write tolerance as every other arm:
            // an out-of-shape beat costs the dashboard one row-update, not
            // the turn. `task` rides only on started but is validated as a
            // string always — encodeEvent writes it on every op (empty after
            // started), so a frame missing it predates this build.
            $op = $encoded['op'] ?? null;
            $id = $encoded['id'] ?? null;
            $name = $encoded['name'] ?? null;
            $task = $encoded['task'] ?? null;
            $seq = $encoded['seq'] ?? null;
            $tail = $encoded['tail'] ?? null;
            if (!is_string($op) || !in_array($op, SubAgentActivity::OPS, true)
                || !is_string($id) || $id === ''
                || !is_string($name) || $name === ''
                || !is_string($task) || !is_int($seq) || !is_string($tail)) {
                return null;
            }

            return new SubAgentActivity($op, $id, $name, $task, $seq, $tail);
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
     */
    private function completeAsyncBlocking(array $history, ?callable $onToken, Deferred $deferred, ?callable $onEvent = null, ?callable $onReasoning = null): PromiseInterface
    {
        try {
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
            $deferred->resolve($this->complete($history, $onToken, $onEvent, $paintable));
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
    private function resolveHookManager(): HookManager
    {
        $manager = $this->hookManager;

        if ($manager === null) {
            $manager = new HookManager(new HookRegistry());
            if (!$this->hooksDisabled) {
                $manager->registerBuiltIns();
            }
        }

        if ($this->permissionGate !== null) {
            $manager->register(new PermissionGateHook($this->permissionGate));
        }

        return $manager;
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
     * @param array<int, Message> $history
     * @return array<int, TypedMessage>
     */
    private function toTypedMessages(array $history): array
    {
        $out = [];
        foreach (Message::agentVisible($history) as $msg) {
            $out[] = match ($msg->role->value) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
                default => new SystemMessage($msg->content),
            };
        }

        return $out;
    }
}
