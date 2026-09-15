<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\ColorDistance;
use SugarCraft\Palette\ColorMath;
use SugarCraft\Palette\NearestColor;
use SugarCraft\Palette\StandardColors;

/**
 * Nearest-color matcher coverage: table shape, tie-breaking, memo identity,
 * cross-metric agreement with a brute-force reference, and the documented
 * Euclidean-vs-CIE2000 divergence that motivates the feature.
 */
final class NearestColorTest extends TestCase
{
    public function testPaletteHas256EntriesWithExpectedCornersAndGreys(): void
    {
        $palette = NearestColor::palette256();
        self::assertCount(256, $palette);
        self::assertTrue($palette[0]->equals(StandardColors::$black));
        self::assertSame('#000000', $palette[16]->toHex());
        self::assertSame('#ffffff', $palette[231]->toHex());
        self::assertSame('#080808', $palette[232]->toHex());
        self::assertSame('#eeeeee', $palette[255]->toHex());
    }

    public function testCubeIsEvenlySteppedAt51(): void
    {
        $palette = NearestColor::palette256();
        // index 16 + 36*r + 6*g + b -> channel = level * 51.
        self::assertSame('#333333', $palette[16 + 36 + 6 + 1]->toHex()); // levels (1,1,1)
        self::assertSame('#ff0000', $palette[16 + 36 * 5]->toHex());     // levels (5,0,0)
        self::assertSame('#00ff00', $palette[16 + 6 * 5]->toHex());      // levels (0,5,0)
    }

    public function testPaletteMemoIsStableAcrossCalls(): void
    {
        self::assertSame(NearestColor::palette256(), NearestColor::palette256());
        // Element-level identity (arrays of objects compare instances with ===):
        // the memo hands out the SAME Color objects, not re-created lookalikes.
        self::assertSame(NearestColor::palette256()[42], NearestColor::palette256()[42]);
        // Lab tables are arrays of floats, so === proves value stability; the
        // static memo (not a recompute) is what guarantees identity of contents.
        self::assertSame(NearestColor::palette256Lab(), NearestColor::palette256Lab());
        self::assertSame(NearestColor::palette16Lab(), NearestColor::palette16Lab());
    }

    public function testMemoizedLabMatchesFreshConversion(): void
    {
        $memo = NearestColor::palette256Lab();
        self::assertCount(256, $memo);
        foreach (NearestColor::palette256() as $index => $color) {
            self::assertSame(
                ColorMath::toLab($color->r, $color->g, $color->b),
                $memo[$index],
                "Lab mismatch at index {$index}",
            );
        }
    }

    public function testMetricDefaultsToEuclidean(): void
    {
        self::assertSame(ColorDistance::Euclidean, (new NearestColor())->metric());
    }

    public function testEuclideanAnsi256SnapsToExactCubeAndRampNodes(): void
    {
        $matcher = new NearestColor();
        // (153,102,51) is cube level (3,2,1) -> distance 0, unique winner.
        self::assertSame(16 + 36 * 3 + 6 * 2 + 1, $matcher->ansi256(new Color(153, 102, 51)));
        // (128,128,128) is grey-ramp step 12 (8 + 12*10) -> distance 0.
        self::assertSame(232 + 12, $matcher->ansi256(new Color(128, 128, 128)));
    }

    /**
     * Exact palette nodes are fixed points of the matcher under every metric
     * (the conversions are injective, so zero Lab distance implies the same
     * RGB entry).
     *
     * @return array<string, array{0: int, 1: Color}>
     */
    public static function exactNodeProvider(): array
    {
        return [
            'cube (0,0,1)'    => [17, new Color(0, 0, 51)],
            'cube (3,1,0)'    => [130, new Color(153, 51, 0)],
            'grey ramp first' => [232, new Color(8, 8, 8)],
            'grey ramp last'  => [255, new Color(238, 238, 238)],
        ];
    }

    /**
     * @dataProvider exactNodeProvider
     */
    public function testExactPaletteNodesMapToThemselvesUnderEveryMetric(int $expected, Color $color): void
    {
        foreach (ColorDistance::cases() as $metric) {
            self::assertSame($expected, (new NearestColor($metric))->ansi256($color), $metric->value);
        }
    }

    public function testGreyAnsi256WinnersAreAllGreyscaleUnderEveryMetric(): void
    {
        $grey = new Color(128, 128, 128);
        foreach (ColorDistance::cases() as $metric) {
            $winner = NearestColor::palette256()[(new NearestColor($metric))->ansi256($grey)];
            self::assertSame($winner->r, $winner->g, $metric->value);
            self::assertSame($winner->g, $winner->b, $metric->value);
        }
    }

    public function testTiesResolveToLowestPaletteIndex(): void
    {
        // Index 0 (standard black) and index 16 (cube origin) are both #000000.
        self::assertSame(0, (new NearestColor())->ansi256(new Color(0, 0, 0)));
        // Index 15 and 231 are both #ffffff.
        self::assertSame(15, (new NearestColor())->ansi256(new Color(255, 255, 255)));
    }

    public function testAnsi16HandMatchesBasicBlue(): void
    {
        // (0,0,200) sits nearest basic blue (0,0,238) — d=1444 vs bright blue
        // (92,92,255) d=19953 — so index 4, not bright.
        self::assertSame(4, (new NearestColor())->ansi16(new Color(0, 0, 200)));
    }

    public function testAnsi16FindsEveryStandardColorItself(): void
    {
        $matcher = new NearestColor(ColorDistance::Cie2000);
        foreach (StandardColors::all() as $index => $color) {
            self::assertSame($index, $matcher->ansi16($color));
        }
    }

    public function testEuclideanAndCie2000DivergeForSaturatedGreen(): void
    {
        // The motivating case: RGB Euclidean pulls (30,120,30) toward the
        // desaturated olive cube node (51,102,51) #336633, while CIE2000 keeps
        // it on the pure green (0,102,0) #006600 — dE00 6.29 vs 8.53.
        $green = new Color(30, 120, 30);
        self::assertSame(65, (new NearestColor())->ansi256($green));
        self::assertSame(28, (new NearestColor(ColorDistance::Cie2000))->ansi256($green));
    }

    public function testCie2000BlueWinnerKeepsTheHueNavyNotTeal(): void
    {
        // (12,34,56): Euclidean -> 23 #003333 (teal, dE00 17.41) while CIE2000
        // -> 24 #003366 (navy, dE00 8.72) — the mis-rank this feature fixes.
        $navy = new Color(12, 34, 56);
        self::assertSame(23, (new NearestColor())->ansi256($navy));
        self::assertSame(24, (new NearestColor(ColorDistance::Cie2000))->ansi256($navy));
    }

    /**
     * Independent brute-force reference: recompute the winner for every metric
     * through ColorDistance::between() (fresh conversions, no memo), then
     * assert the memoized matcher agrees.
     */
    public function testMatcherAgreesWithBruteForceReferenceAcrossMetrics(): void
    {
        $palette = NearestColor::palette256();
        $probe = new Color(23, 200, 120);

        foreach (ColorDistance::cases() as $metric) {
            $expected = 0;
            $best = \INF;
            foreach ($palette as $index => $entry) {
                $distance = $metric->between($probe, $entry);
                if ($distance < $best) {
                    $best = $distance;
                    $expected = $index;
                }
            }
            self::assertSame($expected, (new NearestColor($metric))->ansi256($probe), $metric->value);
        }
    }

    public function testClosestOnArbitraryPaletteMatchesAnsi256(): void
    {
        $probe = new Color(23, 200, 120);
        $matcher = new NearestColor(ColorDistance::Cie2000);
        self::assertSame($matcher->ansi256($probe), $matcher->closest($probe, NearestColor::palette256()));
    }

    public function testClosestRejectsEmptyPalette(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty palette');
        (new NearestColor())->closest(new Color(1, 2, 3), []);
    }

    public function testClosestReturnsStringKeyForAssociativePalette(): void
    {
        $palette = ['brand' => new Color(10, 20, 30), 'other' => new Color(240, 250, 255)];
        self::assertSame('brand', (new NearestColor())->closest(new Color(12, 18, 33), $palette));
        self::assertSame(
            'brand',
            (new NearestColor(ColorDistance::Cie2000))->closest(new Color(12, 18, 33), $palette),
        );
    }

    /**
     * Perf guard + measured cost (PHP 8.3.6, x86-64, memo primed): a warm
     * CIEDE2000 search over all 256 entries costs ~0.95 ms; 2k searches
     * ~1.9 s. The budget below is deliberately loose (anti-flake) — the point
     * is that the static Lab memo keeps per-frame media quantization linear in
     * cheap distance calls, not in 256 conversions + distance calls.
     */
    public function testCie2000WarmSearchScalesForMediaClients(): void
    {
        $matcher = new NearestColor(ColorDistance::Cie2000);
        $probe = new Color(30, 120, 30);

        $started = \microtime(true);
        for ($i = 0; $i < 2000; $i++) {
            self::assertSame(28, $matcher->ansi256($probe));
        }
        $elapsed = \microtime(true) - $started;

        self::assertLessThan(15.0, $elapsed, "2k CIE2000 searches took {$elapsed}s");
    }
}
