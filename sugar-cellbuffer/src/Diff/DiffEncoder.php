<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Hyperlink;
use SugarCraft\CellBuffer\Style;

/**
 * Encodes a list of DiffOp objects into a minimal ANSI byte stream.
 *
 * DiffEncoder maintains running state — cursor position and current
 * SGR style — across ops so that redundant sequences are omitted.
 * For example, if the previous op already positioned the cursor
 * at (5, 2), a MoveCursorOp for (5, 2) is a no-op and is skipped.
 *
 * ANSI sequences emitted (per xterm ctlseqs):
 *   - CUP  \x1b[row;colH  (cursor position, 1-based; the stream always OPENS
 *                          with an unconditional home anchor \x1b[1;1H so a
 *                          frame never depends on where the terminal was
 *                          left — the same promise sugar-panels used to
 *                          patch in from outside)
 *   - ECH  \x1b[N X        (erase N chars)
 *   - REP  \x1b[N b        (repeat preceding char N times — emitted only
 *                          when RepeatGate::worth() says it beats the
 *                          literal bytes, a byte-arithmetic rule chafa's
 *                          canvas printer follows too)
 *   - SGR  \x1b[...m      (style transitions)
 *   - OSC 8;id=<id>;<url>\x1b\\    (hyperlink open, id rides the params field)
 *   - OSC 8;;<url>\x1b\\           (hyperlink open, EMPTY params field — the
 *                          spec grammar is OSC 8 ; params ; URI, so the
 *                          id-less form keeps both semicolons; single-field
 *                          spellings land the URL in params and read as a
 *                          CLOSE to conforming decoders)
 *   - OSC 8;;\x1b\\        (hyperlink close)
 *   - trailing \x1b[0m    (conditional SGR reset closing the stream when the
 *                          frame ended styled — same contract as toAnsi())
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

    /** Currently active hyperlink, tracked by VALUE (url+id) (null = none open). */
    private ?Hyperlink $currentLink = null;

    /** Last emitted rune (used to validate REP sequences). */
    private ?string $lastRune = null;

    /** Home anchor every encoded frame opens with (1-based CUP to (1,1)). */
    private const HOME_CUP = "\x1b[1;1H";

    /**
     * Encode a list of DiffOps to a raw ANSI byte string.
     *
     * Every frame starts with an unconditional home CUP: consumers return
     * these bytes verbatim to a live terminal (sugar-overlay, sugar-
     * dashboard), and a frame whose first change is (0,0) used to carry no
     * positioning at all — mis-painting whole frames wherever the terminal
     * had been left. The logical cursor starts home, so the anchor costs one
     * 6-byte CUP and buys the self-contained contract the README promises.
     *
     * @param list<DiffOp> $ops
     */
    public function encode(array $ops): string
    {
        // Reset all state at start of encode() so the instance is safe
        // to reuse across multiple diffs without stale cursor/style bleed.
        $this->cursorCol = 1;
        $this->cursorRow = 1;
        $this->currentStyle = null;
        $this->currentLink = null;
        $this->lastRune = null;

        $out = self::HOME_CUP;

        foreach ($ops as $op) {
            $out .= $this->encodeOp($op);
        }

        // Close any open hyperlink + reset SGR.
        if ($this->currentLink !== null) {
            $out .= Hyperlink::CLOSE;
            $this->currentLink = null;
        }
        // The reset half of that promise is load-bearing: sugar-overlay and
        // sugar-dashboard return encode() output verbatim to a live terminal, so
        // a frame ending on a styled cell would leave the terminal styled
        // and every following UNSTYLED write (a clear, a space, the next
        // frame's blanks) inherits the ghost colour. Buffer::toAnsi() has
        // always closed its stream conditionally the same way — the two
        // emitters must agree on end-of-stream terminal state.
        if ($this->currentStyle !== null) {
            $out .= "\x1b[0m";
            $this->currentStyle = null;
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

        // Unknown op subclasses must never vanish from the byte stream
        // silently: a seventh op type reaching an encoder that predates it
        // is a programmer error, refused loudly like every other bad data
        // path in this lib.
        throw new \InvalidArgumentException('Unencodable diff op ' . $op::class);
    }

    private function encodeMoveCursor(MoveCursorOp $op): string
    {
        // 1-based for CUP; negatives clamp to the grid edge (fail-soft render
        // path — applyDiff refuses negative coords fail-loud).
        $row = max(0, $op->row) + 1;
        $col = max(0, $op->col) + 1;

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
            // Emit hyperlink open/close if the link VALUE (url+id) changed.
            $cellLink = $cell->link();
            if (!Hyperlink::valuesEqual($cellLink, $this->currentLink)) {
                if ($this->currentLink !== null) {
                    $out .= Hyperlink::CLOSE;
                    $this->currentLink = null;
                }
                if ($cellLink !== null) {
                    $out .= $this->openHyperlink($cellLink);
                    $this->currentLink = $cellLink;
                }
            }

            // Emit SGR transition if style changed (by value, not identity).
            if (!Style::valuesEqual($cell->style(), $this->currentStyle)) {
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

        // REP repeats whatever glyph actually sits under the cursor. The
        // diff algorithm guarantees a SetCellOp is emitted before any
        // RepeatRunOp in the same diff, so $this->lastRune already matches
        // $op->rune when REP is encountered. If a hand-built op stream
        // violates that invariant, this fallback only RELABELS lastRune for
        // downstream cursor bookkeeping — it does NOT write $op->rune, so
        // the terminal would still repeat the preceding glyph. Defensive
        // only; correct diff output never reaches this branch.
        if ($op->rune !== $this->lastRune) {
            $this->lastRune = $op->rune;
        }

        $this->cursorCol += $op->count * ($op->width > 0 ? $op->width : 1);

        // Chafa gates REP on byte arithmetic too, and never
        // emits it unconditionally. Below the break-even the run is written
        // as literal repeats — same cells, same cursor advance, no escape.
        // The literal arm also happens to paint $op->rune rather than the
        // stale preceding glyph, so on the (defensive, never-reached-by-real-
        // diffs) rune-mismatch shape it is strictly MORE faithful than REP.
        if (!RepeatGate::worth($op->count, strlen($op->rune))) {
            return str_repeat($op->rune, $op->count);
        }

        return "\x1b[{$op->count}b";
    }

    private function encodeSetStyle(SetStyleOp $op): string
    {
        // The same by-value transition guard the SetCellOp write path and
        // Buffer::diff() apply: a redundant SetStyleOp (hand-built streams
        // are the documented consumer shape) must not ship duplicate SGR
        // bytes. The SetStyleOp docblock always promised this skip; here it
        // is actually true.
        if (Style::valuesEqual($this->currentStyle, $op->style)) {
            return '';
        }

        $sgr = $this->emitSgr($op->style);
        $this->currentStyle = $op->style;

        return $sgr;
    }

    private function encodeSetHyperlink(SetHyperlinkOp $op): string
    {
        if ($op->hyperlink === null) {
            if ($this->currentLink !== null) {
                $this->currentLink = null;
                return Hyperlink::CLOSE;
            }

            return '';
        }

        $this->currentLink = $op->hyperlink;

        return $this->openHyperlink($op->hyperlink);
    }

    /**
     * OSC 8 open frame for a hyperlink — delegates to {@see Hyperlink::toAnsi()},
     * the single wire-format home shared with Buffer::toAnsi() so both paths
     * emit byte-identical opens (id as the spec's `id=` parameter, and the
     * empty-params double semicolon on the id-less form).
     */
    private function openHyperlink(Hyperlink $link): string
    {
        return $link->toAnsi();
    }

    /**
     * Emit SGR sequence for a style (or reset if null).
     */
    private function emitSgr(?Style $style): string
    {
        return SgrEmitter::emit($style);
    }
}
