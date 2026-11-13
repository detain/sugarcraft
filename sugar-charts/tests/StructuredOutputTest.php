<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

/**
 * Structured cell outputs (sugar-charts-027): the per-cell accessors
 * must agree with view() byte-for-byte once styling is projected out —
 * they exist precisely so consumers (candy-codetime's preg_split of the
 * stripped view) never re-parse ANSI again.
 */
final class StructuredOutputTest extends TestCase
{
    public function testSparklineCellsReconstructTheStrippedView(): void
    {
        $spark = Sparkline::new([3, 1, 4, 1, 5, 9, 2, 6], 6);
        $cells = $spark->cells();
        self::assertCount(6, $cells);
        self::assertSame(Ansi::strip($spark->view()), implode('', $cells));
    }

    public function testSparklineCellsCarryNoStyling(): void
    {
        $spark = Sparkline::new([1, 5, 2, 8], 4)->withStyle(Style::new()->bold()->fg('#4488FF'));
        self::assertStringNotContainsString("\x1b", implode('', $spark->cells()));
        self::assertSame(str_replace("\n", '', Ansi::strip($spark->view())), implode('', $spark->cells()));
    }

    public function testSparklineFloorPromotionReachesCells(): void
    {
        // CH-15: with a style engaged the min-floor cell is '▁', and the
        // structured surface must show that promoted glyph, not the
        // invisible blank.
        $data = array_fill(0, 5, 0); // every value AT min -> floor glyph
        $spark = Sparkline::new($data, 5)->withStyle(Style::new()->fg('#22AA44'))->withMin(0)->withMax(10);
        $cells = $spark->cells();
        self::assertCount(5, $cells);
        self::assertContains('▁', $cells);
        self::assertSame(Ansi::strip($spark->view()), implode('', $cells));
    }

    public function testSparklineEmptyPaths(): void
    {
        self::assertSame([], Sparkline::new([1, 2, 3], 0)->cells());
        $empty = Sparkline::new([], 4);
        self::assertSame([' ', ' ', ' ', ' '], $empty->cells());
        self::assertSame(Ansi::strip($empty->view()), implode('', $empty->cells()));
    }

    public function testSparklineWindowSliceIsReflectedInCells(): void
    {
        $spark = Sparkline::new(range(1, 10), 3);
        self::assertCount(3, $spark->cells());
        self::assertSame(Ansi::strip($spark->view()), implode('', $spark->cells()));
    }

    public function testLineChartCellsReconstructTheStrippedView(): void
    {
        $chart = LineChart::new([1.0, 4.0, 2.0, 8.0, 5.0], 24, 5)
            ->withAxes(true)
            ->withXLabels(['a', 'b', 'c', 'd', 'e'])
            ->withDataset('second', [2.0, 3.0, 6.0, 1.0, 4.0])
            ->withLegend(true);
        $rows = $chart->cells();
        $viewRows = explode("\n", $chart->view());
        self::assertCount(count($viewRows), $rows);
        foreach ($rows as $i => $row) {
            self::assertSame(Ansi::strip($viewRows[$i]), implode('', $row), "row {$i} desynced from view()");
            foreach ($row as $cell) {
                self::assertStringNotContainsString("\x1b", $cell);
            }
        }
    }

    public function testLineChartEmptyCanvasYieldsNoRows(): void
    {
        // The Canvas 0x0 off-switch: no chrome, no rows, cells agrees.
        $chart = LineChart::new([1.0, 2.0], 0, 0);
        self::assertSame('', $chart->view());
        self::assertSame([], $chart->cells());
    }

    public function testLineChartThemedCellsStillProjectStylingOut(): void
    {
        $chart = LineChart::new([3.0, 1.0, 2.0], 20, 4)
            ->withTheme(Theme::dark());
        $rows = $chart->cells();
        $viewRows = explode("\n", $chart->view());
        self::assertCount(count($viewRows), $rows);
        $flat = implode('', array_merge(...$rows));
        self::assertStringNotContainsString("\x1b", $flat);
        self::assertSame(str_replace("\n", '', Ansi::strip($chart->view())), $flat);
        foreach ($rows as $i => $row) {
            self::assertSame(Ansi::strip($viewRows[$i]), implode('', $row), "themed row {$i} desynced");
        }
    }
}
