<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\NearestColor;

/**
 * B-07: error-compensated palette lookup pins for NearestColor::withError().
 *
 * The contract mirrors chafa chafa-palette.c:1160-1197: the query is pushed by
 * the accumulated error damped 0.9/16, the entry is chosen for the COMPENSATED
 * query, and the written-back residual uses the UNCLAMPED compensated value so
 * a pinned channel keeps its push. Null is the plain-nearest passthrough.
 * Expected values are derived with the same arithmetic order the implementation
 * documents (`channel + (error * 0.9) / 16`) — the damping constant appears as
 * a literal 0.9 on this side, so mutating ERROR_DAMPING reddens these pins.
 */
final class NearestColorErrorCompensationTest extends TestCase
{
    /**
     * B-07: a null accumulator is pure passthrough — same answer as
     * ansi256() for both metric families, and the referenced variable is left
     * untouched (still null, no write).
     */
    public function testNullPassthroughIsPlainNearest(): void
    {
        $queries = [
            new Color(0, 0, 0),
            new Color(255, 255, 255),
            new Color(128, 128, 128),
            new Color(17, 200, 88),
        ];

        foreach ([ColorDistance::Euclidean, ColorDistance::Cie76, ColorDistance::Cie2000] as $metric) {
            $matcher = new NearestColor($metric);
            foreach ($queries as $query) {
                $err = null;
                self::assertSame($matcher->ansi256($query), $matcher->withError($query, $err));
                self::assertNull($err, 'passthrough must not materialize an accumulator');
            }
        }
    }

    /**
     * B-07: a zero accumulator picks the plain-nearest entry and writes back
     * the full quantization residual (query minus found, per channel, floats).
     */
    public function testZeroErrorChoosesPlainNearestAndWritesResidual(): void
    {
        $matcher = new NearestColor(ColorDistance::Euclidean);
        $query = new Color(30, 70, 200);
        $err = [0.0, 0.0, 0.0];

        $index = $matcher->withError($query, $err);

        self::assertSame($matcher->ansi256($query), $index);
        $found = NearestColor::palette256()[$index];
        self::assertSame(
            [(float) (30 - $found->r), (float) (70 - $found->g), (float) (200 - $found->b)],
            $err,
        );
    }

    /**
     * B-07: the damped feedback MOVES the selection — with an error pushing
     * across a quantization boundary, the chosen entry is the one the
     * compensated query would pick plain, and it differs from the uncompensated
     * answer (the non-vacuity leg; without feedback the two legs would collapse).
     */
    public function testDampedErrorCompensationMovesSelection(): void
    {
        $matcher = new NearestColor(ColorDistance::Euclidean);
        $query = new Color(90, 90, 90);

        $compensated = [
            90.0 + (120.0 * 0.9) / 16,
            90.0 + (120.0 * 0.9) / 16,
            90.0 + (0.0 * 0.9) / 16,
        ];
        $expected = $matcher->ansi256(new Color(
            (int) round($compensated[0]),
            (int) round($compensated[1]),
            (int) round($compensated[2]),
        ));

        $err = [120.0, 120.0, 0.0];
        $index = $matcher->withError($query, $err);

        self::assertSame($expected, $index);
        self::assertNotSame($matcher->ansi256($query), $index, 'feedback must change this fixture');

        $found = NearestColor::palette256()[$index];
        self::assertSame([
            $compensated[0] - $found->r,
            $compensated[1] - $found->g,
            $compensated[2] - $found->b,
        ], $err, 'residual must be the compensated query minus the found entry');
    }

    /**
     * B-07: the residual comes from the UNCLAMPED compensated value (chafa
     * :1189-1192) — white pushed to 264 still LOOKS UP clamped 255, but the
     * written error keeps the full +9 so the next cell inherits the push.
     * Pinning the exact expression `255 + (160 * 0.9) / 16` catches any change
     * to the damping constant or its consumption order.
     */
    public function testResidualUsesUnclampedCompensatedQuery(): void
    {
        $matcher = new NearestColor(ColorDistance::Euclidean);
        $err = [160.0, 160.0, 160.0];

        $index = $matcher->withError(new Color(255, 255, 255), $err);

        // Lookup side: clamped to the pure-white entry (first minimizer by the
        // lowest-index rule — derived, never hard-coded).
        self::assertSame($matcher->ansi256(new Color(255, 255, 255)), $index);
        $found = NearestColor::palette256()[$index];

        $expected = 255 + (160 * 0.9) / 16 - $found->r;
        self::assertSame($expected, $err[0]);
        self::assertGreaterThan(0.0, $err[0], 'the push survives the clamp');
    }

    /**
     * B-07: negative feedback pulls the query down and clamps at zero for the
     * lookup, while the residual stays unclamped (keeps the drag).
     */
    public function testNegativeErrorPullsAndKeepsDrag(): void
    {
        $matcher = new NearestColor(ColorDistance::Euclidean);
        $err = [-32.0, -300.0, 0.0];

        $index = $matcher->withError(new Color(1, 1, 1), $err);

        $pullR = 1 + (-32.0 * 0.9) / 16;
        $pullG = 1 + (-300.0 * 0.9) / 16;
        $expected = $matcher->ansi256(new Color(max(0, (int) round($pullR)), 0, 1));
        self::assertSame($expected, $index);

        $found = NearestColor::palette256()[$index];
        self::assertSame($pullR - $found->r, $err[0]);
        self::assertSame($pullG - $found->g, $err[1], 'deep negative drag recorded, not clamped away');
    }

    /**
     * B-07: the chain — three lookups feeding one accumulator forward, the
     * shape an ordered-dither scanline uses. Expectations replay the documented
     * recurrence independently of the implementation's internals.
     */
    public function testChainFeedsForwardAcrossLookups(): void
    {
        $matcher = new NearestColor(ColorDistance::Euclidean);
        $sequence = [new Color(41, 42, 43), new Color(44, 45, 46), new Color(40, 200, 90)];

        $err = [0.0, 0.0, 0.0];
        $got = [];
        foreach ($sequence as $query) {
            $got[] = $matcher->withError($query, $err);
        }

        // Reference replay of chafa :1160-1197 in plain floats.
        $ref = [0.0, 0.0, 0.0];
        $want = [];
        foreach ($sequence as $query) {
            $comp = [
                $query->r + ($ref[0] * 0.9) / 16,
                $query->g + ($ref[1] * 0.9) / 16,
                $query->b + ($ref[2] * 0.9) / 16,
            ];
            $idx = $matcher->ansi256(new Color(
                (int) round($comp[0]),
                (int) round($comp[1]),
                (int) round($comp[2]),
            ));
            $found = NearestColor::palette256()[$idx];
            $ref = [$comp[0] - $found->r, $comp[1] - $found->g, $comp[2] - $found->b];
            $want[] = $idx;
        }

        self::assertSame($want, $got);
        self::assertSame($ref, $err, 'terminal accumulator must match the replay');
        // Non-vacuity: the replayed chain must actually accumulate a residual
        // (a zero-everywhere fixture would pass any recurrence pin vacuously).
        self::assertNotSame([0.0, 0.0, 0.0], $ref);
    }

    /**
     * B-07: malformed accumulators fail fast — wrong arity, non-list keys, or
     * non-numeric channels are programming errors, never silently zeroed.
     */
    #[DataProvider('malformedAccumulators')]
    public function testRejectsMalformedAccumulator(array $err): void
    {
        $matcher = new NearestColor();
        $this->expectException(\InvalidArgumentException::class);
        $matcher->withError(new Color(1, 2, 3), $err);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedAccumulators(): iterable
    {
        yield 'two channels' => [[1.0, 2.0]];
        yield 'four channels' => [[1.0, 2.0, 3.0, 4.0]];
        yield 'string keyed' => [['r' => 1.0, 'g' => 2.0, 'b' => 3.0]];
        yield 'non-numeric channel' => [[1.0, 'hot', 3.0]];
    }

    /**
     * B-07: the palette searched is the ANSI-256 table itself (index domain
     * 0-255, residual measured against palette256()), on the Cie76 metric too.
     */
    public function testCie76MatcherCompensatesInItsOwnSpace(): void
    {
        $matcher = new NearestColor(ColorDistance::Cie76);
        $query = new Color(120, 121, 122);
        $err = [64.0, 0.0, -64.0];

        $comp = [
            (int) round(120 + (64.0 * 0.9) / 16),
            121,
            (int) round(122 + (-64.0 * 0.9) / 16),
        ];
        $index = $matcher->withError($query, $err);

        self::assertSame($matcher->ansi256(new Color($comp[0], $comp[1], $comp[2])), $index);
        $found = NearestColor::palette256()[$index];
        self::assertSame([
            (120.0 + (64.0 * 0.9) / 16) - $found->r,
            121.0 - $found->g,
            (122.0 + (-64.0 * 0.9) / 16) - $found->b,
        ], $err);
    }
}
