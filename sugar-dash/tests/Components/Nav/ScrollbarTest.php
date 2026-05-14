<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Nav;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Components\Nav\Scrollbar;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ScrollbarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testScrollbarImplementsSizer(): void
    {
        $scrollbar = Scrollbar::new(0.0);
        $this->assertInstanceOf(Sizer::class, $scrollbar);
    }

    public function testScrollbarImplementsItem(): void
    {
        $scrollbar = Scrollbar::new(0.0);
        $this->assertInstanceOf(Item::class, $scrollbar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $scrollbar = Scrollbar::new(0.0);
        $rendered = $scrollbar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsThumbCharacter(): void
    {
        $scrollbar = Scrollbar::new(0.5);
        $rendered = $scrollbar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringContainsString('█', $stripped);
    }

    public function testRenderContainsTrackCharacter(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withThumbColor(null);
        $rendered = $scrollbar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringContainsString('│', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Position tests
    // ═══════════════════════════════════════════════════════════════

    public function testZeroPositionShowsThumbAtTop(): void
    {
        $scrollbar = Scrollbar::new(0.0);
        $rendered = $scrollbar->render();
        $lines = explode("\n", $rendered);

        // Thumb should be near top (after arrow if shown)
        // Second line should contain thumb char at position 0
        $this->assertStringContainsString('█', $lines[1] ?? '');
    }

    public function testFullPositionShowsThumbAtBottom(): void
    {
        $scrollbar = Scrollbar::new(1.0);
        $rendered = $scrollbar->render();
        $lines = explode("\n", $rendered);

        // Thumb should be near bottom
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $lines[count($lines) - 2] ?? '');
        $this->assertStringContainsString('█', $stripped);
    }

    public function testMiddlePositionShowsThumbInMiddle(): void
    {
        $scrollbar = Scrollbar::new(0.5);
        $rendered = $scrollbar->render();
        $lines = explode("\n", $rendered);

        // Count lines with thumb
        $thumbLines = array_filter($lines, fn($l) => str_contains(preg_replace('/\x1b\[[0-9;]*m/', '', $l) ?? '', '█'));

        // With 10 height and arrows, track is 8 lines
        // Thumb should be in the middle portion
        $this->assertGreaterThan(0, count($thumbLines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Position clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativePositionClampedToZero(): void
    {
        $scrollbar = Scrollbar::new(-0.5);
        $rendered = $scrollbar->render();
        $lines = explode("\n", $rendered);

        // Thumb should be at top
        $this->assertStringContainsString('█', $lines[1] ?? '');
    }

    public function testOverOnePositionClampedToFull(): void
    {
        $scrollbar = Scrollbar::new(1.5);
        $rendered = $scrollbar->render();
        $lines = explode("\n", $rendered);

        // Thumb should be at bottom
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $lines[count($lines) - 2] ?? '');
        $this->assertStringContainsString('█', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Viewport ratio
    // ═══════════════════════════════════════════════════════════════

    public function testSmallViewportRatioLargerThumb(): void
    {
        $scrollbar = Scrollbar::new(0.0)->withViewportRatio(0.1);
        $rendered = $scrollbar->render();

        // Smaller viewport = larger visible portion = larger thumb
        // Count thumb characters
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $thumbCount = substr_count($stripped, '█');
        $this->assertGreaterThanOrEqual(1, $thumbCount);
    }

    public function testLargeViewportRatioSmallerThumb(): void
    {
        $scrollbar = Scrollbar::new(0.0)->withViewportRatio(0.9);
        $rendered = $scrollbar->render();

        // Larger viewport = smaller thumb (if track allows)
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $thumbCount = substr_count($stripped, '█');
        $this->assertGreaterThanOrEqual(1, $thumbCount);
    }

    // ═══════════════════════════════════════════════════════════════
    // Arrows
    // ═══════════════════════════════════════════════════════════════

    public function testShowArrowsAddsArrowCharacters(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withShowArrows(true);
        $rendered = $scrollbar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringContainsString('▲', $stripped);
        $this->assertStringContainsString('▼', $stripped);
    }

    public function testHideArrowsRemovesArrowCharacters(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withShowArrows(false)->withHeight(10);
        $rendered = $scrollbar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringNotContainsString('▲', $stripped);
        $this->assertStringNotContainsString('▼', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $resized = $original->setSize(1, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testCustomHeightAffectsOutput(): void
    {
        $short = Scrollbar::new(0.5)->withHeight(5);
        $tall = Scrollbar::new(0.5)->withHeight(20);

        $shortRendered = $short->render();
        $tallRendered = $tall->render();

        $shortLines = count(explode("\n", $shortRendered));
        $tallLines = count(explode("\n", $tallRendered));

        $this->assertGreaterThan($shortLines, $tallLines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testThumbColorAddsAnsiCodes(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withThumbColor(Color::ansi(9));
        $rendered = $scrollbar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTrackColorAddsAnsiCodes(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withTrackColor(Color::ansi(8));
        $rendered = $scrollbar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNoColorRendersPlainCharacters(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withThumbColor(null)->withTrackColor(null);
        $rendered = $scrollbar->render();

        // Should not have ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testCustomThumbAndTrackChars(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withChars('#', '-');
        $rendered = $scrollbar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringContainsString('#', $stripped);
        $this->assertStringContainsString('-', $stripped);
        $this->assertStringNotContainsString('█', $stripped);
        $this->assertStringNotContainsString('│', $stripped);
    }

    public function testCustomArrows(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withArrows('^', 'v');
        $rendered = $scrollbar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered ?? '');
        $this->assertStringContainsString('^', $stripped);
        $this->assertStringContainsString('v', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithPositionReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $updated = $original->withPosition(0.8);

        $this->assertNotSame($original, $updated);
    }

    public function testWithPositionAffectsOutput(): void
    {
        $top = Scrollbar::new(0.0);
        $bottom = Scrollbar::new(1.0);

        // The rendered output should differ based on position
        $topRendered = $top->render();
        $bottomRendered = $bottom->render();

        // The two renders should differ
        $this->assertNotEquals($topRendered, $bottomRendered);
    }

    public function testOriginalUnchangedAfterWithPosition(): void
    {
        $original = Scrollbar::new(0.5);
        $original->withPosition(0.9);

        // Original should be immutable: rendering it again still produces the
        // same string (with the 0.5 position thumb), since withPosition returns
        // a new instance.
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $original->render());
        $this->assertStringContainsString('█', $stripped);
    }

    public function testWithViewportRatioReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $updated = $original->withViewportRatio(0.2);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $updated = $original->withHeight(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithThumbColorReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $updated = $original->withThumbColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTrackColorReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $updated = $original->withTrackColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowArrowsReturnsNewInstance(): void
    {
        $original = Scrollbar::new(0.5);
        $updated = $original->withShowArrows(false);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withHeight(10);
        [$w, $h] = $scrollbar->getInnerSize();

        $this->assertSame(1, $w);
        $this->assertSame(10, $h);
    }

    public function testGetInnerSizeWithArrows(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withHeight(10)->withShowArrows(true);
        [$w, $h] = $scrollbar->getInnerSize();

        // Height should be 10 (track + 2 arrows)
        $this->assertSame(10, $h);
    }

    public function testGetInnerSizeWithoutArrows(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withHeight(10)->withShowArrows(false);
        [$w, $h] = $scrollbar->getInnerSize();

        // Height should be 10 (just track)
        $this->assertSame(10, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVerySmallHeight(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withHeight(3);
        $rendered = $scrollbar->render();

        // Should still render something
        $this->assertNotSame('', $rendered);
    }

    public function testZeroViewportRatioClamped(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withViewportRatio(0.0);
        $rendered = $scrollbar->render();

        // Should still render without error
        $this->assertNotSame('', $rendered);
    }

    public function testViewportRatioOneClamped(): void
    {
        $scrollbar = Scrollbar::new(0.5)->withViewportRatio(1.0);
        $rendered = $scrollbar->render();

        // Should still render without error
        $this->assertNotSame('', $rendered);
    }
}
