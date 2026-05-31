<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

/**
 * Move cursor to ($col, $row) using CUP (Cursor Position) sequence.
 *
 * CUP is emitted as \x1b[row;colH where row and col are 1-based.
 *
 * @readonly
 */
final class MoveCursorOp extends DiffOp
{
    public function __construct(
        public readonly int $col,
        public readonly int $row,
    ) {}

    /**
     * @return array{col: int, row: int}
     */
    public function info(): array
    {
        return ['col' => $this->col, 'row' => $this->row];
    }
}
