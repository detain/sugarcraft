<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Grid\Progress;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ProgressTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testProgressImplementsSizer(): void
    {
        $progress = Progress::new(0.5);
        $this->assertInstanceOf(Sizer::class, $progress);
    }

    public function testProgressImplementsItem(): void
    {
        $progress = Progress::new(0.5);
        $this->assertInstanceOf(Item::class, $progress);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $progress = Progress::new(0.5);
        $rendered = $progress->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsPercentage(): void
    {
        $progress = Progress::new(0.75);
        $rendered = $progress->render();

        $this->assertStringContainsString('75%', $rendered);
    }

    public function testRenderContainsFilledChars(): void
    {
        $progress = Progress::new(0.5);
        $rendered = $progress->render();

        $this->assertStringContainsString('█', $rendered);
    }

    public function testRenderContainsEmptyChars(): void
    {
        $progress = Progress::new(0.5);
        $rendered = $progress->render();

        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio handling
    // ═══════════════════════════════════════════════════════════════

    public function testZeroRatio(): void
    {
        $progress = Progress::new(0.0);
        $rendered = $progress->render();

        $this->assertStringContainsString('0%', $rendered);
    }

    public function testFullRatio(): void
    {
        $progress = Progress::new(1.0);
        $rendered = $progress->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    public function testRatioClampedAboveOne(): void
    {
        $progress = Progress::new(1.5);
        $rendered = $progress->render();

        // Should clamp to 100%
        $this->assertStringContainsString('100%', $rendered);
    }

    public function testRatioClampedBelowZero(): void
    {
        $progress = Progress::new(-0.5);
        $rendered = $progress->render();

        // Should clamp to 0%
        $this->assertStringContainsString('0%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Segmented progress
    // ═══════════════════════════════════════════════════════════════

    public function testSegmentedFactory(): void
    {
        $progress = Progress::segmented([
            ['label' => 'A', 'ratio' => 0.3, 'color' => Color::hex('#EF4444')],
            ['label' => 'B', 'ratio' => 0.4, 'color' => Color::hex('#22C55E')],
        ]);
        $rendered = $progress->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testSegmentedShowsLabels(): void
    {
        $progress = Progress::segmented([
            ['label' => 'One', 'ratio' => 0.5],
        ])->withShowLabels(true);
        $rendered = $progress->render();

        $this->assertStringContainsString('One', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testSegmentColorAddsAnsiCodes(): void
    {
        $progress = Progress::segmented([
            ['label' => 'Test', 'ratio' => 0.5, 'color' => Color::ansi(9)],
        ]);
        $rendered = $progress->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Percentage display
    // ═══════════════════════════════════════════════════════════════

    public function testHidePercentage(): void
    {
        $progress = Progress::new(0.5)->withShowPercentages(false);
        $rendered = $progress->render();

        $this->assertStringNotContainsString('%', $rendered);
    }

    public function testShowPercentage(): void
    {
        $progress = Progress::new(0.33)->withShowPercentages(true);
        $rendered = $progress->render();

        $this->assertStringContainsString('%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Progress::new(0.5);
        $resized = $original->setSize(50, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testWithWidth(): void
    {
        $progress = Progress::new(0.5)->withWidth(60);
        $rendered = $progress->render();

        // Should be wider than default
        $this->assertGreaterThan(40, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSegmentsReturnsNewInstance(): void
    {
        $original = Progress::new(0.5);
        $updated = $original->withSegments([
            ['label' => 'X', 'ratio' => 0.7, 'color' => Color::hex('#000')],
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $original = Progress::new(0.5);
        $updated = $original->withShowLabels(true);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $progress = Progress::new(0.5);
        [$w, $h] = $progress->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithPercentageShowsExtraWidth(): void
    {
        $progress = Progress::new(0.5)->withShowPercentages(true);
        [$w, ] = $progress->getInnerSize();

        // Should include percentage width
        $this->assertGreaterThan(40, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptySegments(): void
    {
        $progress = new Progress([], true, true, '');
        $rendered = $progress->render();

        $this->assertNotSame('', $rendered);
    }

    public function testVerySmallRatio(): void
    {
        $progress = Progress::new(0.01);
        $rendered = $progress->render();

        $this->assertStringContainsString('1%', $rendered);
    }
}