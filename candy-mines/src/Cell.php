<?php

declare(strict_types=1);

namespace CandyCore\Mines;

/**
 * One cell on the minefield. Immutable value object — every transition
 * (reveal, flag, etc.) returns a fresh Cell rather than mutating in
 * place, so the {@see Board} can rebuild itself with normal array
 * spread without aliasing issues.
 */
final class Cell
{
    public function __construct(
        public readonly bool $mine,
        public readonly bool $revealed = false,
        public readonly bool $flagged = false,
        /** Adjacent-mine count, valid only after the board is laid out. */
        public readonly int $adjacent = 0,
    ) {}

    public function withMine(bool $mine): self
    {
        return new self($mine, $this->revealed, $this->flagged, $this->adjacent);
    }

    public function withAdjacent(int $n): self
    {
        return new self($this->mine, $this->revealed, $this->flagged, $n);
    }

    public function reveal(): self
    {
        if ($this->revealed || $this->flagged) {
            return $this;
        }
        return new self($this->mine, true, false, $this->adjacent);
    }

    public function toggleFlag(): self
    {
        if ($this->revealed) {
            return $this;
        }
        return new self($this->mine, false, !$this->flagged, $this->adjacent);
    }
}
