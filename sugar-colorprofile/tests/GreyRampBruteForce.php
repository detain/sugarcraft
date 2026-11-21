<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\Color;

/**
 * The brute-force oracle behind the grey-ramp equivalence pins: a lowest-index
 * scan over all 240 fixed slots (16-255) in squared RGB, with the reference
 * table DERIVED from Color::fromAnsi256Index() itself, so a table change cannot
 * mask an encoder regression.
 *
 * Shared by GreyRampEquivalenceTest and the sweep slices
 * (GreyRampMidpointSweepCase, GreyRampStrideSweepCase), which carry the two
 * dense sweeps in several files so ParaTest can run them on several workers:
 * it dispatches whole files, and the sweeps alone were 90 s of one file.
 */
trait GreyRampBruteForce
{
    /** Channel values whose exact cube tie SC resolves UP; the index equality leg is suspended there. */
    private const CUBE_MIDPOINTS = [115, 155, 195, 235];

    /** @var list<array{int,int,int}> squared-RGB reference slots 16..255 */
    private static array $slotRgb = [];

    /** @return list<array{int,int,int}> */
    private static function slotTable(): array
    {
        if (self::$slotRgb === []) {
            for ($i = 16; $i <= 255; $i++) {
                $p = Color::fromAnsi256Index($i);
                self::$slotRgb[] = [$p->r, $p->g, $p->b];
            }
        }
        return self::$slotRgb;
    }

    /** @return array{int,int} [lowest-index winner, minimal squared-RGB distance] */
    private static function brute(int $r, int $g, int $b): array
    {
        $best = 16;
        $bestDist = -1;
        foreach (self::slotTable() as $slot => [$pr, $pg, $pb]) {
            $d = ($r - $pr) ** 2 + ($g - $pg) ** 2 + ($b - $pb) ** 2;
            if ($bestDist < 0 || $d < $bestDist) {
                $bestDist = $d;
                $best = 16 + $slot;
            }
        }
        return [$best, $bestDist];
    }

    /**
     * One sampled color: distance is always minimal; the index matches the
     * brute lowest-index winner unless the color sits on a cube midpoint.
     */
    private function assertColorAgreesWithBruteForce(int $r, int $g, int $b): void
    {
        $enc = (new Color($r, $g, $b))->toAnsi256Index();
        $p = Color::fromAnsi256Index($enc);
        $encDist = ($r - $p->r) ** 2 + ($g - $p->g) ** 2 + ($b - $p->b) ** 2;
        [$idx, $dist] = self::brute($r, $g, $b);

        self::assertSame($dist, $encDist, "encoder distance not minimal at ({$r},{$g},{$b})");
        if (!in_array($r, self::CUBE_MIDPOINTS, true)
            && !in_array($g, self::CUBE_MIDPOINTS, true)
            && !in_array($b, self::CUBE_MIDPOINTS, true)) {
            self::assertSame($idx, $enc, "encoder index disagrees with brute force at ({$r},{$g},{$b})");
        }
    }
}
