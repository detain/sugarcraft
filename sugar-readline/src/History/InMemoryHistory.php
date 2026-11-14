<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * In-memory history store for readline session.
 *
 * Entries are stored newest-first: index 0 = most recent, index N = oldest.
 * Position -1 means "live buffer" (no entry selected).  previous() moves
 * deeper into history (toward older entries); next() moves back toward the
 * live buffer.  push() prepends to the array (newest-first), then resets
 * position to -1.
 *
 * Duplicate policy: push() skips an entry equal to the one just before it —
 * the adjacent-only collapse GNU/zsh HIST_IGNORE_DUPS describes. FileHistory
 * layers the stricter any-entry rule (HIST_IGNORE_ALL_DUPS) on top.
 */
class InMemoryHistory implements HistoryInterface
{
    /** @var list<string> newest-first */
    protected array $history = [];

    /**
     * Navigation cursor: -1 = live buffer (no entry selected),
     * 0 = most recent entry, higher = older entries.
     */
    private int $position = -1;

    /**
     * Maximum number of history entries to retain. 0 = unlimited; a
     * negative cap is rejected (it used to mean UNLIMITED silently —
     * finding 032's fail-fast ruling).
     * Protected so FileHistory can consult the same cap when bounding its
     * on-disk rewrite — one source of truth for the limit.
     */
    protected int $maxHistory;

    public function __construct(int $maxHistory = 0)
    {
        $this->maxHistory = self::checkedCap($maxHistory);
    }

    /**
     * @throws \InvalidArgumentException When the cap is negative.
     */
    protected static function checkedCap(int $limit): int
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException(
                'history cap must not be negative, got ' . $limit . '; use 0 for unlimited'
            );
        }
        return $limit;
    }

    /**
     * Apply the current cap at once: drop over-cap entries (newest-first
     * list, so the oldest fall off) and re-clamp the navigation cursor when
     * the trim moved the end of the list under it (finding 101 — a trim
     * mid-walk must not strand previous()/next() on a purged slot).
     */
    protected function trimToCap(): void
    {
        if ($this->maxHistory > 0 && \count($this->history) > $this->maxHistory) {
            $this->history = \array_slice($this->history, 0, $this->maxHistory);
        }
        if ($this->position >= \count($this->history)) {
            $this->position = $this->history === [] ? -1 : \count($this->history) - 1;
        }
    }

    public function push(string $line): void
    {
        if ($line === '') {
            return;
        }
        // Don't push a duplicate of the current most recent entry.
        if (($this->history[0] ?? null) === $line) {
            return;
        }
        // Prepend so index 0 = most recent.
        array_unshift($this->history, $line);
        $this->position = -1;
        // Enforce the live cap by evicting oldest entries.
        $this->trimToCap();
    }

    /**
     * Set the retained-entry cap, applying it immediately.
     *
     * Mutates and returns $this (the with-prefix clone violated the store's
     * push/clear-mutate paradigm; finding 032). The dropped-return call
     * `$h->setMaxHistory(50);` and the chained form both work. 0 = unlimited;
     * a negative cap throws. Trimming re-clamps the navigation cursor
     * (finding 101). FileHistory overrides this to bound the disk file too.
     *
     * @throws \InvalidArgumentException When the cap is negative.
     */
    public function setMaxHistory(int $limit): self
    {
        $this->maxHistory = self::checkedCap($limit);
        $this->trimToCap();
        return $this;
    }

    /**
     * BC forwarder for the pre-rename immutable spelling.
     *
     * @deprecated The clone-on-set form contradicted the store's mutating
     *     paradigm; kept only as a BC forwarder marked for v2.0.0 removal.
     *     Use {@see self::setMaxHistory()} instead. The forwarder applies
     *     the cap to THIS instance and returns it, so a caller who relied
     *     on the returned handle keeps working while one who dropped the
     *     return value now gets the effect the clone never delivered.
     *     REMOVED-IN: 2.0.0.
     */
    public function withMaxHistory(int $limit): self
    {
        \trigger_error(
            'SugarCraft\Readline\History\InMemoryHistory::withMaxHistory() is deprecated; use setMaxHistory()',
            E_USER_DEPRECATED
        );
        return $this->setMaxHistory($limit);
    }

    public function previous(): ?string
    {
        // Are there entries beyond the current position?
        if ($this->position < \count($this->history) - 1) {
            $this->position++;
            return $this->history[$this->position];
        }
        return null; // exhausted
    }

    public function next(): ?string
    {
        if ($this->position === -1) {
            return null; // already at live buffer
        }
        $this->position--;
        if ($this->position === -1) {
            return null; // back at live buffer
        }
        return $this->history[$this->position];
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::previous()} instead. REMOVED-IN: 2.0.0.
     */
    public function getPrevious(): ?string { return $this->previous(); }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::next()} instead. REMOVED-IN: 2.0.0.
     */
    public function getNext(): ?string { return $this->next(); }

    /**
     * Storage-order read view: newest first (index 0 = most recent push).
     *
     * PHP arrays copy on write, so handing the internal list straight over
     * is both O(1) and safe: a caller mutating its copy cannot write
     * through to the store.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->history;
    }

    public function count(): int
    {
        return \count($this->history);
    }

    public function search(string $query, int $fromIndex, SearchDirection|int $direction): ?SearchHit
    {
        // The enum is canonical; raw ints ride the legacy polarity (the
        // int-backed cases carry the exact old constants), step >= 0 →
        // toward older — including a legacy 0, which behaves as before.
        $polarity = $direction instanceof SearchDirection ? $direction->value : $direction;
        $count = \count($this->history);
        $step = $polarity >= 0 ? 1 : -1;
        // No clamping: a $fromIndex already outside the list means the scan
        // direction is exhausted, and the caller must see null (not a rematch).
        for ($i = $fromIndex; $i >= 0 && $i < $count; $i += $step) {
            if ($query === '' || str_contains($this->history[$i], $query)) {
                return SearchHit::new($i, $this->history[$i]);
            }
        }
        return null;
    }

    public function reset(): void
    {
        $this->position = -1;
    }

    public function clear(): void
    {
        $this->history = [];
        $this->position = -1;
    }
}
