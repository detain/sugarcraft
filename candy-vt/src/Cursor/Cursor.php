<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Cursor;

/**
 * Cursor state snapshot.
 *
 * Mirrors charmbracelet/x/vt Cursor.
 */
final readonly class Cursor
{
    public function __construct(
        public int $row = 0,
        public int $col = 0,
        public bool $visible = true,
        public int $shape = 0,    // 0=block 1=underline 2=pipe
        public ?int $savedRow = null,
        public ?int $savedCol = null,
    ) {}

    public function withRow(int $row): self
    {
        return new self(
            row: $row, col: $this->col,
            visible: $this->visible, shape: $this->shape,
            savedRow: $this->savedRow, savedCol: $this->savedCol,
        );
    }

    public function withCol(int $col): self
    {
        return new self(
            row: $this->row, col: $col,
            visible: $this->visible, shape: $this->shape,
            savedRow: $this->savedRow, savedCol: $this->savedCol,
        );
    }

    public function withVisible(bool $v): self
    {
        return new self(
            row: $this->row, col: $this->col,
            visible: $v, shape: $this->shape,
            savedRow: $this->savedRow, savedCol: $this->savedCol,
        );
    }

    public function withShape(int $shape): self
    {
        return new self(
            row: $this->row, col: $this->col,
            visible: $this->visible, shape: $shape,
            savedRow: $this->savedRow, savedCol: $this->savedCol,
        );
    }

    public function save(): self
    {
        return new self(
            row: $this->row, col: $this->col,
            visible: $this->visible, shape: $this->shape,
            savedRow: $this->row, savedCol: $this->col,
        );
    }

    public function restore(): self
    {
        return new self(
            row: $this->savedRow ?? $this->row,
            col: $this->savedCol ?? $this->col,
            visible: $this->visible, shape: $this->shape,
            savedRow: $this->savedRow, savedCol: $this->savedCol,
        );
    }

    public function equals(self $other): bool
    {
        return $this->row === $other->row
            && $this->col === $other->col
            && $this->visible === $other->visible
            && $this->shape === $other->shape;
    }
}
