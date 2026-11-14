<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * Contracts for readline history navigation.
 *
 * The legacy `getPrevious()`/`getNext()` spellings left this contract — the
 * bare {@see self::previous()}/{@see self::next()} pair is canonical.
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
     * Every retained entry in storage order: newest first (index 0 is the
     * most recent push — the same order {@see self::previous()} walks).
     *
     * Implementations hand back a read view; mutating the returned list
     * must never write through to the store. The prompt's autosuggest
     * scans this window instead of replaying previous() per repaint
     * (finding 014), so callers that only need recent matches should
     * bound their scan rather than the storage.
     *
     * @return list<string>
     */
    public function entries(): array;

    /** Number of retained entries — the length of {@see self::entries()}. */
    public function count(): int;

    /**
     * Incrementally search history for an entry containing $query.
     *
     * Read-only: does not disturb the previous()/next() navigation
     * position, so incremental search and arrow-key navigation can coexist.
     *
     * @param string               $query     Substring to match; '' matches any entry.
     * @param int                  $fromIndex Index to start scanning at (0 = newest entry).
     * @param SearchDirection|int  $direction Canonical enum (finding 091); a raw
     *                                        int is still accepted and maps by the
     *                                        legacy polarity — non-negative scans
     *                                        toward older entries (higher indexes),
     *                                        negative toward newer (lower indexes).
     * @return SearchHit|null      The first match at or after $fromIndex in the
     *                                        scan direction, or null. An
     *                                        out-of-range start index means the
     *                                        direction is exhausted: null, never a
     *                                        clamped rematch.
     */
    public function search(string $query, int $fromIndex, SearchDirection|int $direction): ?SearchHit;
}
