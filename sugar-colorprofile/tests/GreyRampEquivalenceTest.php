<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;

/**
 * Brute-force equivalence pins for Color::toAnsi256Index().
 *
 * The grey-ramp single pick by RGB mean rounded one bin UP on an exact
 * ramp midpoint (avg = 13, 23, …, 233), so the returned index could sit
 * one slot away from the brute-force winner while being equidistant. The
 * encoder now probes bin-1, bin, bin+1 and keeps the first strict minimum,
 * making it identical to a lowest-index scan over all 240 fixed slots
 * (16-255) in squared RGB — with one documented, deliberate exception:
 * per-channel cube midpoints 115/155/195/235 keep SC's round-UP
 * convention (shared with sugar-core's private Color::nearest256();
 * chafa's channel LUT floors instead). The two slots are equidistant, so
 * the convention never costs distance — these pins prove exactly that:
 * minimal distance on EVERY sampled color, and index equality everywhere
 * outside the documented cube-midpoint set.
 *
 * The reference table is DERIVED from Color::fromAnsi256Index() itself, so
 * a table change cannot mask an encoder regression (the oracle lives in
 * GreyRampBruteForce). The two dense sweeps, the stride-4 cube and every
 * grey-ramp midpoint color, run as slice files (GreyRampStrideSweepSlice*Test,
 * GreyRampMidpointSweepSlice*Test) so ParaTest can spread them over workers;
 * this file pins that the slices still partition them. The exhaustive
 * 16,777,216-color sweep is opt-in via COLORPROFILE_FULL_SWEEP=1 and tagged
 * @group slow so default runs skip it without hiding the coverage claim.
 */
final class GreyRampEquivalenceTest extends TestCase
{
    use GreyRampBruteForce;

    /**
     * The two dense sweeps moved to slice files (GreyRampMidpointSweepCase,
     * GreyRampStrideSweepCase). This pins that the slices still cover exactly
     * what the single-file sweeps did: every grey-ramp midpoint sum
     * 39, 69, …, 699 and every stride-4 red value 0, 4, …, 252, each once.
     */
    public function testTheSweepSlicesPartitionTheOriginalSweeps(): void
    {
        $sums = [];
        for ($slice = 0; $slice < GreyRampMidpointSweepCase::SLICES; $slice++) {
            self::assertTrue(
                class_exists(__NAMESPACE__ . "\\GreyRampMidpointSweepSlice{$slice}Test"),
                "midpoint slice {$slice} has no test file",
            );
            array_push($sums, ...GreyRampMidpointSweepCase::sumsOf($slice));
        }
        sort($sums);
        self::assertSame(range(39, 699, 30), $sums, 'midpoint slices must cover every ramp midpoint sum exactly once');

        $reds = [];
        for ($slice = 0; $slice < GreyRampStrideSweepCase::SLICES; $slice++) {
            self::assertTrue(
                class_exists(__NAMESPACE__ . "\\GreyRampStrideSweepSlice{$slice}Test"),
                "stride slice {$slice} has no test file",
            );
            array_push($reds, ...GreyRampStrideSweepCase::redsOf($slice));
        }
        sort($reds);
        self::assertSame(range(0, 252, 4), $reds, 'stride slices must cover every stride-4 red value exactly once');
    }

    /**
     * Hand-computed midpoint pins (the shipped ±1 behaviour). Each pair
     * of ramp levels is equidistant; the lower slot must win.
     *
     * @return array<string, array{array{int,int,int}, int}>
     */
    public static function midpointPinProvider(): array
    {
        return [
            // avg 13: levels 8 (232) and 18 (233) tie at d=75 -> 232
            'avg 13'            => [[13, 13, 13], 232],
            // avg 63: levels 58 (237) and 68 (238) tie at d=75 -> 237
            'avg 63 charcoal'   => [[63, 63, 63], 237],
            // avg 123: levels 118 (243) and 128 (244) tie at d=75 -> 243
            'avg 123'           => [[123, 123, 123], 243],
            // avg 233: levels 228 (254) and 238 (255) tie at d=75 -> 254
            'avg 233'           => [[233, 233, 233], 254],
            // off-diagonal midpoint: avg 23 with unequal channels still ties
            // at d=1089 -> lower level 18 (233); cube (0,0,0) is farther at 2601
            'sum 69 uneven'     => [[0, 45, 24], 233],
        ];
    }

    /**
     * The exact bins the pre-fix round-up pick mis-resolved.
     *
     * @param array{int,int,int} $rgb
     * @dataProvider midpointPinProvider
     */
    public function testExactRampMidpointsResolveToLowerBruteForceBin(array $rgb, int $expected): void
    {
        [$r, $g, $b] = $rgb;
        self::assertSame($expected, (new Color($r, $g, $b))->toAnsi256Index());
        [$idx] = self::brute($r, $g, $b);
        self::assertSame($idx, $expected, 'pin must agree with brute force');
    }

    /**
     * Parity note: cube-midpoint channels keep SC's round-UP convention.
     * The chosen and rejected slots are equidistant (distance leg still
     * pinned); the index leg records WHICH side of the arbitrary tie we keep,
     * as the survey ruled. sugar-core's nearest256() shares the round-up;
     * chafa's 216-cube channel LUT floors (115 -> level below) instead.
     *
     * @return array<string, array{array{int,int,int}, int}>
     */
    public static function cubeMidpointConventionProvider(): array
    {
        return [
            // r=115 ties levels 95 (idx 52) and 135 (idx 88) at d=1200 -> 88
            'r 115'        => [[115, 0, 0], 88],
            // r=155 ties 135 and 175 (level 3) -> level 3: 16+108+6+1 = 131
            'r 155'        => [[155, 64, 64], 131],
            // all three at 115: (135,135,0) = 100, not the lower (95,95,0) 58
            'rg 115'       => [[115, 115, 0], 100],
            // 235 is not a grey midpoint (midpoints are …,233); grey 238
            // strictly wins over the round-up cube -> convention does not bite
            'white-ish 235' => [[235, 235, 235], 255],
        ];
    }

    /**
     * @param array{int,int,int} $rgb
     * @dataProvider cubeMidpointConventionProvider
     */
    public function testCubeMidpointsKeepTheDocumentedRoundUpConvention(array $rgb, int $expected): void
    {
        [$r, $g, $b] = $rgb;
        $enc = (new Color($r, $g, $b))->toAnsi256Index();
        self::assertSame($expected, $enc);
        $this->assertColorAgreesWithBruteForce($r, $g, $b);
    }

    /**
     * Dense distance-only sweep across every cube-midpoint plane (one
     * channel exactly at a midpoint, others stepped) — the round-up must never
     * cost distance, only the arbitrary slot choice.
     */
    public function testCubeMidpointPlanesNeverLoseDistance(): void
    {
        $checked = 0;
        foreach (self::CUBE_MIDPOINTS as $m) {
            for ($x = 0; $x <= 255; $x += 5) {
                for ($y = 0; $y <= 255; $y += 5) {
                    $this->assertColorAgreesWithBruteForce($m, $x, $y);
                    $this->assertColorAgreesWithBruteForce($x, $m, $y);
                    $this->assertColorAgreesWithBruteForce($x, $y, $m);
                    $checked += 3;
                }
            }
        }
        self::assertGreaterThan(30000, $checked, 'midpoint-plane sweep must be dense');
    }

    /**
     * The exhaustive claim behind the ±1 probe fix — every one of the
     * 16,777,216 colors agrees with brute force on distance, and on index
     * outside the documented cube-midpoint set. Too slow for default CI
     * (tens of minutes in PHP), so it is env-gated and group-tagged.
     *
     * @group slow
     */
    public function testFullSweepOfAll16Point7MColors(): void
    {
        if (getenv('COLORPROFILE_FULL_SWEEP') !== '1') {
            self::markTestSkipped(
                'exhaustive 16,777,216-color sweep; run with COLORPROFILE_FULL_SWEEP=1 '
                . 'vendor/bin/phpunit --group slow (tens of minutes in PHP)'
            );
        }
        $checked = 0;
        for ($r = 0; $r <= 255; $r++) {
            for ($g = 0; $g <= 255; $g++) {
                for ($b = 0; $b <= 255; $b++) {
                    $this->assertColorAgreesWithBruteForce($r, $g, $b);
                    $checked++;
                }
            }
        }
        self::assertSame(16777216, $checked, 'full sweep must cover the whole cube');
    }
}
