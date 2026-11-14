<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Represents a single stash entry.
 *
 * Mirrors jesseduffield/lazygit stash panel entry — the index, sha, branch
 * and message record parsed out of `git stash list`.
 */
final readonly class StashEntry
{
    public function __construct(
        public readonly int $index,
        public readonly string $sha,
        public readonly string $branch,
        public readonly string $message,
    ) {}

    /**
     * Format for display in the stash list.
     *
     * Empty fields (the `On <branch>: <msg>` form carries no sha) collapse
     * instead of leaving a double space.
     */
    public function displayLine(): string
    {
        $parts = array_filter(
            ['stash@{' . $this->index . '}', $this->sha, $this->message],
            static fn(string $part): bool => $part !== '',
        );
        return implode(' ', $parts);
    }

    /**
     * The stash ref for git commands (e.g. "stash@{0}").
     */
    public function stashRef(): string
    {
        return 'stash@{' . $this->index . '}';
    }
}
