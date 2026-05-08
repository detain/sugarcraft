<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Handler;

use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;

/**
 * Erase / delete / insert handlers — EL, ED, ECH, DCH, ICH.
 *
 * All operations mutate the {@see Buffer} in place. The cursor never
 * moves: erasing leaves it where it was. Erased cells are replaced
 * with {@see Cell::empty()}; background-colored erase ("BCE") can land
 * later if a real-world TUI needs it.
 */
final class EraseHandler
{
    /**
     * @param list<int> $params
     */
    public function apply(int $final, array $params, Buffer $buffer, Cursor $cursor): void
    {
        $first = $params[0] ?? -1;

        match (chr($final)) {
            'K' => $this->eraseInLine($buffer, $cursor, $first === -1 ? 0 : $first),
            'J' => $this->eraseInDisplay($buffer, $cursor, $first === -1 ? 0 : $first),
            'X' => $this->eraseChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first)),
            'P' => $this->deleteChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first)),
            '@' => $this->insertChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first)),
            default => null,
        };
    }

    private function eraseInLine(Buffer $buf, Cursor $cur, int $mode): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        match ($mode) {
            0 => $this->fillRow($buf, $cur->row, $cur->col, $buf->cols - 1),
            1 => $this->fillRow($buf, $cur->row, 0, $cur->col),
            2 => $this->fillRow($buf, $cur->row, 0, $buf->cols - 1),
            default => null,
        };
    }

    private function eraseInDisplay(Buffer $buf, Cursor $cur, int $mode): void
    {
        switch ($mode) {
            case 0:
                $this->fillRow($buf, $cur->row, $cur->col, $buf->cols - 1);
                for ($r = $cur->row + 1; $r < $buf->rows; $r++) {
                    $this->fillRow($buf, $r, 0, $buf->cols - 1);
                }
                return;
            case 1:
                for ($r = 0; $r < $cur->row; $r++) {
                    $this->fillRow($buf, $r, 0, $buf->cols - 1);
                }
                $this->fillRow($buf, $cur->row, 0, $cur->col);
                return;
            case 2:
                for ($r = 0; $r < $buf->rows; $r++) {
                    $this->fillRow($buf, $r, 0, $buf->cols - 1);
                }
                return;
            case 3:
                // Erase scrollback — we have no scrollback yet, so no-op.
                return;
        }
    }

    private function eraseChars(Buffer $buf, Cursor $cur, int $count): void
    {
        $end = min($buf->cols - 1, $cur->col + $count - 1);
        $this->fillRow($buf, $cur->row, $cur->col, $end);
    }

    private function deleteChars(Buffer $buf, Cursor $cur, int $count): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        $shift = min($count, $buf->cols - $cur->col);
        for ($c = $cur->col; $c + $shift < $buf->cols; $c++) {
            $buf->put($cur->row, $c, $buf->cell($cur->row, $c + $shift));
        }
        for ($c = $buf->cols - $shift; $c < $buf->cols; $c++) {
            $buf->put($cur->row, $c, Cell::empty());
        }
    }

    private function insertChars(Buffer $buf, Cursor $cur, int $count): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        $shift = min($count, $buf->cols - $cur->col);
        for ($c = $buf->cols - 1; $c >= $cur->col + $shift; $c--) {
            $buf->put($cur->row, $c, $buf->cell($cur->row, $c - $shift));
        }
        for ($c = $cur->col; $c < $cur->col + $shift; $c++) {
            $buf->put($cur->row, $c, Cell::empty());
        }
    }

    private function fillRow(Buffer $buf, int $row, int $start, int $end): void
    {
        if ($start > $end) {
            return;
        }
        for ($c = $start; $c <= $end; $c++) {
            $buf->put($row, $c, Cell::empty());
        }
    }
}
