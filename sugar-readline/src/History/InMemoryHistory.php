<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * In-memory history store for readline session.
 *
 * Entries are stored newest-first: index 0 = most recent, index N = oldest.
 * Position -1 means "live buffer" (no entry selected).  getPrevious() moves
 * deeper into history (toward older entries); getNext() moves back toward the
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
    }

    public function getPrevious(): ?string
    {
        // Are there entries beyond the current position?
        if ($this->position < \count($this->history) - 1) {
            $this->position++;
            return $this->history[$this->position];
        }
        return null; // exhausted
    }

    public function getNext(): ?string
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
