<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\MarkLine;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Color;

/**
 * Short-form alias parity for the most-used chart types.
 *
 * Each test renders both the long-form (`with*`) and short-form chain and
 * asserts byte-identical output.
 */
final class ShortAliasesTest extends TestCase
{
    public function testLineChartAliases(): void
    {
        $long  = LineChart::new([1.0, 2.0, 3.0, 4.0])->withSize(20, 5)->withMin(0.0)->withMax(5.0)->view();
        $short = LineChart::new([1.0, 2.0, 3.0, 4.0])->size(20, 5)->min(0.0)->max(5.0)->view();
        $this->assertSame($long, $short);
    }

    public function testLineChartDataAlias(): void
    {
        $long  = LineChart::new([])->withSize(15, 4)->withData([1.0, 5.0, 3.0])->view();
        $short = LineChart::new([])->size(15, 4)->data([1.0, 5.0, 3.0])->view();
        $this->assertSame($long, $short);
    }

    public function testLineChartLineStyleAlias(): void
    {
        $long  = LineChart::new([1.0, 2.0, 3.0, 4.0])->size(20, 5)->axes()
            ->withLineStyle(Graph::LINE_ROUNDED)->view();
        $short = LineChart::new([1.0, 2.0, 3.0, 4.0])->size(20, 5)->axes()
            ->lineStyle(Graph::LINE_ROUNDED)->view();
        $this->assertSame($long, $short);
    }

    public function testLineChartMarkLinesAlias(): void
    {
        $marks = [MarkLine::at(2.5, 'mid')];
        $long  = LineChart::new([1.0, 2.0, 3.0, 4.0])->size(20, 5)->withMarkLines($marks)->view();
        $short = LineChart::new([1.0, 2.0, 3.0, 4.0])->size(20, 5)->markLines($marks)->view();
        $this->assertSame($long, $short);
        $this->assertStringContainsString(
            '╌',
            $short,
            'alias must reach the mark-line render path, not a silent no-op',
        );
    }

    public function testSparklineAliases(): void
    {
        $long  = Sparkline::new([1.0, 2.0, 3.0])->withWidth(10)->withMin(0.0)->withMax(5.0)->view();
        $short = Sparkline::new([1.0, 2.0, 3.0])->width(10)->min(0.0)->max(5.0)->view();
        $this->assertSame($long, $short);
    }

    public function testBarChartAliases(): void
    {
        $long  = BarChart::new([['a', 1.0], ['b', 2.0]])->withSize(20, 5)->withShowLabels(true)->withShowAxis(true)->view();
        $short = BarChart::new([['a', 1.0], ['b', 2.0]])->size(20, 5)->showLabels(true)->showAxis(true)->view();
        $this->assertSame($long, $short);
    }

    public function testBarChartFractionalAlias(): void
    {
        $long  = BarChart::new([['a', 0.7], ['b', 0.4]])->size(20, 5)->withFractionalHeights()->view();
        $short = BarChart::new([['a', 0.7], ['b', 0.4]])->size(20, 5)->fractional()->view();
        $this->assertSame($long, $short);
        $this->assertNotSame(
            BarChart::new([['a', 0.7], ['b', 0.4]])->size(20, 5)->view(),
            $long,
            'alias must reach the fractional-cap render path, not a silent no-op',
        );
    }

    public function testHeatmapAliases(): void
    {
        $cold = Color::hex('#001');
        $hot  = Color::hex('#fff');
        $long  = Heatmap::new()->withSize(8, 4)->withMin(0.0)->withMax(10.0)->withColors($cold, $hot)->view();
        $short = Heatmap::new()->size(8, 4)->min(0.0)->max(10.0)->colors($cold, $hot)->view();
        $this->assertSame($long, $short);
    }
}
