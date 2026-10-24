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

    /** Maximum number of history entries to retain. 0 = unlimited. */
    private int $maxHistory;

    public function __construct(int $maxHistory = 0)
    {
        $this->maxHistory = $maxHistory;
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
        // Enforce maxHistory limit by evicting oldest entries.
        if ($this->maxHistory > 0 && \count($this->history) > $this->maxHistory) {
            $this->history = \array_slice($this->history, 0, $this->maxHistory);
        }
    }

    /**
     * Returns a new instance with the specified maxHistory limit.
     */
    public function withMaxHistory(int $limit): self
    {
        $clone = clone $this;
        $clone->maxHistory = $limit;
        // Immediately trim if current history exceeds new limit.
        if ($limit > 0 && \count($clone->history) > $limit) {
            $clone->history = \array_slice($clone->history, 0, $limit);
        }
        return $clone;
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

    public function search(string $query, int $fromIndex, int $direction): ?array
    {
        $count = \count($this->history);
        $step = $direction >= 0 ? 1 : -1;
        // No clamping: a $fromIndex already outside the list means the scan
        // direction is exhausted, and the caller must see null (not a rematch).
        for ($i = $fromIndex; $i >= 0 && $i < $count; $i += $step) {
            if ($query === '' || str_contains($this->history[$i], $query)) {
                return ['index' => $i, 'entry' => $this->history[$i]];
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

    /**
     * Return the most recent entry without modifying navigation position,
     * or null if history is empty.
     */
    protected function getLastEntry(): ?string
    {
        return $this->history[0] ?? null;
    }
}
