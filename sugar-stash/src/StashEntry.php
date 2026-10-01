<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Represents a single stash entry.
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
     * instead of leaving a double space (audit #15).
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
