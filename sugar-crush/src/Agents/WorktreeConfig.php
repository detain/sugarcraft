<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Configuration for git worktree isolation per agent.
 *
 * Controls where worktrees are created, when they are cleaned up, and which
 * isolation strategy is used. All values are immutable after construction —
 * use with*() methods to produce derived instances.
 */
final readonly class WorktreeConfig
{
    public function __construct(
        /**
         * Base directory where agent worktrees are created.
         * Expandable via FileSystem::expandPath().
         * Defaults to .sugar-crush/worktrees/ within the repo root.
         */
        public string $basePath = '.sugar-crush/worktrees/',

        /**
         * When true, worktrees are automatically removed when the team
         * they belong to is dissolved.
         */
        public bool $autoCleanup = true,

        /**
         * The isolation strategy for agent file operations.
         * Worktree: each agent gets a full git worktree on its own branch.
         * Branch: agents share the filesystem but work on dedicated branches.
         * Path: agents share the filesystem but file ops are routed through PathJail.
         */
        public WorktreeIsolationMode $isolationMode = WorktreeIsolationMode::Worktree,
    ) {}

    /**
     * Create a new config with a different basePath value.
     */
    public function withBasePath(string $basePath): self
    {
        return new self(
            basePath: $basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
        );
    }

    /**
     * Create a new config with a different autoCleanup value.
     */
    public function withAutoCleanup(bool $autoCleanup): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $autoCleanup,
            isolationMode: $this->isolationMode,
        );
    }

    /**
     * Create a new config with a different isolationMode value.
     */
    public function withIsolationMode(WorktreeIsolationMode $isolationMode): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $isolationMode,
        );
    }
}
