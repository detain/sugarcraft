<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Configuration for git worktree isolation per agent.
 *
 * Controls where worktrees are created, when they are cleaned up, and which
 * isolation strategy is used. All values are immutable after construction —
 * use with*() methods to produce derived instances.
 *
 * @note This class is mutable (not `readonly class`) to support the ::new()
 * static factory which reads .sugar-crush/config.json at construction time.
 * Individual properties remain readonly once constructed.
 */
final class WorktreeConfig
{
    /**
     * Base directory where agent worktrees are created.
     * Expandable via FileSystem::expandPath().
     * Defaults to .sugar-crush/worktrees/ within the repo root.
     */
    public readonly string $basePath;

    /**
     * When true, worktrees are automatically removed when the team
     * they belong to is dissolved.
     */
    public readonly bool $autoCleanup;

    /**
     * The isolation strategy for agent file operations.
     * Worktree: each agent gets a full git worktree on its own branch.
     * Branch: agents share the filesystem but work on dedicated branches.
     * Path: agents share the filesystem but file ops are routed through PathJail.
     */
    public readonly WorktreeIsolationMode $isolationMode;

    /**
     * Worktrees older than this many days are considered stale and
     * eligible for removal by cleanupStaleWorktrees().
     * Used by the periodic sweep cleanup pass.
     */
    public readonly int $worktreeCleanupPeriodDays;

    /**
     * Path to the .worktreeinclude file (relative to repo root) that
     * lists glob patterns for files to copy into every new worktree,
     * even if they are normally excluded by .gitignore.
     * Set to '' to disable .worktreeinclude resolution.
     */
    public readonly string $worktreeIncludeFile;

    /**
     * Create a new config, optionally loading values from .sugar-crush/config.json.
     *
     * Values from the JSON file are used as defaults; any explicitly-passed
     * arguments override those defaults. This allows `new WorktreeConfig()` to
     * automatically pick up settings from the JSON file without requiring
     * callers to know about the file path.
     */
    public static function new(
        string $basePath = '.sugar-crush/worktrees/',
        bool $autoCleanup = true,
        WorktreeIsolationMode $isolationMode = WorktreeIsolationMode::Worktree,
        int $worktreeCleanupPeriodDays = 7,
        string $worktreeIncludeFile = '.worktreeinclude',
    ): self {
        $configPath = __DIR__ . '/../../../.sugar-crush/config.json';
        if (file_exists($configPath)) {
            $json = json_decode(file_get_contents($configPath), true);
            if (is_array($json)) {
                if (isset($json['worktreeCleanupPeriodDays'])) {
                    $worktreeCleanupPeriodDays = (int) $json['worktreeCleanupPeriodDays'];
                }
                if (isset($json['worktreeIncludeFile'])) {
                    $worktreeIncludeFile = (string) $json['worktreeIncludeFile'];
                }
            }
        }

        return new self(
            basePath: $basePath,
            autoCleanup: $autoCleanup,
            isolationMode: $isolationMode,
            worktreeCleanupPeriodDays: $worktreeCleanupPeriodDays,
            worktreeIncludeFile: $worktreeIncludeFile,
        );
    }

    public function __construct(
        string $basePath = '.sugar-crush/worktrees/',
        bool $autoCleanup = true,
        WorktreeIsolationMode $isolationMode = WorktreeIsolationMode::Worktree,
        int $worktreeCleanupPeriodDays = 7,
        string $worktreeIncludeFile = '.worktreeinclude',
    ) {
        self::assertIsolationModeImplemented($isolationMode);

        $this->basePath = $basePath;
        $this->autoCleanup = $autoCleanup;
        $this->isolationMode = $isolationMode;
        $this->worktreeCleanupPeriodDays = $worktreeCleanupPeriodDays;
        $this->worktreeIncludeFile = $worktreeIncludeFile;
    }

    /**
     * Guard against silently accepting an isolation mode WorktreeManager does
     * not actually implement. Only WorktreeIsolationMode::Worktree has real
     * behavior today (full git worktree per agent) — Branch and Path are
     * defined on the enum but WorktreeManager::createWorktree() never
     * branches on them, so setting either previously had no effect at all.
     *
     * @throws \InvalidArgumentException When $mode has no implementation in WorktreeManager.
     */
    private static function assertIsolationModeImplemented(WorktreeIsolationMode $mode): void
    {
        if ($mode !== WorktreeIsolationMode::Worktree) {
            throw new \InvalidArgumentException(sprintf(
                'WorktreeIsolationMode::%s is not implemented by WorktreeManager yet — only'
                . ' WorktreeIsolationMode::Worktree is currently supported.',
                $mode->name,
            ));
        }
    }

    /**
     * Create a new config with a different basePath value.
     */
    public function withBasePath(string $basePath): self
    {
        return new self(
            basePath: $basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
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
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
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
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
        );
    }

    /**
     * Create a new config with a different worktreeCleanupPeriodDays value.
     */
    public function withWorktreeCleanupPeriodDays(int $worktreeCleanupPeriodDays): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
        );
    }

    /**
     * Create a new config with a different worktreeIncludeFile value.
     */
    public function withWorktreeIncludeFile(string $worktreeIncludeFile): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $worktreeIncludeFile,
        );
    }
}
