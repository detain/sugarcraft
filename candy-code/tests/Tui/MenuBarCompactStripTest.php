<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;

/**
 * P-MENU.e: the bar's right end carries ONLY the `Currently:` indicator —
 * a terse bare pane label below 61 columns, gone below 50 — and it is the
 * door into View ▸ Panes (zone `panesmenu`), not a tab strip. The old three
 * tab densities are dead; what replaces them is the law P-MENU.d's handoff
 * demanded: the five menu titles ALWAYS come first, and widening the
 * terminal NEVER takes a title away (the indicator only ever appears at
 * widths where all five titles already fit — pinned by the monotonic sweep).
 */
final class MenuBarCompactStripTest extends TestCase
{
    private const VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    /** @var list<string> */
    private const TITLES = ['Session', 'Agent', 'Tools', 'View', 'App'];

    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
    }

    public function testTheRightEndCarriesTheIndicatorNotATabStrip(): void
    {
        $app = $this->app();

        foreach ([null, 120] as $cols) {
            $bar = $this->plain(MenuBar::render($app, $cols));
            self::assertStringEndsWith('Currently: Chat', $bar, 'the indicator names the focused pane');
            foreach (Pane::tabCycle() as $pane) {
                self::assertStringNotContainsString(
                    '[' . $pane->icon(),
                    $bar,
                    "{$pane->label()} no longer rides the bar as a tab",
                );
            }
        }
    }

    public function testTheTitlesComeFirstAndSurviveBesideTheIndicator(): void
    {
        $bar = $this->plain(MenuBar::render($this->app()->withPane(Pane::Files), 100));

        self::assertStringStartsWith(' Session ', $bar);
        foreach (self::TITLES as $title) {
            self::assertStringContainsString($title, $bar, "at 100 columns every title is painted");
        }
        self::assertStringEndsWith('Currently: Files', $bar);
        self::assertLessThanOrEqual(100, Width::string($bar));
    }

    public function testTheIndicatorGoesTerseBelowSixtyOneColumns(): void
    {
        $bar = $this->plain(MenuBar::render($this->app(), 60));

        self::assertStringNotContainsString('Currently:', $bar);
        self::assertStringEndsWith(' Chat', $bar, 'the bare label keeps the pane named');
        foreach (self::TITLES as $title) {
            self::assertStringContainsString($title, $bar, 'the terse indicator never costs a title');
        }
        self::assertLessThanOrEqual(60, Width::string($bar));
    }

    public function testTheIndicatorIsGoneBelowFiftyColumns(): void
    {
        $bar = $this->plain(MenuBar::render($this->app()->withPane(Pane::Settings), 49));

        self::assertStringNotContainsString('Settings', $bar, 'the pane is unnamed here');
        self::assertStringNotContainsString('Currently:', $bar);
        self::assertLessThanOrEqual(49, Width::string($bar));
    }

    public function testWideningNeverTakesATitleAwayAndTheIndicatorNeverCostsOne(): void
    {
        $app = $this->app()->withPane(Pane::Settings);
        $previous = -1;

        for ($cols = 1; $cols <= 200; $cols++) {
            $bar = $this->plain(MenuBar::render($app, $cols));
            self::assertLessThanOrEqual($cols, Width::string($bar), "at {$cols} columns");

            $painted = 0;
            foreach (self::TITLES as $title) {
                if (str_contains($bar, " $title ")) {
                    $painted++;
                }
            }

            self::assertGreaterThanOrEqual($previous, $painted, "widening to {$cols} columns took a title away");
            $previous = $painted;

            if (str_contains($bar, 'Currently:') || str_ends_with($bar, 'Settings')) {
                self::assertSame(5, $painted, "at {$cols} columns the indicator appeared while a title was missing");
            }
        }
    }

    public function testTheMarkedTwinMatchesThePlainBarAndCarriesTheTitlesPlusTheDoor(): void
    {
        $app = $this->app();

        foreach ([120, 50, 40] as $cols) {
            $marked = MenuBar::renderMarked($app, $cols);
            self::assertSame(
                MenuBar::render($app, $cols),
                (string) preg_replace('/\x{E000}\/?[A-Za-z0-9._:-]*\x{E001}/u', '', $marked),
            );
            self::assertSame(
                0,
                preg_match_all('/\x{E000}' . MenuBar::PANE_TAB_ZONE_PREFIX . '/u', $marked),
                "{$cols} columns: the bar itself carries no panetab: zone any more",
            );
            $door = preg_match_all('/\x{E000}' . MenuBar::PANES_DOOR_ZONE . '/u', $marked);
            self::assertSame($cols >= 50 ? 1 : 0, $door, "{$cols} columns: the door rides the indicator, and nothing else");
        }
    }

    public function testTheDoorIsHitWhereTheIndicatorIsPainted(): void
    {
        $body = TuiRenderer::renderView($this->app(), 120, 30)->body;
        $line = Ansi::strip(explode("\n", $body)[0]);
        $offset = mb_strpos($line, 'Currently: Chat');
        self::assertNotFalse($offset, 'fixture: the 120-column frame paints the full indicator');

        $col = Width::string(mb_substr($line, 0, $offset)) + 1;
        self::assertSame(MenuBar::PANES_DOOR_ZONE, TuiRenderer::chromeZoneAt($col, 1)?->id);
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
