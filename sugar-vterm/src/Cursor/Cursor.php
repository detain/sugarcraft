<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Cursor;

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
         * (see {@see \SugarCraft\Vterm\CursorShape}). The handler stores Ps
         * verbatim, so a non-conformant larger value can land here; it is not
         * clamped, and {@see \SugarCraft\Vterm\CursorShape::fromInt()} maps
         * anything unknown back to BlinkingBlock. Every writer of this field
         * inside the handler writes {@see \SugarCraft\Vterm\Mode\Mode::$cursorShape}
         * with the same value, so a pair that starts equal stays equal — see
         * {@see \SugarCraft\Vterm\Handler\ScreenHandler::__construct()} for the
         * one construction that takes a disagreeing pair as supplied.
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

    /**
     * Position alias: row and column in one call. The canonical primitive
     * pair is {@see withRow()}/{@see withCol()}; {@see \SugarCraft\Vterm\Cursor::at()}
     * (the root renderer VO) carries the same verb so callers move between the
     * two classes without renaming (vterm-09, additive-only — behaviour is
     * identical to chaining the pair).
     */
    public function at(int $row, int $col): self
    {
        return $this->withRow($row)->withCol($col);
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

    /** Visibility alias for `withVisible(false)`; mirrors {@see \SugarCraft\Vterm\Cursor::hidden()}. */
    public function hidden(): self
    {
        return $this->withVisible(false);
    }

    /** Visibility alias for `withVisible(true)`; mirrors {@see \SugarCraft\Vterm\Cursor::shown()}. */
    public function shown(): self
    {
        return $this->withVisible(true);
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
