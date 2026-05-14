<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\StatusBar;

use SugarCraft\Dash\Components\StatusBar\StatusBar;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class StatusBarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testStatusBarImplementsSizer(): void
    {
        $bar = StatusBar::new();
        $this->assertInstanceOf(Sizer::class, $bar);
    }

    public function testStatusBarImplementsItem(): void
    {
        $bar = StatusBar::new();
        $this->assertInstanceOf(Item::class, $bar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $bar = StatusBar::new()->withLeft('Status');
        $this->assertNotSame('', $bar->render());
    }

    public function testRenderEmptySegments(): void
    {
        $bar = StatusBar::new();
        $this->assertNotSame('', $bar->render());
    }

    public function testRenderWithLeftSegment(): void
    {
        $bar = StatusBar::new()->withLeft('Left Content');
        $rendered = $bar->render();

        $this->assertStringContainsString('Left Content', $rendered);
    }

    public function testRenderWithCenterSegment(): void
    {
        $bar = StatusBar::new()->withCenter('Center Content');
        $rendered = $bar->render();

        $this->assertStringContainsString('Center Content', $rendered);
    }

    public function testRenderWithRightSegment(): void
    {
        $bar = StatusBar::new()->withRight('Right Content');
        $rendered = $bar->render();

        $this->assertStringContainsString('Right Content', $rendered);
    }

    public function testRenderWithAllSegments(): void
    {
        $bar = StatusBar::new()->withSegments('L', 'C', 'R');
        $rendered = $bar->render();

        $this->assertStringContainsString('L', $rendered);
        $this->assertStringContainsString('C', $rendered);
        $this->assertStringContainsString('R', $rendered);
    }

    public function testDefaultNewHasColors(): void
    {
        $bar = StatusBar::new();
        $rendered = $bar->render();

        // Default should have ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testDefaultNewEndsWithReset(): void
    {
        $bar = StatusBar::new();
        $rendered = $bar->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Width handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $resized = $original->setSize(40, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRender(): void
    {
        $bar = StatusBar::new()->withLeft('Hi')->setSize(30, 1);
        $rendered = $bar->render();

        // Should be padded to width 30
        $this->assertGreaterThan(2, strlen($rendered));
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $bar = StatusBar::new()->withSegments('A', 'B', 'C')->setSize(0, 1);
        $this->assertSame('', $bar->render());
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $bar = StatusBar::new()->withSegments('L', 'C', 'R')->setSize(20, 1);
        [$w, $h] = $bar->getInnerSize();

        $this->assertSame(20, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithoutSetSize(): void
    {
        $bar = StatusBar::new()->withLeft('Hello');
        [$w, $h] = $bar->getInnerSize();

        // Height should be 1 for status bar
        $this->assertSame(1, $h);
        // Width should be at least content width
        $this->assertGreaterThanOrEqual(5, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithForegroundAddsAnsiCodes(): void
    {
        $bar = StatusBar::new()
            ->withForeground(Color::ansi(12)); // Cyan
        $rendered = $bar->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWithBackgroundAddsAnsiCodes(): void
    {
        $bar = StatusBar::new()
            ->withBackground(Color::ansi(9)); // Red
        $rendered = $bar->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWithNullForegroundRendersWithoutFgColor(): void
    {
        $bar = new StatusBar('test', '', '', null, Color::ansi(9), '', '');
        $rendered = $bar->render();

        // Should still render but without foreground color
        $this->assertNotSame('', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $bar = StatusBar::new()
            ->withForeground(Color::ansi(9))
            ->withBackground(Color::ansi(8));
        $rendered = $bar->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithBordersAddsBorderChars(): void
    {
        $bar = StatusBar::new()
            ->withSegments('Content', '', '')
            ->withBorders('[', ']')
            ->setSize(20, 1);
        $rendered = $bar->render();

        // Should contain the border characters
        $this->assertStringContainsString('[', $rendered);
        $this->assertStringContainsString(']', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Truncation
    // ═══════════════════════════════════════════════════════════════

    public function testLongContentTruncated(): void
    {
        $bar = StatusBar::new()
            ->withSegments('This is very long left', 'Center content', 'Right content')
            ->setSize(15, 1);
        $rendered = $bar->render();

        // Should be truncated to fit
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLeftReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withLeft('Modified');

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('Modified', $modified->render());
        $this->assertStringNotContainsString('Modified', $original->render());
    }

    public function testWithCenterReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withCenter('Center');

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('Center', $modified->render());
    }

    public function testWithRightReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withRight('Right');

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('Right', $modified->render());
    }

    public function testWithSegmentsReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withSegments('L', 'C', 'R');

        $this->assertNotSame($original, $modified);
    }

    public function testWithForegroundReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withForeground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithBackgroundReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withBackground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithBordersReturnsNewInstance(): void
    {
        $original = StatusBar::new();
        $modified = $original->withBorders('[', ']');

        $this->assertNotSame($original, $modified);
    }

    public function testChainedWithers(): void
    {
        $bar = StatusBar::new()
            ->withSegments('Left', 'Center', 'Right')
            ->withForeground(Color::ansi(3))
            ->withBackground(Color::ansi(8))
            ->withBorders('|', '|');

        $rendered = $bar->render();

        // Strip ANSI codes to check raw content
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);

        // Should contain the full content and borders
        $this->assertStringContainsString('Left', $stripped);
        $this->assertStringContainsString('Center', $stripped);
        $this->assertStringContainsString('Right', $stripped);
        $this->assertStringContainsString('|', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeContent(): void
    {
        $bar = StatusBar::new()
            ->withSegments('左', '中', '右')
            ->setSize(10, 1);
        $rendered = $bar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testEmptySegmentsWithColors(): void
    {
        $bar = StatusBar::new()
            ->withForeground(Color::ansi(12))
            ->withBackground(Color::ansi(8))
            ->setSize(10, 1);
        $rendered = $bar->render();

        // Should still render with colors
        $this->assertNotSame('', $rendered);
    }

    public function testHeightAlwaysOne(): void
    {
        $bar = StatusBar::new()->withSegments('L', 'C', 'R')->setSize(20, 5);
        [$w, $h] = $bar->getInnerSize();

        // Height should still be 1 regardless of setSize height
        $this->assertSame(1, $h);
    }

    public function testConstructorWithAllParams(): void
    {
        $bar = new StatusBar(
            left: 'L',
            center: 'C',
            right: 'R',
            foreground: Color::ansi(5),
            background: Color::ansi(0),
            leftBorder: '<',
            rightBorder: '>',
        );

        $rendered = $bar->render();

        // Strip ANSI codes to check raw content
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);

        $this->assertStringContainsString('L', $stripped);
        $this->assertStringContainsString('C', $stripped);
        $this->assertStringContainsString('R', $stripped);
        $this->assertStringContainsString('<', $stripped);
        $this->assertStringContainsString('>', $stripped);
    }

    public function testOnlyCenterContent(): void
    {
        $bar = StatusBar::new()
            ->withCenter('Centered')
            ->setSize(30, 1);
        $rendered = $bar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringContainsString('Centered', $stripped);
    }

    public function testLeftAndRightContentNoCenter(): void
    {
        $bar = StatusBar::new()
            ->withSegments('Left', '', 'Right')
            ->setSize(30, 1);
        $rendered = $bar->render();

        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $this->assertStringContainsString('Left', $stripped);
        $this->assertStringContainsString('Right', $stripped);
    }
}
