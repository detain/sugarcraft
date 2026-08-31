<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Skills\SkillSource;

final readonly class Agent
{
    /**
     * @param ?EnvironmentBlock $environment Session environment snapshot appended to
     *                                       systemPrompt(); null lets systemPrompt()
     *                                       capture one for itself.
     *
     * THE TEN PARAMETERS AFTER `$environment` ARE THE PRESET'S FIELDS, and
     * they are here because {@see fromPreset()} was reading SIX of
     * {@see AgentPreset}'s sixteen and dropping the rest on the floor. A
     * preset is the `.md`+frontmatter file a user or a foreign tool
     * (`.claude/agents/`, `.opencode/agents/`) wrote; every key it declares
     * was declared deliberately, and a bridge that silently keeps six of them
     * is a bridge that makes the other ten look broken rather than unwired.
     *
     * WHAT CARRYING THEM DOES AND DOES NOT BUY, said plainly because the
     * field names imply more than is true today:
     *
     *  - NONE of them changes this process's behaviour yet. `Agent` is what
     *    {@see AgentManager::register()} stores and what
     *    {@see \SugarCraft\Crush\Renderer::agentDisplayState()} renders;
     *    neither reads any of the ten. `$permissionMode`'s downstream
     *    consumer is {@see AgentManager::createSubAgent()}, which takes the
     *    mode as its OWN argument and has no `src/` caller at all — the
     *    delegation path that would call it is not wired.
     *  - What they buy is that the value has somewhere to put them. Before
     *    this, a consumer that wanted a registered agent's `maxTurns` had to
     *    go back to the registry and re-read the preset, which is only
     *    possible for agents that CAME from a preset — the six built-in
     *    {@see AgentDefinition} templates have no preset to go back to.
     *
     * They are appended after `$environment` rather than grouped with the
     * fields they mirror because every construction site in `src/` and
     * `tests/` uses named arguments; appending keeps all of them compiling
     * untouched, which is the whole reason this is a widening and not a
     * refactor.
     *
     * @param list<string>          $disallowedTools Preset tool denylist; see the note above.
     * @param ?int                  $maxTurns        Preset turn cap, null for uncapped.
     * @param array<string, mixed>  $mcpServers      Preset MCP server declarations.
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $prompt,
        public string $model,
        public string $provider,
        public array $tools,
        public array $skillNames,
        public array $hooks,
        public bool $isActive,
        public ?EnvironmentBlock $environment = null,
        public array $disallowedTools = [],
        public PermissionMode $permissionMode = PermissionMode::Default,
        public ?int $maxTurns = null,
        public array $mcpServers = [],
        public MemoryScope $memory = MemoryScope::User,
        public bool $background = false,
        public Effort $effort = Effort::Medium,
        public ?Isolation $isolation = null,
        public ?string $color = null,
        public SkillSource $source = SkillSource::Native,
    ) {}

    /**
     * Rebuild an agent from {@see toArray()}'s output.
     *
     * The ten preset-derived keys are read back through their enums'
     * `tryFrom()` with the constructor default as the fallback, so a frame
     * written by an older build — or hand-edited to something the enum does
     * not know — costs that ONE field its stored value rather than the whole
     * agent. `?? ''` before `tryFrom()` because a `null` would be a
     * TypeError, and a missing key must land on the default, not on a throw.
     *
     * BOTH HALVES OF THIS ROUND TRIP ARE A DORMANT SEAM: neither
     * {@see toArray()} nor `fromArray()` has a caller anywhere in `src/`.
     * They are the documented way to persist an agent and are kept for the
     * roster-restoration path {@see fromDefinition()} describes; they are
     * widened here so that path does not silently lose ten fields the day it
     * is wired.
     *
     * `permissionMode` IS GATED HERE TOO, on the frame's own `source`, for the
     * reason {@see fromPreset()}'s doc-block gives at length. Doing it in both
     * constructors makes "a foreign-sourced `Agent` never carries a raised
     * permission mode" a property of the TYPE rather than of one code path —
     * which matters most for a seam that is dormant, because the day it is
     * wired is the day nobody re-reads this file. A frame written by this
     * process cannot carry the escape (`fromPreset()` forced it to Default
     * before `toArray()` ran); a hand-edited or foreign-written one is the
     * case this refuses.
     */
    public static function fromArray(array $data): self
    {
        $source = SkillSource::tryFrom((string) ($data['source'] ?? '')) ?? SkillSource::Native;

        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            prompt: $data['prompt'] ?? '',
            model: $data['model'] ?? 'claude-sonnet-4-6',
            provider: $data['provider'] ?? 'anthropic',
            tools: $data['tools'] ?? [],
            skillNames: $data['skills'] ?? [],
            hooks: $data['hooks'] ?? [],
            isActive: $data['is_active'] ?? false,
            disallowedTools: $data['disallowed_tools'] ?? [],
            // GATED THE SAME WAY {@see fromPreset()} GATES IT, so the invariant
            // is a property of the TYPE rather than of one constructor. A frame
            // this process wrote cannot carry a raised mode on a foreign source
            // — fromPreset() already forced it to Default before toArray() saw
            // it — so this only bites a frame that was edited by hand or by
            // something else, which is exactly the case worth refusing.
            permissionMode: $source === SkillSource::Native
                ? (PermissionMode::tryFrom((string) ($data['permission_mode'] ?? '')) ?? PermissionMode::Default)
                : PermissionMode::Default,
            maxTurns: isset($data['max_turns']) ? (int) $data['max_turns'] : null,
            mcpServers: $data['mcp_servers'] ?? [],
            memory: MemoryScope::tryFrom((string) ($data['memory'] ?? '')) ?? MemoryScope::User,
            background: $data['background'] ?? false,
            effort: Effort::tryFrom((string) ($data['effort'] ?? '')) ?? Effort::Medium,
            isolation: Isolation::tryFrom((string) ($data['isolation'] ?? '')),
            color: $data['color'] ?? null,
            source: $source,
        );
    }

    /**
     * Build a registrable agent from one of the six built-in
     * {@see AgentDefinition} templates.
     *
     * The definitions carry no provider or model of their own — they are
     * library templates, not a session's configuration — so the run's
     * selected provider/model are supplied by the caller
     * ({@see \SugarCraft\Crush\Cli\Bootstrap::agentRoster()}). Without this
     * bridge the templates were a data model with no way into
     * {@see AgentManager::register()}, which takes an Agent.
     *
     * `isActive` defaults to false: on this class, active means *currently
     * doing work* — {@see \SugarCraft\Crush\Renderer::agentDisplayState()}
     * renders it as the literal string "working" — and a template nobody has
     * delegated to yet is not working. {@see AgentManager::active()} derives
     * the live value from running sub-agents.
     *
     * The `$isActive` PARAMETER is therefore an INTENTIONAL DORMANT SEAM with
     * no production caller, exercised only by {@see
     * \SugarCraft\Crush\Tests\Agents\AgentTest}. It is kept, not removed,
     * because these two factories are the supported way to reach the
     * constructor's full field set, and the one caller that will need it is
     * roster restoration: a session resumed from disk knows which of its
     * agents were mid-flight when it was suspended, and derivation cannot
     * recover that — the sub-agents it would derive from are gone with the
     * process. Do not delete it to satisfy a coverage tool.
     */
    public static function fromDefinition(
        AgentDefinition $definition,
        string $provider,
        string $model,
        bool $isActive = false,
    ): self {
        return new self(
            name: $definition->name,
            description: $definition->description,
            prompt: $definition->prompt,
            model: $model,
            provider: $provider,
            tools: $definition->defaultTools,
            skillNames: $definition->defaultSkills,
            hooks: [],
            isActive: $isActive,
        );
    }

    /**
     * Build a registrable agent from a discovered {@see AgentPreset} — the
     * `.md`+frontmatter files {@see AgentPresetRegistry} reads out of
     * `{root}/.sugar-crush/agents` and `~/.sugar-crush/agents`, and the
     * foreign `.claude/agents`/`.opencode/agents` imports
     * {@see ForeignAgentPresetRegistry} maps onto the same type.
     *
     * `model: 'inherit'` is AgentPreset's documented "use whatever model the
     * session is on" default, so it resolves to $model rather than being
     * passed through as a literal model name a provider would reject.
     *
     * THIS USED TO READ SIX OF THE PRESET'S SIXTEEN FIELDS. `name`,
     * `description`, `initialPrompt`, `model`, `tools` and `skills` came
     * across; `disallowedTools`, `permissionMode`, `maxTurns`, `mcpServers`,
     * `memory`, `background`, `effort`, `isolation`, `color` and `source`
     * did not, and the doc-block here said they "stay on the preset" — true
     * of where the value lived, misleading about what a caller could reach,
     * since {@see AgentManager} stores the Agent and hands back the Agent.
     * All sixteen are copied now, with ONE gated on provenance — see below.
     *
     * `permissionMode` IS GATED ON `$preset->source`, and it is the only
     * carried field that is. The other fifteen describe an agent; this one
     * decides what an agent is allowed to do without asking, so carrying it
     * unconditionally would let `{projectRoot}/.claude/agents/x.md` — a file
     * that arrives with a `git clone` and is read with no `trustedProject*`
     * opt-in ({@see ForeignAgentPresetRegistry}) — declare
     * `permissionMode: bypass-permissions` and have it land on the roster.
     * MEASURED end-to-end through {@see \SugarCraft\Crush\Cli\Bootstrap::agentRoster()}:
     * before the gate a `.claude/agents` preset produced an `Agent` carrying
     * {@see PermissionMode::BypassPermissions}. A non-native preset therefore
     * gets {@see PermissionMode::Default} — the same mode it would have had
     * when this method read six fields — while a NATIVE preset out of
     * `.sugar-crush/agents` keeps the mode its author wrote, because that tier
     * is sugar-crush's own configuration rather than somebody else's.
     *
     * WHY HERE AND NOT AT THE READ SITE: the field has no reader outside this
     * class yet ({@see \SugarCraft\Crush\Tests\Integration\ForeignAgentPresetWiringTest::testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode()}
     * is the census that says so), so a gate at the read site would have to be
     * remembered by a call site that does not exist yet. Gating on
     * construction makes the escape UNREPRESENTABLE on this path rather than
     * merely unread, which is the stronger of the two bounds and the one the
     * six-field version accidentally had.
     *
     * The remaining fifteen change nothing observable today, because no
     * consumer of a registered Agent reads them yet.
     *
     * The prompt is the preset's `initialPrompt`, which
     * {@see AgentPresetRegistry} fills from a declared `initialPrompt:` key or,
     * failing that, from the file's markdown BODY — the convention Claude Code
     * and opencode both write subagent prompts in. `''` remains the value for
     * a preset that genuinely declares no prompt; the agent then carries only
     * its environment block.
     *
     * `$isActive` is the same intentional dormant seam documented on
     * {@see fromDefinition()}.
     */
    public static function fromPreset(
        AgentPreset $preset,
        string $provider,
        string $model,
        bool $isActive = false,
    ): self {
        return new self(
            name: $preset->name,
            description: $preset->description,
            prompt: $preset->initialPrompt ?? '',
            model: $preset->model === 'inherit' || $preset->model === '' ? $model : $preset->model,
            provider: $provider,
            tools: $preset->tools,
            skillNames: $preset->skills,
            hooks: [],
            isActive: $isActive,
            disallowedTools: $preset->disallowedTools,
            // THE ONE FIELD PROVENANCE GATES. See the doc-block: a foreign
            // preset is repository content that arrived with a clone, and its
            // `permissionMode:` is the only carried field that is a privilege
            // decision rather than a description. Gated on the source the
            // preset already carries, one line above the source it is copied
            // from, so the two cannot drift apart.
            permissionMode: $preset->source === SkillSource::Native
                ? $preset->permissionMode
                : PermissionMode::Default,
            maxTurns: $preset->maxTurns,
            mcpServers: $preset->mcpServers,
            memory: $preset->memory,
            background: $preset->background,
            effort: $preset->effort,
            isolation: $preset->isolation,
            color: $preset->color,
            source: $preset->source,
        );
    }

    /**
     * Serialize the agent's persisted configuration.
     *
     * The environment snapshot is deliberately absent: it is per-session
     * runtime state (cwd, git status, date), not agent config, and writing a
     * stale snapshot back into an agent definition file would outlive the
     * session that captured it.
     *
     * The ten preset-derived fields ARE present, in snake_case like their
     * neighbours and with their enums flattened to `->value`, so the array
     * stays JSON-encodable. `isolation` is the one nullable enum, so it is
     * `?->value` and writes `null` rather than a sentinel string — `null` is
     * a distinct state from {@see Isolation::None} on {@see AgentPreset}, and
     * collapsing the two here would make the round trip lossy in the one
     * place it is easiest not to notice.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'prompt' => $this->prompt,
            'model' => $this->model,
            'provider' => $this->provider,
            'tools' => $this->tools,
            'skills' => $this->skillNames,
            'hooks' => $this->hooks,
            'is_active' => $this->isActive,
            'disallowed_tools' => $this->disallowedTools,
            'permission_mode' => $this->permissionMode->value,
            'max_turns' => $this->maxTurns,
            'mcp_servers' => $this->mcpServers,
            'memory' => $this->memory->value,
            'background' => $this->background,
            'effort' => $this->effort->value,
            'isolation' => $this->isolation?->value,
            'color' => $this->color,
            'source' => $this->source->value,
        ];
    }

    public function withName(string $name): self
    {
        return new self(
            name: $name,
            description: $this->description,
            prompt: $this->prompt,
            model: $this->model,
            provider: $this->provider,
            tools: $this->tools,
            skillNames: $this->skillNames,
            hooks: $this->hooks,
            isActive: $this->isActive,
            environment: $this->environment,
            disallowedTools: $this->disallowedTools,
            permissionMode: $this->permissionMode,
            maxTurns: $this->maxTurns,
            mcpServers: $this->mcpServers,
            memory: $this->memory,
            background: $this->background,
            effort: $this->effort,
            isolation: $this->isolation,
            color: $this->color,
            source: $this->source,
        );
    }

    public function withActive(bool $isActive): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            prompt: $this->prompt,
            model: $this->model,
            provider: $this->provider,
            tools: $this->tools,
            skillNames: $this->skillNames,
            hooks: $this->hooks,
            isActive: $isActive,
            environment: $this->environment,
            disallowedTools: $this->disallowedTools,
            permissionMode: $this->permissionMode,
            maxTurns: $this->maxTurns,
            mcpServers: $this->mcpServers,
            memory: $this->memory,
            background: $this->background,
            effort: $this->effort,
            isolation: $this->isolation,
            color: $this->color,
            source: $this->source,
        );
    }

    /**
     * Attach the session's environment snapshot so systemPrompt() reuses it
     * instead of capturing (and re-shelling to git for) its own.
     */
    public function withEnvironment(?EnvironmentBlock $environment): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            prompt: $this->prompt,
            model: $this->model,
            provider: $this->provider,
            tools: $this->tools,
            skillNames: $this->skillNames,
            hooks: $this->hooks,
            isActive: $this->isActive,
            environment: $environment,
            disallowedTools: $this->disallowedTools,
            permissionMode: $this->permissionMode,
            maxTurns: $this->maxTurns,
            mcpServers: $this->mcpServers,
            memory: $this->memory,
            background: $this->background,
            effort: $this->effort,
            isolation: $this->isolation,
            color: $this->color,
            source: $this->source,
        );
    }

    /**
     * Build the system prompt for this agent.
     *
     * A subagent needs the same environment orientation the primary thread
     * gets from Runtime::buildSystemPrompt() — cwd, git state, platform,
     * model, date. Without it a subagent asked to "fix the failing test"
     * has no idea which directory or branch it is standing in.
     *
     * Resolution order is caller-supplied block, then the block attached to
     * this agent, then a freshly captured one. The final fallback is what
     * keeps this reachable: every existing caller (AgentManager,
     * ProcessExecutor, WorkflowEngine) passes no argument, so the block
     * reaches real subagent prompts without those call sites changing.
     * Callers holding a session snapshot should pass it, since capture()
     * shells out to git.
     *
     * P3.S6 - WHY THE PER-STEP WRITE SIGNAL IS NOT WIRED INTO THIS ASSEMBLER,
     * recorded here rather than in a plan document because this is the API the
     * question is about and this is where the next reader lands.
     *
     * P3.S5 wired {@see EnvironmentBlock::withWriteSinceLastRender()} into
     * {@see \SugarCraft\Crush\Runtime}'s assembler, which suppresses the two git
     * diff sections on a step that wrote nothing. It reached ONE of
     * `EnvironmentBlock`'s four production construction sites; the other three
     * feed this method, the second assembler prompt_plan.md section 17.2 keeps
     * deliberately separate because the two order `<env>` oppositely. The gap
     * was left open on purpose, to be closed or explained rather than to lapse.
     *
     * IT IS EXPLAINED, AND THE EXPLANATION IS A MEASUREMENT: THERE IS NO
     * PER-STEP SEAM ON THIS PATH TO WIRE. The suppression is defined relative
     * to the step BEFORE it - it withholds a diff the model was already shown
     * earlier in the same conversation - so it needs a caller that renders this
     * prompt more than once for one conversation. No caller does. All EIGHT
     * production call sites are once-per-dispatch, derived and pinned by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testEveryProductionCallSiteOfTheAgentAssemblerIsDerivedAndAccountedFor()}:
     * {@see AgentManager::executeSubAgent()} (one), `App::dispatchSkill()`
     * (one), {@see ProcessExecutor::spawnWorker()} (one) and five in
     * `WorkflowEngine`. Each builds one `CompleteRequest` and hands it to one
     * completion; there is no agentic loop, and the transient-failure retry
     * inside `executeSubAgent()` re-sends the SAME request object rather than
     * rebuilding it. Driven rather than read off, at one streamed chunk and at
     * twenty, by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testASubAgentDispatchRendersTheEnvironmentBlockOnceHoweverManyChunksTheProviderStreams()}.
     *
     * A NUMBER IN THE OTHER DIRECTION, so this is not read as "the second
     * assembler is cheap". It is the expensive one per call.
     * {@see EnvironmentBlock::render()} is not memoised here the way
     * {@see \SugarCraft\Crush\Runtime}'s snapshot is, so the git shell-out is paid
     * on every call: MEASURED with a logging `git` shim on `PATH`, ZERO
     * subprocesses for `EnvironmentBlock::capture()`, FIVE per render, THREE
     * when the write signal is suppressed, and FIFTEEN for three calls on one
     * agent. All four are asserted by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testTheAgentAssemblerCostsFiveGitSubprocessesPerRenderAndThreeWithTheDiffSuppressed()}.
     *
     * AND ONE DISPATCH RENDERS TWICE, WHICH IS A FINDING AND NOT A FIX. Every
     * caller that goes through the worker pool builds its `CompleteRequest`
     * with this method AND then {@see ProcessExecutor::spawnWorker()} calls it
     * again for the worker's startup message - TEN subprocesses for one
     * dispatch, two unmemoised renders of a live git section that can disagree
     * with each other. `App::dispatchSkill()`'s own comment says the two
     * consumers "must agree"; nothing makes them. Pinned by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testOneDispatchThroughTheProcessExecutorRendersTheAgentPromptTwice()}
     * and escalated rather than repaired: dropping either call site is the
     * removal prompt_plan.md section 1.10 prohibits, and `ProcessExecutor.php`
     * is outside P3.S6's declared file list.
     */
    public function systemPrompt(?EnvironmentBlock $environment = null): string
    {
        $rendered = ($environment ?? $this->environment ?? EnvironmentBlock::capture((string) getcwd(), $this->model))
            ->render();

        return $this->prompt === '' ? $rendered : $this->prompt . "\n\n" . $rendered;
    }
}
