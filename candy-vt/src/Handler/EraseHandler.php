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
 * where it was. Erased cells are replaced with a blank cell. When the
 * pen has an explicit background colour, the blank carries that
 * background and default foreground/attributes (BCE — Background Color
 * Erase, VT500 §BCE; always on for DEC models, no ?12 gate — that DEC
 * private mode is the reverse-cursor blink in xterm). The new-row blanks
 * of the line/region scrolls (IL/DL/SU/SD via ScrollHandler) intentionally
 * stay default cells — documented divergence, no known emitter depends on
 * bce-filled scroll rows, kept out of this class so every blank it writes
 * is uniform.
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
            'P' => $this->deleteChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first), $sgr, $pending),
            '@' => $this->insertChars($buffer, $cursor, $first === -1 ? 1 : max(1, $first), $sgr, $pending),
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
                // ED 3 = erase scrollback — no grid effect; the caller
                // (ScreenHandler) drains the Scrollback ring via clear().
                return;
        }
    }

    private function eraseChars(Buffer $buf, Cursor $cur, int $count, ?Sgr $sgr, ?array &$pending): void
    {
        $end = min($buf->cols - 1, $cur->col + $count - 1);
        $this->fillRow($buf, $cur->row, $cur->col, $end, $sgr, $pending);
    }

    private function deleteChars(Buffer $buf, Cursor $cur, int $count, ?Sgr $sgr, ?array &$pending): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        $shift = min($count, $buf->cols - $cur->col);
        for ($c = $cur->col; $c + $shift < $buf->cols; $c++) {
            $this->putOrQueue($buf, $cur->row, $c, $buf->cell($cur->row, $c + $shift), $pending);
        }
        $blank = $this->blankCell($sgr);
        for ($c = $buf->cols - $shift; $c < $buf->cols; $c++) {
            $this->putOrQueue($buf, $cur->row, $c, $blank, $pending);
        }
    }

    private function insertChars(Buffer $buf, Cursor $cur, int $count, ?Sgr $sgr, ?array &$pending): void
    {
        if ($cur->row < 0 || $cur->row >= $buf->rows) {
            return;
        }
        $shift = min($count, $buf->cols - $cur->col);
        for ($c = $buf->cols - 1; $c >= $cur->col + $shift; $c--) {
            $this->putOrQueue($buf, $cur->row, $c, $buf->cell($cur->row, $c - $shift), $pending);
        }
        $blank = $this->blankCell($sgr);
        for ($c = $cur->col; $c < $cur->col + $shift; $c++) {
            $this->putOrQueue($buf, $cur->row, $c, $blank, $pending);
        }
    }

    /**
     * The BCE blank: a space carrying ONLY the pen's background colour,
     * foreground/attributes default; no background set → plain empty cell.
     * Shared by ED/EL/ECH (fillRow) and the DCH/ICH shift gaps, matching
     * xterm's application of the erase colour to those operations too.
     */
    private function blankCell(?Sgr $sgr): Cell
    {
        return $sgr?->background !== null
            ? new Cell(grapheme: ' ', sgr: Sgr::empty()->withBackground($sgr->background))
            : Cell::empty();
    }

    /**
     * Fill a row range with blank cells using proper BCE (Background Color
     * Erase) semantics: an erased cell inherits ONLY the pen's background
     * colour; foreground and every attribute reset to default. Copying the
     * whole pen — bold/underline/fg included into the blanks — is the
     * classic mis-render the VT500 §BCE / xterm `bce` option warns about
     * ("erase colour is the background colour attribute").
     *
     * @param array<int, array{row: int, col: int, cell: Cell}>|null $pending
     *   When non-null, mutations are queued instead of applied.
     */
    private function fillRow(Buffer $buf, int $row, int $start, int $end, ?Sgr $sgr, ?array &$pending): void
    {
        if ($start > $end) {
            return;
        }
        $blank = $this->blankCell($sgr);
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
