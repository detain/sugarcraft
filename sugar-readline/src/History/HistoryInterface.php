<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * Contracts for readline history navigation.
 *
 * Accessor-deprecation era (2026-10-24, ruling 3b): the legacy
 * `getPrevious()`/`getNext()` spellings left this contract — the bare
 * {@see self::previous()}/{@see self::next()} pair is canonical.
 * {@see InMemoryHistory} (and so FileHistory by inheritance) keeps the
 * old names as `@deprecated` BC forwarders until v2.0.0.
 */
interface HistoryInterface
{
    /**
     * Add a line to history if it is non-empty and differs from the last entry.
     *
     * This is the floor every implementation honors; some apply stricter
     * rules — {@see FileHistory} skips any duplicate of a retained entry,
     * not just the most recent one.
     */
    public function push(string $line): void;

    /**
     * Return the previous history entry and advance the navigation position.
     * Returns null when there is no previous entry.
     */
    public function previous(): ?string;

    /**
     * Return the next history entry and advance the navigation position.
     * Returns null when there is no next entry (we are at the "live" buffer).
     */
    public function next(): ?string;

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
     * Read-only: does not disturb the previous()/next() navigation
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
