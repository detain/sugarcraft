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
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
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
final class EngineBackend implements Backend, ReportsContextWindow
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
     * Upper bound on a single length-prefixed frame from the child. A frame
     * legitimately carries raw image bytes, so it has to be generous - but a
     * corrupt/truncated header must never make the parent try to buffer an
     * arbitrary length before it notices the stream is garbage.
     */
    private const MAX_FRAME_BYTES = 64 * 1024 * 1024;

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
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self($provider, $model);
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
        return new self($this->provider, $this->model, $tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function withSkills(array $skills): self
    {
        return new self($this->provider, $this->model, $this->tools, $skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
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
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
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
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
    }

    public function withHooks(HookManager $hookManager): self
    {
        // An explicit hook manager always wins and clears any prior opt-out.
        return new self($this->provider, $this->model, $this->tools, $this->skills, $hookManager, $this->maxSteps, false, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
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
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $this->root, $permissionGate, $this->permissionApprover, $this->memoryStore);
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
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $approver, $this->memoryStore);
    }

    /**
     * Escape hatch for callers that deliberately want an UNGUARDED engine —
     * no built-in hooks, no custom manager. Everything else is safe-by-default
     * (see {@see resolveHookManager()}), so opting out is an explicit choice.
     */
    public function withoutHooks(): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, null, $this->maxSteps, true, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
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
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
    }

    /**
     * The memory store whose project-scope notes reach the model's system
     * prompt. @see App::$memoryStore
     */
    public function withMemoryStore(?MemoryStore $memoryStore): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $memoryStore);
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, max(1, $maxSteps), $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
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
     * @see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook
     */
    public function withWorktreeRoot(string $worktreeRoot): self
    {
        if ($this->hooksDisabled) {
            return $this;
        }

        $manager = $this->hookManager ?? new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new BashEscapeDenyHook($worktreeRoot));

        return new self($this->provider, $this->model, $this->tools, $this->skills, $manager, $this->maxSteps, false, $this->skillRegistry, $this->instructionLoader, $this->root, $this->permissionGate, $this->permissionApprover, $this->memoryStore);
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
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null, ?callable $onReasoning = null): Message
    {
        // Read once and hand to both resolvers, so a turn touches the config
        // file at most one time however many settings are resolved off it.
        $userConfig = self::userConfig();

        $runtime = new Runtime(
            $this->provider,
            $this->resolveHookManager(),
            parallelToolCalls: self::parallelToolCallsEnabled($userConfig),
            parallelToolDeadlineSeconds: self::parallelToolDeadlineSeconds($userConfig),
        );

        $app = App::new($this->provider, $this->model)
            ->withTools($this->tools)
            ->withEnabledSkills($this->skills)
            ->withAvailableSkills($this->skillRegistry ?? new SkillRegistry())
            ->withInstructionLoader($this->instructionLoader)
            ->withRoot($this->root)
            ->withMemoryStore($this->memoryStore)
            ->withMessages($this->toTypedMessages($history));

        $lastAssistant = null;
        $lastImageBytes = null;
        $lastImageProtocol = null;
        // EVERY step's usage, not the last one's. This loop makes up to
        // $maxSteps provider calls per turn and each reports its own figure,
        // so the turn's cost is the SUM: a readout fed from $lastAssistant
        // alone would bill a five-tool turn as if it were the one final call
        // that answered without tools (crush_code.md Phase 5 item 7).
        /** @var list<?Usage> $stepUsages */
        $stepUsages = [];

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

            foreach ($runtime->run($app, $onEvent, $this->permissionApprover, $tokenSink, $progressSink) as $message) {
                if ($message instanceof AssistantMessage) {
                    $assistant = $message;
                } elseif ($message instanceof ToolResultMessage) {
                    $toolResults[] = $message;
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

            if ($assistant !== null) {
                $lastAssistant = $assistant;
                $stepUsages[] = $assistant->usage();
            }

            if ($toolResults === []) {
                break; // model answered without calling tools — done
            }

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
        if ($onToken !== null && !$streamed && $content !== '') {
            $onToken($content);
        }

        // Thread the reasoning ReasoningExtractor already split out (§12 D3)
        // across the typed-Message -> root-Message seam instead of dropping
        // it here - it's the last point in this call path that still has
        // access to $lastAssistant before only the plain-string Message DTO
        // survives back to Chat/Renderer. withImage() does the same for an
        // image-bearing tool result (W1.G2 reachability fix).
        return Message::assistant($content, reasoning: $lastAssistant?->reasoning())
            ->withImage($lastImageBytes, $lastImageProtocol)
            ->withUsage(Usage::sum($stepUsages));
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
     * usage figures), and the final result as the last frame. The parent drains
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
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);

            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent, $onReasoning);
        }

        if ($pid === 0) {
            $this->runCompleteInChild($childSocket, $history);
        }

        self::$unreapedChildren[$pid] = true;

        fclose($childSocket);
        stream_set_blocking($parentSocket, false);

        $loop = Loop::get();
        $buffer = '';
        $settled = false;
        $result = null;
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

        // Shared teardown for the failure ways this can end (timeout,
        // cancellation): stop watching the socket, cancel BOTH timers
        // (critical for $cancelTimer, a periodic timer that would otherwise
        // keep polling forever after settling via a different path), kill and
        // reap the child so it never zombies.
        $teardown = function (string $rejectMessage) use (&$settled, $loop, $parentSocket, $pid, $deferred, &$timeoutTimer, &$cancelTimer): void {
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
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            }
            self::reapChild($pid);
            $deferred->reject(new \RuntimeException($rejectMessage));
        };

        // The success path: the child either delivered its result frame or
        // hung up. Same cleanup as $teardown minus the kill (the child is
        // already on its way out), then settle from whatever result frame
        // arrived - a child that died before writing one is still a failure.
        $finalize = function () use (&$settled, &$result, &$streamed, $loop, $parentSocket, $pid, $deferred, $onToken, &$timeoutTimer, &$cancelTimer): void {
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
            self::reapChild($pid);
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

        $loop->addReadStream($parentSocket, function ($stream) use (&$buffer, &$result, &$streamed, $onToken, $onEvent, $onReasoning, $finalize, $resetTimeout): void {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                $finalize();

                return;
            }

            $buffer .= $chunk;
            foreach (self::drainFrames($buffer) as $frame) {
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
                // It deliberately does NOT set $streamed. That latch suppresses
                // the result frame's one-shot re-delivery of the assistant's
                // TEXT, and a turn that thought at length and then answered
                // with a single un-streamed content block still needs that
                // fallback.
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
                static function (ToolStarted|ToolFinished $event) use ($childSocket): void {
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
     * A dead parent (fwrite failing or making no progress) ends the write
     * rather than spinning; the child is about to exit anyway.
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
                return;
            }
            $written += $n;
        }
    }

    /**
     * Pull every COMPLETE frame out of the parent's read buffer, leaving any
     * trailing partial frame in $buffer for the next readable edge - a frame
     * can and does span two reads once a tool result carries image bytes.
     *
     * A frame whose declared length is nonsensical means the stream is no
     * longer parseable, so the buffer is dropped rather than misinterpreted;
     * the turn then fails via the missing-result path instead of resolving
     * with garbage.
     *
     * @return list<array<string, mixed>>
     */
    private static function drainFrames(string &$buffer): array
    {
        $frames = [];

        while (strlen($buffer) >= 4) {
            $header = unpack('N', substr($buffer, 0, 4));
            $length = is_array($header) ? (int) ($header[1] ?? 0) : 0;
            if ($length <= 0 || $length > self::MAX_FRAME_BYTES) {
                $buffer = '';
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
            if (is_array($decoded)) {
                $frames[] = $decoded;
            }
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
     * {@see complete()}'s own fallback.
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
    private static function encodeEvent(ToolStarted|ToolFinished $event): array
    {
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
    private static function decodeEvent(array $encoded): ToolStarted|ToolFinished|null
    {
        $id = is_string($encoded['id'] ?? null) ? $encoded['id'] : null;
        $name = is_string($encoded['name'] ?? null) ? $encoded['name'] : null;
        if ($id === null || $name === null) {
            return null;
        }

        if (($encoded['kind'] ?? null) === 'started') {
            return new ToolStarted($id, $name, is_array($encoded['arguments'] ?? null) ? $encoded['arguments'] : []);
        }

        if (($encoded['kind'] ?? null) !== 'finished') {
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
            $deferred->resolve($this->complete($history, $onToken, $onEvent, $onReasoning));
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
     * the manager in place, exactly as {@see withWorktreeRoot()} already does:
     * {@see HookManager} owns a private registry with no copy constructor, and
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
     * @param array<int, Message> $history
     * @return array<int, TypedMessage>
     */
    private function toTypedMessages(array $history): array
    {
        $out = [];
        foreach ($history as $msg) {
            $out[] = match ($msg->role->value) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
                default => new SystemMessage($msg->content),
            };
        }

        return $out;
    }
}
