<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Stride-4 sweep of the whole RGB cube (262,144 colors). No stride-4 channel
 * hits an odd cube midpoint and no stride-4 sum hits a grey ramp midpoint, so
 * the full set asserts the strong claim: index AND distance equal brute force.
 *
 * The 64 red steps are dealt round-robin to SLICES files, one per subclass,
 * because ParaTest gives a worker one whole file at a time and the sweep is
 * about half a minute. GreyRampEquivalenceTest pins that the slices partition
 * the 64 steps exactly.
 */
abstract class GreyRampStrideSweepCase extends TestCase
{
    use GreyRampBruteForce;

    public const SLICES = 2;

    /** Which of the SLICES this file sweeps, 0-based. */
    abstract protected static function slice(): int;

    /** @return list<int> the red values the given slice sweeps */
    public static function redsOf(int $slice): array
    {
        $reds = [];
        foreach (range(0, 252, 4) as $i => $r) {
            if ($i % self::SLICES === $slice) {
                $reds[] = $r;
            }
        }

        return $reds;
    }

    public function testStrideSweepSliceAgreesWithBruteForceOnEveryColor(): void
    {
        $checked = 0;
        foreach (self::redsOf(static::slice()) as $r) {
            for ($g = 0; $g <= 252; $g += 4) {
                for ($b = 0; $b <= 252; $b += 4) {
                    $this->assertColorAgreesWithBruteForce($r, $g, $b);
                    $checked++;
                }
            }
        }
        self::assertSame(262144 / self::SLICES, $checked, 'stride sweep slice must cover its share of 64^3 colors');
    }
}
