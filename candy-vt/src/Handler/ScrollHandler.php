<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;

/**
 * Vertical scrolling primitives — SU/SD plus IND/RI/NEL.
 *
 * Operates on the full screen; per-region scroll margins (DECSTBM)
 * land in a later slice. Scrolled-off rows are dropped — there's no
 * scrollback yet.
 */
final class ScrollHandler
{
    /**
     * Apply a scroll CSI (SU = 'S', SD = 'T').
     *
     * @param list<int> $params
     */
    public function applyCsi(int $final, array $params, Buffer $buffer): void
    {
        $first = $params[0] ?? -1;
        $count = $first === -1 ? 1 : max(1, $first);

        match (chr($final)) {
            'S' => $this->scrollUp($buffer, $count),
            'T' => $this->scrollDown($buffer, $count),
            default => null,
        };
    }

    /**
     * IND (index) — move down, scroll up if at the bottom.
     */
    public function index(Buffer $buffer, Cursor $cursor): Cursor
    {
        if ($cursor->row >= $buffer->rows - 1) {
            $this->scrollUp($buffer, 1);
            return $cursor;
        }
        return $cursor->withRow($cursor->row + 1);
    }

    /**
     * RI (reverse index) — move up, scroll down if at the top.
     */
    public function reverseIndex(Buffer $buffer, Cursor $cursor): Cursor
    {
        if ($cursor->row <= 0) {
            $this->scrollDown($buffer, 1);
            return $cursor;
        }
        return $cursor->withRow($cursor->row - 1);
    }

    /**
     * NEL (next line) — CR + IND.
     */
    public function nextLine(Buffer $buffer, Cursor $cursor): Cursor
    {
        return $this->index($buffer, $cursor->withCol(0));
    }

    public function scrollUp(Buffer $buffer, int $count): void
    {
        $rows = $buffer->rows;
        $count = min($count, $rows);
        if ($count <= 0) {
            return;
        }

        for ($r = 0; $r < $rows - $count; $r++) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, $buffer->cell($r + $count, $c));
            }
        }
        for ($r = $rows - $count; $r < $rows; $r++) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, Cell::empty());
            }
        }
    }

    public function scrollDown(Buffer $buffer, int $count): void
    {
        $rows = $buffer->rows;
        $count = min($count, $rows);
        if ($count <= 0) {
            return;
        }

        for ($r = $rows - 1; $r >= $count; $r--) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, $buffer->cell($r - $count, $c));
            }
        }
        for ($r = 0; $r < $count; $r++) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, Cell::empty());
            }
        }
    }
}
