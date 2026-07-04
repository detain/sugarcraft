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

    /**
     * Incrementally search history for an entry containing $query.
     *
     * Read-only: does not disturb the getPrevious()/getNext() navigation
     * position, so incremental search and arrow-key navigation can coexist.
     *
     * @param string $query     Substring to match; '' matches any entry.
     * @param int    $fromIndex Index to start scanning at (0 = newest entry).
     * @param int    $direction Positive scans toward older entries (higher
     *                          indexes), negative toward newer (lower indexes).
     * @return array{index: int, entry: string}|null The first match at or
     *                          after $fromIndex in the scan direction, or null.
     */
    public function search(string $query, int $fromIndex, int $direction): ?array;
}
