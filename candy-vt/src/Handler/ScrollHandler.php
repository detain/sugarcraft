<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;

/**
 * Vertical scrolling primitives — SU/SD plus IND/RI/NEL.
 *
 * Scrolling operates within a scroll region defined by DECSTBM
 * (CSI r). Defaults to the full screen when no margin is set.
 * Scrolled-off rows are dropped — there's no scrollback yet.
 */
final class ScrollHandler
{
    /**
     * Apply a scroll CSI (SU = 'S', SD = 'T').
     *
     * @param list<int> $params
     */
    public function applyCsi(int $final, array $params, Buffer $buffer, int $scrollTop, int $scrollBottom): void
    {
        $first = $params[0] ?? -1;
        $count = $first === -1 ? 1 : max(1, $first);

        match (chr($final)) {
            'S' => $this->scrollUp($buffer, $scrollTop, $scrollBottom, $count),
            'T' => $this->scrollDown($buffer, $scrollTop, $scrollBottom, $count),
            default => null,
        };
    }

    /**
     * IND (index) — move down, scroll up if at the bottom of the region.
     */
    public function index(Buffer $buffer, Cursor $cursor, int $scrollTop, int $scrollBottom): Cursor
    {
        if ($cursor->row >= $scrollBottom) {
            $this->scrollUp($buffer, $scrollTop, $scrollBottom, 1);
            return $cursor;
        }
        return $cursor->withRow($cursor->row + 1);
    }

    /**
     * RI (reverse index) — move up, scroll down if at the top of the region.
     */
    public function reverseIndex(Buffer $buffer, Cursor $cursor, int $scrollTop, int $scrollBottom): Cursor
    {
        if ($cursor->row <= $scrollTop) {
            $this->scrollDown($buffer, $scrollTop, $scrollBottom, 1);
            return $cursor;
        }
        return $cursor->withRow($cursor->row - 1);
    }

    /**
     * NEL (next line) — CR + IND.
     */
    public function nextLine(Buffer $buffer, Cursor $cursor, int $scrollTop, int $scrollBottom): Cursor
    {
        return $this->index($buffer, $cursor->withCol(0), $scrollTop, $scrollBottom);
    }

    /**
     * Scroll the region up by $count rows (SU).
     *
     * @param int $scrollTop    Top row of the scroll region (0-indexed inclusive).
     * @param int $scrollBottom Bottom row of the scroll region (0-indexed inclusive).
     */
    public function scrollUp(Buffer $buffer, int $scrollTop, int $scrollBottom, int $count): void
    {
        $height = $scrollBottom - $scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($r = $scrollTop; $r <= $scrollBottom - $count; $r++) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, $buffer->cell($r + $count, $c));
            }
        }
        for ($r = $scrollBottom - $count + 1; $r <= $scrollBottom; $r++) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, Cell::empty());
            }
        }
    }

    /**
     * Scroll the region down by $count rows (SD).
     *
     * @param int $scrollTop    Top row of the scroll region (0-indexed inclusive).
     * @param int $scrollBottom Bottom row of the scroll region (0-indexed inclusive).
     */
    public function scrollDown(Buffer $buffer, int $scrollTop, int $scrollBottom, int $count): void
    {
        $height = $scrollBottom - $scrollTop + 1;
        $count = min($count, $height);
        if ($count <= 0) {
            return;
        }

        for ($r = $scrollBottom; $r >= $scrollTop + $count; $r--) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, $buffer->cell($r - $count, $c));
            }
        }
        for ($r = $scrollTop; $r < $scrollTop + $count; $r++) {
            for ($c = 0; $c < $buffer->cols; $c++) {
                $buffer->put($r, $c, Cell::empty());
            }
        }
    }
}
