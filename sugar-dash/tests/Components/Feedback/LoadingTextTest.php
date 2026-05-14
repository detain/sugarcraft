<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Feedback;

use SugarCraft\Dash\Components\Feedback\LoadingText;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class LoadingTextTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testLoadingTextImplementsSizer(): void
    {
        $loading = LoadingText::new();
        $this->assertInstanceOf(Sizer::class, $loading);
    }

    public function testLoadingTextImplementsItem(): void
    {
        $loading = LoadingText::new();
        $this->assertInstanceOf(Item::class, $loading);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $loading = LoadingText::new();
        $rendered = $loading->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsText(): void
    {
        $loading = LoadingText::new('Processing');
        $rendered = $loading->render();

        $this->assertStringContainsString('Processing', $rendered);
    }

    public function testDefaultTextIsLoading(): void
    {
        $loading = LoadingText::new();
        $rendered = $loading->render();

        $this->assertStringContainsString('Loading', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithColorAddsAnsiCodes(): void
    {
        $loading = LoadingText::new()
            ->withColor(Color::ansi(9)); // Red
        $rendered = $loading->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRenderWithoutColorHasNoAnsi(): void
    {
        $loading = LoadingText::new()->withColor(null);
        $rendered = $loading->render();

        // Should NOT contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $loading = LoadingText::new()
            ->withColor(Color::ansi(9)); // Red
        $rendered = $loading->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Animation styles
    // ═══════════════════════════════════════════════════════════════

    public function testDotsStyleAnimation(): void
    {
        $loading = LoadingText::new()->withStyle(LoadingText::Dots);

        // Get frames at different indices
        $frame0 = $loading->getFrameAt(0); // 0 dots
        $frame1 = $loading->getFrameAt(1); // 1 dot
        $frame2 = $loading->getFrameAt(2); // 2 dots
        $frame3 = $loading->getFrameAt(3); // 3 dots
        $frame4 = $loading->getFrameAt(4); // wraps to 0 dots

        $this->assertSame('', $frame0);
        $this->assertSame('.', $frame1);
        $this->assertSame('..', $frame2);
        $this->assertSame('...', $frame3);
        $this->assertSame('', $frame4); // wraps
    }

    public function testBouncingStyleAnimation(): void
    {
        $loading = LoadingText::new()->withStyle(LoadingText::Bouncing);

        $frame0 = $loading->getFrameAt(0);
        $frame1 = $loading->getFrameAt(1);
        $frame2 = $loading->getFrameAt(2);
        $frame3 = $loading->getFrameAt(3);

        $this->assertSame(' ●', $frame0);
        $this->assertSame(' ○', $frame1);
        $this->assertSame(' ●', $frame2);
        $this->assertSame(' ○', $frame3);
    }

    public function testPulsingStyleAnimation(): void
    {
        $loading = LoadingText::new()->withStyle(LoadingText::Pulsing);

        $frame0 = $loading->getFrameAt(0);
        $frame1 = $loading->getFrameAt(1);
        $frame2 = $loading->getFrameAt(2);

        $this->assertSame('*', $frame0);
        $this->assertSame('', $frame1);
        $this->assertSame('*', $frame2);
    }

    public function testArrowsStyleAnimation(): void
    {
        $loading = LoadingText::new()->withStyle(LoadingText::Arrows);

        $frame0 = $loading->getFrameAt(0);
        $frame1 = $loading->getFrameAt(1);
        $frame2 = $loading->getFrameAt(2);
        $frame3 = $loading->getFrameAt(3);

        $this->assertSame(' < ', $frame0);
        $this->assertSame('   ', $frame1);
        $this->assertSame(' > ', $frame2);
        $this->assertSame('   ', $frame3);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interval handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithIntervalAcceptsPositiveValues(): void
    {
        $loading = LoadingText::new()->withInterval(100);
        $this->assertSame(100, $loading->getInterval());
    }

    public function testWithIntervalClampsNegativeToOne(): void
    {
        $loading = LoadingText::new()->withInterval(-50);
        $this->assertSame(1, $loading->getInterval());
    }

    public function testWithIntervalClampsZeroToOne(): void
    {
        $loading = LoadingText::new()->withInterval(0);
        $this->assertSame(1, $loading->getInterval());
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $loading = LoadingText::new('Test');
        [$w, $h] = $loading->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithLongerText(): void
    {
        $loading = LoadingText::new('Processing files...');
        [$w, $h] = $loading->getInnerSize();

        $this->assertGreaterThan(10, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTextReturnsNewInstance(): void
    {
        $original = LoadingText::new();
        $updated = $original->withText('Processing');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Processing', $updated->render());
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $original = LoadingText::new()->withStyle(LoadingText::Dots);
        $updated = $original->withStyle(LoadingText::Bouncing);

        $this->assertNotSame($original, $updated);
        $this->assertSame(LoadingText::Bouncing, $updated->getStyle());
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = LoadingText::new()->withColor(null);
        $red = Color::ansi(9);
        $updated = $original->withColor($red);

        $this->assertNotSame($original, $updated);
    }

    public function testWithIntervalReturnsNewInstance(): void
    {
        $original = LoadingText::new()->withInterval(100);
        $updated = $original->withInterval(200);

        $this->assertNotSame($original, $updated);
        $this->assertSame(200, $updated->getInterval());
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = LoadingText::new();
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyText(): void
    {
        $loading = LoadingText::new('');
        $rendered = $loading->render();

        // Should still render animation frames
        $this->assertNotSame('', $rendered);
    }

    public function testGetFrameAtDotsWithEmptyText(): void
    {
        $loading = (new LoadingText(''))->withStyle(LoadingText::Dots);
        $frame = $loading->getFrameAt(2);

        // Frame should still work with empty base text
        $this->assertSame('..', $frame);
    }

    public function testMultipleInstancesIndependent(): void
    {
        $loading1 = LoadingText::new('A')->withStyle(LoadingText::Dots);
        $loading2 = LoadingText::new('B')->withStyle(LoadingText::Bouncing);

        $rendered1 = $loading1->render();
        $rendered2 = $loading2->render();

        $this->assertStringContainsString('A', $rendered1);
        $this->assertStringContainsString('B', $rendered2);
    }
}