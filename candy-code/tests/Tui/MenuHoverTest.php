<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use Closure;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tests\Support\CarriesStandardFrameTrait;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;

/**
 * P-MENU.e hover law: while ANY menu is open the shell arms all-motion mouse
 * reporting (cell-motion reports no button-less motion, so the highlight
 * could not exist without it) and restores cell-motion on EVERY close path —
 * the bar itself is the only thing that consumes motion while a menu is up.
 *
 * The transition lives in exactly one place — App::update() comparing
 * `MenuBar::activeMenu() > 0` before and after every message — so this file
 * is the close-path table the plan's risk note demands: F10-open and
 * click-open arm AllMotion; Esc, a row run, and a click-away each restore
 * CellMotion; a mouse turned Off emits NO mode Cmd at all; motion over a row
 * highlights it (and previews a submenu); motion with no menu open still
 * reaches the chat (pinned by the press-drift the chat itself records).
 */
final class MenuHoverTest extends TestCase
{
    use CarriesStandardFrameTrait;

    private ProviderInterface $provider;
    private string $mouseModeBefore = '';
    private string $clicksBefore = '';

    protected function setUp(): void
    {
        $this->mouseModeBefore = (string) getenv('CANDY_CODE_DISABLE_MOUSE');
        $this->clicksBefore = (string) getenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');
        putenv('CANDY_CODE_DISABLE_MOUSE');
        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');

        MenuBar::closeMenu();
        TuiRenderer::setSize(self::FRAME_COLS, self::FRAME_ROWS);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->resetChromeTracker();
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        putenv($this->mouseModeBefore === '' ? 'CANDY_CODE_DISABLE_MOUSE' : 'CANDY_CODE_DISABLE_MOUSE=' . $this->mouseModeBefore);
        putenv($this->clicksBefore === '' ? 'CANDY_CODE_DISABLE_MOUSE_CLICKS' : 'CANDY_CODE_DISABLE_MOUSE_CLICKS=' . $this->clicksBefore);
        TuiRenderer::setSize(200, 60);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        $this->resetChromeTracker();
        (new \ReflectionProperty(Chat::class, 'pressGesture'))->setValue(null, null);
    }

    private function resetChromeTracker(): void
    {
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
    }

    public function testOpeningTheMenuByF10ArmsAllMotion(): void
    {
        [, $cmd] = $this->app()->update(new KeyMsg(KeyType::F10));

        self::assertGreaterThan(0, MenuBar::activeMenu(), 'fixture: F10 opens a menu');
        self::assertStringContainsString(
            Ansi::mouseAllMotionOn(),
            self::modeBytes($cmd),
            'an OPENED menu must arm all-motion reporting, or hover cannot exist',
        );
    }

    public function testOpeningTheMenuByClickArmsAllMotion(): void
    {
        $app = $this->app();
        $this->paint($app);
        $zone = TuiRenderer::chromeScanner()->get(MenuBar::MENU_TITLE_ZONE_PREFIX . '1');
        self::assertNotNull($zone, 'fixture: the painted bar marked a menu title');

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [, $cmd] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        self::assertGreaterThan(0, MenuBar::activeMenu());
        self::assertStringContainsString(Ansi::mouseAllMotionOn(), self::modeBytes($cmd));
    }

    public function testEscapeRestoresCellMotion(): void
    {
        [$app] = $this->app()->update(new KeyMsg(KeyType::F10));
        [, $cmd] = $app->update(new KeyMsg(KeyType::Escape));

        self::assertSame(0, MenuBar::activeMenu(), 'fixture: Esc closed the menu');
        self::assertStringContainsString(
            Ansi::mouseCellMotionOn(),
            self::modeBytes($cmd),
            'the close path must hand the terminal back the cell-motion mode Chat arms',
        );
    }

    public function testRunningARowRestoresCellMotion(): void
    {
        $app = $this->app();
        [$app] = $app->update(new KeyMsg(KeyType::F10));
        $this->paint($app);

        // The View menu's `cmd:theme` row is a command row: dispatching it
        // closes the menu (only Panes toggles keep it open), and the ONE
        // transition check in update() must hand back CellMotion. Paint the
        // View panel and tap the row at its painted cell.
        $view = self::viewMenuIndex();
        MenuBar::activateMenu($view);
        $this->paint($app);
        $zone = TuiRenderer::chromeScanner()->get(MenuBar::MENU_ITEM_ZONE_PREFIX . '3');
        self::assertNotNull($zone, 'fixture: the third View row (theme) carries a menuitem: zone');

        [$app] = $app->update(new MouseClickMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Press));
        [, $cmd] = $app->update(new MouseReleaseMsg($zone->startCol, $zone->startRow, MouseButton::Left, MouseAction::Release));

        self::assertSame(0, MenuBar::activeMenu(), 'fixture: a command row closes the menu');
        self::assertStringContainsString(Ansi::mouseCellMotionOn(), self::modeBytes($cmd));
    }

    public function testClickAwayRestoresCellMotion(): void
    {
        $app = $this->app();
        [$app] = $app->update(new KeyMsg(KeyType::F10));

        // Deep in the transcript band, far from the bar and any open panel:
        // the press names no chrome zone, so it dismisses the menu (the
        // Windows-95 law P-MENU.e makes explicit) and still lands in the chat.
        [, $cmd] = $app->update(new MouseClickMsg(60, 20, MouseButton::Left, MouseAction::Press));

        self::assertSame(0, MenuBar::activeMenu(), 'a press outside the menu dismisses it');
        self::assertStringContainsString(Ansi::mouseCellMotionOn(), self::modeBytes($cmd));
    }

    public function testNoModeCmdIsEmittedWhenTheMouseIsOff(): void
    {
        putenv('CANDY_CODE_DISABLE_MOUSE=1');
        try {
            [, $cmd] = $this->app()->update(new KeyMsg(KeyType::F10));

            self::assertGreaterThan(0, MenuBar::activeMenu(), 'fixture: the menu still opens');
            self::assertNull($cmd, 'a disabled mouse never receives a mode switch');
        } finally {
            putenv('CANDY_CODE_DISABLE_MOUSE');
        }
    }

    public function testMotionOverARowHighlightsItAndAPreviewRowExpandsTheSubmenu(): void
    {
        $app = $this->app();
        MenuBar::activateMenu(self::viewMenuIndex());
        $this->paint($app);

        // Hover the 'Panes ▸' submenu row: it lights and PREIEWS its panel.
        $submenuRow = TuiRenderer::chromeScanner()->get(MenuBar::MENU_ITEM_ZONE_PREFIX . '1');
        self::assertNotNull($submenuRow, 'fixture: the View panel paints the Panes row with a menuitem: zone');
        [$app] = $app->update(new MouseMotionMsg($submenuRow->startCol, $submenuRow->startRow, MouseButton::Left, MouseAction::Motion));

        self::assertSame([0], MenuBar::cursor()->rows, 'the hovered row lights');
        self::assertTrue(MenuBar::cursor()->expanded, 'and a submenu row previews its submenu');

        // Now the Panes panel is painted too: hover one of its toggle rows
        // (they carry the panetab: zone P-MENU.e moved onto them).
        $this->paint($app);
        $row = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Tools->value);
        self::assertNotNull($row, 'fixture: the open Panes rows carry the panetab: zones');
        $absolute = MenuBar::tree()->pathOf('pane:' . Pane::Tools->value);
        self::assertNotNull($absolute);

        [, $app] = $app->update(new MouseMotionMsg($row->startCol, $row->startRow, MouseButton::Left, MouseAction::Motion));

        self::assertSame(array_slice($absolute, 1), MenuBar::cursor()->rows, 'the hovered row is the highlighted row of every open panel');
        self::assertFalse(MenuBar::cursor()->expanded, 'a toggle row lights itself; it previews nothing');
    }

    private static function viewMenuIndex(): int
    {
        foreach (MenuBar::tree()->menus as $index => $root) {
            if ($root->id === 'menu:view') {
                return $index + 1;
            }
        }

        self::fail('fixture: the kit has no View menu');
    }

    public function testMotionWithNoMenuOpenStillReachesTheChat(): void
    {
        // Seed the chat's press gesture directly (its own static, normally
        // written by a transcript press): the discriminator is whether the
        // shell forwards MOTION to the chat, which folds drift into it.
        $gesture = new \ReflectionProperty(Chat::class, 'pressGesture');

        $app = $this->app();
        $gesture->setValue(null, [60, 20, 0]);
        $app->update(new MouseMotionMsg(66, 20, MouseButton::Left, MouseAction::Motion));

        self::assertSame(
            [60, 20, 6],
            $gesture->getValue(null),
            'with no menu open the motion is the chat’s — drift folds into the held press',
        );

        // Counter-polarity: with a menu open the same motion is consumed by
        // the shell (hover), and the chat's gesture stays where it was.
        $gesture->setValue(null, [60, 20, 0]);
        [$open] = $app->update(new KeyMsg(KeyType::F10));
        $gesture->setValue(null, [60, 20, 0]);

        $open->update(new MouseMotionMsg(66, 20, MouseButton::Left, MouseAction::Motion));

        self::assertSame([60, 20, 0], $gesture->getValue(null), 'the shell consumed the hover; the chat never sees it');
    }

    private function app(): App
    {
        [$app] = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->update(new WindowSizeMsg(self::FRAME_COLS, self::FRAME_ROWS));

        return $app;
    }

    private function paint(App $app): void
    {
        TuiRenderer::renderView($app, self::FRAME_COLS, self::FRAME_ROWS);
    }

    /**
     * The bytes the mouse-mode Cmd writes: run the closure — core's Cmd
     * closures RETURN a Msg (BatchMsg explodes into the members' closures,
     * each of which returns a RawMsg) — and concatenate the RawMsg payloads.
     */
    private static function modeBytes(?Closure $cmd): string
    {
        if ($cmd === null) {
            return '';
        }

        $bytes = '';
        $stack = [$cmd];
        while ($stack !== []) {
            $msg = (array_pop($stack))();
            if ($msg instanceof BatchMsg) {
                foreach ($msg->cmds as $inner) {
                    $stack[] = $inner;
                }
            } elseif ($msg instanceof RawMsg) {
                $bytes .= $msg->bytes;
            }
        }

        return $bytes;
    }
}
