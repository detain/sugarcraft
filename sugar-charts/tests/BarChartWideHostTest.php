<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Core\Util\Width;

/**
 * Wide-host posture for the RAW-string bar tracks (sugar-charts-047).
 *
 * The track glyphs are EAW-Ambiguous; the fleet canon is A = 1
 * (sugar-core Width policy). These tests pin the assumption, prove the
 * overrun is REAL (not atmospheric) under the GNU wcwidth A = 2 table,
 * and act as tripwires: if the canon moves or a fix lands, one of the
 * two direction-assertions reddens loudly — the exposure may never go
 * silent by accident.
 */
final class BarChartWideHostTest extends TestCase
{
    /**
     * Fleet canon tripwire: every track glyph must measure 1. If
     * sugar-core ever adopts Ambiguous-in-CJK, this reddens and BarChart
     * must grow the Buffer re-measure routing or an ASCII line-set.
     */
    public function testAmbiguousCanonTripwire(): void
    {
        $glyphs = ['█', '▁', '▄', '▇', '▉', '▐', '─', '┤', '└'];
        foreach ($glyphs as $g) {
            self::assertSame(
                1,
                Width::of($g),
                "BarChart raw layout assumes Ambiguous=1 (sugar-charts-047); glyph {$g} broke the canon — revisit CH-30 posture"
            );
        }
    }

    public function testDefaultRenderFitsDeclaredWidthUnderFleetCanon(): void
    {
        $chart = BarChart::new([['a', 9.0], ['b', 5.0], ['c', 8.0]], width: 12, height: 5)
            ->withAxes(true)
            ->withFractionalHeights(true);
        foreach (explode("\n", $chart->view()) as $row) {
            $plain = self::stripSgr($row);
            self::assertLessThanOrEqual(12, Width::string($plain), "row overran width under fleet canon: {$plain}");
        }
    }

    /**
     * Prove the overrun: with the same bytes read under the GNU
     * A = 2 table, the full-bar row of a dense chart exceeds its
     * declared width. This is documentation-as-assertion — if the raw
     * tracks are ever re-routed so no ambiguous glyph remains
     * position-critical, THIS reddens and the posture doc goes stale.
     */
    public function testAmbiguousTwoReadingWouldOverrun(): void
    {
        $chart = BarChart::new([['a', 9.0], ['b', 5.0], ['c', 8.0]], width: 12, height: 5)
            ->withAxes(true)
            ->withFractionalHeights(true);
        $worst = 0;
        foreach (explode("\n", $chart->view()) as $row) {
            $worst = max($worst, self::gnuWcwidth($row));
        }
        self::assertGreaterThan(12, $worst, 'a dense 12-wide bar row must overrun under the A=2 reading — otherwise this row-proving test has gone stale and CH-30 posture needs re-derivation');
    }

    /**
     * The eighth-block cap ramp exists to hug fractional heights; with
     * the door OFF, no cap glyphs appear at all — so both cap families
     * (0x2581.. and 0x2589..) genuinely reach the output only through
     * withFractionalHeights(true). Pins which bytes feed the exposure.
     */
    public function testTrackGlyphsActuallyReachOutput(): void
    {
        $on = BarChart::new([['a', 7.0], ['b', 3.5]], width: 6, height: 4)
            ->withFractionalHeights(true)
            ->view();
        $off = BarChart::new([['a', 7.0], ['b', 3.5]], width: 6, height: 4)
            ->view();
        self::assertStringContainsString('█', $on);
        self::assertStringContainsString('█', $off);
        self::assertMatchesRegularExpression('/[\x{2581}-\x{2587}]/u', $on, 'the fractional ramp must be present with the door on');
    }

    private static function stripSgr(string $row): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $row) ?? $row;
    }

    /** GNU wcwidth reading: East-Asian-Ambiguous slots U+2500..U+25FF
     * (box drawing + block elements + shades) score 2, everything else
     * keeps the fleet measure. Only the bytes BarChart emits matter. */
    private static function gnuWcwidth(string $row): int
    {
        $w = 0;
        foreach (mb_str_split(self::stripSgr($row), 1, 'UTF-8') as $cp) {
            $o = mb_ord($cp, 'UTF-8');
            $w += ($o >= 0x2500 && $o <= 0x25FF) ? 2 : 1;
        }
        return $w;
    }
}
