<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Skeleton;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SkeletonTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSkeletonImplementsSizer(): void
    {
        $skeleton = Skeleton::new();
        $this->assertInstanceOf(Sizer::class, $skeleton);
    }

    public function testSkeletonImplementsItem(): void
    {
        $skeleton = Skeleton::new();
        $this->assertInstanceOf(Item::class, $skeleton);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $skeleton = Skeleton::new();
        $rendered = $skeleton->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderHasCorrectNumberOfLines(): void
    {
        $skeleton = Skeleton::new(5);
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(5, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset types
    // ═══════════════════════════════════════════════════════════════

    public function testTextPreset(): void
    {
        $skeleton = Skeleton::text(3);
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(3, $lines);
    }

    public function testAvatarPreset(): void
    {
        $skeleton = Skeleton::avatar();
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(1, $lines);
    }

    public function testCardPreset(): void
    {
        $skeleton = Skeleton::card();
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(4, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Fill character
    // ═══════════════════════════════════════════════════════════════

    public function testCustomFillChar(): void
    {
        $skeleton = Skeleton::new(1)->withFillChar('▓');
        $rendered = $skeleton->render();

        $this->assertStringContainsString('▓', $rendered);
    }

    public function testEmptyChar(): void
    {
        $skeleton = Skeleton::new(1)->withEmptyChar('·');
        $rendered = $skeleton->render();

        $this->assertStringContainsString('·', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Line widths
    // ═══════════════════════════════════════════════════════════════

    public function testWithLineWidths(): void
    {
        $skeleton = Skeleton::new(3)->withLineWidths([10, 20, 30]);
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        // First line should be narrower
        $this->assertLessThan(
            mb_strlen($lines[1], 'UTF-8'),
            mb_strlen($lines[0], 'UTF-8')
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $skeleton = Skeleton::new()
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $skeleton->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testForegroundColorAddsAnsiCodes(): void
    {
        $skeleton = Skeleton::new()
            ->withForegroundColor(Color::ansi(9));
        $rendered = $skeleton->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $skeleton = Skeleton::new()
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $skeleton->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Skeleton::new();
        $resized = $original->setSize(80, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocationAffectsOutput(): void
    {
        $narrow = Skeleton::new(1)->setSize(20, 1);
        $wide = Skeleton::new(1)->setSize(80, 1);

        $narrowRendered = $narrow->render();
        $wideRendered = $wide->render();

        $this->assertLessThan(
            mb_strlen($wideRendered, 'UTF-8'),
            mb_strlen($narrowRendered, 'UTF-8')
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLinesReturnsNewInstance(): void
    {
        $original = Skeleton::new(1);
        $updated = $original->withLines(5);

        $this->assertNotSame($original, $updated);
        $updatedRendered = $updated->render();
        $this->assertCount(5, explode("\n", $updatedRendered));
    }

    public function testWithLineWidthsReturnsNewInstance(): void
    {
        $original = Skeleton::new(3);
        $updated = $original->withLineWidths([10, 20, 30]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Skeleton::new();
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithFillCharReturnsNewInstance(): void
    {
        $original = Skeleton::new();
        $updated = $original->withFillChar('█');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithLines(): void
    {
        $original = Skeleton::new(3);
        $original->withLines(10);
        $rendered = $original->render();

        $this->assertCount(3, explode("\n", $rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $skeleton = Skeleton::new(3);
        [$w, $h] = $skeleton->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $skeleton = Skeleton::new(2)->setSize(100, 2);
        [$w, ] = $skeleton->getInnerSize();

        $this->assertSame(100, $w);
    }

    public function testGetInnerSizeWithCustomLineWidths(): void
    {
        $skeleton = Skeleton::new(2)->withLineWidths([30, 60]);
        [$w, $h] = $skeleton->getInnerSize();

        $this->assertSame(80, $w); // max of allocated or custom
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroLines(): void
    {
        $skeleton = Skeleton::new(0);
        $rendered = $skeleton->render();

        $this->assertSame('', $rendered);
    }

    public function testNegativeLines(): void
    {
        $skeleton = Skeleton::new(-5);
        $rendered = $skeleton->render();

        // Should handle gracefully
        $this->assertNotNull($rendered);
    }

    public function testSingleLine(): void
    {
        $skeleton = Skeleton::new(1);
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(1, $lines);
    }

    public function testManyLines(): void
    {
        $skeleton = Skeleton::new(100);
        $rendered = $skeleton->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(100, $lines);
    }
}
