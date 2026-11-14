<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Immutable worktree manager state — the list of worktrees and its cursor.
 *
 * Activated by pressing 'w' in the branches pane. Shows the list of
 * worktrees, with options to add or remove them.
 *
 * @readonly
 *
 * Mirrors jesseduffield/lazygit worktrees panel — `git worktree list --porcelain` with add/remove. */
final readonly class Worktrees
{
    /**
     * @param list<WorktreeEntry> $worktrees
     * @param int                  $cursor     Current cursor
     * @param bool                 $adding     Whether we're collecting new worktree path
     * @param string               $newPath    The path being typed for new worktree
     * @param string               $newBranch  The branch for new worktree
     * @param string               $addField   Which add-form field the keyboard edits: 'path' or 'branch' (CG-24)
     * @param bool                 $removing   Whether we're in remove-confirm mode
     */
    public function __construct(
        public array $worktrees,
        public int $cursor = 0,
        public bool $adding = false,
        public string $newPath = '',
        public string $newBranch = '',
        public string $addField = 'path',
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
            return new self(worktrees: [], cursor: 0, adding: $this->adding, newPath: $this->newPath, newBranch: $this->newBranch, addField: $this->addField, removing: $this->removing);
        }
        $newCursor = max(0, min($count - 1, $this->cursor + $dir));
        return new self(worktrees: $this->worktrees, cursor: $newCursor, adding: $this->adding, newPath: $this->newPath, newBranch: $this->newBranch, addField: $this->addField, removing: $this->removing);
    }

    /**
     * Enter "add new worktree" mode.
     */
    public function startAdding(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: true, newPath: '', newBranch: '', addField: 'path', removing: false);
    }

    /**
     * Update new worktree path being typed.
     */
    public function withNewPath(string $path): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: $this->adding, newPath: $path, newBranch: $this->newBranch, addField: $this->addField, removing: $this->removing);
    }

    /**
     * Update new worktree branch being typed.
     */
    public function withNewBranch(string $branch): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: $this->adding, newPath: $this->newPath, newBranch: $branch, addField: $this->addField, removing: $this->removing);
    }

    /**
     * Flip which add-form field the keyboard edits (CG-24: the branch slot
     * existed in the model but no surface could ever fill it).
     */
    public function toggleAddField(): self
    {
        return new self(
            worktrees: $this->worktrees,
            cursor: $this->cursor,
            adding: $this->adding,
            newPath: $this->newPath,
            newBranch: $this->newBranch,
            addField: $this->addField === 'path' ? 'branch' : 'path',
            removing: $this->removing,
        );
    }

    /** The buffer of the field currently being edited. */
    public function activeText(): string
    {
        return $this->addField === 'branch' ? $this->newBranch : $this->newPath;
    }

    /** Replace the buffer of the field currently being edited. */
    public function withActiveText(string $text): self
    {
        return $this->addField === 'branch' ? $this->withNewBranch($text) : $this->withNewPath($text);
    }

    /** Append to the buffer of the field currently being edited (pastes). */
    public function appendActive(string $text): self
    {
        return $this->withActiveText($this->activeText() . $text);
    }

    /**
     * Cancel add mode.
     */
    public function cancelAdding(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: false, newPath: '', newBranch: '', addField: 'path', removing: false);
    }

    /**
     * Enter remove-confirm mode for current worktree.
     */
    public function startRemoving(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: false, newPath: '', newBranch: '', addField: 'path', removing: true);
    }

    /**
     * Cancel remove mode.
     */
    public function cancelRemoving(): self
    {
        return new self(worktrees: $this->worktrees, cursor: $this->cursor, adding: false, newPath: '', newBranch: '', addField: 'path', removing: false);
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
