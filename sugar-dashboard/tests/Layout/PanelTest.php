<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use SugarCraft\Dashboard\Layout\Panel;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class PanelTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPanelImplementsSizer(): void
    {
        $panel = Panel::new('Test content');
        $this->assertInstanceOf(Sizer::class, $panel);
    }

    public function testPanelImplementsItem(): void
    {
        $panel = Panel::new('Test content');
        $this->assertInstanceOf(Item::class, $panel);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $panel = Panel::new('Test content');
        $rendered = $panel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $panel = Panel::new('Hello World');
        $rendered = $panel->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $panel = Panel::new('Test');
        $rendered = $panel->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $panel = Panel::new('Content')->withTitle('Title');
        $rendered = $panel->render();

        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('Content', $rendered);
    }

    public function testTitledFactory(): void
    {
        $panel = Panel::titled('Content', 'My Title');
        $rendered = $panel->render();

        $this->assertStringContainsString('My Title', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Header and footer
    // ═══════════════════════════════════════════════════════════════

    public function testWithHeader(): void
    {
        $panel = Panel::new('Content')->withHeader('Header');
        $rendered = $panel->render();

        $this->assertStringContainsString('Header', $rendered);
    }

    public function testWithFooter(): void
    {
        $panel = Panel::new('Content')->withFooter('Footer');
        $rendered = $panel->render();

        $this->assertStringContainsString('Footer', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $panel = Panel::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $panel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTitleColorAddsAnsiCodes(): void
    {
        $panel = Panel::new('Test')
            ->withTitle('Title')
            ->withTitleColor(Color::ansi(12));
        $rendered = $panel->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $panel = Panel::new('Test')->withStyle('double');
        $rendered = $panel->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $panel = Panel::new('Test')->withStyle('rounded');
        $rendered = $panel->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $panel = Panel::new('Test')->withStyle('bold');
        $rendered = $panel->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Panel::new('Test');
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $panel = Panel::new('Test content')->setSize(40, 10);
        $rendered = $panel->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Panel::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Panel::new('Original');
        $original->withContent('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $panel = Panel::new('Test');
        [$w, $h] = $panel->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleIncreasesHeight(): void
    {
        $panelNoTitle = Panel::new('Content');
        $panelWithTitle = Panel::new('Content')->withTitle('Title');

        [, $h1] = $panelNoTitle->innerSize();
        [, $h2] = $panelWithTitle->innerSize();

        $this->assertGreaterThanOrEqual($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $panel = Panel::new('');
        $rendered = $panel->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentWraps(): void
    {
        $panel = Panel::new('This is a very long content that should wrap within the panel to test word wrapping.');
        $panel = $panel->setSize(30, 20);
        $rendered = $panel->render();

        // Should have multiple lines due to wrapping
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    public function testUnicodeContent(): void
    {
        $panel = Panel::new('日本語コンテンツ');
        $rendered = $panel->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }

    public function testLongTitleAtNarrowWidthRendersWithoutThrowing(): void
    {
        $rendered = Panel::titled('body', 'A VERY LONG TITLE HERE')->setSize(7, 3)->render();

        $this->assertIsString($rendered);
        $this->assertStringContainsString("\u{2026}", $rendered);
        foreach (explode("\n", $rendered) as $line) {
            $this->assertSame(7, Width::string($line));
        }
    }

    public function testWideContentItemIsClippedToFrame(): void
    {
        $item = new class implements Item {
            public function render(): string
            {
                return str_repeat('x', 40);
            }
        };

        $rendered = Panel::new($item)->setSize(12, 4)->render();

        foreach (explode("\n", $rendered) as $line) {
            $this->assertSame(12, Width::string($line));
        }
    }

    public function testOverWideHeaderIsClipped(): void
    {
        $rendered = Panel::new('ok')->withHeader(str_repeat('H', 30))->setSize(10, 4)->render();

        foreach (explode("\n", $rendered) as $line) {
            $this->assertSame(10, Width::string($line));
        }
    }

}
