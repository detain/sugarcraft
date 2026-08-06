<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Value object representing the result of any Git MCP operation.
 *
 * Captures success/failure, output data, error messages, and metadata
 * relevant to the Git operation groups: git_context, git_history,
 * git_commits, git_branches, git_worktree, git_flow, git_lfs.
 */
final readonly class GitOperationResult
{
    /**
     * @param bool $success Whether the operation succeeded.
     * @param mixed $output The output data from the operation (varies by operation type).
     * @param string|null $error Error message if the operation failed.
     * @param string $operation The specific operation name (e.g., 'git_commit', 'git_branch_list').
     * @param string $group The operation group (e.g., 'git_commits', 'git_branches').
     * @param array<string, mixed> $metadata Additional metadata relevant to the operation.
     * @param float|null $executionTimeMs How long the operation took in milliseconds.
     */
    public function __construct(
        public bool $success,
        public mixed $output,
        public ?string $error,
        public string $operation,
        public string $group,
        public array $metadata = [],
        public ?float $executionTimeMs = null,
    ) {}

    /**
     * Create a successful result.
     *
     * @param mixed $output
     * @param string $operation
     * @param string $group
     * @param array<string, mixed> $metadata
     * @param float|null $executionTimeMs
     * @return self
     */
    public static function success(
        mixed $output,
        string $operation,
        string $group,
        array $metadata = [],
        ?float $executionTimeMs = null,
    ): self {
        return new self(
            success: true,
            output: $output,
            error: null,
            operation: $operation,
            group: $group,
            metadata: $metadata,
            executionTimeMs: $executionTimeMs,
        );
    }

    /**
     * Create a failed result.
     *
     * @param string $error The error message.
     * @param string $operation
     * @param string $group
     * @param array<string, mixed> $metadata
     * @param float|null $executionTimeMs
     * @return self
     */
    public static function failure(
        string $error,
        string $operation,
        string $group,
        array $metadata = [],
        ?float $executionTimeMs = null,
    ): self {
        return new self(
            success: false,
            output: null,
            error: $error,
            operation: $operation,
            group: $group,
            metadata: $metadata,
            executionTimeMs: $executionTimeMs,
        );
    }

    /**
     * @return bool True if the operation succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return bool True if the operation failed.
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Get output only if successful, otherwise null.
     *
     * @return mixed
     */
    public function getOutputOrNull(): mixed
    {
        return $this->success ? $this->output : null;
    }
}
