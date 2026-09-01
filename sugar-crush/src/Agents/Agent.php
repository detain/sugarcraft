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
     * @param ?string               $environmentRoot The directory
     *        {@see systemPrompt()}'s last-resort capture is anchored at — the
     *        session's resolved project root (`--root`'s value), or null to
     *        fall back to the process directory exactly as before.
     *
     * WHY A ROOT AND NOT A BLOCK. The block itself is per-session state and
     * belongs attached ({@see withEnvironment()}), which is what
     * `Bootstrap::agentManager()` does for every REGISTERED agent; the fresh
     * per-stage agents a {@see \SugarCraft\Crush\Workflows\WorkflowEngine}
     * run builds carry no block precisely so each stage re-renders, and a
     * block passed down that path would change that measured cost. The
     * DIRECTORY, though, is not per-stage state: on a `--root <dir>` run
     * whose process started elsewhere it is fixed for the session, and the
     * last-resort capture used to read the process directory — the same
     * mismatch between the prompt's orientation and the tools' jail that
     * {@see Runtime} closes at its own capture site. This parameter is that
     * one seam carried to the second assembler: same-file plumbing of the
     * root `Bootstrap::chat()` already resolved, never a second resolver. It
     * is absent from `fromArray()`, `fromDefinition()`, `fromPreset()` and
     * `toArray()` for the reason the block is: per-session, not agent config.
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
        public ?string $environmentRoot = null,
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
            environmentRoot: $this->environmentRoot,
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
            environmentRoot: $this->environmentRoot,
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
            environmentRoot: $this->environmentRoot,
        );
    }

    /**
     * Set the directory the last-resort environment capture anchors at — the
     * session's resolved project root. Per-session plumbing like the block
     * itself, which is why `toArray()` does not carry it; pinned in
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testTheEnvironmentRootSurvivesTheWitherRebuilds()}.
     */
    public function withEnvironmentRoot(?string $environmentRoot): self
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
            environmentRoot: $environmentRoot,
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
     * READ THE LINE NUMBERS BELOW AS DIRECTIONS, NOT AS FACTS UNDER TEST. This
     * doc-block carries 31 distinct citations of the form file-dot-php-colon-line
     * in 54 occurrences. TWO figures need TWO commands, because one pipeline
     * cannot produce both - the second is the first without its `sort -u`:
     *
     *   distinct:
     *     /usr/bin/grep -oP '[A-Za-z/]+[.]php:[0-9]+(-[0-9]+)?' src/Agents/Agent.php | sort -u | wc -l
     *   occurrences:
     *     /usr/bin/grep -oP '[A-Za-z/]+[.]php:[0-9]+(-[0-9]+)?' src/Agents/Agent.php | wc -l
     *
     * WHAT THIS SAID: "THIRTY distinct ... in FORTY-SIX occurrences", with only
     * the `sort -u` pipeline attached - so the second figure had no generator at
     * all, which is the defect the paragraph is about happening inside the
     * paragraph.
     * WHAT IS TRUE: 31 and 54.
     * HOW MEASURED: both commands above, run from `sugar-crush/`, at
     * `f958ba8e6` - the merge that WROTE the wrong pair - and again at
     * `bb4a311d0`. Identical at both, 31 / 54. So this is not later drift:
     * both figures were wrong the day they were typed, and nothing went red.
     * WHY THE SENTENCE STILL EARNS ITS PLACE: the count IS the argument - it is
     * the measure of how much of this doc-block is unpinned - and it is no
     * longer prose. Section 16.8 rule 2 says ship the generator, not the count;
     * both generators are above, and
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testTheCitationCensusInThisDocBlockIsDerivedFromTheFileRatherThanWrittenDown()}
     * re-derives both figures from this file, reads this sentence's own two
     * numbers back out of it, and reds naming the new pair. One added citation
     * now costs two two-digit edits here instead of rotting silently.
     * ITS DOMAIN, spelled out because this sentence greps the file it lives in:
     * both commands count the WHOLE of `src/Agents/Agent.php`, and every
     * occurrence is INSIDE this one doc-block - which that test asserts as
     * well, so the day a citation lands outside it the domain claim reds
     * instead of drifting. This file's own name appears above without a
     * colon-line suffix and therefore matches neither pipeline; before this
     * correction that was luck, and now it is checked. `src/Runtime.php` spends
     * twenty lines making exactly this correction for a different grep, in this
     * same phase, and it did not travel here at the time.
     *
     * NOT ONE of the line numbers below is pinned by anything, which is the same argument
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::AGENT_ASSEMBLER_CALL_SITES}
     * makes for keying its roster on the FILE and not on `file:line`. They are
     * given anyway because the argument needs the reader to be able to find the
     * code, and prose that says "somewhere in WorkflowEngine" is not evidence.
     * They will rot: `Runtime.php`'s own citation of this file's
     * `EnvironmentBlock::capture(` call was correct at the base of this branch
     * and stale by its tip, one commit later, without anything going red. So
     * when a number here misses, re-derive it - the claims that are actually
     * under test are the fixture-INDEPENDENT ones, and every one of them is a
     * named test rather than a line number: the subprocess counts, the
     * distinct-prompt count, the roster BY FILE, and
     * `AgentResult::__construct`'s parameter list.
     *
     * P3.S5 wired {@see EnvironmentBlock::withWriteSinceLastRender()} into
     * {@see \SugarCraft\Crush\Runtime}'s assembler, which suppresses the two git
     * diff sections on a step that wrote nothing. It reached ONE of
     * `EnvironmentBlock`'s four production construction sites; the other three
     * feed this method, the second assembler prompt_plan.md section 17.2 keeps
     * deliberately separate. The gap
     * was left open on purpose, to be closed or explained rather than to lapse.
     *
     * WHY §17.2 KEEPS THEM SEPARATE - CORRECTED IN PLACE (section 16.8 rule
     * 42), because the reason this sentence used to give is FALSE and it was
     * false before this doc-block was written.
     *
     * WHAT IT SAID: "…because the two order `<env>` oppositely."
     *
     * WHAT IS TRUE: both assemblers put the env block LAST, so their orders are
     * IDENTICAL, not opposite. The whole body of {@see systemPrompt()} below is
     * one ternary that returns the rendered block, or this agent's prompt
     * followed by it - nothing follows the render. `Runtime::buildSystemPrompt()`
     * appends its own `environmentSnapshot()->render()` as the last statement
     * before its `return`, under a comment reading "Volatile content LAST".
     *
     * HOW MEASURED: read both method bodies end to end; both fixtures under
     * `tests/fixtures/prompt/` end with the closing env fence and no trailing
     * newline (`tail -c 30 <fixture> | cat -A`); and
     * {@see \SugarCraft\Crush\Tests\RuntimeTest::testBothPromptAssemblersPutTheEnvironmentBlockLastAndAgreeOnTheTail()}
     * now derives it from the two assemblers rather than from the fixtures, so
     * the corrected claim is load-bearing instead of decorative. P3.S1 is the
     * step that made the old reason false, by moving the env block from
     * `Runtime`'s layer 2 to its layer 7; P3.S6 then copied the dead reason
     * into this file.
     *
     * WHY THE CONCLUSION SURVIVES ITS ARGUMENT, and this is the part worth
     * keeping: the two assemblers have DIFFERENT LIFETIMES - `Runtime` memoises
     * one block per turn and replaces it only when the write signal differs,
     * while this method captures a fresh block on every call when none is
     * passed - and the `Runtime` assembler carries FIVE layers this one has
     * none of: a repo map, the project-instruction documents, the memory
     * block, the enabled skills' bodies, and the discovered-skill listing.
     * Those are what unification would have to reconcile. Only §17.2's argument
     * died; its answer did not.
     *
     * IT IS EXPLAINED, AND THE EXPLANATION IS A MEASUREMENT - CORRECTED IN
     * PLACE (prompt_plan.md section 16.8 rule 42), because the first revision
     * of this paragraph was FALSE and it was the step's headline claim.
     *
     * WHAT IT USED TO SAY: that the suppression "needs a caller that renders
     * this prompt more than once for one conversation. No caller does" - i.e.
     * that THERE IS NO PER-STEP SEAM ON THIS PATH AT ALL, every one of the
     * eight call sites being once-per-dispatch.
     *
     * WHAT IS TRUE NOW: there IS a repeated-render seam, and it is in
     * `Workflows/WorkflowEngine.php`. Re-derived over this tree rather than
     * reasoned about:
     *
     *   - `WorkflowEngine.php:1126` `foreach ($nestedStages as $nestedStage)`
     *     encloses the render at `:1174` - ONE RENDER PER NESTED PIPELINE
     *     STAGE.
     *   - `WorkflowEngine::executeVerificationStage()` renders TWICE,
     *     straight-line, at `:1275` (the task agent) and `:1318` (the
     *     verifier), the verifier after the task's sub-agent has already run.
     *   - `WorkflowEngine.php:895`
     *     `foreach ($workflow->stages as $stageIndex => $stage)` reaches
     *     `:1063`/`:1275`/`:1318`/`:1422` once per stage.
     *   - And this is the LIVE half, unlike the two dormant sites in the roster
     *     below: `Bootstrap.php:1183` `workflowEngine()` is wired at
     *     `Bootstrap.php:1058`, the same construction point as `agentManager:`
     *     at `Bootstrap.php:1044`, so it is reachable from `bin/sugarcrush`.
     *
     * MEASURED with a logging `git` shim on `PATH`, driving the nested-pipeline
     * shape (a fresh `Agent` per stage, `environment` left null, the process
     * directory inside the GENERATED fixture repository under
     * `vendor/prompt-fixture/`): TWO stages cost TEN git
     * subprocesses and FIVE stages cost TWENTY-FIVE, and in both cases the
     * stages see ONE DISTINCT PROMPT - every render byte-identical, and the two
     * git-diff sections re-sent unchanged on every stage. Driven by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testTheWorkflowShapedPipelineReRendersTheSameEnvironmentBlockOncePerStageAndNothingCanTellItNotTo()},
     * which DERIVES the size of the repeated diff tail rather than quoting one,
     * because that size is a property of the repository being rendered and not
     * of this method: on that generated fixture it is 498 of the render's 967
     * bytes, and a brief that reached this step quoted 405 of 864 from a
     * different fixture. GENERATED, NOT COMMITTED, and two earlier sentences in
     * this doc-block called it committed: `git check-ignore -v
     * sugar-crush/vendor/prompt-fixture/agent-repo` answers with the root
     * ignore rule for `vendor/` at `.gitignore:6`, and
     * `AgentTest::ensureFixtureRepo()` rebuilds the repository from scratch on
     * any run that finds no `.git` under it. The word matters exactly here,
     * because 498 and 967 are numbers taken OFF that repository: "committed"
     * would have promised a reader they could reproduce them by inspecting
     * checked-in bytes, when the only way to reproduce them is to run the
     * suite that builds the repository first. The subprocess counts and the distinct-prompt count
     * are the fixture-independent half, and they are the half that matters.
     *
     * AND THE CALLER IS DRIVEN, NOT ONLY THE SHAPE. That test hand-rolls the
     * per-stage loop, so it pins the assembler and never reaches
     * `WorkflowEngine`; a review proved the gap by applying the hoisted-shared-
     * block wiring described under reason 2 below and watching the whole file
     * stay green. {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testARealWorkflowEnginePipelineRendersTheAgentAssemblerOncePerStage()}
     * closes it: it registers a K-stage pipeline on a real {@see \SugarCraft\Crush\Workflows\WorkflowEngine}
     * with a mock {@see ExecutorInterface} - in-process, nothing spawned, the
     * parent-side render at `:1174` untouched - and asserts `5 * K` git
     * subprocesses at K = 2 and K = 4. MEASURED under that same hoisted-shared-
     * block mutation: 6 against an expected 10, red.
     *
     * WHY THE DISPOSITION STILL STANDS. The seam is real and it is still not
     * wireable here, and reason 1 - declared scope - is the one the
     * disposition rests on. Reason 2 is a narrower fact than an earlier
     * revision of this paragraph claimed, and the correction is recorded in
     * place (prompt_plan.md section 16.8 rule 42) rather than overwritten,
     * because "decisive" was the word a future reader would have cited.
     *
     *   1. All five repeated-render sites are in
     *      `Workflows/WorkflowEngine.php`, which is not one of P3.S6's four
     *      declared files. This is the whole of the disposition.
     *   2. THE SIGNAL CANNOT BE DERIVED FROM WHAT A STAGE DID - only from what
     *      its worker is structurally incapable of doing.
     *
     *      WHAT THIS USED TO SAY: that "'Did this stage write?' is
     *      UNANSWERABLE on this path today", and that this was decisive.
     *
     *      WHAT IS TRUE NOW: it is answerable, and the answer is a derivable
     *      CONSTANT. A workflow stage's worker cannot execute a tool at all.
     *      `ProcessExecutor`'s live worker script builds its request with
     *      `tools: null` - "Deliberately null: the parent sends tool NAMES,
     *      not tools", `ProcessExecutor.php:983-985` - and its body
     *      (`ProcessExecutor.php:1003-1034`) is a single
     *      `completeStream()`/`complete()` with NO tool-execution loop after
     *      it. So for stages 2..N of one run the answer is a constant `false`,
     *      not an unknown, and a review demonstrated exactly that: one hoisted
     *      `EnvironmentBlock::capture(...)->withWriteSinceLastRender(false)`
     *      above `WorkflowEngine.php:1126`, passed into the render at `:1174`,
     *      green.
     *
     *      WHY THE DISPOSITION SURVIVES IT ANYWAY: that hoisted line is an
     *      edit to `WorkflowEngine.php`, which reason 1 already puts outside
     *      this step. And it would derive the signal from the WORKER'S
     *      STRUCTURE rather than from what the stage actually DID - correct
     *      only for as long as the worker has no tool loop, and silently wrong
     *      the day it gains one. Deriving it from what the stage did is what
     *      the `AgentResult`/IPC-frame gap blocks: a stage's answer comes back
     *      as an {@see AgentResult}, whose constructor
     *      (`src/Agents/AgentResult.php:15-23`) is exactly `agentId`,
     *      `status`, `output`, `error`, `tokensUsed`, `costUsd`, `startedAt`,
     *      `completedAt` - NO TOOL CALLS - and the worker's own `complete`
     *      frame (`ProcessExecutor.php:1037-1042`) carries `output`,
     *      `tokensUsed` and `costUsd` and nothing else. Wiring THAT is a
     *      BUILD-IT-OUT across `WorkflowEngine` + `AgentResult` + the worker
     *      IPC frame, not a one-line mark. It is pinned as a decision by the
     *      same test, which asserts `AgentResult::__construct`'s parameter
     *      list exactly, so the day a tool-call field appears it reds and says
     *      this seam has become wireable from evidence rather than from
     *      structure.
     *
     * So this is escalated to the orchestrator as a declared-scope event - not
     * silently widened, and not silently dropped.
     *
     * THE ROSTER, AND ITS REACHABILITY, WHICH THE FIRST REVISION DID NOT STATE.
     * EIGHT call sites, derived and pinned by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testEveryProductionCallSiteOfTheAgentAssemblerIsDerivedAndAccountedFor()};
     * re-derived here with `/usr/bin/grep -rn -- '->systemPrompt(' src/ bin/`,
     * SIX are production-reachable and TWO are dormant:
     *
     *   - LIVE: the five in `WorkflowEngine` named above, and
     *     {@see ProcessExecutor::spawnWorker()} at `ProcessExecutor.php:473`,
     *     which those five reach through `AgentWorkerPool::executeOne()`.
     *   - DORMANT, TWO OF THEM: {@see AgentManager::executeSubAgent()} at
     *     `AgentManager.php:433`, which `Renderer.php:164-166` and
     *     `AgentDefinition.php:137` already record as callerless; and
     *     `App::dispatchSkill()` at `App/App.php:569`, whose own comment at
     *     `App.php:533` says "Nothing calls dispatchSkill() in production
     *     yet".
     *
     *     RE-DERIVE THE FIRST WITH THE EXCLUSION, WHICH IS LOAD-BEARING:
     *     `/usr/bin/grep -rn --exclude=Agent.php -- '->executeSubAgent(' src/
     *     bin/` prints nothing and exits 1. Drop `--exclude=Agent.php` and it
     *     exits 0 with TWO hits, both of them inside THIS DOC-BLOCK - the
     *     wrapped command you are reading now, and the transcript that spells
     *     it out again a few paragraphs below. The count is not a coincidence
     *     and does not need a line number to check: it is exactly the number
     *     of times this block writes the search string out, so it moves only
     *     if an editor adds or removes a spelling, and never on line drift.
     *     (An earlier revision said "exactly ONE hit ... THIS DOC-BLOCK'S OWN
     *     LINE" and was wrong by one because the block had grown a second
     *     spelling since; the revision before THAT wrote the command without
     *     the exclusion at all and asserted it "finds nothing", so the command
     *     as printed falsified its own stated result the moment anybody ran
     *     it. Both are the same defect: prose about a command, not re-derived
     *     after the prose around it changed.) The exclusion is named here
     *     rather than quietly dropped so that what is written is what
     *     reproduces.
     *
     * AND THE STEP TEXT SAYS OTHERWISE - recorded here because nothing else in
     * this diff can record it. prompt_plan.md's P3.S6 Goal names
     * `AgentManager.php:433` as the terminus of a LIVE production path. It is
     * not one, and the command above is the whole of the argument. MEASURED
     * from `sugar-crush/`:
     *
     *     $ /usr/bin/grep -rn --exclude=Agent.php -- '->executeSubAgent(' src/ bin/
     *     $ echo $?
     *     1
     *
     * No output, exit 1. `executeSubAgent()` has no caller anywhere in `src/`
     * or `bin/`, so the Goal's path stops one hop short of the line it names,
     * and a step briefed to classify a LIVE seam was pointed at a dormant one.
     *
     * THE PATH THAT IS ACTUALLY LIVE, every hop re-derived over this tree
     * rather than reasoned about: `bin/sugarcrush:423` builds
     * `Bootstrap::app($args->root)`; `Bootstrap::app()` calls
     * `Bootstrap::chat()` at `Bootstrap.php:1888` (`chat()` itself at
     * `Bootstrap.php:838`); `chat()` hands the `Chat` its engine through the
     * `workflowEngine:` argument at `Bootstrap.php:1058`, built by
     * `Bootstrap::workflowEngine()` at `Bootstrap.php:1183`;
     * `Chat::workflowRun()` (`src/Chat.php:7820`) calls
     * `$engine->run($workflowName, $context)` at `Chat.php:7844`, inside the
     * `\Fiber` opened at `Chat.php:7842`; and `WorkflowEngine::run()`
     * (`WorkflowEngine.php:368`) reaches this assembler at
     * `WorkflowEngine.php:1063`, `:1174`, `:1275`, `:1318` and `:1422`.
     *
     * That is why every measurement in this doc-block is taken against
     * `WorkflowEngine` and not against `AgentManager`. `prompt_plan.md` is
     * outside P3.S6's declared file list, so the step text cannot be corrected
     * from here; the correction is recorded at the API the claim is about, and
     * escalated to the orchestrator with the rest.
     *
     * (A review of this step wrote "five reachable, three dormant". That sums
     * to eight but splits it wrongly: `ProcessExecutor.php:473` is reached from
     * every WorkflowEngine stage. Six and two are the derived numbers.)
     *
     * WHICH OF THE EIGHT ARE DRIVEN BY A TEST, AND WHICH ARE CLASSIFIED BY
     * READING. This is stated plainly because the classification is the whole
     * deliverable and a reader is entitled to know how much of it is executed.
     * FOUR ARE DRIVEN:
     *
     *   - `AgentManager.php:433`, by
     *     {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testASubAgentDispatchRendersTheEnvironmentBlockOnceHoweverManyChunksTheProviderStreams()};
     *   - `ProcessExecutor.php:473`, by
     *     {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testOneDispatchThroughTheProcessExecutorRendersTheAgentPromptTwice()};
     *   - `WorkflowEngine.php:1174`, the nested-pipeline render, by
     *     {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testARealWorkflowEnginePipelineRendersTheAgentAssemblerOncePerStage()};
     *   - `WorkflowEngine.php:1063`, the plain sequential-stage render reached
     *     from the outer `foreach` at `WorkflowEngine.php:895`, by
     *     {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testARealWorkflowEngineSequentialStageChainRendersTheAgentAssemblerOncePerStage()}.
     *
     * FOUR ARE CLASSIFIED BY READING ONLY - no test enters them, and a wiring
     * applied at any of them would not red anything in this diff:
     * `App/App.php:569`, and `WorkflowEngine.php:1275`, `:1318` and `:1422`
     * (the verification stage's straight-line pair, and the parallel stage's
     * single `$firstAgent->systemPrompt()`). The two per-step seams this
     * doc-block calls out by name are now BOTH driven - the `:1126` loop and
     * the `:895` loop - but `executeVerificationStage()`'s double render is
     * not, and neither is the dormant `App::dispatchSkill()`. Do not read the
     * roster as four-driven-implies-eight-covered.
     *
     * Taken ONE AT A TIME the eight are still once-per-dispatch: each builds
     * one `CompleteRequest` and hands it to one completion, there is no agentic
     * loop inside a dispatch, and the transient-failure retry inside
     * `executeSubAgent()` re-sends the SAME request object rather than
     * rebuilding it. Driven at one streamed chunk and at twenty by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testASubAgentDispatchRendersTheEnvironmentBlockOnceHoweverManyChunksTheProviderStreams()},
     * which drives the DORMANT `executeSubAgent()` path and measures what its
     * name has to be read carefully to mean: renders reaching ONE
     * `CompleteRequest`, not renders per workflow run. It is retained because
     * that per-dispatch property is real and worth pinning; the per-run
     * property is the new test's subject.
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
     * AND THAT COST LANDS ON THE EVENT LOOP, NOT ONLY ON THE WIRE. Recorded as
     * an OBSERVATION: it is pre-existing, this step did not cause it and is not
     * fixing it, and it is here only because every other number in this
     * doc-block is a count of BYTES or of SUBPROCESSES and a reader could
     * finish it believing the repeated render is merely wasteful. It is also
     * BLOCKING. `Chat::workflowRun()` (`src/Chat.php:7820`) runs
     * `$engine->run(...)` (`Chat.php:7844`) inside a `\Fiber` opened at
     * `Chat.php:7842` - on the MAIN loop, not in a child.
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool} does suspend that fiber
     * while it waits on children (`\Fiber::suspend()`,
     * `AgentWorkerPool.php:869`), which is what keeps the TUI alive across the
     * model call - but the PARENT-side render is not inside that wait. At
     * `WorkflowEngine.php:1174` (and at `:1063`, `:1275`, `:1318`, `:1422`)
     * `$agent->systemPrompt()` is evaluated as an argument to the
     * `CompleteRequest` constructor, i.e. fully synchronously, BEFORE
     * `$this->pool->executeOne()` is entered and therefore before any suspend -
     * and {@see EnvironmentBlock::render()}'s five git calls are blocking
     * `proc_open`/`shell_exec`, four and one respectively. `EnvironmentBlock`'s
     * own class doc-block measures a render at **399 ms** on a large working
     * tree (`EnvironmentBlock.php:54`; 373 of those milliseconds inside
     * `git diff`, and it explicitly refuses to call any millisecond figure a
     * ceiling). So a K-stage workflow can stall the event loop for roughly K
     * times that, in K separate un-suspendable chunks, on a repository big
     * enough. Not fixed here, and not fixable here: every one of the five
     * render sites is in `Workflows/WorkflowEngine.php`, which reason 1 above
     * already puts outside P3.S6's declared file list.
     *
     * AND ONE DISPATCH RENDERS TWICE, WHICH IS A FINDING AND NOT A FIX.
     * `App::dispatchSkill()` and FOUR of the five `WorkflowEngine` sites
     * (`:1063`, `:1174`, `:1275`, `:1318`) build their `CompleteRequest` with
     * this method AND then {@see ProcessExecutor::spawnWorker()} calls it again
     * for the worker's startup message - TEN subprocesses for one dispatch, two
     * unmemoised renders of a live git section that can disagree with each
     * other. NOT "every caller that goes through the worker pool", which is
     * what an earlier revision of this sentence said: `Chat::executeAgents()`
     * (`src/Chat.php:5124`) also goes through the pool and is not one of the
     * eight call sites at all. The PARALLEL stage at `:1422` is a third shape
     * again - `$firstAgent->systemPrompt()` builds one `$defaultRequest`, the
     * per-task agents built in the `foreach` below it call nothing, and
     * `AgentWorkerPool::executeAll()` copies `$request->systemPrompt` onto each
     * per-agent request - so N parallel tasks cost N+1 renders, not 2N.
     * `App::dispatchSkill()`'s own comment says the two consumers "must agree";
     * nothing makes them. Pinned by
     * {@see \SugarCraft\Crush\Tests\Agents\AgentTest::testOneDispatchThroughTheProcessExecutorRendersTheAgentPromptTwice()}
     * and escalated rather than repaired: dropping either call site is the
     * removal prompt_plan.md section 1.10 prohibits, and `ProcessExecutor.php`
     * is outside P3.S6's declared file list.
     *
     * ONE PROPOSED WIRING FOR IT, MEASURED AND DECLINED - AND THE MEASUREMENT
     * IS CORRECTED IN PLACE (prompt_plan.md section 16.8 rule 42), because the
     * first revision quoted a number for a shape `App/App.php` cannot express.
     *
     * WHAT IT USED TO SAY: that handing the `SubAgent` in
     * `App::dispatchSkill()` an agent whose block has
     * `withWriteSinceLastRender(false)` drops the dispatch from TEN
     * subprocesses to EIGHT, and that this is declined because it makes the
     * two consumers "disagree MORE".
     *
     * WHAT IS TRUE NOW. TEN-to-EIGHT is the ASYMMETRIC edit: the
     * `CompleteRequest` built from an unmarked block (five) and the worker's
     * second render from a marked one (three). `App/App.php` has no attachment
     * point that produces it. There is exactly ONE, at `App.php:552-554`
     * (`$agent = $agent->withEnvironment(EnvironmentBlock::capture(...))`),
     * and BOTH consumers - the request's `systemPrompt` at `App.php:569` and
     * `ProcessExecutor::spawnWorker()`'s second render at
     * `ProcessExecutor.php:473` - read that one block. So the edit `App.php`
     * actually admits is SYMMETRIC. MEASURED with the same logging-`git`-shim
     * technique against `ProcessExecutor(simulatedWorker: true)`, on the same
     * generated fixture repository, all three shapes side by side:
     *
     *     DEFAULT, one unmarked block feeding both     => 10 subprocesses; consumers agree
     *     ASYMMETRIC, only the SubAgent's agent marked =>  8 subprocesses; consumers DISAGREE
     *     SYMMETRIC, the single App.php:552 attachment =>  6 subprocesses; consumers agree
     *
     * The symmetric edit is 10 -> 6, and the two consumers still render
     * BYTE-FOR-BYTE identical prompts. So the "disagree MORE" reasoning is
     * dropped rather than quietly kept: it is a true statement about the
     * asymmetric shape and it does not apply to the shape `App.php` offers.
     *
     * WHY THE DISPOSITION STILL STANDS, on the ground that survives the
     * correction. `App::dispatchSkill()` has NO per-step seam - it is one of
     * the once-per-dispatch eight, with no loop around its render - so marking
     * its block `withWriteSinceLastRender(false)` is not a suppression keyed on
     * a step that wrote nothing; it is an UNCONDITIONAL suppression of the two
     * git diff sections on every dispatch this path ever makes. That is a
     * change to the DEFAULT behaviour, which the step text forbids in terms:
     * `writeSinceLastRender` defaults to `true`, and a diff that moves the
     * golden has changed default behaviour and is wrong. It is also a
     * suppression keyed on nothing in the sense reason 2 establishes: the write
     * signal it would spend is one this path cannot derive. And
     * `dispatchSkill()` is dormant besides - `App.php:533` says so in its own
     * words - so the six-versus-ten saving is a saving on a path nothing runs.
     *
     * AND THE SECOND PROPOSED WIRING, IN THE OTHER DECLARED FILE - ALSO
     * MEASURED, ALSO DECLINED. An earlier revision of this block dispositioned
     * `App/App.php` and said nothing at all about `Cli/Bootstrap.php`, which
     * left the reader to assume no attachment point was there. One is.
     * `/usr/bin/grep -rn 'withEnvironment(' src/ bin/` finds exactly TWO
     * production attachment points - `Bootstrap.php:1463`
     * (`$manager->register($agent->withEnvironment(EnvironmentBlock::capture($root, $agent->model)))`,
     * inside `Bootstrap::agentManager()`'s roster loop) and `App.php:552`
     * treated above; every other hit is this file's own, the setter's
     * declaration and this doc-block's quotations of the two.
     *
     * IT IS LIVE AS A CONSTRUCTION POINT, which is why it needs an argued
     * answer rather than a shrug: `Bootstrap::agentManager()` is reached from
     * `bin/sugarcrush` through the `agentManager:` argument at
     * `Bootstrap.php:1044`, the same construction call `chat()` takes its
     * engine from. It is also, mechanically, the ONLY place a registered
     * agent's block is built, so it is the only place a mark could be applied
     * to one.
     *
     * IT IS NOT A VIABLE HOME FOR THE WRITE SIGNAL, and the two reasons are
     * measured rather than asserted.
     *
     *   FIRST, IT REACHES NO LIVE RENDER.
     *   `/usr/bin/grep -c 'environment:' src/Workflows/WorkflowEngine.php`
     *   answers ZERO against `/usr/bin/grep -c 'new Agent(' ...` SEVEN: every
     *   one of the five per-stage renders builds a fresh {@see Agent} and
     *   passes no block at all, so each falls through to the last-resort
     *   capture in the method below. WHAT THAT CAPTURE ANCHORS AT MOVED ONCE
     *   SINCE THIS PARAGRAPH WAS WRITTEN, and the three-part form because the
     *   signal disposition above is unchanged by it: it used to read
     *   `EnvironmentBlock::capture((string) getcwd(), ...)`; it now reads
     *   `EnvironmentBlock::capture($this->environmentRoot ?? (string) getcwd(), ...)`,
     *   because P3.audit-fix-3 F5 closed the directory half of the two-assembler
     *   seam - a `--root <dir>` run whose process started elsewhere was told the
     *   process directory here while `Runtime` told it the session root, exactly
     *   the orienting-line mismatch that capture site exists to prevent. The
     *   write-signal argument above is about attaching a BLOCK and is untouched
     *   by passing a DIRECTORY: each stage still captures and renders its own
     *   block, at the root the launch resolved. `ProcessExecutor::spawnWorker()`'s second render at
     *   `ProcessExecutor.php:473` renders the SubAgent's OWN agent, which on
     *   the live path is that same fresh one. The one consumer that does read
     *   a registered agent's block is {@see AgentManager::executeSubAgent()}
     *   at `AgentManager.php:433` - dormant, by the `--exclude=Agent.php`
     *   command above, which prints nothing and exits 1. So the mark's whole
     *   audience is a path with no caller.
     *
     *   SECOND, IT WOULD CHANGE DEFAULT BEHAVIOUR UNCONDITIONALLY, which is
     *   the same ground `App.php:552` was declined on. A once-per-launch
     *   roster loop has no step to key a per-step signal on; a mark placed
     *   there is spent on every dispatch the launch ever makes. MEASURED with
     *   the same logging-`git`-shim technique, against a throwaway fixture
     *   repository with one modified and one staged file (a scratch repo built
     *   for the measurement, NOT the committed tree - so read the byte figures
     *   as this fixture's and the subprocess figures as the method's):
     *
     *       EnvironmentBlock::capture() alone           => 0 subprocesses
     *       registered agent, block UNMARKED (today)    => 5 subprocesses,  864 bytes
     *       registered agent, block MARKED false        => 3 subprocesses,  450 bytes
     *       fresh Agent, no block (WorkflowEngine)      => 5 subprocesses,  864 bytes
     *
     *   The 0 is worth reading twice: `capture()` shells out to nothing, so
     *   the mark is free AT the attachment point and pays back only where the
     *   block is rendered - which line 4 shows is nowhere a Bootstrap mark can
     *   reach, since the fresh-Agent shape is identical to the unmarked one.
     *   Lines 2 and 3 are NOT byte-identical, 450 against 864: the two git
     *   diff sections are gone. That is a moved default, and a diff that moves
     *   the default is wrong here in the step text's own terms.
     *
     * SO THE HONEST DISPOSITION FOR `Bootstrap.php:1463` IS THE ESCALATED ONE.
     * Carrying a real write signal to the renders that actually repeat needs
     * `WorkflowEngine` to pass a block at all (it passes none, measured above),
     * {@see AgentResult}'s constructor to carry what a stage did, and the
     * worker's `complete` IPC frame to report it - the BUILD-IT-OUT named
     * under reason 2, across `WorkflowEngine` + `AgentResult` + the worker IPC
     * frame. `Bootstrap.php:1463` is downstream of none of that and cannot
     * substitute for it.
     *
     * AND WHY THAT DISPOSITION IS RECORDED HERE AND NOT AT THE SITE, since a
     * "do not just add the flag" note in the roster loop is exactly what the
     * next reader of that line wants. It was written, measured, and taken back
     * out: the comment ran 15 lines, and
     * `/usr/bin/grep -rno 'Bootstrap[.]php:[0-9]\+' ../docs | sed 's/.*Bootstrap[.]php://' | awk '$1+0>1462' | wc -l`
     * answers FIFTEEN citations past that line, in FOUR files under
     * `docs/plans/` that this step's file list does not include. Inserting
     * there would have staled every one of them, and staled them in files it
     * may not repair - which is the same defect this paragraph's neighbours
     * were opened to fix. So the site keeps its line number and the argument
     * lives with the API it is about.
     */
    public function systemPrompt(?EnvironmentBlock $environment = null): string
    {
        $rendered = ($environment ?? $this->environment ?? EnvironmentBlock::capture($this->environmentRoot ?? (string) getcwd(), $this->model))
            ->render();

        return $this->prompt === '' ? $rendered : $this->prompt . "\n\n" . $rendered;
    }
}
