<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Core\Util\Width;

/**
 * CH-11 (sugar-charts-017, -032, -098): vertical-mode geometry pins.
 *
 * - 017: with the axis on, body rows start at the '┤' gutter and the
 *   data-label row pads a matching space — the bottom category-label row
 *   used to start at column 0, shifting every label one cell left of its
 *   bar. No golden pinned the combined shape.
 * - 032: the default-geometry fit guard ignored a configured
 *   barWidth/barGap, so explicit shapes painted past the declared width.
 * - 098: all-equal negative data synthesized a v..v+1 range, normalizing
 *   every bar to height 0 — an invisible plot; Sparkline's steady mid-bar
 *   honesty posture applies instead.
 */
final class VerticalGutterTest extends TestCase
{
    /** Character columns at which each '█' run starts in a plain row. */
    private static function bodyRunColumns(string $row): array
    {
        $cols = [];
        $prev = '';
        foreach (preg_split('//u', $row, -1, PREG_SPLIT_NO_EMPTY) as $i => $ch) {
            if ($ch === '█' && $prev !== '█') {
                $cols[] = $i;
            }
            $prev = $ch;
        }
        return $cols;
    }

    private static function plain(string $out): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $out) ?? '';
    }

    public function testCategoryLabelColumnsMatchBarColumnsUnderAxis(): void
    {
        // sugar-charts-017 bites with the axis ON: body rows start at the
        // '┤' gutter, and the category row used to start at column 0.
        // width 12, 3 bars → auto gap 1 (12 ≥ 5), colW intdiv(12-2, 3) = 3:
        // bar runs start at columns 1, 5, 9 after the gutter column.
        $chart = BarChart::new([['a', 3.0], ['b', 7.0], ['c', 5.0]], 12, 6)->withAxes();
        $rows  = explode("\n", self::plain($chart->view()));
        $label = $rows[count($rows) - 1];
        $body  = null;
        foreach ($rows as $row) {
            if (substr_count($row, '█') === 9) { // bottom body row: all three bars filled
                $body = $row;
                break;
            }
        }
        self::assertNotNull($body, 'no body row paints all three bars');
        self::assertSame([1, 5, 9], self::bodyRunColumns($body));
        $labelCols = array_map(
            static fn(string $l): int => mb_strpos($label, $l, 0, 'UTF-8'),
            ['a', 'b', 'c'],
        );
        self::assertSame(self::bodyRunColumns($body), $labelCols, 'category labels sit off their bars');
        // Axes-off parity: labels still land on their bar columns.
        $bareRows  = explode("\n", self::plain(BarChart::new([['a', 3.0], ['b', 7.0], ['c', 5.0]], 12, 6)->view()));
        $bareLabel = $bareRows[count($bareRows) - 1];
        $bareBody  = null;
        foreach ($bareRows as $row) {
            if (substr_count($row, '█') === 9) {
                $bareBody = $row;
                break;
            }
        }
        self::assertNotNull($bareBody);
        self::assertSame(
            self::bodyRunColumns($bareBody),
            array_map(static fn(string $l): int => mb_strpos($bareLabel, $l, 0, 'UTF-8'), ['a', 'b', 'c']),
        );
    }

    public function testGutterPrefixConsistentAcrossAllThreeRows(): void
    {
        // colW is identical in both fixtures (single bar, width 8), so the
        // axis mode must shift every row's content exactly one column right.
        $onRows  = explode("\n", self::plain(BarChart::new(['a' => 5.0], 8, 4)->withAxes()->withDataLabels()->view()));
        $offRows = explode("\n", self::plain(BarChart::new(['a' => 5.0], 8, 4)->withDataLabels()->view()));
        $onValue  = $onRows[0];
        $offValue = $offRows[0];
        self::assertSame(
            mb_strpos($offValue, '5') + 1,
            mb_strpos($onValue, '5'),
            'data-label row lost/moved its gutter pad'
        );
        $onLabel  = $onRows[count($onRows) - 1];
        $offLabel = $offRows[count($offRows) - 1];
        self::assertSame(
            mb_strpos($offLabel, 'a') + 1,
            mb_strpos($onLabel, 'a'),
            'category row lost its gutter pad (017 regression)'
        );
        self::assertSame(1, mb_strpos($onLabel, 'a'), 'gutter must be exactly one cell');
    }

    public function testExplicitBarWidthSlicesToDeclaredWidth(): void
    {
        $bars = [];
        for ($i = 0; $i < 8; $i++) {
            $bars[] = ['b' . $i, (float) (8 - $i)];
        }
        // colW 2 pinned, auto gap collapses to 0 (10 < 15); under the axis
        // gutter capacity = intdiv((10-1)+0, 2+0) = 4 → bars 4..7 drop.
        $chart = BarChart::new($bars, 10, 4)->withAxes()->withBarWidth(2);
        $out   = $chart->view();
        foreach (explode("\n", $out) as $row) {
            self::assertLessThanOrEqual(10, Width::string($row), "row exceeds declared width: {$row}");
        }
        self::assertStringNotContainsString('b4', $out, 'over-capacity tail still rendered');
        self::assertStringContainsString('b0', $out);
        // Pre-fix bytes overran the frame (5 pinned bars = gutter + 10 cells);
        // without the axis the gutter-free capacity is 5 bars, rows at width.
        $bareOut = BarChart::new($bars, 10, 4)->withBarWidth(2)->view();
        foreach (explode("\n", $bareOut) as $row) {
            self::assertLessThanOrEqual(10, Width::string($row), "row exceeds declared width: {$row}");
        }
        self::assertStringContainsString('b4', $bareOut);
        self::assertStringNotContainsString('b5', $bareOut);
    }

    public function testExplicitBarGapSlicesToDeclaredWidth(): void
    {
        $bars = [];
        for ($i = 0; $i < 8; $i++) {
            $bars[] = ['c' . $i, (float) ($i + 1)];
        }
        // gap pinned 2; auto colW lands on 1 (avail goes negative, clamped);
        // under the gutter capacity = intdiv((10-1)+2, 1+2) = 3 bars.
        $chart = BarChart::new($bars, 10, 4)->withAxes()->withBarGap(2);
        $out   = $chart->view();
        foreach (explode("\n", $out) as $row) {
            self::assertLessThanOrEqual(10, Width::string($row), "row exceeds declared width: {$row}");
        }
        $labelRow = explode("\n", self::plain($out))[count(explode("\n", self::plain($out))) - 1];
        self::assertSame(3, substr_count($labelRow, 'c'), 'gap-pinned slice missed its capacity');
        // 4 bars fit without the axis: capacity intdiv(10+2, 1+2) = 4.
        $bareRows  = explode("\n", self::plain(BarChart::new($bars, 10, 4)->withBarGap(2)->view()));
        $bareLabel = $bareRows[count($bareRows) - 1];
        self::assertSame(4, substr_count($bareLabel, 'c'));
    }

    public function testAllEqualNegativeDataRendersSteadyMidBars(): void
    {
        // height 5, labels on → bodyHeight 4; Sparkline parity mid posture
        // paints round(0.5*4) = 2 cells, not the invisible 0 of the old
        // synthesized v..v+1 range.
        $rows = explode("\n", self::plain(BarChart::new([['a', -3.0], ['b', -3.0]], 10, 5)->view()));
        $painted = array_filter($rows, static fn(string $r): bool => str_contains($r, '█'));
        self::assertCount(2, $painted, 'constant negative data plotted invisibly again');
    }

    public function testAllEqualZeroDataStaysHonestZero(): void
    {
        $out = self::plain(BarChart::new(['a' => 0.0, 'b' => 0.0], 10, 5)->view());
        self::assertStringNotContainsString('█', $out, 'zero-height bars are the truth, not a mid bar');
    }

    public function testHorizontalAllEqualNegativeSharesMidPosture(): void
    {
        $out = self::plain(BarChart::new(['a' => -2.0, 'b' => -2.0], 12, 4)->withHorizontal()->view());
        self::assertStringContainsString('█', $out, 'horizontal mode kept the invisible-plot divergence');
    }
}
