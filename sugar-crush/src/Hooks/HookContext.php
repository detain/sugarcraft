<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

/**
 * Context passed to a hook execution.
 */
final readonly class HookContext
{
    public function __construct(
        public string $sessionId,
        public string $toolName,
        public array $toolArgs,
        public string $toolInput,
        public string $toolOutput,
        public string $model,
        public string $provider,
        public string $projectRoot,
    ) {}

    public function withToolInput(string $input): self
    {
        return new self(
            sessionId: $this->sessionId,
            toolName: $this->toolName,
            toolArgs: $this->toolArgs,
            toolInput: $input,
            toolOutput: $this->toolOutput,
            model: $this->model,
            provider: $this->provider,
            projectRoot: $this->projectRoot,
        );
    }

    public function withToolOutput(string $output): self
    {
        return new self(
            sessionId: $this->sessionId,
            toolName: $this->toolName,
            toolArgs: $this->toolArgs,
            toolInput: $this->toolInput,
            toolOutput: $output,
            model: $this->model,
            provider: $this->provider,
            projectRoot: $this->projectRoot,
        );
    }
}
