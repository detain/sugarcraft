<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Symbol\SymbolDef;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolTags;

/**
 * A-04: coverage-class symbol engine pins (plan_chafa S6.2).
 *
 * The braille / sextant / octant bitmaps are checked against oracles
 * re-derived in-test from the Unicode character properties themselves
 * (dot cell positions, the 2x3 sextant grid with its two shared middle
 * rows, the octant 4x2 band layout) — never from the port's own
 * generator, so a transcription slip here cannot validate itself.
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
