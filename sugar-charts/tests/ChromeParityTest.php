<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Chart\ChartExtras;
use SugarCraft\Charts\Chart\ChromeAccessors;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;

/**
 * sugar-charts-008: the dual Chart/ChartExtras chrome fork converged into
 * ONE compositor body serving both families, behind the ChromeAccessors
 * contract. 024 capability shims and 025 title-side refusals ride here.
 */
final class ChromeParityTest extends TestCase
{
    /** @return array<string, array{0: object}> */
    public static function rendererProvider(): array
    {
        return [
            'LineChart' => [LineChart::new([1.0, 2.0, 3.0], 30, 4)],
            'BarChart'  => [BarChart::new([['a', 1.0], ['b', 2.0]], 30, 4)],
            'Scatter'   => [Scatter::new([[1, 2], [3, 4]], 30, 4)],
            'OHLCChart' => [OHLCChart::new([
                new \SugarCraft\Charts\OHLC\Candle(open: 100.0, high: 110.0, low: 95.0, close: 108.0),
                new \SugarCraft\Charts\OHLC\Candle(open: 105.0, high: 120.0, low: 100.0, close: 115.0),
            ], 30, 4)],
        ];
    }

    /**
     * @param object $renderer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rendererProvider')]
    public function testEveryFamilyComposesThroughTheSingleTraitBody(object $renderer): void
    {
        $this->assertInstanceOf(ChromeAccessors::class, $renderer);
        // A trait-flattened method reports the USING class as declarer, but
        // its file is the trait — proof no renderer carries a fork again.
        $file = (new ReflectionMethod($renderer, 'buildChartWithExtras'))->getFileName();
        $this->assertSame((new \ReflectionClass(ChartExtras::class))->getFileName(), $file, 'two compositor bodies are back');
    }

    /**
     * @param object $renderer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rendererProvider')]
    public function testTitleSideRefusesAtTheDoor(object $renderer): void
    {
        $refused = 0;
        foreach ([Position::Left, Position::Right] as $side) {
            try {
                $renderer->withTitle('No gutter for you', $side);
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        $this->assertSame(2, $refused, 'both off-composition title sides must refuse');
    }

    public function testBarChartTitlePositionSetterRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarChart::new([['a', 1.0]], 20, 4)->withTitlePosition(Position::Right);
    }

    public function testTopAndBottomTitlesStillCompose(): void
    {
        foreach ([Position::Top, Position::Bottom] as $side) {
            foreach (self::rendererProvider() as $case) {
                $renderer = $case[0];
                $out = $renderer->withTitle('Chrome', $side)->view();
                $this->assertStringContainsString('Chrome', $out);
            }
        }
    }

    /**
     * y-label composition is one rule for every family — the fork used to
     * carry two copies. CH-03 (sugar-charts-030) made it a single
     * centered emission with an aligned gutter on the other rows.
     *
     * @param object $renderer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rendererProvider')]
    public function testYLabelEmitsOnceWithAlignedGutterInEveryFamily(object $renderer): void
    {
        $rows = explode("\n", $renderer->withYLabel('YY')->view());
        $labeled = 0;
        foreach ($rows as $row) {
            if (str_starts_with($row, 'YY ')) {
                $labeled++;
                continue;
            }
            $this->assertStringStartsWith('   ', $row, 'gutter rows must stay column-aligned');
        }
        $this->assertSame(1, $labeled, 'the axis name is a label, not wallpaper');
    }

    /**
     * @param object $renderer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rendererProvider')]
    public function testXLabelAppendsOneRowInEveryFamily(object $renderer): void
    {
        $bare  = explode("\n", $renderer->view());
        $added = explode("\n", $renderer->withXLabel('X Axis')->view());
        $this->assertCount(count($bare) + 1, $added);
        $this->assertStringEndsWith('X Axis', rtrim($added[count($added) - 1]));
    }

    public function testDataLabelsRefuseOnLineChartAndStoreOnFalse(): void
    {
        $chart = LineChart::new([1.0, 2.0], 20, 4);
        $this->assertFalse($chart->supportsDataLabels());
        $this->expectException(\InvalidArgumentException::class);
        $chart->withDataLabels(true);
    }

    public function testDataLabelsAliasRefusesOnLineChart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([1.0, 2.0], 20, 4)->dataLabels(true);
    }

    public function testDataLabelsFalseIsHarmlessOnLineChart(): void
    {
        $chart = LineChart::new([1.0, 2.0], 20, 4)->withDataLabels(false);
        $this->assertFalse($chart->showDataLabels);
    }

    public function testBarChartHonorsDataLabelsCapability(): void
    {
        $chart = BarChart::new([['a', 1.0], ['b', 2.0]], 30, 4)->withDataLabels(true);
        $this->assertTrue($chart->supportsDataLabels());
        $this->assertTrue($chart->showDataLabels);
    }

    public function testAnimationCapabilityFlags(): void
    {
        $this->assertTrue(LineChart::new()->supportsAnimation());
        $this->assertFalse(BarChart::new()->supportsAnimation());
    }

    public function testWithAxesIsCanonicalAndShowAxisWindowStillWorks(): void
    {
        $viaNew = BarChart::new([['a', 1.0], ['b', 2.0]], 30, 4)->withAxes(true)->view();
        $viaOld = BarChart::new([['a', 1.0], ['b', 2.0]], 30, 4)->withShowAxis(true)->view();
        $viaAlias = BarChart::new([['a', 1.0], ['b', 2.0]], 30, 4)->showAxis(true)->view();
        $this->assertSame($viaNew, $viaOld);
        $this->assertSame($viaNew, $viaAlias);

        foreach (['withShowAxis', 'showAxis'] as $windowName) {
            $doc = (new ReflectionMethod(BarChart::class, $windowName))->getDocComment();
            $this->assertIsString($doc);
            $this->assertStringContainsString('@deprecated', $doc);
        }
    }

    public function testChartBaseCarriesWithLegendItemsAndAliasRoutesThroughIt(): void
    {
        $items = [['label' => 'Series A', 'color' => '#ff0000']];
        $line = LineChart::new([1.0, 2.0], 24, 3)
            ->withLegendItems($items)
            ->withLegend(true)
            ->view();
        $this->assertStringContainsString('Series A', $line);

        $viaAlias = LineChart::new([1.0, 2.0], 24, 3)
            ->legendItems($items)
            ->withLegend(true)
            ->view();
        $this->assertSame($line, $viaAlias);
    }

    public function testNoAutoMaxValueIsMarkedDeprecated(): void
    {
        $doc = (new ReflectionMethod(\SugarCraft\Charts\Sparkline\Sparkline::class, 'withNoAutoMaxValue'))->getDocComment();
        $this->assertIsString($doc);
        $this->assertStringContainsString('@deprecated', $doc);
    }

    public function testAccessorsCarryStoredValues(): void
    {
        $chart = BarChart::new([['a', 1.0]], 21, 4)
            ->withTitle('T')
            ->withLegendItems([['label' => 'A', 'color' => '#fff']]);
        $this->assertSame(21, $chart->chartExtrasGetWidth());
        $this->assertSame('T', $chart->chartExtrasGetTitle());
        $this->assertSame(Position::Top, $chart->chartExtrasGetTitlePosition());
        $this->assertSame([['label' => 'A', 'color' => '#fff']], $chart->chartExtrasGetLegendItems());

        $line = LineChart::new([1.0], 22, 4)->withYLabel('YY');
        $this->assertSame(22, $line->chartExtrasGetWidth());
        $this->assertSame('YY', $line->chartExtrasGetYLabel());
    }
}
