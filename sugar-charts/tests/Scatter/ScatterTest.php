<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Scatter;

use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Chart\Position;
use PHPUnit\Framework\TestCase;

final class ScatterTest extends TestCase
{
    public function testEmptyPointsRendersBlankCanvas(): void
    {
        $out = Scatter::new([], 5, 3)->view();
        // Empty chart returns '' per unified empty-output behavior (task 1.4)
        $this->assertSame('', $out);
    }

    public function testZeroSizeIsEmpty(): void
    {
        $this->assertSame('', Scatter::new([[1, 1]], 0, 0)->view());
    }

    public function testEndpointsLandAtCornerCells(): void
    {
        // Two points covering the full range: (0,0) bottom-left,
        // (10,10) top-right. CH-09: gutter axes are on by default; this
        // pin is about plot geometry, so opt out (disclosed migration —
        // the default-axes posture gets its own AxesDefaultTest).
        $out = Scatter::new([[0, 0], [10, 10]], 5, 3)->withAxes(false)->view();
        $rows = explode("\n", $out);
        // Top-right cell carries one '*'.
        $this->assertSame('*', substr($rows[0], -1));
        // Bottom-left cell carries one '*'.
        $this->assertSame('*', substr($rows[2], 0, 1));
    }

    public function testNoConnectorsBetweenPoints(): void
    {
        // Two diagonal points: nothing between them should be drawn.
        $out = Scatter::new([[0, 0], [4, 2]], 5, 3)->view();
        // Total '*' must equal point count.
        $this->assertSame(2, substr_count($out, '*'));
    }

    public function testCustomRune(): void
    {
        $out = Scatter::new([[1, 1]], 3, 3)->withRune('o')->view();
        $this->assertStringContainsString('o', $out);
        $this->assertStringNotContainsString('*', $out);
    }

    public function testExplicitRangePinsAxes(): void
    {
        // Force the range so the single point lands at the midpoint.
        // CH-09 disclosed: pinned at the full default-axes width math —
        // the gutter rides in front of the same plot cell, so the probe
        // column shifts by gutter+1. Opt out to keep this pin about the
        // range→column mapping it names.
        $out  = Scatter::new([[5.0, 5.0]], 5, 3)
            ->withXRange(0.0, 10.0)
            ->withYRange(0.0, 10.0)
            ->withAxes(false)
            ->view();
        $rows = explode("\n", $out);
        $this->assertSame('*', $rows[1][2] ?? '');
    }

    public function testSinglePointPlotsAtZeroZeroByDefault(): void
    {
        // Single point degenerate range — should still plot somewhere
        // without crashing.
        $out = Scatter::new([[3.5, 7.0]], 4, 2)->view();
        $this->assertSame(1, substr_count($out, '*'));
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scatter::new([], -1, 5);
    }

    // ─── Axis Label Tests ────────────────────────────────────────────────

    public function testWithXLabelAddsLabelAtBottom(): void
    {
        $out = Scatter::new([[1, 4], [2, 7]], 10, 3)
            ->withXLabel('X Value')
            ->view();
        $this->assertStringEndsWith("\nX Value", $out);
    }

    public function testWithYLabelEmitsOnceOnCenteredRow(): void
    {
        // CH-03: the y-label is a gutter title, printed once at the vertical
        // centre (was stamped on every row).
        $out = Scatter::new([[1, 4], [2, 7]], 28, 3) // lib-reviews-1103 M5: y-label budget is a quarter of the width
            ->withYLabel('Y Value')
            ->view();
        $lines = explode("\n", $out);
        $labelled = 0;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Y Value ')) {
                $labelled++;
            } elseif ($line !== '') {
                $this->assertStringStartsWith(str_repeat(' ', 8), $line);
            }
        }
        $this->assertSame(1, $labelled);
    }

    // ─── Legend Tests ────────────────────────────────────────────────────

    public function testWithLegendShowsLegendWhenEnabled(): void
    {
        $chart = Scatter::new([[1, 4], [2, 7]], 10, 3)
            ->withLegend(true)
            ->withLegendItems([['label' => 'Data', 'color' => 'blue']]);
        $this->assertTrue($chart->showLegend);
    }

    public function testWithLegendFalseDisablesLegend(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)
            ->withLegend(true)
            ->withLegend(false);
        $this->assertFalse($chart->showLegend);
    }

    public function testWithLegendPositionChangesPosition(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom);
        $this->assertSame(Position::Bottom, $chart->legendPosition);
    }

    public function testWithLegendStyleCustomizesIndicator(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)
            ->withLegend(true)
            ->withLegendStyle('◆');
        $this->assertSame('◆', $chart->legendIndicatorChar);
    }

    public function testLegendShortFormAlias(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)
            ->legend(true)
            ->legendPos(Position::Left)
            ->legendStyle('●');
        $this->assertTrue($chart->showLegend);
        $this->assertSame(Position::Left, $chart->legendPosition);
        $this->assertSame('●', $chart->legendIndicatorChar);
    }

    public function testXLabelShortFormAlias(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)->xLabel('X-Axis');
        $this->assertSame('X-Axis', $chart->xLabel);
    }

    public function testYLabelShortFormAlias(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)->yLabel('Y-Axis');
        $this->assertSame('Y-Axis', $chart->yLabel);
    }

    // ─── Title Tests ─────────────────────────────────────────────────────

    public function testWithTitleSetsTitle(): void
    {
        $chart = Scatter::new([[1, 4]], 10, 3)
            ->withTitle('Scatter Plot');
        $this->assertSame('Scatter Plot', $chart->title);
    }

    // ─── Fluent Interface Tests ──────────────────────────────────────────

    public function testFluentInterfaceChaining(): void
    {
        $chart = Scatter::new([[1, 4], [2, 7]], 20, 6)
            ->withXLabel('X Axis')
            ->withYLabel('Y Axis')
            ->withLegend(true)
            ->withLegendPosition(Position::Right)
            ->withLegendStyle('*')
            ->withTitle('My Scatter');

        $this->assertSame('X Axis', $chart->xLabel);
        $this->assertSame('Y Axis', $chart->yLabel);
        $this->assertTrue($chart->showLegend);
        $this->assertSame(Position::Right, $chart->legendPosition);
        $this->assertSame('*', $chart->legendIndicatorChar);
        $this->assertSame('My Scatter', $chart->title);
    }
}
