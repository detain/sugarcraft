<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

use SugarCraft\Core\Concerns\Mutable;

/**
 * One cell on the minefield. Immutable value object — every transition
 * (reveal, flag, etc.) routes the house {@see Mutable} with-pattern to
 * return a fresh Cell rather than mutating in place, so {@see Board} can
 * rebuild itself with normal array spread without aliasing issues.
 */
final class Cell
{
    use Mutable;

    public function __construct(
        public readonly bool $mine,
        public readonly bool $revealed = false,
        public readonly bool $flagged = false,
        /** Adjacent-mine count, valid only after the board is laid out. */
        public readonly int $adjacent = 0,
        /**
         * Third marker (msw-28, finding -093): the classic `?` memo for an
         * uncertain cell. Inert everywhere else — it never counts toward
         * flagCount, never blocks a reveal, never satisfies a chord, and
         * the win math cannot see it. LAST constructor slot: the 4-arg
         * positional callers predate it.
         */
        public readonly bool $questioned = false,
    ) {}

    public function withMine(bool $mine): self
    {
        return $this->mutate(['mine' => $mine]);
    }

    public function withAdjacent(int $n): self
    {
        return $this->mutate(['adjacent' => $n]);
    }

    public function reveal(): self
    {
        if ($this->revealed || $this->flagged) {
            return $this;
        }
        // A question mark does not protect a cell — opening it clears the
        // memo along the way (Cell invariant: never revealed AND marked).
        return $this->mutate(['revealed' => true, 'flagged' => false, 'questioned' => false]);
    }

    /**
     * The classic marker cycle: empty → flag → question → empty (finding
     * -093 GAP-05). Flag and question are mutually exclusive states on the
     * same memo key; a revealed cell holds no marker at all (no-op).
     */
    public function cycleMarker(): self
    {
        if ($this->revealed) {
            return $this;
        }
        if ($this->flagged) {
            return $this->mutate(['flagged' => false, 'questioned' => true]);
        }
        if ($this->questioned) {
            return $this->mutate(['questioned' => false]);
        }
        return $this->mutate(['flagged' => true]);
    }
}
