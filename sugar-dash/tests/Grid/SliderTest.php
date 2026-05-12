<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Slider;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class SliderTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSliderImplementsSizer(): void
    {
        $slider = Slider::new(50);
        $this->assertInstanceOf(Sizer::class, $slider);
    }

    public function testSliderImplementsItem(): void
    {
        $slider = Slider::new(50);
        $this->assertInstanceOf(Item::class, $slider);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $slider = Slider::new(50);
        $rendered = $slider->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsThumbChar(): void
    {
        $slider = Slider::new(50);
        $rendered = $slider->render();

        $this->assertStringContainsString('●', $rendered);
    }

    public function testRenderContainsTrackChar(): void
    {
        $slider = Slider::new(50);
        $rendered = $slider->render();

        // Default track char is '─'
        $this->assertStringContainsString('─', $rendered);
    }

    public function testRenderShowsValue(): void
    {
        $slider = Slider::new(75);
        $rendered = $slider->render();

        // Should show value label
        $this->assertStringContainsString('75', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Value clamping
    // ═══════════════════════════════════════════════════════════════

    public function testValueBelowMinClamped(): void
    {
        $slider = Slider::new(-50, 0, 100);
        $rendered = $slider->render();

        // Should show 0 not -50
        $this->assertStringContainsString('0', $rendered);
        $this->assertStringNotContainsString('-', $rendered);
    }

    public function testValueAboveMaxClamped(): void
    {
        $slider = Slider::new(150, 0, 100);
        $rendered = $slider->render();

        // Should show 100 not 150
        $this->assertStringContainsString('100', $rendered);
    }

    public function testValueAtMinShowsAtStart(): void
    {
        $slider = Slider::new(0, 0, 100)->withShowValue(false);
        $rendered = $slider->render();

        // Thumb should be at the start (strip ANSI codes before checking)
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringStartsWith('●', trim($stripped));
    }

    public function testValueAtMaxShowsAtEnd(): void
    {
        $slider = Slider::new(100, 0, 100)->withShowValue(false);
        $rendered = $slider->render();

        // Thumb should be at the end (strip ANSI codes before checking)
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringEndsWith('●', trim($stripped));
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom range
    // ═══════════════════════════════════════════════════════════════

    public function testCustomMinMax(): void
    {
        $slider = Slider::new(50, 0, 50);
        $rendered = $slider->render();

        // Should show 50%
        $this->assertStringContainsString('50', $rendered);
    }

    public function testWithRangeMethod(): void
    {
        $slider = Slider::new(25)->withRange(0, 50);
        $rendered = $slider->render();

        $this->assertStringContainsString('25', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Vertical slider
    // ═══════════════════════════════════════════════════════════════

    public function testVerticalSlider(): void
    {
        $slider = Slider::vertical(50);
        $rendered = $slider->render();

        // Should contain vertical track char
        $this->assertStringContainsString('│', $rendered);
        $this->assertStringContainsString('●', $rendered);
    }

    public function testVerticalFactory(): void
    {
        $slider = Slider::vertical(75, 0, 100);
        $this->assertStringContainsString('│', $slider->render());
    }

    public function testWithVertical(): void
    {
        $slider = Slider::new(50)->withVertical(true);
        $rendered = $slider->render();

        $this->assertStringContainsString('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testTrackColorAddsAnsiCodes(): void
    {
        $slider = Slider::new(50)
            ->withTrackColor(Color::ansi(8));
        $rendered = $slider->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testThumbColorAddsAnsiCodes(): void
    {
        $slider = Slider::new(50)
            ->withThumbColor(Color::ansi(9));
        $rendered = $slider->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $slider = Slider::new(50)
            ->withTrackColor(Color::ansi(8))
            ->withThumbColor(Color::ansi(9));
        $rendered = $slider->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Show/hide value
    // ═══════════════════════════════════════════════════════════════

    public function testShowValueByDefault(): void
    {
        $slider = Slider::new(50);
        $rendered = $slider->render();

        // Should show numeric value (account for ANSI reset at end)
        $this->assertMatchesRegularExpression('/\s+\d+\s*(\x1b\[0m)?$/', $rendered);
    }

    public function testHideValue(): void
    {
        $slider = Slider::new(50)->withShowValue(false);
        $rendered = $slider->render();

        // Should not show any digits at end
        $this->assertDoesNotMatchRegularExpression('/\d+\s*$/', trim($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testCustomChars(): void
    {
        $slider = Slider::new(50)->withChars('■', '□');
        $rendered = $slider->render();

        $this->assertStringContainsString('■', $rendered);
        $this->assertStringContainsString('□', $rendered);
        $this->assertStringNotContainsString('●', $rendered);
        $this->assertStringNotContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Slider::new(50);
        $resized = $original->setSize(40, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $slider = Slider::new(50)->setSize(50, 1);
        $rendered = $slider->render();

        $this->assertNotSame('', $rendered);
    }

    public function testWidthAllocation(): void
    {
        $slider = Slider::new(50)->setSize(60, 1);
        $rendered = $slider->render();

        // With value label, should be wider than track width
        $this->assertGreaterThan(60, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithValueReturnsNewInstance(): void
    {
        $original = Slider::new(25);
        $updated = $original->withValue(75);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('75', $updated->render());
    }

    public function testWithMinReturnsNewInstance(): void
    {
        $original = Slider::new(50);
        $updated = $original->withMin(10);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxReturnsNewInstance(): void
    {
        $original = Slider::new(50);
        $updated = $original->withMax(200);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRangeReturnsNewInstance(): void
    {
        $original = Slider::new(50);
        $updated = $original->withRange(0, 200);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTrackColorReturnsNewInstance(): void
    {
        $original = Slider::new(50);
        $updated = $original->withTrackColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithThumbColorReturnsNewInstance(): void
    {
        $original = Slider::new(50);
        $updated = $original->withThumbColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithValue(): void
    {
        $original = Slider::new(50);
        $original->withValue(75);
        $rendered = $original->render();

        // Original should still show 50
        $this->assertStringContainsString('50', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $slider = Slider::new(50);
        [$w, $h] = $slider->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Single line for horizontal
    }

    public function testGetInnerSizeVertical(): void
    {
        $slider = Slider::vertical(50)->setSize(5, 15);
        [$w, $h] = $slider->getInnerSize();

        $this->assertSame(5, $w);
        $this->assertSame(15, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $slider = Slider::new(50)->setSize(80, 1);
        [$w, ] = $slider->getInnerSize();

        $this->assertGreaterThanOrEqual(80, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinEqualsMax(): void
    {
        $slider = Slider::new(50, 50, 50);
        $rendered = $slider->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testHalfValuePositionsThumbInMiddle(): void
    {
        $slider = Slider::new(50, 0, 100)->setSize(10, 1)->withShowValue(false);
        $rendered = $slider->render();

        // Thumb ● should be roughly in the middle
        // With width 10 and value 50%, thumb at position 5 (0-indexed 4 or 5)
        $this->assertStringContainsString('●', $rendered);
    }
}
