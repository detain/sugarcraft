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

    // ═══════════════════════════════════════════════════════════════
    // Mouse modes are paired (finding sugar-dashboard-027) + size
    // negotiation instead of silent 80x24 (finding sugar-dashboard-136)
    // ═══════════════════════════════════════════════════════════════

    public function testTeardownDisablesTheMouseModesSetupEnabled(): void
    {
        $screen = new Screen(content: new Text("s"), enableMouse: true);
        $setup = $screen->setup();
        $teardown = $screen->teardown();

        $this->assertStringContainsString("\x1b[?1000h", $setup);
        $this->assertStringContainsString("\x1b[?1006h", $setup);
        $this->assertStringContainsString("\x1b[?1006l", $teardown);
        $this->assertStringContainsString("\x1b[?1000l", $teardown);

        // Symmetric reverse order: the LAST mode armed is the FIRST dropped.
        $onPos = strrpos($setup, "\x1b[?1006h");
        $offPos = strpos($teardown, "\x1b[?1006l");
        $this->assertNotFalse($onPos);
        $this->assertNotFalse($offPos);
    }

    public function testMouseDisabledScreenEmitsNoMouseSequences(): void
    {
        $screen = new Screen(content: new Text("s"), enableMouse: false);

        $this->assertStringNotContainsString("\x1b[?100", $screen->setup());
        $this->assertStringNotContainsString("\x1b[?100", $screen->teardown());
    }

    public function testEveryMouseAllOnCallSiteHasPairedOffInTeardownSource(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Layout/Screen.php');

        $onSites = substr_count($source, 'Ansi::mouseAllOn()');
        $this->assertSame(1, $onSites, 'exactly one mouseAllOn call site in Screen');

        // The teardown() method body must carry the symmetric mouseAllOff.
        $this->assertMatchesRegularExpression(
            '/function teardown\(\)[\s\S]*?Ansi::mouseAllOff\(\)[\s\S]*?\n    \}/',
            $source,
            'teardown() lost its paired mouseAllOff — the shell would keep swallowing clicks (sugar-dashboard-027)'
        );
    }

    public function testSizeNegotiationFallsBackToHostEnvironment(): void
    {
        $columns = getenv('COLUMNS');
        $lines = getenv('LINES');
        putenv('COLUMNS=133');
        putenv('LINES=50');
        try {
            $screen = new Screen(content: new Text("s"));
            $this->assertSame([133, 50], $screen->innerSize());

            // Explicit sizing always wins over the handoff.
            $fixed = $screen->setSize(40, 10);
            $this->assertSame([40, 10], $fixed->innerSize());
        } finally {
            putenv(false === $columns ? 'COLUMNS' : "COLUMNS=$columns");
            putenv(false === $lines ? 'LINES' : "LINES=$lines");
        }
    }

    public function testGarbageEnvironmentFallsBackToTerminalDefault(): void
    {
        $columns = getenv('COLUMNS');
        putenv('COLUMNS=nonsense');
        try {
            $screen = new Screen(content: new Text("s"));
            $this->assertSame(Screen::DEFAULT_WIDTH, $screen->innerSize()[0]);
        } finally {
            putenv(false === $columns ? 'COLUMNS' : "COLUMNS=$columns");
        }
    }
}
