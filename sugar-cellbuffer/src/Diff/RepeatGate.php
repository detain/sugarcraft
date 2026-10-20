<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

/**
 * Byte-cost gate deciding whether a REP sequence actually pays for itself.
 *
 * A-02: chafa's flush_chars (chafa/internal/chafa-canvas-printer.c:56-92)
 * only emits ESC[#b when the literal byte cost of the run EXCEEDS the
 * encoding cost of the escape — an explicit byte-arithmetic gate, never a
 * blanket "always compress". This is that gate, factored into the one home
 * both emitters share: {@see DiffEncoder::encodeRepeatRun()} and sugar-image's
 * OutputOptimizer consult it, so a run can never be encoded one way on the
 * diff path and the other on the mosaic path.
 *
 * Real break-even, derived (this is the docblock the threshold answers):
 *  - REP cost: `"\x1b[" . count . "b"` = 3 + strlen((string)count) bytes.
 *  - Literal cost: count repetitions of the rune = count * runeBytes.
 *
 * For 1-byte runes (ASCII): count 1-3 strictly lose (4 bytes vs 1-3), count 4
 * is a byte-for-byte tie ("AA" style "AAAA" vs "\x1b[4b", both 4), and count 5
 * is the FIRST strict win (4 bytes vs 5). Whole runs therefore start paying at
 * SIX cells: a run under four cells never wins, and the tie at five keeps the
 * literal form — the tie-break prefers plain glyphs because they survive every
 * terminal, while REP is an extra capability assumption.
 *
 * For multi-byte runes the gate flips early: a 3-byte block glyph (▀, U+2580)
 * wins at count 2 (6 literal bytes vs 4), so mosaic rows of repeated half
 * blocks compress where ASCII rows of the same length stay literal — exactly
 * the asymmetry chafa's charBytes arithmetic produces.
 */
final class RepeatGate
{
    /** Bytes every REP sequence costs regardless of digit width: ESC + [ + b. */
    private const REP_FRAME_BYTES = 3;

    /**
     * Whether encoding $count repeats of a $runeBytes-wide rune as one
     * `ESC[count b` sequence is strictly smaller than repeating it literally.
     *
     * Ties answer FALSE (literal): equal size with fewer capability
     * assumptions is the better byte. Counts <= 0 have nothing to repeat.
     */
    public static function worth(int $count, int $runeBytes = 1): bool
    {
        if ($count <= 0 || $runeBytes <= 0) {
            return false;
        }

        return $count * $runeBytes > self::repByteCost($count);
    }

    /**
     * Wire size of the REP encoding for $count repeats (3 frame bytes plus the
     * decimal digits of the count). Public so the break-even ladder in
     * tests/RepeatGateTest derives its expectations instead of re-spelling
     * the arithmetic.
     */
    public static function repByteCost(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        return self::REP_FRAME_BYTES + strlen((string) $count);
    }
}
