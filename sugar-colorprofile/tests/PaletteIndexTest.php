<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\NearestColor;
use SugarCraft\ColorProfile\PaletteIndex;

/**
 * B-04: PaletteIndex differential-fuzz coverage.
 *
 * The contract under test is exactness, not speed: for ANY palette and query,
 * the bucketed lookup must return the same index a brute-force
 * {@see NearestColor::closest()} scan returns — including ties, which resolve
 * to the lowest palette index. The fuzz draws random and structured palettes
 * from a PINNED seed (FUZZ_SEED) so every disagreement is reproducible;
 * corrupting the binary search or the pruning bound reddens it, because the
 * missed winner cannot hide behind "approximately nearest".
 */
final class PaletteIndexTest extends TestCase
{
    /**
     * B-04: pinned fuzz seed — the Mt19937 stream that generated the palettes
     * and queries. Changing it re-rolls the corpus and must stay a deliberate act.
     */
    private const FUZZ_SEED = 20261020;

    /** @var int palettes drawn per metric */
    private const FUZZ_PALETTES = 40;

    /** @var int queries drawn per palette */
    private const FUZZ_QUERIES = 20;

    public function testFuzzDifferentialAgainstBruteForce(): void
    {
        $randomizer = new Randomizer(new Mt19937(self::FUZZ_SEED));
        $cubeLevels = [0, 95, 135, 175, 215, 255];
        $checked = 0;

        foreach ([ColorDistance::Euclidean, ColorDistance::Cie76] as $metric) {
            $matcher = new NearestColor($metric);
            for ($p = 0; $p < self::FUZZ_PALETTES; $p++) {
                $size = $randomizer->getInt(1, 40);
                // Alternating corpus: fully random channels (near-ties are then
                // measure-zero), and cube-level channels (duplicates, symmetric
                // pairs and EXACT ties are common — these pin the tie rule).
                $structured = $p % 2 === 0;
                $palette = [];
                for ($i = 0; $i < $size; $i++) {
                    $palette[] = $structured
                        ? new Color(
                            $cubeLevels[$randomizer->getInt(0, 5)],
                            $cubeLevels[$randomizer->getInt(0, 5)],
                            $cubeLevels[$randomizer->getInt(0, 5)],
                        )
                        : new Color(
                            $randomizer->getInt(0, 255),
                            $randomizer->getInt(0, 255),
                            $randomizer->getInt(0, 255),
                        );
                }

                $index = PaletteIndex::fromPalette($palette, $metric);
                for ($q = 0; $q < self::FUZZ_QUERIES; $q++) {
                    // A quarter of the queries ARE palette entries (exact hits);
                    // the rest roam the cube, sometimes through tie geometry.
                    $query = $randomizer->getInt(0, 3) === 0
                        ? $palette[$randomizer->getInt(0, $size - 1)]
                        : new Color(
                            $randomizer->getInt(0, 255),
                            $randomizer->getInt(0, 255),
                            $randomizer->getInt(0, 255),
                        );
                    $checked++;

                    // closest() over a LIST is the oracle: ascending iteration,
                    // strict-< updates => lowest index among exact minimizers,
                    // the very rule the bucketed scan must reproduce bit-for-bit.
                    self::assertSame(
                        $matcher->closest($query, $palette),
                        $index->nearest($query),
                        \sprintf(
                            'B-04 fuzz divergence (%s, palette #%d, size %d, query %d,%d,%d)',
                            $metric->value,
                            $p,
                            $size,
                            $query->r,
                            $query->g,
                            $query->b,
                        ),
                    );
                }
            }
        }

        self::assertSame(2 * self::FUZZ_PALETTES * self::FUZZ_QUERIES, $checked);
    }

    /**
     * B-04: exact ties resolve to the LOWEST palette index — the documented
     * rule (chafa chafa-color-table.c:248, NearestColor's own contract). The
     * (5,0,0)-over-(10,0,0)/(0,0,0) geometry is an exact squared-distance tie
     * in Euclidean space (25 == 25); the bucketed scan visits them in
     * leading-axis order, so only an explicit tie rule yields index 0.
     */
    public function testExactTieResolvesToLowestIndex(): void
    {
        $palette = [new Color(10, 0, 0), new Color(0, 0, 0)];
        $index = PaletteIndex::fromPalette($palette, ColorDistance::Euclidean);

        self::assertSame(0, $index->nearest(new Color(5, 0, 0)));
        self::assertSame(0, $index->nearest(new Color(10, 0, 0)));
        self::assertSame(1, $index->nearest(new Color(0, 0, 0)));

        // Duplicate entries: identical colors at indices 0 and 2 -> 0 wins
        // under BOTH metrics (identical vectors => identical FP everywhere).
        $dupes = [new Color(200, 30, 40), new Color(0, 255, 0), new Color(200, 30, 40)];
        foreach ([ColorDistance::Euclidean, ColorDistance::Cie76] as $metric) {
            self::assertSame(0, PaletteIndex::fromPalette($dupes, $metric)->nearest(new Color(200, 30, 40)));
        }
    }

    /**
     * B-04: degenerate palette shapes — one entry always answers 0, empty
     * palettes refuse at construction, and the all-identical palette (zero
     * covariance, degenerate eigenbasis) still answers every query.
     */
    public function testDegeneratePalettes(): void
    {
        $single = PaletteIndex::fromPalette([new Color(11, 22, 33)]);
        self::assertSame(0, $single->nearest(new Color(250, 250, 250)));

        $flat = PaletteIndex::fromPalette(array_fill(0, 5, new Color(7, 7, 7)), ColorDistance::Euclidean);
        self::assertSame(0, $flat->nearest(new Color(100, 50, 200)));

        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentionally empty */
        PaletteIndex::fromPalette([]);
    }

    /**
     * B-04: non-Euclidean-family metrics have no distance-preserving rotation;
     * the index refuses them loudly instead of silently degrading exactness.
     */
    #[DataProvider('unsupportedMetrics')]
    public function testRefusesMetricsWithoutFixedBasis(ColorDistance $metric): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaletteIndex::fromPalette([new Color(1, 2, 3)], $metric);
    }

    /**
     * @return iterable<string, array{ColorDistance}>
     */
    public static function unsupportedMetrics(): iterable
    {
        yield 'cie94' => [ColorDistance::Cie94];
        yield 'cie2000' => [ColorDistance::Cie2000];
    }

    /**
     * B-04: fromPalette() memoizes per (metric, palette-bytes) identity; any
     * channel difference (alpha included) or metric change re-builds, and the
     * bounded cache drops the lot past the limit rather than growing without end.
     */
    public function testConstructionCachesByIdentity(): void
    {
        $palette = [new Color(9, 9, 9), new Color(240, 10, 10)];
        $a = PaletteIndex::fromPalette($palette);
        $b = PaletteIndex::fromPalette($palette);
        self::assertSame($a, $b, 'same palette + metric must hand back the same instance');

        self::assertNotSame($a, PaletteIndex::fromPalette($palette, ColorDistance::Euclidean));
        self::assertNotSame($a, PaletteIndex::fromPalette([new Color(9, 9, 9, 128), new Color(240, 10, 10)]));
        self::assertNotSame($a, PaletteIndex::fromPalette(array_reverse($palette)));

        $first = PaletteIndex::fromPalette([new Color(1, 1, 1)]);
        for ($i = 0; $i < 17; $i++) {
            PaletteIndex::fromPalette([new Color($i + 2, 0, 0)]);
        }
        // Past the 16-identity budget the cache is flushed wholesale: the old
        // singleton is no longer handed back (bounded memory, cheap rebuilds).
        self::assertNotSame($first, PaletteIndex::fromPalette([new Color(1, 1, 1)]));
    }

    /**
     * B-04: bare accessors; palette() hands back the list the index was built from.
     */
    public function testAccessors(): void
    {
        $palette = [new Color(1, 2, 3), new Color(4, 5, 6)];
        $index = PaletteIndex::fromPalette($palette, ColorDistance::Euclidean);

        self::assertSame(ColorDistance::Euclidean, $index->metric());
        self::assertSame($palette, $index->palette());
        self::assertSame(2, $index->count());
    }

    /**
     * B-04: end-to-end parity with the production hot path — an index over the
     * ANSI-256 palette must answer exactly what {@see NearestColor::ansi256()}
     * answers for a battery of derived corner/edge probes.
     */
    public function testMatchesAnsi256PathOnFixedPalette(): void
    {
        $queries = [];
        foreach (NearestColor::palette256() as $step => $entry) {
            if ($step % 17 === 0) {
                $queries[] = $entry;
            }
        }
        foreach ([[0, 0, 0], [255, 255, 255], [128, 128, 128], [250, 4, 160]] as [$r, $g, $b]) {
            $queries[] = new Color($r, $g, $b);
        }

        foreach ([ColorDistance::Euclidean, ColorDistance::Cie76] as $metric) {
            $matcher = new NearestColor($metric);
            $index = PaletteIndex::fromPalette(NearestColor::palette256(), $metric);
            foreach ($queries as $query) {
                self::assertSame(
                    $matcher->ansi256($query),
                    $index->nearest($query),
                    \sprintf('B-04 ansi256-palette divergence (%s) at %s', $metric->value, $query->toHex()),
                );
            }
        }
    }
}
