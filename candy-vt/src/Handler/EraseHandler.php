<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Sgr\Sgr;

/**
 * Erase / delete / insert handlers — EL, ED, ECH, DCH, ICH.
 *
 * All operations mutate the {@see Buffer} in place (or queue into
 * $pending when non-null). The cursor never moves: erasing leaves it
 * where it was. Erased cells are replaced with a blank cell. When a
 * non-null {@see Sgr} with a background color is provided, the blank cell
 * carries that background (BCE — Background Color Erase, CSI ?12 h/l).
 *
 * When $pending is provided (synchronized-output DEC 2026 mode), all
 * cell writes are appended to the array instead of applied to the
 * buffer, letting ScreenHandler flush them atomically on mode exit.
 */
final class EraseHandler
{
    /**
     * @param list<int> $params
     * @param Sgr|null $sgr Current SGR pen; used to carry forward the
     *   background color when filling erased cells (BCE).
     * @param array<int, array{row: int, col: int, cell: Cell}>|null $pending
     *   When non-null, mutations are appended here instead of written to buffer.
     */
    public function apply(int $final, array $params, Buffer $buffer, Cursor $cursor, ?Sgr $sgr = null, ?array &$pending = null): void
    {
        $first = $params[0] ?? -1;

        match (chr($final)) {
            'K' => $this->eraseInLine($buffer, $cursor, $first === -1 ? 0 : $first, $sgr, $pending),
            'J' => $this->eraseInDisplay($buffer, $cursor, $first === -1 ? 0 : $first, $sgr, $pending),
            'X' => $this->eraseChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first), $sgr, $pending),
            'P' => $this->deleteChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first), $pending),
            '@' => $this->insertChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first), $pending),
            default => null,
        };
    }

    private function eraseInLine(Buffer $buf, Cursor $cur, int $mode, ?Sgr $sgr, ?array &$pending): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        match ($mode) {
            0 => $this->fillRow($buf, $cur->row, $cur->col, $buf->cols - 1, $sgr, $pending),
            1 => $this->fillRow($buf, $cur->row, 0, $cur->col, $sgr, $pending),
            2 => $this->fillRow($buf, $cur->row, 0, $buf->cols - 1, $sgr, $pending),
            default => null,
        };
    }

    private function eraseInDisplay(Buffer $buf, Cursor $cur, int $mode, ?Sgr $sgr, ?array &$pending): void
    {
        switch ($mode) {
            case 0:
                $this->fillRow($buf, $cur->row, $cur->col, $buf->cols - 1, $sgr, $pending);
                for ($r = $cur->row + 1; $r < $buf->rows; $r++) {
                    $this->fillRow($buf, $r, 0, $buf->cols - 1, $sgr, $pending);
                }
                return;
            case 1:
                for ($r = 0; $r < $cur->row; $r++) {
                    $this->fillRow($buf, $r, 0, $buf->cols - 1, $sgr, $pending);
                }
                $this->fillRow($buf, $cur->row, 0, $cur->col, $sgr, $pending);
                return;
            case 2:
                for ($r = 0; $r < $buf->rows; $r++) {
                    $this->fillRow($buf, $r, 0, $buf->cols - 1, $sgr, $pending);
                }
                return;
            case 3:
                // Erase scrollback — handled by caller via ScreenHandler.
                return;
        }
    }

    private function eraseChars(Buffer $buf, Cursor $cur, int $count, ?Sgr $sgr, ?array &$pending): void
    {
        $end = min($buf->cols - 1, $cur->col + $count - 1);
        $this->fillRow($buf, $cur->row, $cur->col, $end, $sgr, $pending);
    }

    private function deleteChars(Buffer $buf, Cursor $cur, int $count, ?array &$pending): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        $shift = min($count, $buf->cols - $cur->col);
        for ($c = $cur->col; $c + $shift < $buf->cols; $c++) {
            $this->putOrQueue($buf, $cur->row, $c, $buf->cell($cur->row, $c + $shift), $pending);
        }
        for ($c = $buf->cols - $shift; $c < $buf->cols; $c++) {
            $this->putOrQueue($buf, $cur->row, $c, Cell::empty(), $pending);
        }
    }

    private function insertChars(Buffer $buf, Cursor $cur, int $count, ?array &$pending): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        $shift = min($count, $buf->cols - $cur->col);
        for ($c = $buf->cols - 1; $c >= $cur->col + $shift; $c--) {
            $this->putOrQueue($buf, $cur->row, $c, $buf->cell($cur->row, $c - $shift), $pending);
        }
        for ($c = $cur->col; $c < $cur->col + $shift; $c++) {
            $this->putOrQueue($buf, $cur->row, $c, Cell::empty(), $pending);
        }
    }

    /**
     * Fill a row range with blank cells, optionally carrying the current
     * background color (BCE — Background Color Erase).
     *
     * @param array<int, array{row: int, col: int, cell: Cell}>|null $pending
     *   When non-null, mutations are queued instead of applied.
     */
    private function fillRow(Buffer $buf, int $row, int $start, int $end, ?Sgr $sgr, ?array &$pending): void
    {
        if ($start > $end) {
            return;
        }
        $blank = $sgr?->background !== null
            ? new Cell(grapheme: ' ', sgr: $sgr)
            : Cell::empty();
        for ($c = $start; $c <= $end; $c++) {
            $this->putOrQueue($buf, $row, $c, $blank, $pending);
        }
    }

    /**
     * Write to the buffer directly, or queue into $pending when
     * synchronized-output (DEC 2026) mode defers mutations.
     *
     * @param array<int, array{row: int, col: int, cell: Cell}>|null $pending
     */
    private function putOrQueue(Buffer $buf, int $row, int $col, Cell $cell, ?array &$pending): void
    {
        if ($pending !== null) {
            $pending[] = ['row' => $row, 'col' => $col, 'cell' => $cell];
            return;
        }
        $buf->put($row, $col, $cell);
    }
}
