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
        return $this->mutate(['revealed' => true, 'flagged' => false]);
    }

    public function toggleFlag(): self
    {
        if ($this->revealed) {
            return $this;
        }
        return $this->mutate(['flagged' => !$this->flagged]);
    }
}
