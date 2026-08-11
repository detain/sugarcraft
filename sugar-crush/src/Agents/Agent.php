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
