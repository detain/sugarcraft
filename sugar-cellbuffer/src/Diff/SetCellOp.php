<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

use SugarCraft\CellBuffer\Cell;

/**
 * Write one or more cells at the current cursor position using
 * direct character output (SGR transitions are separate SetStyleOp).
 *
 * The cursor advances by the cell widths (1 for normal, 2 for wide, N
 * for run-length spans). Encoded streams write each lead rune and trust its
 * declared width for the advance; width-0 continuation cells are grid-only
 * state and are never emitted.
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
