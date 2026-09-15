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
        /**
         * Raw DECSCUSR parameter from `CSI Ps SP q`. For conformant input that
         * is 0-6: 0/1 blinking block, 2 steady block, 3 blinking underline,
         * 4 steady underline, 5 blinking bar, 6 steady bar
         * (see {@see \SugarCraft\Vt\CursorShape}). The handler stores Ps
         * verbatim, so a non-conformant larger value can land here; it is not
         * clamped, and {@see \SugarCraft\Vt\CursorShape::fromInt()} maps
         * anything unknown back to BlinkingBlock. Kept equal to
         * {@see \SugarCraft\Vt\Mode\Mode::$cursorShape} by every writer.
         */
        public int $shape = 0,
        public ?int $savedRow = null,
        public ?int $savedCol = null,
    ) {
    }

    public function withRow(int $row): self
    {
        return new self(
            row: $row,
            col: $this->col,
            visible: $this->visible,
            shape: $this->shape,
            savedRow: $this->savedRow,
            savedCol: $this->savedCol,
        );
    }

    public function withCol(int $col): self
    {
        return new self(
            row: $this->row,
            col: $col,
            visible: $this->visible,
            shape: $this->shape,
            savedRow: $this->savedRow,
            savedCol: $this->savedCol,
        );
    }

    public function withVisible(bool $v): self
    {
        return new self(
            row: $this->row,
            col: $this->col,
            visible: $v,
            shape: $this->shape,
            savedRow: $this->savedRow,
            savedCol: $this->savedCol,
        );
    }

    public function withShape(int $shape): self
    {
        return new self(
            row: $this->row,
            col: $this->col,
            visible: $this->visible,
            shape: $shape,
            savedRow: $this->savedRow,
            savedCol: $this->savedCol,
        );
    }

    public function save(): self
    {
        return new self(
            row: $this->row,
            col: $this->col,
            visible: $this->visible,
            shape: $this->shape,
            savedRow: $this->row,
            savedCol: $this->col,
        );
    }

    public function restore(): self
    {
        return new self(
            row: $this->savedRow ?? $this->row,
            col: $this->savedCol ?? $this->col,
            visible: $this->visible,
            shape: $this->shape,
            savedRow: $this->savedRow,
            savedCol: $this->savedCol,
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
