<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\OHLC\Bar as OhlcBar;
use SugarCraft\Style\Theme;

/**
 * lib-reviews-1103 batch 2, M10: every nullable text/color/closure setter
 * previously treated `null` as "keep the current value" because the copy
 * helpers coalesced with `??`. Passing null now CLEARS, mirroring the
 * paired Set-flag pattern the numeric pin setters already used.
 */
final class ChartNullClearTest extends TestCase
{
    public function testLineChartTitleClears(): void
    {
        $c = LineChart::new([1.0, 2.0])->withTitle('T');
        $this->assertSame('T', $c->title);
        $this->assertNull($c->withTitle(null)->title);
    }

    public function testLineChartAxisLabelsClear(): void
    {
        $c = LineChart::new([1.0, 2.0])->withXLabel('x')->withYLabel('y');
        $this->assertNull($c->withXLabel(null)->xLabel);
        $this->assertNull($c->withYLabel(null)->yLabel);
    }

    public function testLineChartLegendIndicatorAndExtrasClear(): void
    {
        $c = LineChart::new([1.0, 2.0])
            ->withLegendStyle('#')
            ->withTheme(Theme::dark())
            ->withCanvas(new \SugarCraft\Charts\Canvas\Canvas(20, 8));
        $cleared = $c->withLegendStyle(null);
        $this->assertNull($cleared->legendIndicatorChar);

        $noTheme = $c->withTheme(null);
        $this->assertNull($noTheme->theme);

        $this->assertNotNull($c->canvas);
        $this->assertNull($c->withCanvas(null)->canvas);

        $fmt = $c->withDataLabelFormatter(fn (float $v): string => (string) $v);
        $this->assertNotNull($fmt->dataLabelFormatter);
        $this->assertNull($fmt->withDataLabelFormatter(null)->dataLabelFormatter);
    }

    public function testBarChartNullClears(): void
    {
        $c = BarChart::new([['a', 1.0]])
            ->withTitle('T')
            ->withXLabel('x')
            ->withYLabel('y')
            ->withLegendStyle('#');
        $this->assertNull($c->withTitle(null)->title);
        $this->assertNull($c->withXLabel(null)->xLabel);
        $this->assertNull($c->withYLabel(null)->yLabel);
        $this->assertNull($c->withLegendStyle(null)->legendIndicatorChar);

        $fmt = $c->withDataLabelFormatter(fn (float $v): string => (string) $v);
        $this->assertNotNull($fmt->dataLabelFormatter);
        $this->assertNull($fmt->withDataLabelFormatter(null)->dataLabelFormatter);
    }

    public function testScatterNullClears(): void
    {
        $c = Scatter::new([[1.0, 2.0], [3.0, 4.0]])
            ->withTitle('T')
            ->withXLabel('x')
            ->withYLabel('y')
            ->withLegendStyle('#');
        $this->assertNull($c->withTitle(null)->title);
        $this->assertNull($c->withXLabel(null)->xLabel);
        $this->assertNull($c->withYLabel(null)->yLabel);
        $this->assertNull($c->withLegendStyle(null)->legendIndicatorChar);
    }

    public function testOhlcNullClears(): void
    {
        $bar = new OhlcBar(2.0, 2.5, 1.5, 2.0);
        $c = OHLCChart::new([$bar])
            ->withTitle('T')
            ->withXLabel('x')
            ->withYLabel('y')
            ->withLegendStyle('#');
        $this->assertNull($c->withTitle(null)->title);
        $this->assertNull($c->withXLabel(null)->xLabel);
        $this->assertNull($c->withYLabel(null)->yLabel);
        $this->assertNull($c->withLegendStyle(null)->legendIndicatorChar);
    }

    public function testClearedTitleRemovesItFromTheRender(): void
    {
        $c = LineChart::new([1.0, 2.0], 12, 3)->withTitle('HELLO');
        $this->assertStringContainsString('HELLO', $c->view());
        $this->assertStringNotContainsString('HELLO', $c->withTitle(null)->view());
    }
}
