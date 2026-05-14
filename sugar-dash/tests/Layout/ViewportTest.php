<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Components\Card\Paragraph;
use SugarCraft\Dash\Layout\Viewport;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ViewportTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testViewportImplementsSizer(): void
    {
        $viewport = Viewport::new(Text::new('test'));
        $this->assertInstanceOf(Sizer::class, $viewport);
    }

    public function testViewportImplementsItem(): void
    {
        $viewport = Viewport::new(Text::new('test'));
        $this->assertInstanceOf(Item::class, $viewport);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $text = Text::new('Hello World');
        $viewport = Viewport::new($text)->setSize(20, 5);
        $this->assertNotSame('', $viewport->render());
    }

    public function testRenderWithMultiLineContent(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3");
        $viewport = Viewport::new($text)->setSize(20, 3);
        $rendered = $viewport->render();

        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 2', $rendered);
        $this->assertStringContainsString('Line 3', $rendered);
    }

    public function testRenderEmptyContent(): void
    {
        $text = Text::new('');
        $viewport = Viewport::new($text)->setSize(20, 5);
        $this->assertNotSame('', $viewport->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Viewport::new(Text::new('test'));
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $text = Text::new('Hello World');
        $viewport = Viewport::new($text)->setSize(0, 5);
        $this->assertSame('', $viewport->render());
    }

    public function testZeroHeightRendersEmpty(): void
    {
        $text = Text::new('Hello World');
        $viewport = Viewport::new($text)->setSize(20, 0);
        $this->assertSame('', $viewport->render());
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $text = Text::new('Hello');
        $viewport = Viewport::new($text)->setSize(20, 5);
        [$w, $h] = $viewport->getInnerSize();

        $this->assertSame(20, $w);
        $this->assertSame(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scrolling
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultScrollPosition(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3\nLine 4\nLine 5");
        $viewport = Viewport::new($text)->setSize(20, 3);
        $rendered = $viewport->render();

        // Should show first 3 lines by default
        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 2', $rendered);
        $this->assertStringContainsString('Line 3', $rendered);
    }

    public function testScrollYShowsLaterLines(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3\nLine 4\nLine 5");
        $viewport = Viewport::new($text)
            ->withScrollY(2)
            ->setSize(20, 3);
        $rendered = $viewport->render();

        // Should show lines 3, 4, 5
        $this->assertStringContainsString('Line 3', $rendered);
        $this->assertStringContainsString('Line 4', $rendered);
        $this->assertStringContainsString('Line 5', $rendered);
    }

    public function testScrollBy(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3\nLine 4\nLine 5");
        $viewport = Viewport::new($text)
            ->setSize(20, 3);
        $scrolled = $viewport->scrollBy(0, 2);

        $rendered = $scrolled->render();

        // Should show lines 3, 4, 5
        $this->assertStringContainsString('Line 3', $rendered);
        $this->assertStringContainsString('Line 4', $rendered);
        $this->assertStringContainsString('Line 5', $rendered);
    }

    public function testScrollXTruncatesLeft(): void
    {
        $text = Text::new('Hello World');
        $viewport = Viewport::new($text)
            ->withScrollX(6)
            ->setSize(5, 1);
        $rendered = $viewport->render();

        // Should show "World" (starting from character 6)
        // Strip ANSI codes
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringNotContainsString('Hello', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Background color
    // ═══════════════════════════════════════════════════════════════

    public function testWithBackgroundAddsAnsiCodes(): void
    {
        $text = Text::new('test');
        $viewport = Viewport::new($text)
            ->withBackground(Color::ansi(9))
            ->setSize(20, 5);
        $rendered = $viewport->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testCanScrollTrueWhenContentLarger(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3\nLine 4\nLine 5");
        $viewport = Viewport::new($text)->setSize(20, 3);

        $this->assertTrue($viewport->canScroll());
    }

    public function testCanScrollFalseWhenContentFits(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3");
        $viewport = Viewport::new($text)->setSize(20, 5);

        $this->assertFalse($viewport->canScroll());
    }

    public function testScrollBeyondContentIsClamped(): void
    {
        $text = Text::new("Line 1\nLine 2");
        $viewport = Viewport::new($text)
            ->withScrollY(100) // Way beyond content
            ->setSize(20, 3);
        $rendered = $viewport->render();

        // Should still render something, just clamped
        $this->assertNotSame('', $rendered);
    }

    public function testWithScrollXReturnsNewInstance(): void
    {
        $original = Viewport::new(Text::new('test'));
        $modified = $original->withScrollX(5);

        $this->assertNotSame($original, $modified);
    }

    public function testWithScrollYReturnsNewInstance(): void
    {
        $original = Viewport::new(Text::new('test'));
        $modified = $original->withScrollY(5);

        $this->assertNotSame($original, $modified);
    }

    public function testWithScrollReturnsNewInstance(): void
    {
        $original = Viewport::new(Text::new('test'));
        $modified = $original->withScroll(3, 5);

        $this->assertNotSame($original, $modified);
    }

    public function testWithBackgroundReturnsNewInstance(): void
    {
        $original = Viewport::new(Text::new('test'));
        $modified = $original->withBackground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testChainedWithers(): void
    {
        $text = Text::new("Line 1\nLine 2\nLine 3");
        $viewport = Viewport::new($text)
            ->withScroll(0, 1)
            ->withBackground(Color::ansi(8))
            ->setSize(20, 3);

        $this->assertNotSame('', $viewport->render());
    }

    public function testUnicodeContent(): void
    {
        $text = Text::new("日本語\nテスト\nコンテンツ");
        $viewport = Viewport::new($text)->setSize(10, 3);
        $rendered = $viewport->render();

        $this->assertNotSame('', $rendered);
    }

    public function testPaddingWithEmptyLines(): void
    {
        $text = Text::new("Line 1");
        $viewport = Viewport::new($text)->setSize(20, 5);
        $rendered = $viewport->render();

        // Should have 5 lines
        $lines = explode("\n", $rendered);
        $this->assertCount(5, $lines);
    }

    public function testViewportWidthEqualToContent(): void
    {
        $text = Text::new("Hello");
        $viewport = Viewport::new($text)->setSize(5, 1);
        $rendered = $viewport->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringContainsString('Hello', $stripped);
    }
}
