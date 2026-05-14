<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\System;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Components\System\ProgressBar;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ProgressBarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testProgressBarImplementsSizer(): void
    {
        $bar = ProgressBar::new(0.5);
        $this->assertInstanceOf(Sizer::class, $bar);
    }

    public function testProgressBarImplementsItem(): void
    {
        $bar = ProgressBar::new(0.5);
        $this->assertInstanceOf(Item::class, $bar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $bar = ProgressBar::new(0.5);
        $this->assertNotSame('', $bar->render());
    }

    public function testRenderContainsFilledCharacters(): void
    {
        $bar = ProgressBar::new(0.5);
        $rendered = $bar->render();

        // Strip ANSI codes and check for filled char
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringContainsString('█', $stripped ?? '');
    }

    public function testRenderContainsPercentage(): void
    {
        $bar = ProgressBar::new(0.75);
        $rendered = $bar->render();

        $this->assertStringContainsString('75%', $rendered);
    }

    public function testZeroRatioShowsNoFilledContent(): void
    {
        $bar = ProgressBar::new(0.0)->withFilledColor(null);
        $rendered = $bar->render();

        // Should only show empty chars and percentage
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringContainsString('0%', $stripped ?? '');
    }

    public function testFullRatioShowsAllFilled(): void
    {
        $bar = ProgressBar::new(1.0)->withFilledColor(Color::ansi(9));
        $rendered = $bar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        // Should show 100%
        $this->assertStringContainsString('100%', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeRatioClampedToZero(): void
    {
        $bar = ProgressBar::new(-0.5);
        $rendered = $bar->render();

        $this->assertStringContainsString('0%', $rendered);
    }

    public function testOverOneRatioClampedToFull(): void
    {
        $bar = ProgressBar::new(1.5);
        $rendered = $bar->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Width handling
    // ═══════════════════════════════════════════════════════════════

    public function testCustomWidthAffectsOutput(): void
    {
        $narrow = ProgressBar::new(0.5)->withWidth(10);
        $wide = ProgressBar::new(0.5)->withWidth(40);

        $narrowRendered = $narrow->render();
        $wideRendered = $wide->render();

        // Wide should be noticeably longer
        $this->assertGreaterThan(
            strlen(preg_replace('/\x1b\[[0-9;]*m/', '', $narrowRendered ?? '')),
            strlen(preg_replace('/\x1b\[[0-9;]*m/', '', $wideRendered ?? ''))
        );
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ProgressBar::new(0.5);
        $resized = $original->setSize(40, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testFilledColorAddsAnsiCodes(): void
    {
        $bar = ProgressBar::new(0.5)->withFilledColor(Color::ansi(9));
        $rendered = $bar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testEmptyColorAddsAnsiCodes(): void
    {
        $bar = ProgressBar::new(0.5)->withEmptyColor(Color::ansi(8));
        $rendered = $bar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label position
    // ═══════════════════════════════════════════════════════════════

    public function testLabelBeforeBar(): void
    {
        $bar = ProgressBar::new(0.5)->withLabelAfter(false);
        $rendered = $bar->render();

        // Percentage should appear before the bar
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $pos = strpos($stripped, '50%');
        $barPos = strpos($stripped, '█');
        $this->assertNotFalse($pos);
        $this->assertNotFalse($barPos);
        $this->assertLessThan($barPos, $pos);
    }

    public function testLabelAfterBar(): void
    {
        $bar = ProgressBar::new(0.5)->withLabelAfter(true);
        $rendered = $bar->render();

        // Percentage should appear after the bar
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $barPos = strpos($stripped, '█');
        $percentPos = strpos($stripped, '50%');
        $this->assertNotFalse($barPos);
        $this->assertNotFalse($percentPos);
        $this->assertGreaterThan($barPos, $percentPos);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testCustomChars(): void
    {
        $bar = ProgressBar::new(0.5)->withChars('=', '-');
        $rendered = $bar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringContainsString('=', $stripped);
        $this->assertStringNotContainsString('█', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Percentage toggle
    // ═══════════════════════════════════════════════════════════════

    public function testHidePercentage(): void
    {
        $bar = ProgressBar::new(0.5)->withPercentage(false);
        $rendered = $bar->render();

        $this->assertStringNotContainsString('%', $rendered);
    }

    public function testShowPercentage(): void
    {
        $bar = ProgressBar::new(0.5)->withPercentage(true);
        $rendered = $bar->render();

        $this->assertStringContainsString('50%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = ProgressBar::new(0.5);
        $updated = $original->withRatio(0.75);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRatioAffectsOutput(): void
    {
        $original = ProgressBar::new(0.5);
        $updated = $original->withRatio(0.25);

        $this->assertStringContainsString('25%', $updated->render());
        $this->assertStringContainsString('50%', $original->render());
    }

    public function testOriginalUnchangedAfterWithRatio(): void
    {
        $original = ProgressBar::new(0.5);
        $original->withRatio(0.9);

        $this->assertStringContainsString('50%', $original->render());
    }

    public function testWithFilledColorReturnsNewInstance(): void
    {
        $original = ProgressBar::new(0.5);
        $updated = $original->withFilledColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithEmptyColorReturnsNewInstance(): void
    {
        $original = ProgressBar::new(0.5);
        $updated = $original->withEmptyColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $bar = ProgressBar::new(0.5)->withWidth(30);
        [$w, $h] = $bar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithoutWidthUsesConstraint(): void
    {
        $bar = ProgressBar::new(0.5);
        [$w, $h] = $bar->getInnerSize();

        // Default width is 30, plus 5 for percentage
        $this->assertGreaterThanOrEqual(30, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVerySmallWidth(): void
    {
        $bar = ProgressBar::new(0.5)->withWidth(1);
        $rendered = $bar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testMidRatioShowsCorrectPercentage(): void
    {
        $bar = ProgressBar::new(0.333);
        $rendered = $bar->render();

        // 33% rounded
        $this->assertStringContainsString('33%', $rendered);
    }
}