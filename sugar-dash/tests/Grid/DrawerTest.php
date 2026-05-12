<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Drawer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class DrawerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDrawerImplementsSizer(): void
    {
        $drawer = Drawer::new('Test content');
        $this->assertInstanceOf(Sizer::class, $drawer);
    }

    public function testDrawerImplementsItem(): void
    {
        $drawer = Drawer::new('Test content');
        $this->assertInstanceOf(Item::class, $drawer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $drawer = Drawer::new('Test content');
        $rendered = $drawer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $drawer = Drawer::new('Hello World');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $drawer = Drawer::new('Test');
        $rendered = $drawer->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Position handling
    // ═══════════════════════════════════════════════════════════════

    public function testLeftPosition(): void
    {
        $drawer = Drawer::left('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testRightPosition(): void
    {
        $drawer = Drawer::right('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testTopPosition(): void
    {
        $drawer = Drawer::top('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testBottomPosition(): void
    {
        $drawer = Drawer::bottom('Content');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testWithPosition(): void
    {
        $drawer = Drawer::new('Content')->withPosition(Drawer::POSITION_RIGHT);
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithSize(): void
    {
        $drawer = Drawer::new('Content')->withSize(30);
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Drawer::new('Test');
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $drawer = Drawer::new('Test')->withStyle('double');
        $rendered = $drawer->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $drawer = Drawer::new('Test')->withStyle('rounded');
        $rendered = $drawer->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $drawer = Drawer::new('Test')->withStyle('bold');
        $rendered = $drawer->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $drawer = Drawer::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $drawer->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Drawer::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = Drawer::new('Original');
        $original->withContent('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    public function testWithTitle(): void
    {
        $drawer = Drawer::new('Content')->withTitle('Drawer Title');
        $rendered = $drawer->render();

        $this->assertStringContainsString('Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $drawer = Drawer::new('Test');
        [$w, $h] = $drawer->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithExplicitSize(): void
    {
        $drawer = Drawer::new('Test')->setSize(80, 24);
        [$w, $h] = $drawer->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $drawer = Drawer::new('');
        $rendered = $drawer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentWraps(): void
    {
        $drawer = Drawer::new('This is a very long content that should wrap within the drawer to test word wrapping.');
        $drawer = $drawer->setSize(30, 15);
        $rendered = $drawer->render();

        // Should have multiple lines due to wrapping
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    public function testUnicodeContent(): void
    {
        $drawer = Drawer::new('日本語コンテンツ');
        $rendered = $drawer->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }
}