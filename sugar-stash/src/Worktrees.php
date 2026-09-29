<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Immutable worktree manager state — list of worktrees + cursor.
 *
 * Activated by pressing 'w' in the branches pane. Shows list of
 * worktrees with options to add/remove.
 *
 * @readonly
 */
final readonly class Worktrees
{
    /**
     * @param list<WorktreeEntry> $worktrees
     * @param int                  $cursor     Current cursor
     * @param bool                 $adding     Whether we're collecting new worktree path
     * @param string               $newPath    The path being typed for new worktree
     * @param string               $newBranch  The branch for new worktree
     * @param bool                 $removing   Whether we're in remove-confirm mode
     */
    public function __construct(
        public array $worktrees,
        public int $cursor = 0,
        public bool $adding = false,
        public string $newPath = '',
        public string $newBranch = '',
        public bool $removing = false,
    ) {}

    /**
     * Parse `git worktree list --porcelain` output into WorktreeEntry list.
     */
    public static function fromGitOutput(array $lines): array
    {
        return WorktreeEntry::fromGitOutput($lines);
    }

    /**
     * Move cursor up/down.
     */
    public function withCursor(int $dir): self
    {
        $count = count($this->worktrees);
        if ($count === 0) {
            return new self(worktrees: [], cursor: 0, adding: $this->adding, newPath: $this->newPath, newBranch: $this->newBranch, removing: $this->removing);
        }
        $newCursor = max(0, min($count - 1, $this->cursor + $dir));
        return new self(worktrees: $this->worktrees, cursor: $newCursor, adding: $this->adding, newPath: $this->newPath, newBranch: $this->newBranch, removing: $this->removing);
    }

    /**
     * Enter "add new worktree" mode.
     */
    public function startAdding(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: true, newPath: '', newBranch: '', removing: false);
    }

    /**
     * Update new worktree path being typed.
     */
    public function withNewPath(string $path): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: $this->adding, newPath: $path, newBranch: $this->newBranch, removing: $this->removing);
    }

    /**
     * Update new worktree branch being typed.
     */
    public function withNewBranch(string $branch): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: $this->adding, newPath: $this->newPath, newBranch: $branch, removing: $this->removing);
    }

    /**
     * Cancel add mode.
     */
    public function cancelAdding(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: false, newPath: '', newBranch: '', removing: false);
    }

    /**
     * Enter remove-confirm mode for current worktree.
     */
    public function startRemoving(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: false, newPath: '', newBranch: '', removing: true);
    }

    /**
     * Cancel remove mode.
     */
    public function cancelRemoving(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: false, newPath: '', newBranch: '', removing: false);
    }

    /**
     * Current worktree entry, or null.
     */
    public function current(): ?WorktreeEntry
    {
        return $this->worktrees[$this->cursor] ?? null;
    }

    /**
     * Count of worktrees.
     */
    public function count(): int
    {
        return count($this->worktrees);
    }
}
