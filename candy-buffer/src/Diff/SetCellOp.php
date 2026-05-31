<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

use SugarCraft\Buffer\Cell;

/**
 * Write one or more cells at the current cursor position using
 * direct character output (SGR transitions are separate SetStyleOp).
 *
 * The cursor advances by the cell widths (1 for normal, 2 for wide).
 * Wide-char cells emit the rune then a zero-width continuation cell.
 *
 * @readonly
 */
final class SetCellOp extends DiffOp
{
    /**
     * @param list<Cell> $cells Cells to write left-to-right
     */
    public function __construct(
        public readonly array $cells,
    ) {}

    /** Number of cells in this op. */
    public function count(): int
    {
        return count($this->cells);
    }
}
