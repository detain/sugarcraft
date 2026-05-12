<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\ColorPicker;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ColorPickerTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testColorPickerImplementsSizer(): void
    {
        $picker = ColorPicker::new();
        $this->assertInstanceOf(Sizer::class, $picker);
    }

    public function testColorPickerImplementsItem(): void
    {
        $picker = ColorPicker::new();
        $this->assertInstanceOf(Item::class, $picker);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $picker = ColorPicker::new();
        $rendered = $picker->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsAnsiCodes(): void
    {
        $picker = ColorPicker::new();
        $rendered = $picker->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRenderShowsColorSwatches(): void
    {
        $picker = ColorPicker::new();
        $rendered = $picker->render();

        // Should contain block characters
        $this->assertStringContainsString('██', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedColorIsHighlighted(): void
    {
        $picker = ColorPicker::new(5);
        $rendered = $picker->render();

        // Selected color should have brackets
        $this->assertStringContainsString('[', $rendered);
    }

    public function testSwitchingSelection(): void
    {
        $picker = ColorPicker::new(0);
        $picker2 = $picker->withSelectedIndex(3);

        $this->assertNotSame($picker, $picker2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Hex display
    // ═══════════════════════════════════════════════════════════════

    public function testShowsHexCode(): void
    {
        $picker = ColorPicker::new(10);
        $rendered = $picker->render();

        // Default palette at index 10 is #3B82F6
        $this->assertStringContainsString('3B82F6', $rendered);
    }

    public function testHideHexCode(): void
    {
        $picker = ColorPicker::new(10)->withShowHex(false);
        $rendered = $picker->render();

        $this->assertStringNotContainsString('3B82F6', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom palette
    // ═══════════════════════════════════════════════════════════════

    public function testCustomPalette(): void
    {
        $picker = ColorPicker::fromPalette([
            '#FF0000',
            '#00FF00',
            '#0000FF',
        ], 1);

        $rendered = $picker->render();

        $this->assertStringContainsString('FF0000', $rendered);
        $this->assertStringContainsString('00FF00', $rendered);
        $this->assertStringContainsString('0000FF', $rendered);
    }

    public function testEmptyPalette(): void
    {
        $picker = ColorPicker::fromPalette([]);
        $rendered = $picker->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Column configuration
    // ═══════════════════════════════════════════════════════════════

    public function testCustomColumns(): void
    {
        $picker = ColorPicker::fromPalette([
            '#FF0000',
            '#00FF00',
            '#0000FF',
            '#FFFF00',
        ])->withColumns(2);

        $rendered = $picker->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSingleColumn(): void
    {
        $picker = ColorPicker::fromPalette([
            '#FF0000',
            '#00FF00',
        ])->withColumns(1);

        $rendered = $picker->render();

        $this->assertNotSame('', $rendered);
        // Should have more rows (one color per row)
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(2, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedColorAddsAnsiCodes(): void
    {
        $picker = ColorPicker::new(5)->withSelectedColor(Color::ansi(9));
        $rendered = $picker->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = ColorPicker::new(0);
        $updated = $original->withSelectedIndex(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColumnsReturnsNewInstance(): void
    {
        $original = ColorPicker::new();
        $updated = $original->withColumns(6);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedIndex(): void
    {
        $original = ColorPicker::new(0);
        $original->withSelectedIndex(5);

        // Original should still have index 0 selected
        $rendered = $original->render();
        $stripped = $this->stripAnsi($rendered);
        // Index 0 shows first color in brackets with block char
        $this->assertStringContainsString('[', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ColorPicker::new();
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $picker = ColorPicker::new();
        [$w, $h] = $picker->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithHiddenHex(): void
    {
        $picker = ColorPicker::new()->withShowHex(false);
        [$w, $h] = $picker->getInnerSize();

        // Height should be one less without hex display
        $withHex = ColorPicker::new();
        [$w2, $h2] = $withHex->getInnerSize();

        $this->assertLessThan($h2, $h);
    }

    public function testGetInnerSizeSingleColumn(): void
    {
        $picker = ColorPicker::fromPalette([
            '#FF0000',
            '#00FF00',
            '#0000FF',
        ])->withColumns(1);

        [$w, $h] = $picker->getInnerSize();

        // Should be taller with single column
        $this->assertGreaterThan(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeIndexClampedToZero(): void
    {
        $picker = ColorPicker::new(-5);
        $rendered = $picker->render();

        $this->assertNotSame('', $rendered);
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $picker = ColorPicker::fromPalette([
            '#FF0000',
            '#00FF00',
        ])->withSelectedIndex(100);

        $rendered = $picker->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testWithPaletteClampsSelectedIndex(): void
    {
        $original = ColorPicker::new(10);
        $updated = $original->withPalette(['#FF0000']);

        $rendered = $updated->render();
        $this->assertNotSame('', $rendered);
    }
}
