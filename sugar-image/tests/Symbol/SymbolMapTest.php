<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolDef;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolTags;

/**
 * A-04/F-17: coverage-class symbol engine pins (plan_chafa S6.2, W10).
 *
 * The braille / sextant / octant / eighth-block bitmaps are checked against
 * oracles re-derived in-test from the Unicode character properties
 * themselves (dot cell positions, the 2x3 sextant grid with its two shared
 * middle rows, the octant 4x2 band layout, the eighth-block FRACTION in the
 * character NAME) — never from the port's own generator, so a transcription
 * slip here cannot validate itself. The ported ASCII/Latin/misc/legacy
 * tables are checked structurally plus against semantic spot facts (space
 * empty, '.' bottom-only, 'o' a ring) because their outlines are hand-drawn
 * font approximations with no independent oracle.
 */
final class SymbolMapTest extends TestCase
{
    private const BRILLE_DOT_CELLS = [
        // dot number => [px row, px col] (each dot is one 2-px row segment)
        1 => [0, 1],
        2 => [2, 1],
        3 => [4, 1],
        4 => [0, 5],
        5 => [2, 5],
        6 => [4, 5],
        7 => [6, 1],
        8 => [6, 5],
    ];

    /**
     * U+1FB80–U+1FB8B eighth-block names, from the Unicode 15.0 chart.
     * The oracle parses the FRACTION out of these names and derives the
     * bitmap itself — the implementation's (axis, eighths) table is never
     * consulted.
     */
    private const EIGHTH_BLOCK_NAMES = [
        0x1FB80 => 'UPPER AND LOWER ONE EIGHTH BLOCK',
        0x1FB81 => 'HORIZONTAL ONE EIGHTH BLOCK-1358',
        0x1FB82 => 'UPPER ONE QUARTER BLOCK',
        0x1FB83 => 'UPPER THREE EIGHTHS BLOCK',
        0x1FB84 => 'UPPER FIVE EIGHTHS BLOCK',
        0x1FB85 => 'UPPER THREE QUARTERS BLOCK',
        0x1FB86 => 'UPPER SEVEN EIGHTHS BLOCK',
        0x1FB87 => 'RIGHT ONE QUARTER BLOCK',
        0x1FB88 => 'RIGHT THREE EIGHTHS BLOCK',
        0x1FB89 => 'RIGHT FIVE EIGHTHS BLOCK',
        0x1FB8A => 'RIGHT THREE QUARTERS BLOCK',
        0x1FB8B => 'RIGHT SEVEN EIGHTHS BLOCK',
    ];

    private SymbolMap $map;

    protected function setUp(): void
    {
        $this->map = SymbolMap::new();
    }

    public function testTheCorpusIsTheChafaNarrowSetInBuildOrder(): void
    {
        $defs = $this->map->defs();

        // 127 basic + 256 braille + 59 sextant + 234 octants-after-skip.
        $this->assertCount(676, $defs);

        $ords = array_map(static fn (SymbolDef $d): int => mb_ord($d->char), $defs);
        $this->assertSame($ords[0], 0x2500);
        $this->assertCount(676, array_unique($ords), 'no codepoint may appear twice');

        // Build order: basic ascending (the U+2500–U+259F block, holes and
        // all), then braille, sextant, octant blocks.
        $basic = array_slice($ords, 0, 127);
        $sorted = $basic;
        sort($sorted);
        $this->assertSame($sorted, $basic);
        $this->assertSame(0x2500, $basic[0]);
        $this->assertSame(0x259F, $basic[126]);
        $this->assertSame(range(0x2800, 0x28FF), array_slice($ords, 127, 256));
        $this->assertSame(range(0x1FB00, 0x1FB3A), array_slice($ords, 127 + 256, 59));
        $octants = array_slice($ords, 127 + 256 + 59);
        $this->assertCount(234, $octants);
        foreach ($octants as $cp) {
            $this->assertGreaterThanOrEqual(0x1CD00, $cp);
            $this->assertLessThanOrEqual(0x1D000, $cp, 'block duplicates stay skipped (chafa generate_octant_syms)');
        }
    }

    public function testBrailleCoverageMatchesTheUnicodeDotGrid(): void
    {
        foreach ([0x2800, 0x2801, 0x2808, 0x2840, 0x2880, 0x2837, 0x28FF] as $cp) {
            $expected = $this->brailleBitmap($cp - 0x2800);
            $actual = $this->bitsFor($cp);
            $this->assertSame($expected, $actual, sprintf('U+%04X', $cp));
        }
    }

    public function testSextantCoverageMatchesTheTeletextGrid(): void
    {
        // U+1FB00: bitmap value 1 = bit 0 = top-left sextant (rows 0-2, cols 0-3).
        $expected = 0;
        foreach ($this->sextantPixels([0]) as $i) {
            $expected |= 1 << (63 - $i);
        }
        $this->assertSame($expected, $bits = $this->bitsFor(0x1FB00));

        // U+1FB3A sits past both block holes: value 61 = bits 0,2,3,4,5.
        $expected = 0;
        foreach ($this->sextantPixels([0, 2, 3, 4, 5]) as $i) {
            $expected |= 1 << (63 - $i);
        }
        $this->assertSame($expected, $this->bitsFor(0x1FB3A));

        // The holes (21, 42) must never be produced: walking the block,
        // the value sequence is 1..20, 22..41, 43..61.
        $vals = [];
        for ($cp = 0x1FB00; $cp <= 0x1FB3A; $cp++) {
            $v = $cp - 0x1FB00 + 1;
            if ($v > 20) {
                $v++;
            }
            if ($v > 41) {
                $v++;
            }
            $vals[] = $v;
        }
        $this->assertNotContains(21, $vals);
        $this->assertNotContains(42, $vals);
        $this->assertSame(61, max($vals));
    }

    public function testOctantDecodeAndCoverage(): void
    {
        // bits 0x10 has no map entry; the insertion point is 7, so the
        // codepoint is (0x10 - 7) | 0x1CD00 = U+1CD09. Its bit 4 band is
        // pixel rows 4-5, left four columns.
        $def = $this->defFor(0x1CD09);
        $this->assertSame(SymbolTags::LEGACY | SymbolTags::OCTANT, $def->tags);

        $expected = 0;
        for ($y = 4; $y <= 5; $y++) {
            for ($x = 0; $x <= 3; $x++) {
                $expected |= 1 << (63 - ($y * 8 + $x));
            }
        }
        $this->assertSame($expected, $def->bits);
        $this->assertSame(8, $def->weight);
    }

    public function testCoverageToBitmapThresholdsAtOneTwentySeven(): void
    {
        // PC-13: the 0..255 ramp becomes binary only at cov > 127.
        $cov = array_fill(0, 64, 0);
        $cov[0] = 127;
        $cov[63] = 128;
        $bitmap = SymbolMap::coverageToBitmap($cov, 127);
        $this->assertSame(1, $bitmap, '127 stays background, 128 crosses');

        $cov[0] = 128;
        $this->assertSame((1 << 63) | 1, SymbolMap::coverageToBitmap($cov, 127));
    }

    public function testCoverageRoundTripsThroughCoverageOf(): void
    {
        $cov = [];
        for ($i = 0; $i < 64; $i++) {
            $cov[] = $i % 3 === 0 ? 255 : 0;
        }
        $bitmap = SymbolMap::coverageToBitmap($cov, 127);
        $this->assertSame($cov, array_map(static fn (int $b): int => $b * 255, SymbolMap::coverageOf($bitmap)));
    }

    public function testCoverageToBitmapRefusesWrongSizedRamps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SymbolMap::coverageToBitmap(array_fill(0, 63, 0), 127);
    }

    public function testCandidateSearchNarrowsByPopcountHamming(): void
    {
        $all = -1; // 64 set bits
        $cands = $this->map->findCandidates($all, true, 8);
        $this->assertCount(8, $cands);
        // The exact solid block wins outright, direct, first.
        $this->assertSame(0x2588, mb_ord($cands[0][0]->char));
        $this->assertFalse($cands[0][1]);

        $none = 0;
        $cands = $this->map->findCandidates($none, true, 1);
        $this->assertCount(1, $cands);
        // The best empty fit is the inverted solid block (hd 64 inverse -> 0).
        $this->assertSame(0x2588, mb_ord($cands[0][0]->char));
        $this->assertTrue($cands[0][1]);
    }

    public function testCandidateWindowIsAscendingWithStableTies(): void
    {
        $bitmap = SymbolMap::coverageToBitmap($this->checkerRamp(), 127);
        $cands = $this->map->findCandidates($bitmap, true, 8);

        $distances = array_map(
            static fn (array $c): int => self::pop64($c[0]->bits ^ ($c[1] ? ~$bitmap : $bitmap)),
            $cands
        );
        for ($i = 1; $i < count($distances); $i++) {
            $this->assertGreaterThanOrEqual($distances[$i - 1], $distances[$i]);
        }

        // Ties keep corpus scan order: among the weight-2 braille
        // mirrors at equal distance, the lower codepoint precedes.
        for ($i = 1; $i < count($cands); $i++) {
            if ($distances[$i] === $distances[$i - 1]) {
                $this->assertLessThan(
                    mb_ord($cands[$i][0]->char),
                    mb_ord($cands[$i - 1][0]->char),
                    'equal-distance candidates must stay in scan order'
                );
            }
        }
    }

    public function testLimitAboveTheBufferStillYieldsAtMostEight(): void
    {
        $cands = $this->map->findCandidates(0, true, 16);
        $this->assertLessThanOrEqual(8, count($cands));
        $this->assertNotEmpty($cands);
    }

    public function testLimitBelowOneRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->map->findCandidates(0, true, 0);
    }

    public function testEveryDefPaintsItsOwnBitmapConsistently(): void
    {
        foreach ($this->map->defs() as $def) {
            $cov = SymbolMap::coverageOf($def->bits);
            $this->assertSame($def->weight, array_sum($cov));
            $this->assertSame(64 - $def->weight, $def->bgWeight());
        }
    }


    public function testTheEighthBlockTierMatchesTheUnicodeFractionNames(): void
    {
        $legacy = $this->map->withSelectors('legacy');
        $eighth = [];
        foreach ($legacy->defs() as $def) {
            $cp = mb_ord($def->char);
            if ($cp >= 0x1FB80 && $cp <= 0x1FB8B) {
                $eighth[$cp] = $def;
            }
        }

        $this->assertSame(array_keys(self::EIGHTH_BLOCK_NAMES), array_keys($eighth), 'the whole 1FB80..1FB8B family, in order');

        foreach (self::EIGHTH_BLOCK_NAMES as $cp => $name) {
            if (class_exists(\IntlChar::class)) {
                $this->assertSame($name, \IntlChar::charName($cp), 'the chart name this oracle parses');
            }

            [$axis, $eighths] = self::fractionFromName($name);
            $this->assertSame(
                self::eighthBlockBitmap($axis, $eighths),
                $eighth[$cp]->bits,
                sprintf('U+%04X (%s)', $cp, $name)
            );
            $this->assertSame(0, $eighth[$cp]->tags & SymbolTags::SEXTANT, 'the eighth family is not a sextant');
            $this->assertNotSame(0, $eighth[$cp]->tags & SymbolTags::LEGACY);
        }
    }

    public function testTheFullNarrowCorpusIsChafasSymbolArray(): void
    {
        // The corpus dump must include the BAD glyphs to be the full array:
        // `all` alone BAD-vetoes the nine misc-narrow AMBIGUOUS entries
        // (chafa char_is_selected parity), so the dump names them explicitly
        // — the same move as chafa's "-ugly+0..fffff" example.
        $all = $this->map->withSelectors('all+ambiguous')->defs();

        // chafa's narrow symbol array at 413b76e4d: 95 ASCII + 365 Latin +
        // 229 block-table + 23 misc-narrow, then 256 braille + 59 sextant +
        // 234 octants (the kana table is 16x8-only and contributes none).
        // U+00B7 is spelled by both latin.h and misc-narrow.h; chafa's
        // codepoint-keyed hash keeps one, so the corpus is 1,260 unique.
        $this->assertCount(1260, $all);

        $ords = array_map(static fn (SymbolDef $d): int => mb_ord($d->char), $all);
        $this->assertCount(1260, array_unique($ords), 'no codepoint may appear twice');

        $ranges = static function (array $cps, int $lo, int $hi): int {
            return count(array_filter($cps, static fn (int $c): bool => $c >= $lo && $c <= $hi));
        };
        $this->assertSame(95, $ranges($ords, 0x20, 0x7E), 'ASCII set');
        $this->assertSame(365, $ranges($ords, 0xA1, 0x3F6), 'Latin/Greek set');
        $this->assertSame(127, $ranges($ords, 0x2500, 0x259F), 'basic block table');
        $this->assertSame(102, $ranges($ords, 0x1FB3C, 0x1FBAE), 'legacy + eighth block entries');
        $this->assertSame(256, $ranges($ords, 0x2800, 0x28FF), 'braille');
        $this->assertSame(59, $ranges($ords, 0x1FB00, 0x1FB3A), 'sextants');

        // Build order is chafa's symbol_defs order: ASCII, Latin, block
        // table, misc, then the generated tiers. The misc table's U+00B7
        // keeps the latin slot (chafa's hash-replace position).
        $this->assertSame(0x20, $ords[0]);
        $this->assertSame(0xA1, $ords[95]);
        $this->assertSame(0x2500, $ords[460]);
        $this->assertSame(0x1FB3C, $ords[587]);
        $this->assertSame(0x1FB80, $ords[677]);
        $this->assertSame(0x2190, $ords[689], 'misc-narrow opens on the leftwards arrow');
        $this->assertSame(0x2800, $ords[711], 'braille follows the tables');
        $this->assertSame(0x1FB00, $ords[967], 'sextants follow braille');
        $this->assertSame(0x1CEA8, $ords[1026], 'octants close the corpus (octant 1; octant 0 is the skipped U+00A0)');

        // chafa's hash replace keeps the LATER table's glyph for U+00B7 —
        // misc-narrow's 2x2 DOT, not latin.h's single pixel.
        $middleDot = $all[array_search(0xB7, $ords, true)];
        $this->assertSame(4, $middleDot->weight);
        $this->assertNotSame(0, $middleDot->tags & SymbolTags::DOT);
    }

    public function testThePortedTablesCarryPlausibleVerbatimCoverage(): void
    {
        $ascii = [];
        foreach ($this->map->withSelectors('ascii')->defs() as $def) {
            $ascii[mb_ord($def->char)] = $def;
        }

        $this->assertSame(0, $ascii[0x20]->weight, 'space paints nothing');
        $this->assertSame(0, $ascii[0x20]->bits);

        $dot = $ascii[0x2E];
        $this->assertGreaterThan(0, $dot->weight);
        $this->assertSame(0, $dot->bits & self::rowMask(0), 'a full stop never touches the top row');

        // chafa draws its lowercase 'o' in the middle band (rows 3-6), a
        // closed ring one pixel in from both sides.
        $o = $ascii[0x6F];
        $this->assertGreaterThan(12, $o->weight);
        $this->assertSame(0, $o->bits & self::rowMask(0), "'o' leaves the top row free");
        $this->assertSame(0, $o->bits & self::rowMask(7), "'o' leaves the bottom row free");
        $this->assertSame(0, $o->bits & (1 << (63 - 24)), "'o' leaves its left edge open");
        $this->assertNotSame(0, $o->bits & (1 << (63 - 25)), "'o' paints one pixel in");

        $latin = $this->map->withSelectors('latin')->defs();
        $this->assertCount(459, $latin, 'Latin includes the ASCII glyphs; U+00B7 belongs to misc-narrow after the dedupe');
        foreach ($latin as $def) {
            $this->assertSame(64, $def->weight + $def->bgWeight());
        }
    }

    public function testSelectorsMirrorChafasTagGrammar(): void
    {
        $this->assertCount(256, $this->map->withSelectors('braille')->defs());
        $this->assertCount(0, $this->map->withSelectors('none')->defs());
        $this->assertCount(286, $this->map->withSelectors('block+braille')->defs());
        // F-17: BAD-veto parity with chafa char_is_selected
        // (chafa-symbol-map.c:489-545) — the nine table-declared AMBIGUOUS
        // misc-narrow glyphs stay out of every selector that does not name
        // them. Chafa's effective `all` count is 1,251 of the 1,260 array.
        $this->assertCount(856, $this->map->withSelectors('all-legacy')->defs());
        $this->assertCount(1251, $this->map->withSelectors('all')->defs());
        $this->assertCount(5, $this->map->withSelectors('geometric')->defs(), 'only U+25AE/25A0/25B2/25BC/25CF — the eight ambiguous triangles and diamond are vetoed');
        $this->assertCount(23, $this->map->withSelectors('dot')->defs(), '24 DOT-tagged less the vetoed U+25AA');

        $ambiguous = array_map(
            static fn (SymbolDef $d): int => mb_ord($d->char),
            $this->map->withSelectors('ambiguous')->defs()
        );
        $this->assertSame(
            [0x25AA, 0x25B6, 0x25C0, 0x25C6, 0x25E2, 0x25E3, 0x25E4, 0x25E5, 0x25FC],
            $ambiguous,
            'exactly the nine misc-narrow.h AMBIGUOUS declarations, in build order'
        );
        $this->assertCount(9, $this->map->withSelectors('bad')->defs());
        $this->assertCount(0, $this->map->withSelectors('ugly')->defs(), 'no chafa static table declares UGLY');

        // Signs persist across comma-separated tokens (chafa parse_selectors).
        $this->assertCount(286, $this->map->withSelectors('block,braille')->defs());

        // A leading sign is relative to the map's current corpus.
        $this->assertCount(676, $this->map->withSelectors('+stipple')->defs(), 'stipple already rides the default corpus');
        $this->assertCount(673, $this->map->withSelectors('-stipple')->defs());
    }

    public function testSelectorErrorsFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->map->withSelectors('notaclass');
    }

    public function testEmptySelectorExpressionFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->map->withSelectors('  ');
    }

    public function testKanaIsWideOnlyAndThereforeOutsideTheNarrowCorpus(): void
    {
        // chafa-symbols-kana.h carries 16x8 outlines only; chafa's
        // init_symbol_array() skips those (they are the wide symbols2
        // array, the unported A-09 engine), so no kana codepoint can ever
        // appear in the narrow corpus.
        foreach ($this->map->withSelectors('all')->defs() as $def) {
            $cp = mb_ord($def->char);
            $this->assertFalse($cp >= 0x3040 && $cp <= 0x30FF, sprintf('U+%04X is a wide kana glyph', $cp));
        }
    }

    private static function rowMask(int $y): int
    {
        $mask = 0;
        for ($x = 0; $x < 8; $x++) {
            $mask |= 1 << (63 - ($y * 8 + $x));
        }

        return $mask;
    }

    /** @return array{0:string, 1:int} axis, eighths parsed from the Unicode name */
    private static function fractionFromName(string $name): array
    {
        if ($name === 'UPPER AND LOWER ONE EIGHTH BLOCK') {
            return ['both', 1];
        }
        if (str_starts_with($name, 'HORIZONTAL ONE EIGHTH BLOCK')) {
            return ['stripes', 1];
        }

        $fractions = [
            'ONE QUARTER' => 2,
            'THREE EIGHTHS' => 3,
            'FIVE EIGHTHS' => 5,
            'THREE QUARTERS' => 6,
            'SEVEN EIGHTHS' => 7,
        ];
        foreach ($fractions as $word => $eighths) {
            if ($name === 'UPPER ' . $word . ' BLOCK') {
                return ['upper', $eighths];
            }
            if ($name === 'RIGHT ' . $word . ' BLOCK') {
                return ['right', $eighths];
            }
        }

        throw new \InvalidArgumentException('unparsable eighth-block name: ' . $name);
    }

    private static function eighthBlockBitmap(string $axis, int $eighths): int
    {
        $bitmap = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $on = match ($axis) {
                    'upper' => $y < $eighths,
                    'right' => $x >= 8 - $eighths,
                    'both' => $y === 0 || $y === 7,
                    'stripes' => in_array($y, [0, 2, 4, 7], true),
                    default => false,
                };
                if ($on) {
                    $bitmap |= 1 << (63 - ($y * 8 + $x));
                }
            }
        }

        return $bitmap;
    }

    private function bitsFor(int $cp): int
    {
        return $this->defFor($cp)->bits;
    }

    private function defFor(int $cp): SymbolDef
    {
        foreach ($this->map->defs() as $def) {
            if (mb_ord($def->char) === $cp) {
                return $def;
            }
        }

        $this->fail(sprintf('U+%04X missing from corpus', $cp));
    }

    /**
     * Braille bitmap from the dot pattern: dot n lights its 2x2 cell.
     *
     * @param int $val 8-bit dot pattern (bit k-1 = dot k)
     */
    private function brailleBitmap(int $val): int
    {
        $bitmap = 0;
        foreach (self::BRILLE_DOT_CELLS as $dot => [$row, $col]) {
            if (!($val & (1 << ($dot - 1)))) {
                continue;
            }
            for ($y = $row; $y <= $row; $y++) {
                for ($x = $col; $x <= $col + 1; $x++) {
                    $bitmap |= 1 << (63 - ($y * 8 + $x));
                }
            }
        }

        return $bitmap;
    }

    /**
     * Pixel indices lit by the given sextant bits (grid bit y*2+x, each
     * sextant 3 rows x 4 cols, rows past the middle collapse onto row 3).
     *
     * @param list<int> $bits
     *
     * @return list<int>
     */
    private function sextantPixels(array $bits): array
    {
        $pixels = [];
        foreach ($bits as $bit) {
            $y = intdiv($bit, 2);
            $x = $bit % 2;
            for ($v = 0; $v < 3; $v++) {
                $row = $y * 3 + $v;
                if ($row > 3) {
                    $row--;
                }
                for ($u = 0; $u < 4; $u++) {
                    $pixels[] = $row * 8 + $x * 4 + $u;
                }
            }
        }

        return $pixels;
    }

    /** @return list<int> 64 bytes of 0/255 checkerboard */
    private function checkerRamp(): array
    {
        $cov = [];
        for ($i = 0; $i < 64; $i++) {
            $cov[] = ($i % 2) === 0 ? 255 : 0;
        }

        return $cov;
    }

    private static function pop64(int $x): int
    {
        // Sign-bit-safe popcount oracle for the tests themselves.
        $n = ($x >> 63) & 1;
        $x &= PHP_INT_MAX;
        while ($x !== 0) {
            $x &= $x - 1;
            $n++;
        }

        return $n;
    }
}
