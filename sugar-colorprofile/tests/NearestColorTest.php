<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\ColorMath;
use SugarCraft\ColorProfile\NearestColor;
use SugarCraft\ColorProfile\StandardColors;

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

    public function testCubeUsesTheCanonicalXtermLevels(): void
    {
        $palette = NearestColor::palette256();
        // the cube is the industry-standard xterm 6×6×6 with channel
        // levels 0,95,135,175,215,255 (Color::CUBE_LEVELS) — NOT the old even
        // 51-step table. index 16 + 36*r + 6*g + b -> channel = CUBE_LEVELS[level].
        self::assertSame('#00005f', $palette[17]->toHex());              // levels (0,0,1)
        self::assertSame('#5f5f5f', $palette[16 + 36 + 6 + 1]->toHex()); // levels (1,1,1)
        self::assertSame('#ff0000', $palette[16 + 36 * 5]->toHex());     // levels (5,0,0)
        self::assertSame('#00ff00', $palette[16 + 6 * 5]->toHex());      // levels (0,5,0)
        // Every cube channel byte is one of the six xterm levels.
        $levels = [0, 95, 135, 175, 215, 255];
        foreach (array_slice($palette, 16, 216) as $color) {
            self::assertContains($color->r, $levels);
            self::assertContains($color->g, $levels);
            self::assertContains($color->b, $levels);
        }
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
        self::assertSame(ColorDistance::Euclidean, (NearestColor::new())->metric());
    }

    public function testTheLabMemosTrackStandardColorReassignments(): void
    {
        $original = StandardColors::$red;
        $warm = NearestColor::palette16Lab();
        self::assertSame($warm, NearestColor::palette16Lab());

        $sentinel = new Color(0x12, 0x34, 0x56);
        try {
            StandardColors::$red = $sentinel;

            $mutated = NearestColor::palette16Lab();
            self::assertNotSame($warm, $mutated, 'a redefined slot must not hide behind the frozen memo');
            self::assertSame(ColorMath::toLab(0x12, 0x34, 0x56), $mutated[1]);
            // The 256-entry table leads with the same mutable slots — same guarantee there.
            self::assertSame(ColorMath::toLab(0x12, 0x34, 0x56), NearestColor::palette256Lab()[1]);
            // CIE ANSI-16 matching honors the sentinel without any manual rebuild.
            self::assertSame(1, (NearestColor::forMetric(ColorDistance::Cie76))->ansi16(new Color(0x12, 0x34, 0x56)));
        } finally {
            StandardColors::$red = $original;
        }

        $restored = NearestColor::palette16Lab();
        self::assertSame($warm[1], $restored[1], 'restoring the slot restores the original table contents');
    }

    public function testEuclideanAnsi256SnapsToExactCubeAndRampNodes(): void
    {
        $matcher = NearestColor::new();
        // (175,95,0) is cube level (3,1,0) -> distance 0, unique winner.
        self::assertSame(16 + 36 * 3 + 6 * 1 + 0, $matcher->ansi256(new Color(175, 95, 0)));
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
            'cube (0,0,1)'    => [17, new Color(0, 0, 95)],
            'cube (3,1,0)'    => [130, new Color(175, 95, 0)],
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
            self::assertSame($expected, (NearestColor::forMetric($metric))->ansi256($color), $metric->value);
        }
    }

    public function testGreyAnsi256WinnersAreAllGreyscaleUnderEveryMetric(): void
    {
        $grey = new Color(128, 128, 128);
        foreach (ColorDistance::cases() as $metric) {
            $winner = NearestColor::palette256()[(NearestColor::forMetric($metric))->ansi256($grey)];
            self::assertSame($winner->r, $winner->g, $metric->value);
            self::assertSame($winner->g, $winner->b, $metric->value);
        }
    }

    public function testTiesResolveToLowestPaletteIndex(): void
    {
        // Index 0 (standard black) and index 16 (cube origin) are both #000000.
        self::assertSame(0, (NearestColor::new())->ansi256(new Color(0, 0, 0)));
        // Index 15 and 231 are both #ffffff.
        self::assertSame(15, (NearestColor::new())->ansi256(new Color(255, 255, 255)));
    }

    public function testAnsi16HandMatchesBasicBlue(): void
    {
        // (0,0,200) sits nearest basic blue (0,0,238) — d=1444 vs bright blue
        // (92,92,255) d=19953 — so index 4, not bright.
        self::assertSame(4, (NearestColor::new())->ansi16(new Color(0, 0, 200)));
    }

    public function testAnsi16FindsEveryStandardColorItself(): void
    {
        $matcher = NearestColor::forMetric(ColorDistance::Cie2000);
        foreach (StandardColors::all() as $index => $color) {
            self::assertSame($index, $matcher->ansi16($color));
        }
    }

    public function testEuclideanAndCie2000DivergeNearGreenLevelBoundary(): void
    {
        // The metric still matters on the canonical table: (0,143,52) sits
        // between cube greens, Euclidean drifts to the teal-tinged (0,135,95)
        // #00875f while CIE2000 keeps the pure hue at (0,135,0) #008700.
        $green = new Color(0, 143, 52);
        self::assertSame(29, (NearestColor::new())->ansi256($green));
        self::assertSame(28, (NearestColor::forMetric(ColorDistance::Cie2000))->ansi256($green));
    }

    public function testDarkNavyMapsToGreyRampWithMetricSplit(): void
    {
        // (12,34,56) on the old even-51 table was the teal-vs-navy cube story
        // (#003333 vs #003366). On the canonical xterm cube no dark saturated
        // blue node is close enough — both metrics now pick the grey ramp,
        // Euclidean step 3 (#262626) and CIE2000 step 2 (#1c1c1c).
        $navy = new Color(12, 34, 56);
        self::assertSame(235, (NearestColor::new())->ansi256($navy));
        self::assertSame(234, (NearestColor::forMetric(ColorDistance::Cie2000))->ansi256($navy));
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
            self::assertSame($expected, (NearestColor::forMetric($metric))->ansi256($probe), $metric->value);
        }
    }

    public function testClosestOnArbitraryPaletteMatchesAnsi256(): void
    {
        $probe = new Color(23, 200, 120);
        $matcher = NearestColor::forMetric(ColorDistance::Cie2000);
        self::assertSame($matcher->ansi256($probe), $matcher->closest($probe, NearestColor::palette256()));
    }

    public function testClosestRejectsEmptyPalette(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty palette');
        (NearestColor::new())->closest(new Color(1, 2, 3), []);
    }

    public function testClosestReturnsStringKeyForAssociativePalette(): void
    {
        $palette = ['brand' => new Color(10, 20, 30), 'other' => new Color(240, 250, 255)];
        self::assertSame('brand', (NearestColor::new())->closest(new Color(12, 18, 33), $palette));
        self::assertSame(
            'brand',
            (NearestColor::forMetric(ColorDistance::Cie2000))->closest(new Color(12, 18, 33), $palette),
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
        $matcher = NearestColor::forMetric(ColorDistance::Cie2000);
        $probe = new Color(30, 120, 30);

        $started = \microtime(true);
        for ($i = 0; $i < 2000; $i++) {
            self::assertSame(28, $matcher->ansi256($probe));
        }
        $elapsed = \microtime(true) - $started;

        self::assertLessThan(15.0, $elapsed, "2k CIE2000 searches took {$elapsed}s");
    }

    public function testTheHouseFactoriesAnswerOnTheirOwnDoors(): void
    {
        // CP-49 factory pins: the constructor went private (zero consumers
        // tree-wide, enumerated in the commit message) — the two named doors
        // must build matchers whose strategy is the requested seat, and the
        // optional-metric leg of new() must ride DEFAULT_METRIC unchanged.
        $default = NearestColor::new();
        self::assertSame(ColorDistance::DEFAULT_METRIC, $default->metric());

        foreach (ColorDistance::cases() as $metric) {
            self::assertSame($metric, NearestColor::forMetric($metric)->metric(), $metric->name);
            self::assertSame($metric, NearestColor::new($metric)->metric(), $metric->name);
        }
    }
}
