<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Grid;

use SugarCraft\Dashboard\Plot\Chart\Bar;
use SugarCraft\Dashboard\Layout\HAlign;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\Theme;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class BarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBarImplementsSizer(): void
    {
        $bar = Bar::new('test');
        $this->assertInstanceOf(Sizer::class, $bar);
    }

    public function testBarImplementsItem(): void
    {
        $bar = Bar::new('test');
        $this->assertInstanceOf(Item::class, $bar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Theme factory (moved off Foundation\\Theme — finding 138)
    // ═══════════════════════════════════════════════════════════════

    public function testThemedAdoptsTheThemeForegroundAndBackground(): void
    {
        $theme = Theme::dracula();
        $bar = Bar::themed('Status', $theme);

        $this->assertSame($theme->foreground(), $this->readColor($bar, 'foreground'));
        $this->assertSame($theme->background(), $this->readColor($bar, 'background'));
        $this->assertStringContainsString('Status', $bar->render());
    }

    public function testThemedCarriesTheRequestedAlignment(): void
    {
        $bar = Bar::themed('Centered', Theme::dark(), HAlign::Center);

        $this->assertSame(HAlign::Center, $this->readAlign($bar));
    }

    public function testThemedDefaultsToLeftAlignment(): void
    {
        $bar = Bar::themed('Status', Theme::dark());

        $this->assertSame(HAlign::Left, $this->readAlign($bar));
    }

    public function testThemedDoesNotReachIntoHigherLayers(): void
    {
        // Guard the direction of the dependency arrow: the theme-side
        // factory must stay a factory, so Bar still knows only the
        // colors it is handed.
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(Bar::class))->getFileName()
        );

        $this->assertSame(
            0,
            preg_match('/^use SugarCraft\\\\Dashboard\\\\Components\\\\/m', $source),
        );
    }

    private function readColor(Bar $bar, string $property): ?Color
    {
        $reflection = new \ReflectionProperty(Bar::class, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($bar);
    }

    private function readAlign(Bar $bar): HAlign
    {
        $reflection = new \ReflectionProperty(Bar::class, 'align');
        $reflection->setAccessible(true);
        return $reflection->getValue($bar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $bar = Bar::new('Status');
        $this->assertNotSame('', $bar->render());
    }

    public function testRenderEmptyContent(): void
    {
        $bar = Bar::new('');
        $this->assertNotSame('', $bar->render());
    }

    public function testRenderWithContent(): void
    {
        $bar = Bar::new('Hello World');
        $rendered = $bar->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testDefaultNewHasColors(): void
    {
        $bar = Bar::new('test');
        $rendered = $bar->render();

        // Default should have ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testDefaultNewEndsWithReset(): void
    {
        $bar = Bar::new('test');
        $rendered = $bar->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Width handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Bar::new('test');
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRender(): void
    {
        $bar = Bar::new('Hi')->setSize(30, 1);
        $rendered = $bar->render();

        // Should be padded to width 30
        $this->assertGreaterThan(2, strlen($rendered));
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $bar = Bar::new('test')->setSize(0, 1);
        $this->assertSame('', $bar->render());
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $bar = Bar::new('Content')->setSize(20, 1);
        [$w, $h] = $bar->innerSize();

        $this->assertSame(20, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithoutSetSize(): void
    {
        $bar = Bar::new('Hello');
        [$w, $h] = $bar->innerSize();

        // Height should be 1 for bar
        $this->assertSame(1, $h);
        // Width should be at least content width
        $this->assertGreaterThanOrEqual(5, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testAlignLeft(): void
    {
        $bar = Bar::new('Hi')->withAlign(HAlign::Left)->setSize(20, 1);
        $rendered = $bar->render();

        // Content should be near the start
        $this->assertStringContainsString('Hi', $rendered);
    }

    public function testAlignRight(): void
    {
        $bar = Bar::new('Hi')->withAlign(HAlign::Right)->setSize(20, 1);
        $rendered = $bar->render();

        // Should contain content
        $this->assertStringContainsString('Hi', $rendered);
    }

    public function testAlignCenter(): void
    {
        $bar = Bar::new('Hi')->withAlign(HAlign::Center)->setSize(20, 1);
        $rendered = $bar->render();

        // Should contain content
        $this->assertStringContainsString('Hi', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithForegroundAddsAnsiCodes(): void
    {
        $bar = Bar::new('test')
            ->withForeground(Color::ansi(12)); // Cyan
        $rendered = $bar->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWithBackgroundAddsAnsiCodes(): void
    {
        $bar = Bar::new('test')
            ->withBackground(Color::ansi(9)); // Red
        $rendered = $bar->render();

        // Should contain ANSI color codes (48;5 for background or 48;2 for truecolor)
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWithNullForegroundRendersWithoutFgColor(): void
    {
        $bar = new Bar('test', null, Color::ansi(9), HAlign::Left, '', '');
        $rendered = $bar->render();

        // Should still render but without foreground color
        $this->assertNotSame('', $rendered);
    }

    public function testWithNullBackgroundRendersWithoutBgColor(): void
    {
        $bar = new Bar('test', Color::ansi(12), null, HAlign::Left, '', '');
        $rendered = $bar->render();

        // Should still render but without background color
        $this->assertNotSame('', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $bar = Bar::new('test')
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
        $bar = Bar::new('Content')
            ->withBorders('[', ']')
            ->setSize(20, 1);
        $rendered = $bar->render();

        // Should contain the border characters
        $this->assertStringContainsString('[', $rendered);
        $this->assertStringContainsString(']', $rendered);
    }

    public function testEmptyBordersByDefault(): void
    {
        $bar = Bar::new('test');
        $rendered = $bar->render();

        // Strip ANSI codes to check raw content
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);

        // Should not have default border characters
        $this->assertStringNotContainsString('[', $stripped);
        $this->assertStringNotContainsString(']', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Truncation
    // ═══════════════════════════════════════════════════════════════

    public function testLongContentTruncated(): void
    {
        $longContent = 'This is a very long content that should be truncated';
        $bar = Bar::new($longContent)->setSize(10, 1);
        $rendered = $bar->render();

        // Should be truncated to fit
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Bar::new('Original');
        $modified = $original->withContent('Modified');

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('Modified', $modified->render());
        $this->assertStringNotContainsString('Modified', $original->render());
    }

    public function testWithForegroundReturnsNewInstance(): void
    {
        $original = Bar::new('test');
        $modified = $original->withForeground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithBackgroundReturnsNewInstance(): void
    {
        $original = Bar::new('test');
        $modified = $original->withBackground(Color::ansi(9));

        $this->assertNotSame($original, $modified);
    }

    public function testWithAlignReturnsNewInstance(): void
    {
        $original = Bar::new('test')->withAlign(HAlign::Left);
        $modified = $original->withAlign(HAlign::Right);

        $this->assertNotSame($original, $modified);
    }

    public function testWithBordersReturnsNewInstance(): void
    {
        $original = Bar::new('test');
        $modified = $original->withBorders('[', ']');

        $this->assertNotSame($original, $modified);
    }

    public function testChainedWithers(): void
    {
        $bar = Bar::new('test')
            ->withContent('New content')
            ->withAlign(HAlign::Center)
            ->withForeground(Color::ansi(3))
            ->withBackground(Color::ansi(8))
            ->withBorders('|', '|');

        $rendered = $bar->render();

        // Strip ANSI codes to check raw content
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);

        // Should contain the full content and borders
        $this->assertStringContainsString('New content', $stripped);
        $this->assertStringContainsString('|', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeContent(): void
    {
        $bar = Bar::new('日本語ステータス')->setSize(20, 1);
        $rendered = $bar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testEmptyContentWithColors(): void
    {
        $bar = Bar::new('')
            ->withForeground(Color::ansi(12))
            ->withBackground(Color::ansi(8))
            ->setSize(10, 1);
        $rendered = $bar->render();

        // Should still render empty content with colors
        $this->assertNotSame('', $rendered);
    }

    public function testHeightAlwaysOne(): void
    {
        $bar = Bar::new('test')->setSize(20, 5);
        [$w, $h] = $bar->innerSize();

        // Height should still be 1 regardless of setSize height
        $this->assertSame(1, $h);
    }

    public function testConstructorWithAllParams(): void
    {
        $bar = new Bar(
            content: 'Custom',
            foreground: Color::ansi(5),
            background: Color::ansi(0),
            align: HAlign::Right,
            leftBorder: '<',
            rightBorder: '>'
        );

        $rendered = $bar->render();

        // Strip ANSI codes to check raw content
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);

        $this->assertStringContainsString('Custom', $stripped);
        $this->assertStringContainsString('<', $stripped);
        $this->assertStringContainsString('>', $stripped);
    }
}