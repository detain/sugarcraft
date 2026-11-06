<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use SugarCraft\Dashboard\Layout\Screen;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Plot\Chart\Bar;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
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

        // Alternate-screen chrome belongs to the session boundary, not per-frame
        // render output (finding M-30: enter AND leave inside every frame strobed real
        // terminals, and the host Program already writes the same bytes itself).
        $this->assertStringNotContainsString("\x1b[?1049h", $rendered);
        $this->assertStringContainsString("\x1b[?1049h", $screen->setup());
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

        $this->assertStringContainsString("\x1b[?1049h", $screen->setup());
        $this->assertStringContainsString("\x1b[?1049l", $screen->teardown());
        $this->assertStringNotContainsString("\x1b[?1049h", $rendered);
        $this->assertStringNotContainsString("\x1b[?1049l", $rendered);
    }

    public function testAlternateScreenDisabled(): void
    {
        $screen = Screen::new(Text::new('test'))->withAlternateScreenDisabled();
        $rendered = $screen->render();

        // Should NOT contain alternate screen sequences
        $this->assertStringNotContainsString("\x1b[?1049h", $rendered);
        $this->assertStringNotContainsString("\x1b[?1049l", $rendered);
        $this->assertStringNotContainsString("\x1b[?1049h", $screen->setup());
        $this->assertStringNotContainsString("\x1b[?1049l", $screen->teardown());
    }

    // ═══════════════════════════════════════════════════════════════
    // Cursor visibility
    // ═══════════════════════════════════════════════════════════════

    public function testCursorHiddenByDefault(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        $this->assertStringContainsString("\x1b[?25l", $screen->setup());
        $this->assertStringNotContainsString("\x1b[?25l", $rendered);
    }

    public function testCursorShownWhenEnabled(): void
    {
        $screen = Screen::new(Text::new('test'))->withShowCursor(true);
        $rendered = $screen->render();

        // showCursor:true means there is no cursor chrome at all in any leg.
        $this->assertStringNotContainsString("\x1b[?25h", $rendered);
        $this->assertStringNotContainsString("\x1b[?25l", $screen->setup());
        $this->assertStringNotContainsString("\x1b[?25h", $screen->teardown());
    }

    // ═══════════════════════════════════════════════════════════════
    // Mouse tracking
    // ═══════════════════════════════════════════════════════════════

    public function testMouseDisabledByDefault(): void
    {
        $screen = Screen::new(Text::new('test'));
        $rendered = $screen->render();

        $this->assertStringNotContainsString("\x1b[?1000h", $rendered);
        $this->assertStringNotContainsString("\x1b[?1000h", $screen->setup());
    }

    public function testMouseEnabledWhenRequested(): void
    {
        $screen = Screen::new(Text::new('test'))->withEnableMouse(true);
        $rendered = $screen->render();

        $this->assertStringContainsString("\x1b[?1000h", $screen->setup());
        $this->assertStringNotContainsString("\x1b[?1000h", $rendered);
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
        [$w, $h] = $screen->innerSize();

        $this->assertSame(80, $w);
        $this->assertSame(24, $h);
    }

    public function testGetInnerSizeUsesDefaults(): void
    {
        $screen = Screen::new(Text::new('test'));
        [$w, $h] = $screen->innerSize();

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

        $setup = $screen->setup();

        $this->assertStringNotContainsString("\x1b[?25l", $setup);
        $this->assertStringContainsString("\x1b[?1000h", $setup);
        $this->assertStringNotContainsString("\x1b[?1000h", $rendered);
    }

    public function testSetupTeardownPairBracketASession(): void
    {
        // Re-adjudicated (finding M-30): the old pins locked alt-screen enter AND
        // leave inside every render() - that strobed real terminals frame by frame,
        // and the host Program already emits the same bytes via ProgramOptions.
        $screen = Screen::new(Text::new('test'));
        $setup = $screen->setup();
        $rendered = $screen->render();
        $teardown = $screen->teardown();

        $this->assertStringContainsString("\x1b[?1049h", $setup);
        $this->assertStringContainsString("\x1b[?25l", $setup);
        $this->assertStringNotContainsString("\x1b[?1049h", $rendered);
        $this->assertStringNotContainsString("\x1b[?1049l", $rendered);
        $this->assertStringContainsString('test', $rendered);
        $this->assertStringContainsString("\x1b[2J", $rendered);
        $this->assertStringContainsString("\x1b[?1049l", $teardown);
        $this->assertStringContainsString("\x1b[?25h", $teardown);
    }
}
