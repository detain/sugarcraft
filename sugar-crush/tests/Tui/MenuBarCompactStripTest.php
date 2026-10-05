<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * Roadmap 3.C remainder: below about a hundred columns the full pane-tab strip
 * (`[icon Label]` × 7 since the Todo tab) left no room for a single menu and
 * then ran off the edge. The strip now shrinks to icons, then drops the
 * indicator's prefix ({@see MenuBar::render()}).
 */
final class MenuBarCompactStripTest extends TestCase
{
    private const VARS = ['SUGARCRUSH_DISABLE_MOUSE', 'SUGARCRUSH_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
    }

    public function testAWideTerminalKeepsTheFullStrip(): void
    {
        $app = $this->app();

        foreach ([null, 120] as $cols) {
            $bar = $this->plain(MenuBar::render($app, $cols));
            self::assertStringContainsString('[' . Pane::Todo->icon() . ' Todo]', $bar);
            self::assertStringContainsString('Currently: Chat', $bar);
        }
    }

    public function testBelowAHundredColumnsTheTabsShrinkToIconsAndTheMenusComeBack(): void
    {
        $app = $this->app()->withPane(Pane::Files);
        $full = $this->plain(MenuBar::render($app, 100 + 20));
        $bar = $this->plain(MenuBar::render($app, 100));

        self::assertStringNotContainsString('Files]', $bar);
        foreach ([Pane::Chat, Pane::Files, Pane::Tools, Pane::Skills, Pane::Agents, Pane::Settings, Pane::Todo] as $pane) {
            self::assertStringContainsString('[' . $pane->icon() . ']', $bar, "{$pane->label()} keeps its tab");
        }
        self::assertStringEndsWith('Currently: Files', $bar, 'the indicator still names the focused pane');
        self::assertStringStartsWith(' Session ', $bar, 'the first menu is visible again, so F10 opens something seen');
        self::assertStringStartsWith(' Session ', $full);
        self::assertLessThanOrEqual(100, Width::string($bar));
    }

    public function testANarrowTerminalDropsTheIndicatorsPrefix(): void
    {
        $bar = $this->plain(MenuBar::render($this->app(), 40));

        self::assertStringNotContainsString('Currently:', $bar);
        self::assertStringEndsWith(' Chat', $bar);
        self::assertStringContainsString('[' . Pane::Todo->icon() . ']', $bar);
        self::assertLessThanOrEqual(40, Width::string($bar));
    }

    public function testTheBarNeverOverflowsAWidthItsTerseStripFits(): void
    {
        $app = $this->app()->withPane(Pane::Settings);
        $terse = Width::string($this->plain(MenuBar::render($app, 1)));

        for ($cols = $terse; $cols <= 200; $cols++) {
            $bar = $this->plain(MenuBar::render($app, $cols));
            self::assertLessThanOrEqual($cols, Width::string($bar), "at {$cols} columns");
            self::assertStringEndsWith('Settings', $bar, "at {$cols} columns the indicator survives");
        }
    }

    public function testTheMarkedTwinMatchesTheCompactStripAndKeepsEveryTabClickable(): void
    {
        $app = $this->app();

        foreach ([90, 40] as $cols) {
            $marked = MenuBar::renderMarked($app, $cols);
            self::assertSame(MenuBar::render($app, $cols), (string) preg_replace('/\x{E000}\/?[A-Za-z0-9._:-]*\x{E001}/u', '', $marked));
            self::assertSame(7, preg_match_all('/\x{E000}' . MenuBar::PANE_TAB_ZONE_PREFIX . '/u', $marked), "{$cols} columns: one zone per tab");
        }
    }

    public function testACompactTabIsHitWhereItIsPainted(): void
    {
        $body = TuiRenderer::renderView($this->app(), 90, 30)->body;
        $line = Ansi::strip(explode("\n", $body)[0]);
        $offset = mb_strpos($line, '[' . Pane::Tools->icon() . ']');
        self::assertNotFalse($offset);

        $col = Width::string(mb_substr($line, 0, $offset)) + 2;
        self::assertSame(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Tools->value, TuiRenderer::chromeZoneAt($col, 1)?->id);
    }

    private function app(): App
    {
        return App::new(new EchoProvider(), 'test-model')
            ->withChat((new Chat(history: [Message::user('hi')]))->withSize(90, 30));
    }

    private function plain(string $bar): string
    {
        return rtrim(Ansi::strip($bar));
    }

    private function reset(): void
    {
        foreach (self::VARS as $var) {
            putenv($var);
        }
        MenuBar::closeMenu();
        TuiRenderer::resetSizeCache();
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
    }
}
