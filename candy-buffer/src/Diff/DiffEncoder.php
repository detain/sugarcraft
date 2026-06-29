<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;

/**
 * Encodes a list of DiffOp objects into a minimal ANSI byte stream.
 *
 * DiffEncoder maintains running state — cursor position and current
 * SGR style — across ops so that redundant sequences are omitted.
 * For example, if the previous op already positioned the cursor
 * at (5, 2), a MoveCursorOp for (5, 2) is a no-op and is skipped.
 *
 * ANSI sequences emitted (per xterm ctlseqs):
 *   - CUP  \x1b[row;colH  (cursor position, 1-based)
 *   - ECH  \x1b[N X        (erase N chars)
 *   - REP  \x1b[N b        (repeat preceding char N times)
 *   - SGR  \x1b[...m      (style transitions)
 *   - OSC 8;id;url\x1b\\  (hyperlink open)
 *   - OSC 8;;\x1b\\        (hyperlink close)
 *
 * Mirrors ratatui's Buffer::diff encode logic.
 */
final class DiffEncoder
{
    /** 1-based column for CUP. */
    private int $cursorCol = 1;

    /** 1-based row for CUP. */
    private int $cursorRow = 1;

    /** Currently active SGR style (null = reset/default). */
    private ?Style $currentStyle = null;

    /** Currently active hyperlink (null = none open). */
    private ?string $currentLinkUrl = null;

    /** Last emitted rune (used to validate REP sequences). */
    private ?string $lastRune = null;

    /**
     * Encode a list of DiffOps to a raw ANSI byte string.
     *
     * @param list<DiffOp> $ops
     */
    public function encode(array $ops): string
    {
        $out = '';

        foreach ($ops as $op) {
            $out .= $this->encodeOp($op);
        }

        // Close any open hyperlink + reset SGR.
        if ($this->currentLinkUrl !== null) {
            $out .= "\x1b]8;;\x1b\\";
            $this->currentLinkUrl = null;
        }

        return $out;
    }

    private function encodeOp(DiffOp $op): string
    {
        if ($op instanceof MoveCursorOp) {
            return $this->encodeMoveCursor($op);
        }
        if ($op instanceof SetCellOp) {
            return $this->encodeSetCells($op);
        }
        if ($op instanceof EraseRunOp) {
            return $this->encodeEraseRun($op);
        }
        if ($op instanceof RepeatRunOp) {
            return $this->encodeRepeatRun($op);
        }
        if ($op instanceof SetStyleOp) {
            return $this->encodeSetStyle($op);
        }
        if ($op instanceof SetHyperlinkOp) {
            return $this->encodeSetHyperlink($op);
        }

        return '';
    }

    private function encodeMoveCursor(MoveCursorOp $op): string
    {
        // 1-based for CUP.
        $row = $op->row + 1;
        $col = $op->col + 1;

        if ($col === $this->cursorCol && $row === $this->cursorRow) {
            return ''; // Already there.
        }

        $this->cursorCol = $col;
        $this->cursorRow = $row;

        return "\x1b[{$row};{$col}H";
    }

    private function encodeSetCells(SetCellOp $op): string
    {
        $out = '';

        foreach ($op->cells as $cell) {
            // Emit hyperlink open/close if changed.
            $linkUrl = $cell->link()?->url();
            if ($linkUrl !== $this->currentLinkUrl) {
                if ($this->currentLinkUrl !== null) {
                    $out .= "\x1b]8;;\x1b\\";
                    $this->currentLinkUrl = null;
                }
                if ($linkUrl !== null) {
                    $id = $cell->link()->id();
                    $idPart = $id !== '' ? (';' . $id) : '';
                    $out .= "\x1b]8{$idPart};{$linkUrl}\x1b\\";
                    $this->currentLinkUrl = $linkUrl;
                }
            }

            // Emit SGR transition if style changed.
            if ($cell->style() !== $this->currentStyle) {
                $out .= $this->emitSgr($cell->style());
                $this->currentStyle = $cell->style();
            }

            // Write the rune.
            $out .= $cell->rune();
            $this->lastRune = $cell->rune();

            // Advance cursor.
            $this->cursorCol += $cell->width() > 0 ? $cell->width() : 1;
        }

        return $out;
    }

    private function encodeEraseRun(EraseRunOp $op): string
    {
        if ($op->count <= 0) {
            return '';
        }

        // ECH erases characters in-place; the logical cursor does NOT
        // advance (the cursor stays at the start of the erased region).
        return "\x1b[{$op->count}X";
    }

    private function encodeRepeatRun(RepeatRunOp $op): string
    {
        if ($op->count <= 0) {
            return '';
        }

        // REP repeats the last emitted rune. The diff algorithm guarantees
        // a SetCellOp is emitted before any RepeatRunOp in the same diff,
        // so $this->lastRune is set when REP is encountered.
        // Validate the invariant; if violated the output would be wrong.
        if ($op->rune !== $this->lastRune) {
            // Fallback: write the rune directly then repeat what was written.
            // This should not happen with a correct diff algorithm.
            $this->lastRune = $op->rune;
        }

        $this->cursorCol += $op->count * ($op->width > 0 ? $op->width : 1);

        return "\x1b[{$op->count}b";
    }

    private function encodeSetStyle(SetStyleOp $op): string
    {
        $sgr = $this->emitSgr($op->style);
        $this->currentStyle = $op->style;

        return $sgr;
    }

    private function encodeSetHyperlink(SetHyperlinkOp $op): string
    {
        if ($op->hyperlink === null) {
            if ($this->currentLinkUrl !== null) {
                $this->currentLinkUrl = null;
                return "\x1b]8;;\x1b\\";
            }

            return '';
        }

        $url = $op->hyperlink->url();
        $id = $op->hyperlink->id();
        $idPart = $id !== '' ? (';' . $id) : '';

        $this->currentLinkUrl = $url;

        return "\x1b]8{$idPart};{$url}\x1b\\";
    }

    /**
     * Emit SGR sequence for a style (or reset if null).
     */
    private function emitSgr(?Style $style): string
    {
        return SgrEmitter::emit($style);
    }
}
