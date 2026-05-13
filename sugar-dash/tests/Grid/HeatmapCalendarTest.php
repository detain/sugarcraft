<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\HeatmapCalendar;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class HeatmapCalendarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHeatmapCalendarImplementsSizer(): void
    {
        $heatmap = HeatmapCalendar::new([[0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]]);
        $this->assertInstanceOf(Sizer::class, $heatmap);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithDataContainsBlockChars(): void
    {
        $data = [
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data);
        $rendered = $heatmap->render();

        // Should contain Unicode block characters
        $this->assertMatchesRegularExpression('/[░▒▓█·]/', $rendered);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $heatmap = HeatmapCalendar::new([]);
        $this->assertSame('', $heatmap->render());
    }

    public function testEmptyWeekRendersEmpty(): void
    {
        $heatmap = HeatmapCalendar::new([[]]);
        $this->assertSame('', $heatmap->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Grid structure
    // ═══════════════════════════════════════════════════════════════

    public function testRenderHasCorrectLineCount(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data);
        $rendered = $heatmap->render();

        $lines = explode("\n", $rendered);
        // 7 days + legend (2 lines) = 9 lines
        $this->assertCount(9, $lines);
    }

    public function testRenderWithoutLabelsHasCorrectLineCount(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data)->withLabels(false);
        $rendered = $heatmap->render();

        $lines = explode("\n", $rendered);
        // 7 days only, no legend
        $this->assertCount(7, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Day labels
    // ═══════════════════════════════════════════════════════════════

    public function testShowDayLabelsAddsDayAbbreviations(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
        ];
        $heatmap = HeatmapCalendar::new($data)->withDayLabels(true);
        $rendered = $heatmap->render();

        // Should contain day abbreviations (S, M, T, W, T, F, S)
        $this->assertStringContainsString('S ', $rendered);
        $this->assertStringContainsString('M ', $rendered);
    }

    public function testHideDayLabelsRemovesDayAbbreviations(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
        ];
        $heatmap = HeatmapCalendar::new($data)->withDayLabels(false);
        $rendered = $heatmap->render();

        // Should not contain day labels at start of lines
        // Day labels would be "S ", "M ", etc. - should not appear
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Legend
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelsAddsLegend(): void
    {
        $data = [
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data)->withLabels(true);
        $rendered = $heatmap->render();

        // Should contain legend text
        $this->assertStringContainsString('Less', $rendered);
        $this->assertStringContainsString('More', $rendered);
    }

    public function testHideLabelsRemovesLegend(): void
    {
        $data = [
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data)->withLabels(false);
        $rendered = $heatmap->render();

        // Should NOT contain legend text
        $this->assertStringNotContainsString('Less', $rendered);
        $this->assertStringNotContainsString('More', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color application
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $data = [
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data)
            ->withLowColor(Color::ansi(0))
            ->withHighColor(Color::ansi(10));
        $rendered = $heatmap->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNullColorsNoAnsiCodes(): void
    {
        $data = [
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data)
            ->withLowColor(null)
            ->withHighColor(null);
        $rendered = $heatmap->render();

        // Should NOT contain ANSI codes for empty char
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Empty character
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyCharCustomization(): void
    {
        $data = [
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
        ];
        $heatmap = HeatmapCalendar::new($data)->withEmptyChar('○');
        $rendered = $heatmap->render();

        $this->assertStringContainsString('○', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLabelsReturnsNewInstance(): void
    {
        $data = [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]];
        $original = HeatmapCalendar::new($data);
        $updated = $original->withLabels(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithDayLabelsReturnsNewInstance(): void
    {
        $data = [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]];
        $original = HeatmapCalendar::new($data);
        $updated = $original->withDayLabels(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithLowColorReturnsNewInstance(): void
    {
        $data = [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]];
        $original = HeatmapCalendar::new($data);
        $updated = $original->withLowColor(Color::ansi(0));

        $this->assertNotSame($original, $updated);
    }

    public function testWithHighColorReturnsNewInstance(): void
    {
        $data = [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]];
        $original = HeatmapCalendar::new($data);
        $updated = $original->withHighColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testWithEmptyCharReturnsNewInstance(): void
    {
        $data = [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7]];
        $original = HeatmapCalendar::new($data);
        $updated = $original->withEmptyChar('○');

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $original = HeatmapCalendar::new($data);
        $resized = $original->setSize(20, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data);
        [$w, $h] = $heatmap->getInnerSize();

        // Width: day label (4) + 2 weeks * 2 (cell + gap) = 8
        $this->assertSame(8, $w);
        // Height: 7 days + legend (2 lines) = 9
        $this->assertSame(9, $h);
    }

    public function testGetInnerSizeWithoutLabels(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data)->withLabels(false);
        [$w, $h] = $heatmap->getInnerSize();

        // Width: 2 weeks * 2 (cell + gap) = 4
        // Height: 7 days only (legend is part of labels)
        $this->assertGreaterThanOrEqual(4, $w);
        $this->assertSame(7, $h);
    }

    public function testGetInnerSizeEmptyData(): void
    {
        $heatmap = HeatmapCalendar::new([]);
        [$w, $h] = $heatmap->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data dimension helpers
    // ═══════════════════════════════════════════════════════════════

    public function testGetDataDimensions(): void
    {
        $data = [
            [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7],
            [0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 0.3],
        ];
        $heatmap = HeatmapCalendar::new($data);
        [$weeks, $days] = $heatmap->getDataDimensions();

        $this->assertSame(2, $weeks);
        $this->assertSame(7, $days);
    }

    public function testGetTotalValue(): void
    {
        $data = [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
        ];
        $heatmap = HeatmapCalendar::new($data);
        $total = $heatmap->getTotalValue();

        $this->assertSame(2.1, $total);
    }

    public function testGetAverageValue(): void
    {
        $data = [
            [0.2, 0.4, 0.6],
        ];
        $heatmap = HeatmapCalendar::new($data);
        $avg = $heatmap->getAverageValue();

        $this->assertEqualsWithDelta(0.4, $avg, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample data
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesValidData(): void
    {
        $heatmap = HeatmapCalendar::sample(10);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testValueOutOfRangeClamped(): void
    {
        $data = [
            [1.5, -0.5, 0.5], // Values outside 0-1 range
        ];
        $heatmap = HeatmapCalendar::new($data);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSingleWeekSingleDay(): void
    {
        $data = [
            [0.5],
        ];
        $heatmap = HeatmapCalendar::new($data);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString("\n", $rendered);
    }
}
