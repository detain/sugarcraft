<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * Contracts for readline history navigation.
 */
interface HistoryInterface
{
    /**
     * Add a line to history if it is non-empty and differs from the last entry.
     */
    public function push(string $line): void;

    /**
     * Return the previous history entry and advance the navigation position.
     * Returns null when there is no previous entry.
     */
    public function getPrevious(): ?string;

    /**
     * Return the next history entry and advance the navigation position.
     * Returns null when there is no next entry (we are at the "live" buffer).
     */
    public function getNext(): ?string;

    /**
     * Reset the navigation position to "live" (no entry selected, position = -1).
     */
    public function reset(): void;

    /**
     * Clear all history entries.
     */
    public function clear(): void;
}
