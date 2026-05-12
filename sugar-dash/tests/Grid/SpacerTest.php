<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Spacer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use PHPUnit\Framework\TestCase;

final class SpacerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSpacerImplementsSizer(): void
    {
        $spacer = Spacer::new();
        $this->assertInstanceOf(Sizer::class, $spacer);
    }

    public function testSpacerImplementsItem(): void
    {
        $spacer = Spacer::new();
        $this->assertInstanceOf(Item::class, $spacer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $spacer = Spacer::new(5, 3);
        $rendered = $spacer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderReturnsCorrectWidth(): void
    {
        $spacer = Spacer::new(10, 1);
        $rendered = $spacer->render();

        $this->assertSame(10, mb_strlen($rendered, 'UTF-8'));
    }

    public function testRenderReturnsCorrectHeight(): void
    {
        $spacer = Spacer::new(5, 3);
        $rendered = $spacer->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(3, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Default spacer (empty)
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultRenderIsEmpty(): void
    {
        $spacer = Spacer::new(1, 1);
        $rendered = $spacer->render();

        $this->assertSame(' ', $rendered);
    }

    public function testDefaultRenderIsSpaceChar(): void
    {
        $spacer = Spacer::new(5, 1);
        $rendered = $spacer->render();

        $this->assertSame('     ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testDottedFactory(): void
    {
        $spacer = Spacer::dotted(20);
        $rendered = $spacer->render();

        $this->assertStringContainsString('·', $rendered);
        $this->assertSame(20, mb_strlen($rendered, 'UTF-8'));
    }

    public function testDashedFactory(): void
    {
        $spacer = Spacer::dashed(20);
        $rendered = $spacer->render();

        $this->assertStringContainsString('─', $rendered);
        $this->assertSame(20, mb_strlen($rendered, 'UTF-8'));
    }

    public function testVerticalFactory(): void
    {
        $spacer = Spacer::vertical(1, 10);
        $rendered = $spacer->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(10, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Fill character
    // ═══════════════════════════════════════════════════════════════

    public function testCustomFillChar(): void
    {
        $spacer = Spacer::new(5, 1)->withFillChar('.');
        $rendered = $spacer->render();

        $this->assertSame('.....', $rendered);
    }

    public function testFillCharTruncatesToOneChar(): void
    {
        $spacer = Spacer::new(5, 1)->withFillChar('xxx');
        $rendered = $spacer->render();

        $this->assertSame('xxxxx', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Spacer::new(5, 1);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeChangesDimensions(): void
    {
        $original = Spacer::new(5, 1);
        $resized = $original->setSize(20, 5);
        [$w, $h] = $resized->getInnerSize();

        $this->assertSame(20, $w);
        $this->assertSame(5, $h);
    }

    public function testZeroDimensions(): void
    {
        $spacer = Spacer::new(0, 0);
        $rendered = $spacer->render();

        $this->assertSame('', $rendered);
    }

    public function testNegativeDimensionsClamped(): void
    {
        $spacer = Spacer::new(-5, -3);
        $rendered = $spacer->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Spacer::new(5, 1);
        $updated = $original->withWidth(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Spacer::new(5, 1);
        $updated = $original->withHeight(10);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $original = Spacer::new(5, 1);
        $updated = $original->withSize(20, 5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFillCharReturnsNewInstance(): void
    {
        $original = Spacer::new(5, 1);
        $updated = $original->withFillChar('x');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithWidth(): void
    {
        $original = Spacer::new(5, 1);
        $original->withWidth(20);
        [$w, ] = $original->getInnerSize();

        $this->assertSame(5, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $spacer = Spacer::new(10, 5);
        [$w, $h] = $spacer->getInnerSize();

        $this->assertSame(10, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeAfterSetSize(): void
    {
        $spacer = Spacer::new(5, 1)->setSize(30, 10);
        [$w, $h] = $spacer->getInnerSize();

        $this->assertSame(30, $w);
        $this->assertSame(10, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryWideSpacer(): void
    {
        $spacer = Spacer::new(200, 1);
        $rendered = $spacer->render();

        $this->assertSame(200, mb_strlen($rendered, 'UTF-8'));
    }

    public function testVeryTallSpacer(): void
    {
        $spacer = Spacer::new(1, 100);
        $rendered = $spacer->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(100, $lines);
    }

    public function testUnicodeFillChar(): void
    {
        $spacer = Spacer::new(3, 1)->withFillChar('★');
        $rendered = $spacer->render();

        $this->assertSame('★★★', $rendered);
    }
}
