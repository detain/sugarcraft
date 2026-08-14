<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Context\EnvironmentBlock;

final readonly class Agent
{
    /**
     * @param ?EnvironmentBlock $environment Session environment snapshot appended to
     *                                       systemPrompt(); null lets systemPrompt()
     *                                       capture one for itself.
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
    ) {}

    public static function fromArray(array $data): self
    {
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
     * passed through as a literal model name a provider would reject. The
     * preset's richer fields (permissionMode, maxTurns, effort, isolation,
     * mcpServers) have no Agent counterpart and stay on the preset: this
     * bridge exists so a preset can be *registered and delegated to*, and
     * {@see AgentManager::createSubAgent()} takes the permission mode as its
     * own argument.
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
        );
    }

    /**
     * Serialize the agent's persisted configuration.
     *
     * The environment snapshot is deliberately absent: it is per-session
     * runtime state (cwd, git status, date), not agent config, and writing a
     * stale snapshot back into an agent definition file would outlive the
     * session that captured it.
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
     */
    public function systemPrompt(?EnvironmentBlock $environment = null): string
    {
        $rendered = ($environment ?? $this->environment ?? EnvironmentBlock::capture((string) getcwd(), $this->model))
            ->render();

        return $this->prompt === '' ? $rendered : $this->prompt . "\n\n" . $rendered;
    }
}
