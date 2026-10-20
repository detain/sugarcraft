<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Dither\Ditherer;

/**
 * C-01: kernel-conservation and impulse-response pins for the error-diffusion
 * tables in {@see Ditherer::diffuseError()}.
 *
 * Form per plan_chafa S2.1 (C-06 relocated the kernel to the shared
 * Ditherer): drive the public static diffuseError() on a
 * 5×4 accumulator with a unit error at the cell that keeps EVERY kernel tap in
 * bounds (x=2 of width 5, y=1 of height 4 — the widest row reaches x±2 and the
 * deepest row y+2), then assert
 *
 *  1. the exact per-cell canonical layout (offsets AND weights) per algorithm,
 *  2. the propagated totals: Floyd–Steinberg == 16/16 (whole error),
 *     Stucki == 42/42 (whole error), Atkinson == 6/8 (the deliberate ¾ —
 *     Atkinson is the one algorithm that intentionally drops a quarter).
 *
 * Because the impulse sits fully inside the grid, no tap is ever silently
 * dropped by the bounds clamp: the sum legs can only go red if the table
 * itself loses or gains weight — exactly the C-01 regression (a Stucki y+1 row
 * duplicated from y+2 summed 32/42 and 24% of the error vanished with it).
 * The per-cell legs additionally catch any future weight edit regardless of
 * algorithm, including one that preserves the total by moving weight between
 * neighbours.
 */
final class DitherKernelTest extends TestCase
{
    /** Accumulator width used by every pin (kernel widest reach is x±2). */
    private const W = 5;

    /** Accumulator height used by every pin (kernel deepest reach is y+2). */
    private const H = 4;

    /** Impulse column — x-2 .. x+2 all land inside the grid. */
    private const X = 2;

    /** Impulse row — y .. y+2 all land inside the grid. */
    private const Y = 1;

    /**
     * Canonical kernels as [dx, dy, numerator, denominator] triples per
     * algorithm — the arithmetic Burckhardt's 42-weight Stucki reduction and
     * the classic FS 16ths, spelled out independently of the implementation so
     * a drift in the source table shows up here as a red, never as a matching
     * pair of wrong numbers.
     *
     * @var array<string, list<array{int,int,float}> >
     */
    private const CANONICAL = [
        'floyd-steinberg' => [
            [1, 0, 7.0 / 16.0],
            [-1, 1, 3.0 / 16.0],
            [0, 1, 5.0 / 16.0],
            [1, 1, 1.0 / 16.0],
        ],
        'stucki' => [
            [1, 0, 8.0 / 42.0],
            [2, 0, 4.0 / 42.0],
            [-2, 1, 2.0 / 42.0],
            [-1, 1, 4.0 / 42.0],
            [0, 1, 8.0 / 42.0],
            [1, 1, 4.0 / 42.0],
            [2, 1, 2.0 / 42.0],
            [-2, 2, 1.0 / 42.0],
            [-1, 2, 2.0 / 42.0],
            [0, 2, 4.0 / 42.0],
            [1, 2, 2.0 / 42.0],
            [2, 2, 1.0 / 42.0],
        ],
        'atkinson' => [
            [1, 0, 1.0 / 8.0],
            [2, 0, 1.0 / 8.0],
            [-1, 1, 1.0 / 8.0],
            [0, 1, 1.0 / 8.0],
            [1, 1, 1.0 / 8.0],
            [0, 2, 1.0 / 8.0],
        ],
    ];

    /**
     * Whole-error conservation expectations: FS diffuses its entire error
     * (16/16) and Stucki its entire error (42/42); Atkinson deliberately
     * diffuses only ¾ (6/8), so its expectation is 0.75 by design.
     */
    private const CONSERVED = [
        'floyd-steinberg' => 1.0,
        'stucki' => 1.0,
        'atkinson' => 0.75,
    ];

    private const EPSILON = 1e-12;

    /**
     * C-01: the canonical tables themselves must sum to their advertised
     * totals (16/16, 42/42, 6/8). This is the test's own ground truth — if it
     * goes red the expectations above drifted, not the implementation.
     */
    public function testCanonicalTablesSumToTheirClaimedTotals(): void
    {
        $expectedFractions = [
            'floyd-steinberg' => [16.0, 16.0],
            'stucki' => [42.0, 42.0],
            'atkinson' => [6.0, 8.0],
        ];

        foreach (self::CANONICAL as $name => $kernel) {
            $sum = array_sum(array_column($kernel, 2));
            [$num, $den] = $expectedFractions[$name];

            $this->assertEqualsWithDelta(
                $num / $den,
                $sum,
                self::EPSILON,
                "canonical $name kernel must sum to $num/$den",
            );
        }
    }

    /**
     * C-01: per-cell impulse response — every canonical tap lands at exactly
     * its canonical offset with exactly its canonical weight, and nothing
     * else moves. Runs on the R, G and B channels independently so a
     * channel-skewed weight can't hide.
     */
    public function testImpulseResponseMatchesCanonicalOffsetsAndWeights(): void
    {
        foreach ($this->algorithmMap() as $name => $dither) {
            foreach ([0, 1, 2] as $channel) {
                $accum = $this->diffuse($dither, $channel);

                $expected = $this->expectedGrid(self::CANONICAL[$name]);
                for ($y = 0; $y < self::H; $y++) {
                    for ($x = 0; $x < self::W; $x++) {
                        $this->assertEqualsWithDelta(
                            $expected[$y][$x],
                            $accum[$y][$x][$channel],
                            self::EPSILON,
                            "$name: accumulator cell ($x,$y) channel $channel",
                        );
                    }
                }
            }
        }
    }

    /**
     * C-01: conservation totals — FS == 1.0 (16/16), Stucki == 1.0 (42/42),
     * Atkinson == 0.75 (6/8). The pre-fix Stucki arm (y+1 row duplicated from
     * y+2: 1,2,4,2,1) sums 32/42 and reddens exactly here and in the impulse
     * pins for the five y+1 cells.
     */
    public function testPropagatedTotalsConserveTheClaimedError(): void
    {
        foreach ($this->algorithmMap() as $name => $dither) {
            $accum = $this->diffuse($dither, 0);

            $total = 0.0;
            for ($y = 0; $y < self::H; $y++) {
                for ($x = 0; $x < self::W; $x++) {
                    $total += $accum[$y][$x][0];
                }
            }

            $this->assertEqualsWithDelta(
                self::CONSERVED[$name],
                $total,
                self::EPSILON,
                "$name: total propagated error must equal " . self::CONSERVED[$name],
            );
        }
    }

    /**
     * Drive the shared Ditherer::diffuseError() with a unit error on one channel.
     *
     * The impulse grid in {@see testImpulseResponseMatchesCanonicalOffsetsAndWeights()}
     * already pins that every cell no canonical tap names — including the
     * diffusing pixel's own cell — stays exactly 0.0.
     *
     * @return list<list<array{float,float,float}>>
     */
    private function diffuse(Dither $dither, int $channel): array
    {
        $accum = [];
        for ($y = 0; $y < self::H; $y++) {
            $row = [];
            for ($x = 0; $x < self::W; $x++) {
                $row[] = [0.0, 0.0, 0.0];
            }
            $accum[] = $row;
        }

        $errors = [0.0, 0.0, 0.0];
        $errors[$channel] = 1.0;

        Ditherer::diffuseError(
            $accum, self::W, self::H, self::X, self::Y, $errors[0], $errors[1], $errors[2], $dither,
        );

        return $accum;
    }

    /**
     * Materialise the canonical tap list into a full H×W grid of expected
     * weights for the swept channel.
     *
     * @param list<array{int,int,float}> $kernel
     * @return list<list<float>>
     */
    private function expectedGrid(array $kernel): array
    {
        $grid = array_fill(0, self::H, array_fill(0, self::W, 0.0));
        foreach ($kernel as [$dx, $dy, $weight]) {
            $grid[self::Y + $dy][self::X + $dx] = $weight;
        }

        return $grid;
    }

    /**
     * @return array<string, Dither>
     */
    private function algorithmMap(): array
    {
        return [
            'floyd-steinberg' => Dither::FloydSteinberg,
            'stucki' => Dither::Stucki,
            'atkinson' => Dither::Atkinson,
        ];
    }
}
