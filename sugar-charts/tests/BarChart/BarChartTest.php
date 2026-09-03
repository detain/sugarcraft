<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use SugarCraft\Charts\BarChart\Bar;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Chart\Position;
use PHPUnit\Framework\TestCase;

final class BarChartTest extends TestCase
{
    public function testEmptyChartIsEmpty(): void
    {
        $this->assertSame('', BarChart::new([], 10, 5)->view());
    }

    public function testHeightHonoredAndLabelsRow(): void
    {
        $out = BarChart::new([['a', 1], ['b', 2]], 5, 4)->view();
        $rows = explode("\n", $out);
        // 4 height = 3 body rows + 1 label row.
        $this->assertCount(4, $rows);
    }

    public function testTallestBarReachesTop(): void
    {
        $out = BarChart::new([['x', 0.0], ['y', 1.0]], 3, 4)->view();
        $rows = explode("\n", $out);
        // First row should contain a block where the tall bar is.
        $this->assertStringContainsString('█', $rows[0]);
    }

    public function testLabelsTruncatedToColumnWidth(): void
    {
        // Two bars in a width=4 chart: 1 col each, 1 col gap.
        $out  = BarChart::new([['alpha', 0.5], ['beta', 0.9]], 4, 3)->view();
        $rows = explode("\n", $out);
        $this->assertSame('a b', $rows[count($rows) - 1]);
    }

    public function testWithoutLabels(): void
    {
        $out  = BarChart::new([['a', 1], ['b', 2]], 5, 3)->withShowLabels(false)->view();
        $rows = explode("\n", $out);
        $this->assertCount(3, $rows);
    }

    public function testAcceptsBarObjects(): void
    {
        $bars = [new Bar('x', 0.5), new Bar('y', 1.0)];
        $out  = BarChart::new($bars, 5, 3)->view();
        $this->assertNotSame('', $out);
    }

    public function testAcceptsLabelKeyedArray(): void
    {
        $out = BarChart::new(['cpu' => 1.0, 'mem' => 0.5], 7, 3)->view();
        $rows = explode("\n", $out);
        $this->assertStringContainsString('cpu', $rows[count($rows) - 1]);
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarChart::new([], -1, 5);
    }

    public function testRenderedRowsNeverExceedWidth(): void
    {
        // 5 bars in a 3-cell budget — trailing bars must be dropped so
        // every rendered row fits the chart width.
        $bars = [
            ['a', 0.1], ['b', 0.2], ['c', 0.3], ['d', 0.4], ['e', 0.5],
        ];
        $out = BarChart::new($bars, 3, 4)->view();
        foreach (explode("\n", $out) as $row) {
            $this->assertLessThanOrEqual(3, mb_strlen($row, 'UTF-8'),
                'each row must fit the configured width');
        }
    }

    public function testHeightOneWithLabelsRendersOneRow(): void
    {
        // height=1 + showLabels would otherwise emit body+labels=2 rows.
        $out = BarChart::new([['a', 0.5], ['b', 1.0]], 5, 1)->view();
        $rows = array_filter(explode("\n", $out), static fn($r) => $r !== '');
        $this->assertCount(1, $rows);
    }

    public function testHeightTwoWithLabelsRendersTwoRows(): void
    {
        // height=2 fits both a body row and a label row.
        $out = BarChart::new([['a', 1.0]], 3, 2)->view();
        $this->assertCount(2, explode("\n", $out));
    }

    public function testHorizontalRendersOneRowPerBar(): void
    {
        $out = BarChart::new([['a', 5.0], ['bb', 10.0], ['ccc', 2.0]], 20, 5)
            ->withHorizontal()
            ->view();
        $rows = explode("\n", $out);
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('a',   $rows[0]);
        $this->assertStringContainsString('bb',  $rows[1]);
        $this->assertStringContainsString('ccc', $rows[2]);
        $this->assertStringContainsString('█', $out);
    }

    public function testWithShowAxisDrawsAxisRunes(): void
    {
        $out = BarChart::new([['a', 0.7], ['b', 0.3]], 8, 4)
            ->withShowAxis()
            ->view();
        $this->assertStringContainsString('┤', $out);
        $this->assertStringContainsString('└', $out);
        $this->assertStringContainsString('─', $out);
    }

    public function testPushAppendsSingleBar(): void
    {
        $b = BarChart::new([['a', 0.5]]);
        $b = $b->push(['b', 0.7]);
        $this->assertCount(2, $b->bars);
        $this->assertSame('a', $b->bars[0]->label);
        $this->assertSame('b', $b->bars[1]->label);
    }

    public function testPushAcceptsBarInstance(): void
    {
        $b = BarChart::new()->push(new Bar('only', 1.0));
        $this->assertCount(1, $b->bars);
        $this->assertSame('only', $b->bars[0]->label);
    }

    public function testPushAllAppendsEvery(): void
    {
        $b = BarChart::new([['a', 1.0]])->pushAll([['b', 2.0], ['c', 3.0]]);
        $this->assertCount(3, $b->bars);
        $this->assertSame('c', $b->bars[2]->label);
    }

    public function testPushAllOnEmptyArrayIsNoop(): void
    {
        $a = BarChart::new([['x', 1.0]]);
        $b = $a->pushAll([]);
        $this->assertSame($a->bars, $b->bars);
    }

    public function testClearWipesBars(): void
    {
        $b = BarChart::new([['a', 1.0], ['b', 2.0]])->clear();
        $this->assertSame([], $b->bars);
    }

    public function testPushIsImmutable(): void
    {
        $a = BarChart::new([['a', 1.0]]);
        $b = $a->push(['b', 2.0]);
        $this->assertCount(1, $a->bars);
        $this->assertCount(2, $b->bars);
    }

    public function testWithBarWidthPinsColumnWidth(): void
    {
        $chart = BarChart::new([['a', 1.0], ['b', 1.0]], 12, 4)
            ->withShowLabels(false)
            ->withBarWidth(3)
            ->withBarGap(1);
        $rows = explode("\n", $chart->view());
        // 2 bars × 3 cols + 1 gap = 7 cells.
        foreach ($rows as $r) {
            $this->assertSame(7, mb_strlen($r, 'UTF-8'));
        }
    }

    public function testWithBarGapZeroPacksBarsTogether(): void
    {
        $chart = BarChart::new([['a', 1.0], ['b', 1.0]], 6, 3)
            ->withShowLabels(false)
            ->withBarWidth(2)
            ->withBarGap(0);
        $rows = explode("\n", $chart->view());
        // 2 bars × 2 cols + 0 gap = 4 cells, all '█' on the top row.
        $this->assertSame('████', $rows[0]);
    }

    public function testWithNoAutoBarWidthDisablesAutoFit(): void
    {
        // With auto, 2 bars across 10 cells gives colW ~ 4.5 → 4.
        // Pinning to 2 keeps each bar narrow.
        $chart = BarChart::new([['a', 1.0], ['b', 1.0]], 10, 3)
            ->withShowLabels(false)
            ->withBarWidth(2);
        $rows = explode("\n", $chart->view());
        // 2 bars × 2 cols + auto-gap of 1 = 5 cells.
        $this->assertSame(5, mb_strlen($rows[0], 'UTF-8'));
    }

    public function testWithBarWidthRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarChart::new([['a', 1.0]], 4, 3)->withBarWidth(0);
    }

    public function testWithBarGapRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarChart::new([['a', 1.0]], 4, 3)->withBarGap(-1);
    }

    public function testWithNoAutoBarWidthFalseRestoresAuto(): void
    {
        $chart = BarChart::new([['a', 1.0], ['b', 1.0]], 10, 3)
            ->withBarWidth(2)
            ->withNoAutoBarWidth(false);
        $this->assertNull($chart->barWidth);
    }

    // ─── Axis Label Tests ────────────────────────────────────────────────

    public function testWithXLabelAddsLabelAtBottom(): void
    {
        $out = BarChart::new([['a', 0.5], ['b', 1.0]], 10, 3)
            ->withShowLabels(false)
            ->withXLabel('Time')
            ->view();
        $this->assertStringEndsWith("\nTime", $out);
    }

    public function testWithYLabelPrependsToEachLine(): void
    {
        $out = BarChart::new([['a', 0.5], ['b', 1.0]], 10, 3)
            ->withShowLabels(false)
            ->withYLabel('Usage')
            ->view();
        $lines = explode("\n", $out);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('Usage ', $line);
        }
    }

    public function testXLabelAndYLabelCanBeCombined(): void
    {
        $out = BarChart::new([['a', 0.5]], 8, 3)
            ->withShowLabels(false)
            ->withXLabel('X axis')
            ->withYLabel('Y axis')
            ->view();
        $this->assertStringContainsString('Y axis ', $out);
        $this->assertStringEndsWith("\nX axis", $out);
    }

    // ─── Legend Tests ────────────────────────────────────────────────────

    public function testWithLegendShowsLegendWhenEnabled(): void
    {
        $chart = BarChart::new([['a', 0.5], ['b', 1.0]], 10, 3)
            ->withShowLabels(false)
            ->withLegend(true)
            ->withLegendItems([['label' => 'Series A', 'color' => 'red']]);
        $this->assertTrue($chart->showLegend);
    }

    public function testWithLegendFalseDisablesLegend(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->withLegend(true)
            ->withLegend(false);
        $this->assertFalse($chart->showLegend);
    }

    public function testWithLegendPositionChangesPosition(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom);
        $this->assertSame(Position::Bottom, $chart->legendPosition);
    }

    public function testWithLegendStyleCustomizesIndicator(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->withLegend(true)
            ->withLegendStyle('◆');
        $this->assertSame('◆', $chart->legendIndicatorChar);
    }

    public function testLegendShortFormAlias(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->legend(true)
            ->legendPos(Position::Left)
            ->legendStyle('●');
        $this->assertTrue($chart->showLegend);
        $this->assertSame(Position::Left, $chart->legendPosition);
        $this->assertSame('●', $chart->legendIndicatorChar);
    }

    public function testXLabelShortFormAlias(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)->xLabel('Months');
        $this->assertSame('Months', $chart->xLabel);
    }

    public function testYLabelShortFormAlias(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)->yLabel('Values');
        $this->assertSame('Values', $chart->yLabel);
    }

    // ─── Title Tests ─────────────────────────────────────────────────────

    public function testWithTitleSetsTitle(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->withTitle('My Chart');
        $this->assertSame('My Chart', $chart->title);
    }

    public function testWithTitleAndPosition(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->withTitle('Bottom Title', Position::Bottom);
        $this->assertSame('Bottom Title', $chart->title);
        $this->assertSame(Position::Bottom, $chart->titlePosition);
    }

    public function testTitleShortFormAlias(): void
    {
        $chart = BarChart::new([['a', 0.5]], 10, 3)->title('Test Title', Position::Top);
        $this->assertSame('Test Title', $chart->title);
    }

    // ─── Fluent Interface Tests ──────────────────────────────────────────

    public function testFluentInterfaceChaining(): void
    {
        $chart = BarChart::new([['a', 0.5], ['b', 1.0]], 20, 6)
            ->withXLabel('X Axis')
            ->withYLabel('Y Axis')
            ->withLegend(true)
            ->withLegendPosition(Position::Right)
            ->withLegendStyle('█')
            ->withTitle('My Bar Chart')
            ->withTitlePosition(Position::Top);

        $this->assertSame('X Axis', $chart->xLabel);
        $this->assertSame('Y Axis', $chart->yLabel);
        $this->assertTrue($chart->showLegend);
        $this->assertSame(Position::Right, $chart->legendPosition);
        $this->assertSame('█', $chart->legendIndicatorChar);
        $this->assertSame('My Bar Chart', $chart->title);
        $this->assertSame(Position::Top, $chart->titlePosition);
    }

    public function testLegendItemsSetsItems(): void
    {
        $items = [
            ['label' => 'Alpha', 'color' => 'red'],
            ['label' => 'Beta', 'color' => 'blue'],
        ];
        $chart = BarChart::new([['a', 0.5]], 10, 3)
            ->withLegend(true)
            ->withLegendItems($items);

        $out = $chart->view();
        $this->assertStringContainsString('Alpha', $out);
        $this->assertStringContainsString('Beta', $out);
    }

    // ─── Edge Cases ──────────────────────────────────────────────────────

    public function testEmptyChartWithLabelsStillEmpty(): void
    {
        $out = BarChart::new([], 10, 5)
            ->withXLabel('X')
            ->withYLabel('Y')
            ->withLegend(true)
            ->view();
        $this->assertSame('', $out);
    }

    public function testAllExtrasDisabledReturnsPlainChart(): void
    {
        $out = BarChart::new([['a', 0.5]], 10, 3)
            ->withShowLabels(false)
            ->view();
        $this->assertStringNotContainsString('X Axis', $out);
        $this->assertStringNotContainsString('Y Axis', $out);
        $this->assertStringNotContainsString('Title', $out);
    }

    // ─── Fractional Eighth-Block Caps (withFractionalHeights) ───────────

    /** Rune set the fractional caps may emit; nothing else is allowed in a cap cell. */
    private const EIGHTHS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '▏', '▎', '▍', '▌', '▋', '▊', '▉'];

    public function testFractionalVerticalHalfCellRoundsToHalfBlockCap(): void
    {
        // bodyHeight 5 (no label row), norm 0.7 → exact 3.5 cells:
        // three whole `█` rows plus a 4/8 (=`▄`, U+2584) cap row above them.
        $out  = BarChart::new([['a', 0.7]], 1, 5)
            ->withShowLabels(false)
            ->withMin(0.0)
            ->withMax(1.0)
            ->withFractionalHeights()
            ->view();
        $rows = explode("\n", $out);
        $this->assertCount(5, $rows);
        $this->assertSame('', $rows[0], 'row above the cap must stay blank');
        $this->assertSame('▄', $rows[1], '4/8 residual renders as U+2584 ▄');
        $this->assertSame('█', $rows[2]);
        $this->assertSame('█', $rows[3]);
        $this->assertSame('█', $rows[4]);
    }

    public function testFractionalResidualUnderOneEighthRoundsToBlankNoGhost(): void
    {
        // exact 2.06 cells (bodyHeight 5, norm 0.412): floor 2, residual
        // 0.06 < 1/8 → round(0.48) = 0 → blank cap, so fractional output
        // equals legacy and no `▁` ghost is drawn.
        $args = static fn(BarChart $c): BarChart => $c
            ->withShowLabels(false)->withMin(0.0)->withMax(1.0);
        $legacy = $args(BarChart::new([['a', 0.412]], 1, 5))->view();
        $frac   = $args(BarChart::new([['a', 0.412]], 1, 5))->withFractionalHeights()->view();
        $this->assertStringNotContainsString('▁', $frac, 'sub-eighth residual must not draw a ghost');
        foreach (self::EIGHTHS as $rune) {
            $this->assertStringNotContainsString($rune, $frac);
        }
        $this->assertSame($legacy, $frac, 'blank cap equals legacy whole-cell output');
    }

    public function testFractionalResidualOverSevenEighthsPromotesToFullCell(): void
    {
        // exact 4.95: floor 4 whole, round(0.95*8) = 8 → promoted to a
        // full `█` row, so the bar tops out at 5 whole cells with no ramp.
        $out  = BarChart::new([['a', 0.99]], 1, 5)
            ->withShowLabels(false)
            ->withMin(0.0)
            ->withMax(1.0)
            ->withFractionalHeights()
            ->view();
        $rows = explode("\n", $out);
        foreach ($rows as $row) {
            $this->assertSame('█', $row, 'promoted residual renders as a full █ row');
        }
        $this->assertCount(5, $rows);
    }

    public function testFractionalHeightsDefaultsOffAndIsImmutable(): void
    {
        $plain  = BarChart::new([['cpu', 0.7], ['mem', 0.4], ['disk', 0.9]], 20, 5);
        $optOut = $plain->withFractionalHeights(false);
        $this->assertFalse($plain->fractionalHeights);
        $this->assertSame($plain->view(), $optOut->view());
        foreach (self::EIGHTHS as $rune) {
            $this->assertStringNotContainsString($rune, $plain->view(), 'default-off keeps legacy whole-cell bars');
        }
        $on = $plain->withFractionalHeights();
        $this->assertTrue($on->fractionalHeights);
        $this->assertFalse($plain->fractionalHeights, 'wither must not mutate the receiver');
        $this->assertNotSame($plain, $on);
    }

    public function testFractionalHorizontalHalfCellCapsWithHalfBlock(): void
    {
        // width 9, label gutter 1 → barWidth 7; norm 9/14 → exact 4.5:
        // four whole `█` plus a 4/8 cap `▌` (U+258C).
        $out = BarChart::new([['x', 9.0]], 9, 1)
            ->withHorizontal()
            ->withMin(0.0)
            ->withMax(14.0)
            ->withFractionalHeights()
            ->view();
        $this->assertSame('x ████▌', $out);
    }

    public function testFractionalHorizontalOneEighthCapSitsFlushAgainstBody(): void
    {
        // norm 33/56 → exact 4.125: whole 4, round(1/8*8) = 1 → `▏`
        // (U+258F, left-flush). The cap must touch the `█` body — no
        // space and no right-flush `▕` gap between them.
        $out = BarChart::new([['x', 33.0]], 9, 1)
            ->withHorizontal()
            ->withMin(0.0)
            ->withMax(56.0)
            ->withFractionalHeights()
            ->view();
        $this->assertSame('x ████▏', $out);
        $this->assertStringContainsString('█▏', $out, 'cap must be flush against the body run');
        $this->assertStringNotContainsString('▕', $out, 'right-flush U+2595 would open a gap (dash BL-2 bug)');
        $this->assertStringNotContainsString('█ ', $out);
    }

    public function testFractionalVerticalFullHeightBarGetsNoGhostCap(): void
    {
        // norm 1.0 → exact = bodyHeight, zero residual: no cap row, bar
        // tops out exactly at the body height.
        $out  = BarChart::new([['a', 1.0]], 1, 5)
            ->withShowLabels(false)
            ->withMin(0.0)
            ->withMax(1.0)
            ->withFractionalHeights()
            ->view();
        $this->assertSame("█\n█\n█\n█\n█", $out);
    }
}
