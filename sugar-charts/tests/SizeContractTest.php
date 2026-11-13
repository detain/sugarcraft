<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * sugar-charts-010/-030/-096/-097: the width×height contract.
 *
 * The declared width is the CHART BODY. Composed extras that stack
 * (title, xLabel, top/bottom legend) are clipped into it; gutters a
 * caller opts into (legend sidecar, y-label column) legitimately extend
 * the frame — and intrinsicSize() reports the honest total so fixed panes
 * never clip unknowingly.
 */
final class SizeContractTest extends TestCase
{
    private const W = 24;

    /**
     * @return array<string, array{0: object, 1: int}>
     */
    public static function rendererProvider(): array
    {
        $items = [['label' => 'Alpha', 'color' => '#ff0000'], ['label' => 'Beta', 'color' => '#00ff00']];

        return [
            'LineChart' => [LineChart::new([1.0, 2.0, 3.0, 2.5], self::W, 4)->withLegendItems($items)->withLegend(true)->withLegendPosition(Position::Bottom), self::W],
            'BarChart'  => [BarChart::new([['a', 1.0], ['b', 2.0]], self::W, 4)->withLegendItems($items)->withLegend(true)->withLegendPosition(Position::Bottom), self::W],
            'Scatter'   => [Scatter::new([[1, 2], [3, 4]], self::W, 4)->withLegendItems($items)->withLegend(true)->withLegendPosition(Position::Bottom), self::W],
            'OHLCChart' => [OHLCChart::new([new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0)], self::W, 4)->withLegendItems($items)->withLegend(true)->withLegendPosition(Position::Bottom), self::W],
            'Heatmap'   => [Heatmap::new([[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]], 6, 3), 6],
            'Sparkline' => [Sparkline::new([1.0, 2.0, 3.0], self::W), self::W],
            'Waveline'  => [Waveline::new([[0, 5], [1, 4], [2, 5], [3, 6], [4, 5]], self::W, 4), self::W],
        ];
    }

    /**
     * Title + over-wide xLabel + bordered bottom legend over the declared
     * body: no stacked composed row may exceed the declared width.
     *
     * @return array<string, array{0: string}>
     */
    public static function comboProvider(): array
    {
        $items = [
            ['label' => 'Alpha', 'color' => '#ff0000'],
            ['label' => 'Beta', 'color' => '#00ff00'],
        ];
        $title = 'A very long chart title that must be clipped somewhere';
        $x     = 'the x axis label text is far too wide as well';

        $framed = static fn(array $extra): string => $extra[0]
            ->withTitle($title)
            ->withLegendItems($items)
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom)
            ->withXLabel($x)
            ->view();

        return [
            'LineChart' => [$framed([LineChart::new([1.0, 2.0, 3.0, 2.5], self::W, 4)])],
            'BarChart'  => [$framed([BarChart::new([['a', 1.0], ['b', 2.0], ['gamma', 1.5]], self::W, 4)])],
            'Scatter'   => [$framed([Scatter::new([[1, 2], [3, 4]], self::W, 4)])],
            'OHLCChart' => [$framed([OHLCChart::new([
                new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0),
                new Bar(open: 105.0, high: 120.0, low: 100.0, close: 115.0),
            ], self::W, 4)])],
        ];
    }

    /**
     * @param string $view
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('comboProvider')]
    public function testStackedExtrasNeverExceedDeclaredWidth(string $view): void
    {
        foreach (explode("\n", $view) as $row) {
            $this->assertLessThanOrEqual(
                self::W,
                Width::string($row),
                "row exceeds declared width: '{$row}'",
            );
        }
    }

    /**
     * @param object $renderer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rendererProvider')]
    public function testIntrinsicSizeMatchesMeasuredFrame(object $renderer, int $declared): void
    {
        [$w, $h] = $renderer->intrinsicSize();
        $this->assertSame(count(explode("\n", $renderer->view())), $h);
        $this->assertGreaterThan(0, $w);

        // No extras beyond the bottom legend on these fixtures: the frame
        // stays inside the declared body.
        $this->assertLessThanOrEqual($declared, $w, 'a fitted frame must not overshoot');

        // y-label gutter is an opt-in extension (the composer families
        // only): at most label cells + 1 on top of the declared body.
        if (method_exists($renderer, 'withYLabel')) {
            $labeled = $renderer->withYLabel('Usage');
            [$lw] = $labeled->intrinsicSize();
            $this->assertLessThanOrEqual($declared + Width::string('Usage') + 1, $lw);
        }
    }

    public function testOverWideLegendRowsAreClippedToDeclaredWidth(): void
    {
        // CH-03 (sugar-charts-010): a legend wider than the canvas must not
        // push composed rows past the declared width.
        foreach ([Position::Top, Position::Bottom] as $pos) {
            $out = LineChart::new([1.0, 2.0], self::W, 4)
                ->withLegendItems([['label' => str_repeat('L', 60), 'color' => 'red']])
                ->withLegend(true)
                ->withLegendPosition($pos)
                ->view();
            foreach (explode("\n", $out) as $row) {
                $this->assertLessThanOrEqual(self::W, Width::string($row), 'legend overflow at ' . $pos->name);
            }
        }
    }

    public function testPictureIntrinsicSizeRoundTripsItsGrid(): void
    {
        $grid = array_map(
            static fn(int $row): array => array_fill(0, 4, Color::rgb(10 * $row, 51, 102)),
            range(0, 2),
        );
        $this->assertSame([4, 3], Picture::fromGrid($grid)->intrinsicSize());
        $this->assertSame([0, 0], Picture::fromPng('not a png')->intrinsicSize());
    }

    public function testPngIntrinsicSizeReadsIhdr(): void
    {
        // Minimal IHDR-bearing PNG header: 8-byte signature + 4-byte chunk
        // length + 'IHDR' + big-endian width/height.
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 37) . pack('N', 11)
            . str_repeat("\0", 64);
        $this->assertSame([37, 11], Picture::fromPng($png)->intrinsicSize());
    }

    public function testEmptyLegendRequestShowsNoticeNotSilence(): void
    {
        $out = LineChart::new([1.0, 2.0], self::W, 4)
            ->withLegend(true)
            ->view();
        $this->assertStringContainsString('[no series]', $out);
    }

    public function testChartDefaultLegendPositionStillBottomAndFitted(): void
    {
        // 097 flips the BARE Legend() sidecar default; chart families keep
        // their documented legend positions. A bare bottom legend must
        // fit the declared pane without a border box.
        $chart = BarChart::new([['a', 1.0], ['b', 2.0]], self::W, 4)
            ->withLegendItems([['label' => 'Alpha', 'color' => '#ff0000']])
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom);
        [$w] = $chart->intrinsicSize();
        $this->assertLessThanOrEqual(self::W, $w);
        $this->assertStringNotContainsString('┌', $chart->view());
    }

    public function testBareLegendDefaultIsBottomNoBorder(): void
    {
        $legend = \SugarCraft\Charts\Legend\Legend::new([['label' => 'A', 'color' => '#fff']]);
        $this->assertSame(Position::Bottom, $legend->position);
        $this->assertFalse($legend->showBorder);
    }

    public function testYLabelWidthsStayHonestUnderCJK(): void
    {
        // The gutter measure is cell-aware, not byte/char aware.
        $chart = LineChart::new([1.0, 2.0], 20, 3)->withYLabel('温度');
        [$w] = $chart->intrinsicSize();
        $this->assertLessThanOrEqual(20 + 4 + 1, $w);
        $this->assertGreaterThanOrEqual(20, $w);
    }

    public function testSidecarGutterIsReportedByIntrinsicSize(): void
    {
        // The opt-in sidecar path still extends the frame — but the number
        // is no longer a surprise: intrinsicSize() carries it.
        $chart = BarChart::new([['a', 1.0], ['b', 2.0]], 20, 4)
            ->withLegendItems([['label' => 'Alpha', 'color' => '#ff0000']])
            ->withLegend(true)
            ->withLegendPosition(Position::Right);
        $bare = explode("\n", $chart->withLegend(false)->view());
        $with = explode("\n", $chart->view());
        $this->assertGreaterThan(Width::string($bare[0] ?? ''), Width::string($with[0] ?? ''));
        [$w] = $chart->intrinsicSize();
        $this->assertGreaterThan(20, $w);
    }

    public function testYLabelAppearsOnExactlyOneRow(): void
    {
        // sugar-charts-030: the axis name is a label, not wallpaper.
        $rows = explode("\n", LineChart::new([1.0, 2.0, 3.0, 4.0, 5.0], 20, 5)->withYLabel('YY')->view());
        $labelled = 0;
        foreach ($rows as $row) {
            if (str_starts_with($row, 'YY ')) {
                $labelled++;
            }
        }
        $this->assertSame(1, $labelled);
    }
}
