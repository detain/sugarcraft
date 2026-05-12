<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Grid\Cursor;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCursorImplementsSizer(): void
    {
        $cursor = Cursor::new();
        $this->assertInstanceOf(Sizer::class, $cursor);
    }

    public function testCursorImplementsItem(): void
    {
        $cursor = Cursor::new();
        $this->assertInstanceOf(Item::class, $cursor);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderBlockCursorByDefault(): void
    {
        $cursor = Cursor::new();
        $rendered = $cursor->render();

        // Block cursor should render as █
        $this->assertSame('█', $rendered);
    }

    public function testRenderEmptyWhenNotVisible(): void
    {
        $cursor = Cursor::new()->withVisible(false);
        $rendered = $cursor->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style variants
    // ═══════════════════════════════════════════════════════════════

    public function testBlockStyle(): void
    {
        $cursor = Cursor::new()->withStyle(Cursor::Block);
        $rendered = $cursor->render();

        $this->assertSame('█', $rendered);
    }

    public function testUnderlineStyle(): void
    {
        $cursor = Cursor::new()->withStyle(Cursor::Underline);
        $rendered = $cursor->render();

        // Underline cursor should render as ▁
        $this->assertSame('▁', $rendered);
    }

    public function testBarStyle(): void
    {
        $cursor = Cursor::new()->withStyle(Cursor::Bar);
        $rendered = $cursor->render();

        // Bar cursor should render as │
        $this->assertSame('│', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style constants
    // ═══════════════════════════════════════════════════════════════

    public function testStyleConstants(): void
    {
        $this->assertSame('block', Cursor::Block);
        $this->assertSame('underline', Cursor::Underline);
        $this->assertSame('bar', Cursor::Bar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithColor(): void
    {
        $cursor = Cursor::new()->withColor(Color::hex('#FF0000'));
        $rendered = $cursor->render();

        // Should contain ANSI color codes and the cursor character
        $this->assertStringContainsString('█', $rendered);
        // Should contain ANSI escape sequence
        $this->assertStringContainsString("\x1b[", $rendered);
    }

    public function testRenderWithoutColor(): void
    {
        $cursor = Cursor::new();
        $rendered = $cursor->render();

        // Should be just the cursor character
        $this->assertSame('█', $rendered);
    }

    public function testRenderWithNullColor(): void
    {
        $cursor = Cursor::new()->withColor(null);
        $rendered = $cursor->render();

        $this->assertSame('█', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Visibility
    // ═══════════════════════════════════════════════════════════════

    public function testVisibleByDefault(): void
    {
        $cursor = Cursor::new();
        $rendered = $cursor->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHiddenCursorRendersEmpty(): void
    {
        $cursor = Cursor::new()->withVisible(false);
        $rendered = $cursor->render();

        $this->assertSame('', $rendered);
    }

    public function testHiddenCursorWithColorStillRendersEmpty(): void
    {
        $cursor = Cursor::new()->withColor(Color::hex('#FF0000'))->withVisible(false);
        $rendered = $cursor->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Cursor::new();
        $resized = $original->setSize(5, 3);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsGetInnerSize(): void
    {
        $cursor = Cursor::new()->setSize(10, 5);
        [$w, $h] = $cursor->getInnerSize();

        $this->assertSame(10, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeWithoutSetSize(): void
    {
        $cursor = Cursor::new();
        [$w, $h] = $cursor->getInnerSize();

        // Default size should be 1x1
        $this->assertSame(1, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $cursor = Cursor::new()
            ->withStyle(Cursor::Underline)
            ->withColor(Color::hex('#00FF00'))
            ->withVisible(true);

        $rendered = $cursor->render();

        // Should contain the underline cursor and color codes
        $this->assertStringContainsString('▁', $rendered);
        $this->assertStringContainsString("\x1b[", $rendered);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $original = Cursor::new();
        $modified = $original->withStyle(Cursor::Bar);

        // Original should be unchanged
        $this->assertSame('█', $original->render());
        // Modified should have new style
        $this->assertSame('│', $modified->render());
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Cursor::new();
        $modified = $original->withColor(Color::hex('#FF0000'));

        // Original should not have color codes
        $this->assertSame('█', $original->render());
        // Modified should have color codes
        $this->assertStringContainsString("\x1b[", $modified->render());
    }

    public function testWithVisibleReturnsNewInstance(): void
    {
        $original = Cursor::new();
        $hidden = $original->withVisible(false);

        // Original should render
        $this->assertSame('█', $original->render());
        // Hidden should be empty
        $this->assertSame('', $hidden->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // getCursorChar
    // ═══════════════════════════════════════════════════════════════

    public function testGetCursorCharBlock(): void
    {
        $cursor = Cursor::new()->withStyle(Cursor::Block);
        $this->assertSame('█', $cursor->getCursorChar());
    }

    public function testGetCursorCharUnderline(): void
    {
        $cursor = Cursor::new()->withStyle(Cursor::Underline);
        $this->assertSame('▁', $cursor->getCursorChar());
    }

    public function testGetCursorCharBar(): void
    {
        $cursor = Cursor::new()->withStyle(Cursor::Bar);
        $this->assertSame('│', $cursor->getCursorChar());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnknownStyleFallsBackToBlock(): void
    {
        $cursor = Cursor::new()->withStyle('unknown');
        $rendered = $cursor->render();

        // Should fallback to block cursor
        $this->assertSame('█', $rendered);
    }

    public function testCursorWithAllStylesRenderDifferently(): void
    {
        $block = Cursor::new()->withStyle(Cursor::Block)->render();
        $underline = Cursor::new()->withStyle(Cursor::Underline)->render();
        $bar = Cursor::new()->withStyle(Cursor::Bar)->render();

        // All three should be different
        $this->assertNotSame($block, $underline);
        $this->assertNotSame($underline, $bar);
        $this->assertNotSame($block, $bar);
    }

    public function testCursorRendersCorrectlyAfterMultipleWithers(): void
    {
        $cursor = Cursor::new()
            ->withVisible(true)
            ->withStyle(Cursor::Bar)
            ->withColor(null)
            ->withVisible(true);

        $this->assertSame('│', $cursor->render());
    }
}
