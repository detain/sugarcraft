<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
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
     * IL — insert $count blank lines starting at row $from, shifting the
     * rows below it down inside the DECSTBM region [top, bottom].
     *
     * Mirrors charmbracelet/x/vt Screen.InsertLine: a no-op when
     * `$from` lies outside the region or $count < 1; the shift never
     * touches rows above $from, and nothing is pushed to scrollback —
     * IL/DL are region-internal edits (only IND/SU scrolling feeds the
     * ring). Blank fill honours no pen attributes (plain Cell::empty —
     * upstream uses the pen's background only via its blankCell(); the
     * caller passes SGR when BCE matters, kept simple here).
     *
     * The walk itself lives in {@see Buffer::insertRows()} (E736/vt-6.4);
     * this method is its region-parameterised entry point.
     *
     * @see ECMA-48 §8.4.15 (IL)
     * @see https://vt100.net/docs/vt510-rm/IL.html
     */
    public function insertLines(Buffer $buffer, int $scrollTop, int $scrollBottom, int $from, int $count): void
    {
        $buffer->insertRows($count, $from, $scrollTop, $scrollBottom);
    }

    /**
     * DL — delete $count lines starting at row $from, pulling rows below
     * up inside the DECSTBM region; blanks land at the region bottom.
     *
     * Same region-guarding rules as {@see insertLines()}.
     *
     * The walk itself lives in {@see Buffer::deleteRows()} (E736/vt-6.4).
     *
     * @see ECMA-48 §8.4.10 (DL)
     * @see https://vt100.net/docs/vt510-rm/DL.html
     */
    public function deleteLines(Buffer $buffer, int $scrollTop, int $scrollBottom, int $from, int $count): void
    {
        $buffer->deleteRows($count, $from, $scrollTop, $scrollBottom);
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
            return; // SU/SD guard: no-op on empty movement — NOT the IL/DL one-row floor.
        }
        // Whole-region DL: the identical walk with $from pinned to the region top.
        $buffer->deleteRows($count, $scrollTop, $scrollTop, $scrollBottom);
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
            return; // Same SU/SD empty-movement guard as scrollUp().
        }
        // Whole-region IL: the identical walk with $from pinned to the region top.
        $buffer->insertRows($count, $scrollTop, $scrollTop, $scrollBottom);
    }
}
