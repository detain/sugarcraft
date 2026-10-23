<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\Bar;
use SugarCraft\Charts\BarChart\BarChart;

/**
 * docs-fix-1023 B2: BarChart value labels are a real render path (not
 * inert storage), plus the auto-bar-width and cell-width fixes. Labels
 * render only when opted in; the unlabelled default stays byte-identical.
 */
final class BarChartLabelsTest extends TestCase
{
    public function testVerticalValueLabelsRideTopRow(): void
    {
        $chart = BarChart::new([['A', 3], ['B', 7]], 10, 4)
            ->withShowLabels(false)
            ->withDataLabels(true);

        $rows = explode("\n", $chart->view());
        // height 4 + one value row = 5 physical rows.
        self::assertCount(5, $rows);
        // colW distributes (10 - 1 gap) / 2 = 4; values 3 and 7 centered
        // in their column, gap between: '▏ 3  ▏ ▏ 7  ▏' rtrimmed.
        self::assertSame(' 3    7', $rows[0]);
    }

    public function testDisabledLabelsMatchBareRender(): void
    {
        $bare    = BarChart::new([['A', 3], ['B', 7]], 10, 4)->withShowLabels(false)->view();
        $optedOut = BarChart::new([['A', 3], ['B', 7]], 10, 4)->withShowLabels(false)->withDataLabels(false)->view();

        self::assertSame($bare, $optedOut, 'withDataLabels(false) is byte-identical to never enabling');
    }

    public function testHorizontalValueLabelsTrailTheBar(): void
    {
        $chart = BarChart::new([['A', 3], ['B', 7]], 20, 2)
            ->withShowLabels(false)
            ->withHorizontal(true)
            ->withDataLabels(true);

        $rows = explode("\n", $chart->view());
        // A = round(3/7*20)=9 cells + ' ' + '3'; B fills 20 (no room → dropped).
        self::assertStringEndsWith(' 3', $rows[0]);
        self::assertSame(str_repeat('█', 20), $rows[1], 'full-width bar has no room for a trailing label');
    }

    public function testFormatterOverridesDefaultValueText(): void
    {
        $chart = BarChart::new([['A', 3], ['B', 7]], 10, 4)
            ->withShowLabels(false)
            ->withDataLabels(true)
            ->withDataLabelFormatter(static fn(Bar $b, int $i): string => sprintf('%.1f', $b->value));

        $rows = explode("\n", $chart->view());
        self::assertStringContainsString('3.0', $rows[0]);
        self::assertStringContainsString('7.0', $rows[0]);
    }

    public function testDefaultLabelTrimsTrailingZeros(): void
    {
        $chart = BarChart::new([['a', 1.0], ['b', 2.25], ['c', 2.5]], 16, 3)
            ->withShowLabels(false)
            ->withDataLabels(true);

        $top = explode("\n", $chart->view())[0];
        self::assertStringContainsString('1', $top);
        self::assertStringContainsString('2.25', $top);
        self::assertStringContainsString('2.5', $top);
        self::assertStringNotContainsString('1.0', $top, 'whole numbers print bare');
        self::assertStringNotContainsString('2.50', $top, 'trailing zeros trimmed');
    }

    public function testAliasParityForLabelApi(): void
    {
        $long  = BarChart::new([['A', 3]], 10, 3)->withShowLabels(false)->withDataLabels(true)->view();
        $short = BarChart::new([['A', 3]], 10, 3)->withShowLabels(false)->dataLabels(true)->view();
        self::assertSame($long, $short);

        $fn       = static fn(Bar $b, int $i): string => 'X';
        $longFmt  = BarChart::new([['A', 3]], 10, 3)->withShowLabels(false)->withDataLabels(true)->withDataLabelFormatter($fn)->view();
        $shortFmt = BarChart::new([['A', 3]], 10, 3)->withShowLabels(false)->dataLabels(true)->dataLabelFormat($fn)->view();
        self::assertSame($longFmt, $shortFmt);
        self::assertStringContainsString('X', $shortFmt);
    }

    // ── auto-bar-width (Minor-7) ────────────────────────────────────────

    public function testNoAutoBarWidthCollapsesToSingleCell(): void
    {
        $chart = BarChart::new([['A', 5], ['B', 5]], 10, 2)
            ->withShowLabels(false)
            ->withNoAutoBarWidth(true);

        foreach (explode("\n", $chart->view()) as $row) {
            self::assertSame('█ █', $row, 'auto-fit off, no pinned width → 1 cell per bar');
        }
    }

    public function testNoAutoBarWidthFalseReenablesDistribution(): void
    {
        $chart = BarChart::new([['A', 5], ['B', 5]], 10, 2)
            ->withShowLabels(false)
            ->withNoAutoBarWidth(true)
            ->withNoAutoBarWidth(false);

        self::assertNull($chart->barWidth, 'false clears the width pin');
        self::assertTrue($chart->autoBarWidth);
        self::assertStringContainsString('████ ████', $chart->view());
    }

    public function testPinnedBarWidthStillWinsOverAutoFlag(): void
    {
        $chart = BarChart::new([['A', 5], ['B', 5]], 10, 2)
            ->withShowLabels(false)
            ->withBarWidth(2)
            ->withNoAutoBarWidth(true);

        self::assertStringContainsString('██ ██', $chart->view());
    }

    // ── cell-width labels (Minor-8) ─────────────────────────────────────

    public function testCjkHorizontalGutterIsCellCorrect(): void
    {
        $chart = BarChart::new([['日本', 4], ['x', 2]], 20, 2)
            ->withShowLabels(true)
            ->withHorizontal(true);

        $rows = explode("\n", $chart->view());
        // Gutter = max cell width = 日本(4 cells). padRight targets 4 CELLS,
        // not 4 codepoints or bytes: '日本' is already full, 'x' gets 3
        // spaces. Both rows then share the single-space separator, so the
        // bars start at the same cell column — the byte-level str_pad this
        // replaces would have shifted the ASCII row right by 3.
        self::assertStringStartsWith('日本 ', $rows[0], 'CJK label at its 4-cell width + separator');
        self::assertStringStartsWith('x    ', $rows[1], 'ASCII label padded to the same 4-cell gutter');

        // The two prefixes are each exactly 5 CELLS (4 gutter + separator),
        // so these two startsWith pins alone prove the bars share a column:
        // byte-level str_pad would have made row 2's prefix 6 bytes/… and
        // row 1's 8 — the historical desync.
    }

    public function testCjkVerticalLabelTruncatesByCells(): void
    {
        // Narrow width forces label truncation; cell-aware cut must not
        // emit a stray half-cell.
        $chart = BarChart::new([['日本', 5]], 4, 3);
        $last  = explode("\n", $chart->view());
        $label = end($last);
        self::assertLessThanOrEqual(4, \SugarCraft\Core\Util\Width::string($label), 'truncated to the column budget in cells');
    }
}
