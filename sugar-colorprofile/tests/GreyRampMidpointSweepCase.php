<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Dense sweep of every color on a grey-ramp midpoint sum (avg = 13, 23, …,
 * 233 → r+g+b = 39, 69, …, 699). This is the exact set the old round-up pick
 * mis-binned; the probe must land the lower bin, i.e. the brute-force
 * lowest-index winner, every time.
 *
 * The 23 sums are dealt round-robin to SLICES files, one per subclass, because
 * the whole sweep is 558,401 colors (about a minute) and ParaTest gives a
 * worker one whole file at a time. GreyRampEquivalenceTest pins that the
 * slices partition the original sum list exactly.
 */
abstract class GreyRampMidpointSweepCase extends TestCase
{
    use GreyRampBruteForce;

    public const SLICES = 4;

    /** Colors per slice, derived from the loops below; a slice that loses any trips its pin. */
    private const EXPECTED = [139776, 139776, 139776, 139073];

    /** Which of the SLICES this file sweeps, 0-based. */
    abstract protected static function slice(): int;

    /** @return list<int> the r+g+b sums the given slice sweeps */
    public static function sumsOf(int $slice): array
    {
        $sums = [];
        foreach (range(39, 699, 30) as $i => $sum) {
            if ($i % self::SLICES === $slice) {
                $sums[] = $sum;
            }
        }

        return $sums;
    }

    public function testEveryGreyRampMidpointColorInThisSliceResolvesToTheBruteForceBin(): void
    {
        $checked = 0;
        foreach (self::sumsOf(static::slice()) as $sum) {
            for ($r = max(0, $sum - 510); $r <= min(255, $sum); $r++) {
                for ($g = max(0, $sum - $r - 255); $g <= min(255, $sum - $r); $g++) {
                    $this->assertColorAgreesWithBruteForce($r, $g, $sum - $r - $g);
                    $checked++;
                }
            }
        }
        self::assertSame(self::EXPECTED[static::slice()], $checked, 'midpoint band slice must stay dense');
    }
}
