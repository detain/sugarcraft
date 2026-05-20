<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Represents a single stash entry.
 *
 * @readonly
 */
final class StashEntry
{
    public function __construct(
        public readonly int $index,
        public readonly string $sha,
        public readonly string $branch,
        public readonly string $message,
    ) {}

    /**
     * Format for display in the stash list.
     */
    public function displayLine(): string
    {
        return 'stash@{' . $this->index . '} ' . $this->sha . ' ' . $this->message;
    }

    /**
     * The stash ref for git commands (e.g. "stash@{0}").
     */
    public function stashRef(): string
    {
        return 'stash@{' . $this->index . '}';
    }
}
