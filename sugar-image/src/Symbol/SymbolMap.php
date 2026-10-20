<?php

declare(strict_types=1);

namespace SugarCraft\Image\Symbol;

/**
 * Coverage-class symbol engine — the glyph corpus and candidate search
 * behind the 'symbols' render tier (A-04).
 *
 * Corpus build order mirrors chafa's init_symbol_array_narrow() exactly:
 * the 127 non-wide entries of the basic block-elements/box-drawing table
 * (U+2500–U+259F), then the 256 braille dots (U+2800–U+28FF), the 59
 * teletext sextants (U+1FB00–U+1FB3A, bitmap values skip the two holes
 * 21 and 42 of the 2x3 mosaic block), and the octant set decoded via
 * chafa's 26-entry octant_map with the block-duplicate skip window
 * (only U+1CD00–U+1D000 codepoints are kept; U+2580-range octant
 * spellings are already covered by the basic table).
 *
 * PC-13: chafa's candidate search is a FLAT walk of packed 64-bit
 * bitmaps comparing popcount Hamming distance — NOT a hash lookup —
 * and the per-pixel coverage a symbol paints is BINARY 0/1 read out of
 * that bitmap. The 8-bit 0..255 coverage ramp exists only in the
 * pre-threshold stage: the runtime conversion thresholds it at cov > 127
 * (chafa/chafa-symbol-map.c:240), while the corpus generators pack a
 * truthy coverage.
 * findCandidates() reproduces chafa's insertion-sorted 8-slot buffer
 * statement-for-statement, tie order included: equal distances keep the
 * earlier-scanned symbol first, and the inverse pass (hd' = 64-hd) is
 * gated against the tail slot exactly like the direct pass.
 *
 * PC-14 (fixed-palette quantize-before-scoring) lives in
 * {@see CanvasMode}; the work-factor top-K clamp lives in the renderer
 * that consumes this map.
 *
 * Mirrors chafa.ChafaSymbolMap (chafa/chafa-symbol-map.c, chafa/internal/chafa-symbols.c).
 *
 * A-05b note: chafa's fill stipple map (apply_fill) consumes per-cell
 * FILL flags produced upstream by the pre-renderer; sugar-image cells
 * carry no such flags, so the ░▒▓ stipple glyphs ride the corpus as
 * ordinary candidates and the fill-map selection semantics stay a seam.
 *
 * A-09 design note (recorded, NOT implemented): chafa carries wide
 * glyphs as ChafaSymbol2 sym[2] pairs — one 16x8-pixel cell split into
 * two 8x8 halves, scored as hd[0] + hd[1] (inverse against 128). The
 * symbol renderer trials wide-vs-narrow with a four-cell lookback ring
 * and replaces the run when the summed error drops; the printer then
 * emits the lead cell and skips c==0 continuation cells, adding a space
 * when a transparent cell precedes a continuation. The right primitive
 * already exists here: sugar-cellbuffer Cell::continuation(); what is
 * missing is double-width emission bookkeeping in OutputOptimizer.
 */
final class SymbolMap
{
    /** Binary-search map of octant bit patterns to codepoint data (chafa-symbols.c). */
    private const OCTANT_MAP = [
        [0x00, 0x00], [0x01, 0xa8], [0x02, 0xab], [0x03, 0xc2],
        [0x05, 0x98], [0x0a, 0x9d], [0x0f, 0x80], [0x14, 0xe6],
        [0x28, 0xe7], [0x3f, 0xc5], [0x40, 0xa3], [0x50, 0x96],
        [0x55, 0x8c], [0x5a, 0x9e], [0x5f, 0x9b], [0x80, 0xa0],
        [0xa0, 0x97], [0xa5, 0x9a], [0xaa, 0x90], [0xaf, 0x9c],
        [0xc0, 0x82], [0xf0, 0x84], [0xf5, 0x99], [0xfa, 0x9f],
        [0xfc, 0x86], [0xff, 0x88],
    ];

    /**
     * The 127 non-wide entries of chafa's basic symbol table, in codepoint
     * order; each value is the 8x8 outline, rows joined by '|' ('X' = set).
     */
    private const BASIC = [
        0x2500 => '        |        |        |        |XXXXXXXX|        |        |        ',
        0x2501 => '        |        |        |XXXXXXXX|XXXXXXXX|        |        |        ',
        0x2502 => '    X   |    X   |    X   |    X   |    X   |    X   |    X   |    X   ',
        0x2503 => '   XX   |   XX   |   XX   |   XX   |   XX   |   XX   |   XX   |   XX   ',
        0x2504 => '        |        |        |        |XX XX XX|        |        |        ',
        0x2505 => '        |        |        |XX XX XX|XX XX XX|        |        |        ',
        0x2506 => '    X   |    X   |        |    X   |    X   |        |    X   |    X   ',
        0x2507 => '   XX   |   XX   |        |   XX   |   XX   |        |   XX   |   XX   ',
        0x2508 => '        |        |        |        |X X X X |        |        |        ',
        0x2509 => '        |        |        |X X X X |X X X X |        |        |        ',
        0x250a => '    X   |        |    X   |        |    X   |        |    X   |        ',
        0x250b => '   XX   |        |   XX   |        |   XX   |        |   XX   |        ',
        0x250c => '        |        |        |        |    XXXX|    X   |    X   |    X   ',
        0x250d => '        |        |        |    XXXX|    XXXX|    X   |    X   |    X   ',
        0x250e => '        |        |        |        |   XXXXX|   XX   |   XX   |   XX   ',
        0x250f => '        |        |        |   XXXXX|   XXXXX|   XX   |   XX   |   XX   ',
        0x2510 => '        |        |        |        |XXXXX   |    X   |    X   |    X   ',
        0x2511 => '        |        |        |XXXXX   |XXXXX   |    X   |    X   |    X   ',
        0x2512 => '        |        |        |        |XXXXX   |   XX   |   XX   |   XX   ',
        0x2513 => '        |        |        |XXXXX   |XXXXX   |   XX   |   XX   |   XX   ',
        0x2514 => '    X   |    X   |    X   |    X   |    XXXX|        |        |        ',
        0x2515 => '    X   |    X   |    X   |    XXXX|    XXXX|        |        |        ',
        0x2516 => '   XX   |   XX   |   XX   |   XX   |   XXXXX|        |        |        ',
        0x2517 => '   XX   |   XX   |   XX   |   XXXXX|   XXXXX|        |        |        ',
        0x2518 => '    X   |    X   |    X   |    X   |XXXXX   |        |        |        ',
        0x2519 => '    X   |    X   |    X   |XXXXX   |XXXXX   |        |        |        ',
        0x251a => '   XX   |   XX   |   XX   |   XX   |XXXXX   |        |        |        ',
        0x251b => '   XX   |   XX   |   XX   |XXXXX   |XXXXX   |        |        |        ',
        0x251c => '    X   |    X   |    X   |    X   |    XXXX|    X   |    X   |    X   ',
        0x251d => '    X   |    X   |    X   |    XXXX|    XXXX|    X   |    X   |    X   ',
        0x251e => '   XX   |   XX   |   XX   |   XX   |   XXXXX|    X   |    X   |    X   ',
        0x251f => '    X   |    X   |    X   |    X   |   XXXXX|   XX   |   XX   |   XX   ',
        0x2520 => '   XX   |   XX   |   XX   |   XX   |   XXXXX|   XX   |   XX   |   XX   ',
        0x2521 => '   XX   |   XX   |   XX   |   XXXXX|   XXXXX|    X   |    X   |    X   ',
        0x2522 => '    X   |    X   |    X   |   XXXXX|   XXXXX|   XX   |   XX   |   XX   ',
        0x2523 => '   XX   |   XX   |   XX   |   XXXXX|   XXXXX|   XX   |   XX   |   XX   ',
        0x2524 => '    X   |    X   |    X   |    X   |XXXXX   |    X   |    X   |    X   ',
        0x2525 => '    X   |    X   |    X   |XXXXX   |XXXXX   |    X   |    X   |    X   ',
        0x2526 => '   XX   |   XX   |   XX   |   XX   |XXXXX   |    X   |    X   |    X   ',
        0x2527 => '    X   |    X   |    X   |    X   |XXXXX   |   XX   |   XX   |   XX   ',
        0x2528 => '   XX   |   XX   |   XX   |   XX   |XXXXX   |   XX   |   XX   |   XX   ',
        0x2529 => '   XX   |   XX   |   XX   |XXXXX   |XXXXX   |    X   |    X   |    X   ',
        0x252a => '    X   |    X   |    X   |XXXXX   |XXXXX   |   XX   |   XX   |   XX   ',
        0x252b => '   XX   |   XX   |   XX   |XXXXX   |XXXXX   |   XX   |   XX   |   XX   ',
        0x252c => '        |        |        |        |XXXXXXXX|    X   |    X   |    X   ',
        0x252d => '        |        |        |XXXXX   |XXXXXXXX|    X   |    X   |    X   ',
        0x252e => '        |        |        |    XXXX|XXXXXXXX|    X   |    X   |    X   ',
        0x252f => '        |        |        |XXXXXXXX|XXXXXXXX|    X   |    X   |    X   ',
        0x2530 => '        |        |        |        |XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2531 => '        |        |        |XXXXX   |XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2532 => '        |        |        |   XXXXX|XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2533 => '        |        |        |XXXXXXXX|XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2534 => '    X   |    X   |    X   |    X   |XXXXXXXX|        |        |        ',
        0x2535 => '    X   |    X   |    X   |XXXXX   |XXXXXXXX|        |        |        ',
        0x2536 => '    X   |    X   |    X   |    XXXX|XXXXXXXX|        |        |        ',
        0x2537 => '    X   |    X   |    X   |XXXXXXXX|XXXXXXXX|        |        |        ',
        0x2538 => '   XX   |   XX   |   XX   |   XX   |XXXXXXXX|        |        |        ',
        0x2539 => '   XX   |   XX   |   XX   |XXXXX   |XXXXXXXX|        |        |        ',
        0x253a => '   XX   |   XX   |   XX   |   XXXXX|XXXXXXXX|        |        |        ',
        0x253b => '   XX   |   XX   |   XX   |XXXXXXXX|XXXXXXXX|        |        |        ',
        0x253c => '    X   |    X   |    X   |    X   |XXXXXXXX|    X   |    X   |    X   ',
        0x253d => '    X   |    X   |    X   |XXXXX   |XXXXXXXX|    X   |    X   |    X   ',
        0x253e => '    X   |    X   |    X   |    XXXX|XXXXXXXX|    X   |    X   |    X   ',
        0x253f => '    X   |    X   |    X   |XXXXXXXX|XXXXXXXX|    X   |    X   |    X   ',
        0x2540 => '   XX   |   XX   |   XX   |   XX   |XXXXXXXX|    X   |    X   |    X   ',
        0x2541 => '    X   |    X   |    X   |    X   |XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2542 => '   XX   |   XX   |   XX   |   XX   |XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2543 => '   XX   |   XX   |   XX   |XXXXX   |XXXXXXXX|    X   |    X   |    X   ',
        0x2544 => '   XX   |   XX   |   XX   |   XXXXX|XXXXXXXX|    X   |    X   |    X   ',
        0x2545 => '    X   |    X   |    X   |XXXXX   |XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2546 => '    X   |    X   |    X   |   XXXXX|XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2547 => '   XX   |   XX   |   XX   |XXXXXXXX|XXXXXXXX|    X   |    X   |    X   ',
        0x2548 => '    X   |    X   |    X   |XXXXXXXX|XXXXXXXX|   XX   |   XX   |   XX   ',
        0x2549 => '   XX   |   XX   |   XX   |XXXXX   |XXXXXXXX|   XX   |   XX   |   XX   ',
        0x254a => '   XX   |   XX   |   XX   |   XXXXX|XXXXXXXX|   XX   |   XX   |   XX   ',
        0x254b => '   XX   |   XX   |   XX   |XXXXXXXX|XXXXXXXX|   XX   |   XX   |   XX   ',
        0x254c => '        |        |        |        |XXX  XXX|        |        |        ',
        0x254d => '        |        |        |XXX  XXX|XXX  XXX|        |        |        ',
        0x254e => '    X   |    X   |    X   |        |        |    X   |    X   |    X   ',
        0x254f => '   XX   |   XX   |   XX   |        |        |   XX   |   XX   |   XX   ',
        0x2571 => '       X|      X |     X  |    X   |   X    |  X     | X      |X       ',
        0x2572 => 'X       | X      |  X     |   X    |    X   |     X  |      X |       X',
        0x2573 => 'X      X| X    X |  X  X  |   XX   |   XX   |  X  X  | X    X |X      X',
        0x2574 => '        |        |        |        |XXXX    |        |        |        ',
        0x2575 => '    X   |    X   |    X   |    X   |        |        |        |        ',
        0x2576 => '        |        |        |        |    XXXX|        |        |        ',
        0x2577 => '        |        |        |        |    X   |    X   |    X   |    X   ',
        0x2578 => '        |        |        |XXXX    |XXXX    |        |        |        ',
        0x2579 => '   XX   |   XX   |   XX   |   XX   |        |        |        |        ',
        0x257a => '        |        |        |    XXXX|    XXXX|        |        |        ',
        0x257b => '        |        |        |        |   XX   |   XX   |   XX   |   XX   ',
        0x257c => '        |        |        |    XXXX|XXXXXXXX|        |        |        ',
        0x257d => '    X   |    X   |    X   |    X   |   XX   |   XX   |   XX   |   XX   ',
        0x257e => '        |        |        |XXXX    |XXXXXXXX|        |        |        ',
        0x257f => '   XX   |   XX   |   XX   |   XX   |    X   |    X   |    X   |    X   ',
        0x2580 => 'XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|        |        |        |        ',
        0x2581 => '        |        |        |        |        |        |        |XXXXXXXX',
        0x2582 => '        |        |        |        |        |        |XXXXXXXX|XXXXXXXX',
        0x2583 => '        |        |        |        |        |XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x2584 => '        |        |        |        |XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x2585 => '        |        |        |XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x2586 => '        |        |XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x2587 => '        |XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x2588 => 'XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x2589 => 'XXXXXXX |XXXXXXX |XXXXXXX |XXXXXXX |XXXXXXX |XXXXXXX |XXXXXXX |XXXXXXX ',
        0x258a => 'XXXXXX  |XXXXXX  |XXXXXX  |XXXXXX  |XXXXXX  |XXXXXX  |XXXXXX  |XXXXXX  ',
        0x258b => 'XXXXX   |XXXXX   |XXXXX   |XXXXX   |XXXXX   |XXXXX   |XXXXX   |XXXXX   ',
        0x258c => 'XXXX    |XXXX    |XXXX    |XXXX    |XXXX    |XXXX    |XXXX    |XXXX    ',
        0x258d => 'XXX     |XXX     |XXX     |XXX     |XXX     |XXX     |XXX     |XXX     ',
        0x258e => 'XX      |XX      |XX      |XX      |XX      |XX      |XX      |XX      ',
        0x258f => 'X       |X       |X       |X       |X       |X       |X       |X       ',
        0x2590 => '    XXXX|    XXXX|    XXXX|    XXXX|    XXXX|    XXXX|    XXXX|    XXXX',
        0x2591 => 'X   X   |  X   X |X   X   |  X   X |X   X   |  X   X |X   X   |  X   X ',
        0x2592 => 'X X X X | X X X X|X X X X | X X X X|X X X X | X X X X|X X X X | X X X X',
        0x2593 => ' XXX XXX|XX XXX X| XXX XXX|XX XXX X| XXX XXX|XX XXX X| XXX XXX|XX XXX X',
        0x2594 => 'XXXXXXXX|        |        |        |        |        |        |        ',
        0x2595 => '       X|       X|       X|       X|       X|       X|       X|       X',
        0x2596 => '        |        |        |        |XXXX    |XXXX    |XXXX    |XXXX    ',
        0x2597 => '        |        |        |        |    XXXX|    XXXX|    XXXX|    XXXX',
        0x2598 => 'XXXX    |XXXX    |XXXX    |XXXX    |        |        |        |        ',
        0x2599 => 'XXXX    |XXXX    |XXXX    |XXXX    |XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
        0x259a => 'XXXX    |XXXX    |XXXX    |XXXX    |    XXXX|    XXXX|    XXXX|    XXXX',
        0x259b => 'XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXX    |XXXX    |XXXX    |XXXX    ',
        0x259c => 'XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|    XXXX|    XXXX|    XXXX|    XXXX',
        0x259d => '    XXXX|    XXXX|    XXXX|    XXXX|        |        |        |        ',
        0x259e => '    XXXX|    XXXX|    XXXX|    XXXX|XXXX    |XXXX    |XXXX    |XXXX    ',
        0x259f => '    XXXX|    XXXX|    XXXX|    XXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX|XXXXXXXX',
    ];

    /** @var list<SymbolDef> */
    private readonly array $defs;

    private function __construct()
    {
        $defs = [];

        foreach (self::BASIC as $cp => $outline) {
            $defs[] = SymbolDef::new(
                self::chr($cp),
                self::coverageToBitmap(self::outlineToCoverage($outline)),
                self::basicTags($cp),
            );
        }

        for ($cp = 0x2800; $cp < 0x2900; $cp++) {
            $defs[] = SymbolDef::new(
                self::chr($cp),
                self::coverageToBitmap(self::brailleCoverage($cp - 0x2800)),
                SymbolTags::BRAILLE,
            );
        }

        for ($cp = 0x1FB00; $cp < 0x1FB3B; $cp++) {
            $val = $cp - 0x1FB00 + 1;
            if ($val > 20) {
                $val++;
            }
            if ($val > 41) {
                $val++;
            }
            $defs[] = SymbolDef::new(
                self::chr($cp),
                self::coverageToBitmap(self::sextantCoverage($val)),
                SymbolTags::LEGACY | SymbolTags::SEXTANT,
            );
        }

        for ($oct = 0; $oct < 256; $oct++) {
            $cp = self::octantBitsToUnichar($oct);
            // Skip block symbols; the basic table already owns those.
            if ($cp < 0x1CD00 || $cp > 0x1D000) {
                continue;
            }
            $defs[] = SymbolDef::new(
                self::chr($cp),
                self::coverageToBitmap(self::octantBitsToCoverage($oct)),
                SymbolTags::LEGACY | SymbolTags::OCTANT,
            );
        }

        $this->defs = $defs;
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The full corpus in build order (basic, braille, sextant, octant).
     *
     * @return list<SymbolDef>
     */
    public function defs(): array
    {
        return $this->defs;
    }

    /**
     * chafa's find_candidates(): a flat popcount-Hamming walk of every
     * corpus bitmap maintaining an insertion-sorted window of the eight
     * best (symbol, inverted) trials. Equal distances keep scan order;
     * an inverse trial (64 - hd) rides the same buffer.
     *
     * @return list<array{0:SymbolDef, 1:bool}> sorted ascending by distance
     */
    public function findCandidates(int $bitmap, bool $allowInverse, int $limit): array
    {
        // 8 slots seeded {index 0, hd 65, inverted false} exactly as chafa does.
        $cand = array_fill(0, 8, [0, 65, false]);

        foreach ($this->defs as $i => $def) {
            $hd = self::popcount($bitmap ^ $def->bits);
            if ($hd < $cand[7][1]) {
                self::insertCandidate($cand, [$i, $hd, false]);
            }
            if ($allowInverse) {
                $hd = 64 - $hd;
                if ($hd < $cand[7][1]) {
                    self::insertCandidate($cand, [$i, $hd, true]);
                }
            }
        }

        $valid = 0;
        foreach ($cand as $slot) {
            if ($slot[1] > 64) {
                break;
            }
            $valid++;
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('candidate limit must be >= 1, got ' . $limit);
        }

        $out = [];
        $n = min($valid, $limit);
        for ($k = 0; $k < $n; $k++) {
            $out[] = [$this->defs[$cand[$k][0]], $cand[$k][2]];
        }

        return $out;
    }

    /**
     * chafa coverage_to_bitmap(): row-major MSB-first packing of the
     * 8x8 coverage, pixel (x,y) landing on bit 63-(y*8+x). Two faces:
     * the corpus generators (outline 255/0, braille/sextant/octant
     * 1/0) use the truthy form exactly like chafa's internal one; the
     * runtime conversion of a 0..255 pixel ramp passes $threshold = 127
     * (chafa/chafa-symbol-map.c:240) — the single place the 8-bit ramp
     * becomes binary (PC-13).
     *
     * @param list<int> $coverage 64 bytes, 0..255 each
     */
    public static function coverageToBitmap(array $coverage, ?int $threshold = null): int
    {
        if (count($coverage) !== 64) {
            throw new \InvalidArgumentException('symbol coverage must hold exactly 64 pixels, got ' . count($coverage));
        }

        $bitmap = 0;
        foreach ($coverage as $cov) {
            $on = $threshold === null ? $cov !== 0 : $cov > $threshold;
            $bitmap = ($bitmap << 1) | ($on ? 1 : 0);
        }

        return $bitmap;
    }

    /**
     * chafa bitmap_to_bytes(): the binary coverage bytes painted by a
     * bitmap, index = y*8+x (the same bit order as coverageToBitmap).
     *
     * @return list<int> 64 bytes, each 0 or 1
     */
    public static function coverageOf(int $bitmap): array
    {
        $cov = [];
        for ($i = 0; $i < 64; $i++) {
            $cov[] = ($bitmap >> (63 - $i)) & 1;
        }

        return $cov;
    }

    /**
     * @param array{0:int, 1:int, 2:bool} $entry
     * @param array{0:int, 1:int, 2:bool} $cand
     */
    private static function insertCandidate(array &$cand, array $entry): void
    {
        // Walk from slot 6 down; the first slot whose distance is <= the
        // new distance places the new entry right after it (stable ties),
        // otherwise it lands at 0. Tail slot 7 is shadow work — chafa's
        // insertion point never exceeds it because the gate tested < cand[7].
        for ($i = 6; $i >= 0; $i--) {
            if ($entry[1] >= $cand[$i][1]) {
                for ($j = 7; $j > $i + 1; $j--) {
                    $cand[$j] = $cand[$j - 1];
                }
                $cand[$i + 1] = $entry;

                return;
            }
        }

        for ($j = 7; $j > 0; $j--) {
            $cand[$j] = $cand[$j - 1];
        }
        $cand[0] = $entry;
    }

    private static function popcount(int $x): int
    {
        // Sign bit handled by test-then-mask: x & (x-1) at PHP_INT_MIN
        // overflows to float and wedges the Kernighan loop.
        $n = ($x >> 63) & 1;
        $x &= PHP_INT_MAX;
        while ($x !== 0) {
            $x &= $x - 1;
            $n++;
        }

        return $n;
    }

    /**
     * @return list<int>
     */
    private static function outlineToCoverage(string $outline): array
    {
        $cov = [];
        foreach (explode('|', $outline) as $row) {
            for ($u = 0; $u < 8; $u++) {
                $cov[] = $row[$u] === 'X' ? 255 : 0;
            }
        }

        return $cov;
    }

    /** gen_braille_sym: dots 1-3 left col bands, 4-6 right col bands, 7-8 fourth band. */
    private static function brailleCoverage(int $val): array
    {
        $cov = array_fill(0, 64, 0);

        $left = $val & 1;
        $right = ($val >> 3) & 1;
        $cov[1] = $cov[2] = $left;
        $cov[5] = $cov[6] = $right;

        $left = ($val >> 1) & 1;
        $right = ($val >> 4) & 1;
        $cov[17] = $cov[18] = $left;
        $cov[21] = $cov[22] = $right;

        $left = ($val >> 2) & 1;
        $right = ($val >> 5) & 1;
        $cov[33] = $cov[34] = $left;
        $cov[37] = $cov[38] = $right;

        $left = ($val >> 6) & 1;
        $right = ($val >> 7) & 1;
        $cov[49] = $cov[50] = $left;
        $cov[53] = $cov[54] = $right;

        return $cov;
    }

    /** gen_sextant_sym: 3x2 grid, bit y*2+x; each sextant paints 3 px rows x 4 px cols, middle row shared. */
    private static function sextantCoverage(int $val): array
    {
        $cov = array_fill(0, 64, 0);
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 2; $x++) {
                if (!($val & (1 << ($y * 2 + $x)))) {
                    continue;
                }
                for ($v = 0; $v < 3; $v++) {
                    $row = $y * 3 + $v;
                    if ($row > 3) {
                        $row--;
                    }
                    for ($u = 0; $u < 4; $u++) {
                        $cov[$row * 8 + $x * 4 + $u] = 1;
                    }
                }
            }
        }

        return $cov;
    }

    /** find_unicode_octant_map_data: binary search; miss yields -(insertion point). */
    private static function findOctantData(int $bits): int
    {
        $map = self::OCTANT_MAP;
        $first = 0;
        $last = count($map);
        while ($first < $last) {
            $mid = intdiv($first + $last, 2);
            if ($map[$mid][0] < $bits) {
                $first = $mid + 1;
            } elseif ($map[$mid][0] > $bits) {
                $last = $mid;
            } else {
                return $map[$mid][1];
            }
        }

        return -$first;
    }

    /** octant_bits_to_unichar, verbatim decode including the skip-window-relevant shapes. */
    private static function octantBitsToUnichar(int $bits): int
    {
        $data = self::findOctantData($bits);

        if ($data < 0) {
            return (($bits + $data) & 0xFFFFFFFF) | 0x1CD00;
        }

        if ($data === 0x00) {
            return 0x00A0;
        }

        return match (($data >> 5) & 0x3) {
            0 => ($data & 0x1F) | 0x2580,
            1 => ($data & 0x1F) | 0x1CEA0,
            2 => ($data & 0x1F) | 0x1FB80,
            default => ($data & 0x1F) | 0x1FBE6,
        };
    }

    /** octant_bits_to_coverage: 4 bands of 2 px rows x 2 cols of 4 px; bit (y&~1)+((x>>2)&1). */
    private static function octantBitsToCoverage(int $bits): array
    {
        $cov = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bit = ($y & ~1) + (($x >> 2) & 1);
                $cov[] = ($bits >> $bit) & 1;
            }
        }

        return $cov;
    }

    /**
     * Informational tags for the basic table (v1 does not filter on tags);
     * transcribed from chafa's own per-glyph tag lists in
     * chafa/internal/chafa-symbols-block.h (v1 does not filter on tags).
     */
    private static function basicTags(int $cp): int
    {
        // Block glyph family 0x2580-0x259F: chafa's exact per-codepoint tags.
        if ($cp >= 0x2580 && $cp <= 0x259F) {
            return match (true) {
                $cp === 0x2580 => SymbolTags::BLOCK | SymbolTags::VHALF | SymbolTags::QUAD | SymbolTags::OCTANT | SymbolTags::INVERTED,
                $cp === 0x2584 => SymbolTags::BLOCK | SymbolTags::VHALF | SymbolTags::QUAD | SymbolTags::OCTANT,
                $cp === 0x2582 || $cp === 0x2586 => SymbolTags::BLOCK | SymbolTags::OCTANT,
                $cp === 0x2588 => SymbolTags::BLOCK | SymbolTags::SOLID | SymbolTags::QUAD | SymbolTags::SEXTANT | SymbolTags::OCTANT,
                $cp === 0x258C => SymbolTags::BLOCK | SymbolTags::HHALF | SymbolTags::QUAD | SymbolTags::SEXTANT | SymbolTags::OCTANT,
                $cp === 0x2590 => SymbolTags::BLOCK | SymbolTags::HHALF | SymbolTags::QUAD | SymbolTags::SEXTANT | SymbolTags::OCTANT | SymbolTags::INVERTED,
                $cp >= 0x2591 && $cp <= 0x2593 => SymbolTags::STIPPLE,
                $cp === 0x2594 || $cp === 0x2595 => SymbolTags::BLOCK | SymbolTags::INVERTED,
                \in_array($cp, [0x2596, 0x2597, 0x2598, 0x259A, 0x259D], true) => SymbolTags::BLOCK | SymbolTags::QUAD | SymbolTags::OCTANT,
                \in_array($cp, [0x2599, 0x259B, 0x259C, 0x259E, 0x259F], true) => SymbolTags::BLOCK | SymbolTags::QUAD | SymbolTags::OCTANT | SymbolTags::INVERTED,
                default => SymbolTags::BLOCK,
            };
        }

        if (\in_array($cp, [0x2504, 0x2505, 0x2506, 0x2507, 0x2508, 0x2509, 0x250A, 0x250B,
            0x254C, 0x254D, 0x254E, 0x254F, 0x2574, 0x2575, 0x2576, 0x2577, 0x2578, 0x2579,
            0x257A, 0x257B], true)) {
            return SymbolTags::BORDER | SymbolTags::DOT;
        }

        if ($cp >= 0x2571 && $cp <= 0x2573) {
            return SymbolTags::BORDER | SymbolTags::DIAGONAL;
        }

        return SymbolTags::BORDER;
    }

    private static function chr(int $codepoint): string
    {
        $char = mb_chr($codepoint, 'UTF-8');
        if ($char === false) {
            throw new \InvalidArgumentException('invalid codepoint ' . $codepoint);
        }

        return $char;
    }
}
