<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Screen;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Dash\Grid\Bar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use PHPUnit\Framework\TestCase;

final class ScreenTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testScreenImplementsSizer(): void
    {
        $screen = Screen::new(Text::new('test'));
        $this->assertInstanceOf(Sizer::class, $screen);
    }

    public function testScreenImplementsItem(): void
    {
        $screen = Screen::new(Text::new('test'));
        $this->assertInstanceOf(Item::class, $screen);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $screen = Screen::new(Text::new('Hello'));
        $this->assertNotSame('', $screen->render());
    }

    public function testRenderContainsContent(): void
    {
        $screen = Screen::new(Text::new('Hello World'));
        $rendered = $screen->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderContainsAlternateScreenSequence(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        // Should contain alternate screen enter sequence
        $this->assertStringContainsString("\x1b[?1049h", $rendered);
    }

    public function testRenderContainsClearScreenSequence(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        // Should contain clear screen sequence
        $this->assertStringContainsString("\x1b[2J", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alternate screen
    // ═══════════════════════════════════════════════════════════════

    public function testAlternateScreenEnabledByDefault(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        // Should enter alternate screen
        $this->assertStringContainsString("\x1b[?1049h", $rendered);
        // Should leave alternate screen
        $this->assertStringContainsString("\x1b[?1049l", $rendered);
    }

    public function testAlternateScreenDisabled(): void
    {
        $screen = Screen::new(Text::new('test'))->withAlternateScreenDisabled();
        $rendered = $screen->render();

        // Should NOT contain alternate screen sequences
        $this->assertStringNotContainsString("\x1b[?1049h", $rendered);
        $this->assertStringNotContainsString("\x1b[?1049l", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cursor visibility
    // ═══════════════════════════════════════════════════════════════

    public function testCursorHiddenByDefault(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        // Should hide cursor
        $this->assertStringContainsString("\x1b[?25l", $rendered);
    }

    public function testCursorShownWhenEnabled(): void
    {
        $screen = Screen::new(Text::new('test'))->withShowCursor(true);
        $rendered = $screen->render();

        // Should show cursor
        $this->assertStringContainsString("\x1b[?25h", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Mouse tracking
    // ═══════════════════════════════════════════════════════════════

    public function testMouseDisabledByDefault(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        // Should NOT enable mouse tracking
        $this->assertStringNotContainsString("\x1b[?1000h", $rendered);
    }

    public function testMouseEnabledWhenRequested(): void
    {
        $screen = Screen::new(Text::new('test'))->withEnableMouse(true);
        $rendered = $screen->render();

        // Should enable mouse tracking
        $this->assertStringContainsString("\x1b[?1000h", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Screen::new(Text::new('test'));
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsContentSize(): void
    {
        $screen = Screen::new(Text::new('test'))->setSize(80, 24);
        [$w, $h] = $screen->getInnerSize();

        $this->assertSame(80, $w);
        $this->assertSame(24, $h);
    }

    public function testGetInnerSizeUsesDefaults(): void
    {
        $screen = Screen::new(Text::new('test'));
        [$w, $h] = $screen->getInnerSize();

        $this->assertSame(Screen::DEFAULT_WIDTH, $w);
        $this->assertSame(Screen::DEFAULT_HEIGHT, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static helpers
    // ═══════════════════════════════════════════════════════════════

    public function testClearReturnsAnsiSequence(): void
    {
        $clear = Screen::clear();

        $this->assertStringContainsString("\x1b[2J", $clear);
    }

    public function testHideReturnsAnsiSequence(): void
    {
        $hide = Screen::hide();
        $this->assertSame("\x1b[?25l", $hide);
    }

    public function testShowReturnsAnsiSequence(): void
    {
        $show = Screen::show();
        $this->assertSame("\x1b[?25h", $show);
    }

    public function testMoveCursorReturnsAnsiSequence(): void
    {
        $move = Screen::moveCursor(10, 5);
        // Cursor position is 1-based in ANSI, but our method is 0-based
        $this->assertStringContainsString("\x1b[6;11H", $move);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithAlternateScreenReturnsNewInstance(): void
    {
        $original = Screen::new(Text::new('test'));
        $modified = $original->withAlternateScreen(false);

        $this->assertNotSame($original, $modified);
    }

    public function testWithShowCursorReturnsNewInstance(): void
    {
        $original = Screen::new(Text::new('test'));
        $modified = $original->withShowCursor(true);

        $this->assertNotSame($original, $modified);
    }

    public function testWithEnableMouseReturnsNewInstance(): void
    {
        $original = Screen::new(Text::new('test'));
        $modified = $original->withEnableMouse(true);

        $this->assertNotSame($original, $modified);
    }

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Screen::new(Text::new('Original'));
        $modified = $original->withContent(Text::new('Modified'));

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('Modified', $modified->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithBarContent(): void
    {
        $screen = Screen::new(Bar::new('Status'));
        $rendered = $screen->render();

        $this->assertStringContainsString('Status', $rendered);
    }

    public function testRenderWithEmptyContent(): void
    {
        $screen = Screen::new(Text::new(''));
        $this->assertNotSame('', $screen->render());
    }

    public function testChainedWithers(): void
    {
        $screen = Screen::new(Text::new('test'))
            ->withShowCursor(true)
            ->withEnableMouse(true)
            ->withAlternateScreen(true);

        $rendered = $screen->render();

        $this->assertStringContainsString("\x1b[?25h", $rendered);
        $this->assertStringContainsString("\x1b[?1000h", $rendered);
    }

    public function testAlternateScreenEnterAndLeave(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        // Should have enter before content and leave after
        $enterPos = strpos($rendered, "\x1b[?1049h");
        $leavePos = strpos($rendered, "\x1b[?1049l");

        $this->assertNotFalse($enterPos);
        $this->assertNotFalse($leavePos);
        $this->assertLessThan($leavePos, $enterPos);
    }
}
