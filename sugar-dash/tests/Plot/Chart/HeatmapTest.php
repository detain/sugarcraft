<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\Heatmap;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class HeatmapTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHeatmapImplementsSizer(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $this->assertInstanceOf(Sizer::class, $heatmap);
    }

    public function testHeatmapImplementsItem(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $this->assertInstanceOf(Item::class, $heatmap);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsHeatCharacters(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $rendered = $heatmap->render();

        // Should contain block characters
        $this->assertMatchesRegularExpression('/[░▒▓█]/', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $rendered = $heatmap->render();

        // Heatmap is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data handling
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentValuesProduceDifferentOutput(): void
    {
        $heatmap1 = Heatmap::new([[0.0]]);
        $heatmap2 = Heatmap::new([[1.0]]);

        $rendered1 = $heatmap1->render();
        $rendered2 = $heatmap2->render();

        $this->assertNotSame($rendered1, $rendered2);
    }

    public function testValuesClampedToRange(): void
    {
        $heatmap = Heatmap::new([[-0.5], [1.5]]);
        $rendered = $heatmap->render();

        // Should still render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testEmptyData(): void
    {
        $heatmap = Heatmap::new([]);
        $rendered = $heatmap->render();

        // Should return empty string
        $this->assertSame('', $rendered);
    }

    public function testEmptyRow(): void
    {
        $heatmap = Heatmap::new([[]]);
        $rendered = $heatmap->render();

        // Should return empty string
        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Legend
    // ═══════════════════════════════════════════════════════════════

    public function testLegendShownByDefault(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $rendered = $heatmap->render();

        // Should contain legend text
        $this->assertStringContainsString('Legend', $rendered);
    }

    public function testHideLegend(): void
    {
        $heatmap = Heatmap::new([[0.5]])->withLegend(false);
        $rendered = $heatmap->render();

        // Should NOT contain legend
        $this->assertStringNotContainsString('Legend', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Values display
    // ═══════════════════════════════════════════════════════════════

    public function testShowValuesDisabledByDefault(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $rendered = $heatmap->render();

        // Should contain block characters, not numbers
        $this->assertMatchesRegularExpression('/[░▒▓█]/', $rendered);
    }

    public function testShowValues(): void
    {
        $heatmap = Heatmap::new([[0.5]])->withValues(true);
        $rendered = $heatmap->render();

        // Should contain numeric values
        $this->assertMatchesRegularExpression('/0\.\d+/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $heatmap = Heatmap::new([[0.5]])
            ->withLowColor(Color::ansi(4))
            ->withHighColor(Color::ansi(1));
        $rendered = $heatmap->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Statistics
    // ═══════════════════════════════════════════════════════════════

    public function testGetDataDimensions(): void
    {
        $heatmap = Heatmap::new([[0.1, 0.2], [0.3, 0.4], [0.5, 0.6]]);
        [$rows, $cols] = $heatmap->getDataDimensions();

        $this->assertSame(3, $rows);
        $this->assertSame(2, $cols);
    }

    public function testGetMinValue(): void
    {
        $heatmap = Heatmap::new([[0.1, 0.5], [0.3, 0.9]]);
        $min = $heatmap->getMinValue();

        $this->assertSame(0.1, $min);
    }

    public function testGetMaxValue(): void
    {
        $heatmap = Heatmap::new([[0.1, 0.5], [0.3, 0.9]]);
        $max = $heatmap->getMaxValue();

        $this->assertSame(0.9, $max);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        [$w, $h] = $heatmap->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithValues(): void
    {
        $heatmap = Heatmap::new([[0.5]])->withValues(true);
        [$w, $h] = $heatmap->getInnerSize();

        // With values shown, width is larger
        $this->assertGreaterThan(1, $w);
    }

    public function testGetInnerSizeWithoutLegend(): void
    {
        $heatmap = Heatmap::new([[0.5]])->withLegend(false);
        [, $h] = $heatmap->getInnerSize();

        // Without legend, height is just the data rows
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLegendReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $updated = $original->withLegend(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithValuesReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $updated = $original->withValues(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithLowColorReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $updated = $original->withLowColor(Color::ansi(4));

        $this->assertNotSame($original, $updated);
    }

    public function testWithHighColorReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $updated = $original->withHighColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithRowLabelFormatReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $updated = $original->withRowLabelFormat('R%d');

        $this->assertNotSame($original, $updated);
    }

    public function testWithColLabelFormatReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $updated = $original->withColLabelFormat('C%d');

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Heatmap::new([[0.5]]);
        $resized = $original->setSize(20, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesInstance(): void
    {
        $heatmap = Heatmap::new([[0.5]]);
        $this->assertInstanceOf(Heatmap::class, $heatmap);
    }

    public function testSampleCreatesInstance(): void
    {
        $heatmap = Heatmap::sample(3, 4);
        $this->assertInstanceOf(Heatmap::class, $heatmap);
    }

    public function testSampleHasCorrectDimensions(): void
    {
        $heatmap = Heatmap::sample(3, 4);
        [$rows, $cols] = $heatmap->getDataDimensions();

        $this->assertSame(3, $rows);
        $this->assertSame(4, $cols);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testAllSameValues(): void
    {
        $heatmap = Heatmap::new([[0.5, 0.5], [0.5, 0.5]]);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testGradientValues(): void
    {
        $heatmap = Heatmap::new([
            [0.0, 0.25, 0.5, 0.75, 1.0]
        ]);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLargerGrid(): void
    {
        $data = [];
        for ($r = 0; $r < 5; $r++) {
            $row = [];
            for ($c = 0; $c < 10; $c++) {
                $row[] = ($r + $c) / 14.0;
            }
            $data[] = $row;
        }
        $heatmap = Heatmap::new($data);
        $rendered = $heatmap->render();

        $this->assertNotSame('', $rendered);
    }
}