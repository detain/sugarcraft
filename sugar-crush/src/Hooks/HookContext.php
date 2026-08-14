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

    /**
     * The same call, with a MODIFY hook's rewritten arguments in place of the
     * originals — BOTH halves at once.
     *
     * Deliberately not {@see withToolInput()}: that one moves the JSON text
     * only and leaves `$toolArgs` still describing the arguments the rewrite
     * replaced. Every built-in guard and
     * {@see BuiltIn\PermissionGateHook} reads `$toolArgs`, so a re-scan built
     * on `withToolInput()` alone would re-judge the OLD call and report a
     * verdict on arguments that are never going to run.
     *
     * @param array<string, mixed> $args   the decoded rewritten arguments
     * @param string               $input  the hook's own JSON text for them,
     *                                     kept verbatim rather than
     *                                     re-encoded so what a downstream
     *                                     consumer decodes is byte-identical
     *                                     to what the hook emitted
     */
    public function withRewrittenArgs(array $args, string $input): self
    {
        return new self(
            sessionId: $this->sessionId,
            toolName: $this->toolName,
            toolArgs: $args,
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
