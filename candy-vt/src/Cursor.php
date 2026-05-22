<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

/**
 * Cursor state for the vcr renderer path.
 *
 * Readonly value object — row, column, shape, and visibility.
 *
 * Mirrors charmbracelet/x/vt Cursor (simplified for renderer use).
 */
final readonly class Cursor
{
    public function __construct(
        public int $row = 0,
        public int $col = 0,
        public int $shape = 0,
        public bool $visible = true,
    ) {
    }

    /**
     * @param array{row?:int, col?:int, shape?:int, visible?:bool} $changes
     */
    private function mutate(array $changes): self
    {
        return new self(
            row: $changes['row'] ?? $this->row,
            col: $changes['col'] ?? $this->col,
            shape: $changes['shape'] ?? $this->shape,
            visible: $changes['visible'] ?? $this->visible,
        );
    }

    public function at(int $row, int $col): self
    {
        return $this->mutate(['row' => $row, 'col' => $col]);
    }

    public function withShape(int $shape): self
    {
        return $this->mutate(['shape' => $shape]);
    }

    public function hidden(): self
    {
        return $this->mutate(['visible' => false]);
    }

    public function shown(): self
    {
        return $this->mutate(['visible' => true]);
    }

    public function equals(self $other): bool
    {
        return $this->row === $other->row
            && $this->col === $other->col
            && $this->shape === $other->shape
            && $this->visible === $other->visible;
    }
}
