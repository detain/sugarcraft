<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer as LiveRenderer;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Components\MenuSelectedMsg;
use SugarCraft\Code\Tui\Menu\MenuTree;
use SugarCraft\Code\Tui\Menu\ShellMenu;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tests\Support\CarriesStandardFrameTrait;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * P-MENU.e: View ▸ Panes owns the pane toggles.
 *
 * The bar's `Currently:` indicator is the door (zone `panesmenu`,
 * R-MENU-2); the toggle surface is the Panes submenu's rows, which carry the
 * old tabs' `panetab:<pane>` click zone untouched. Every toggle — keyboard
 * Enter or row click — flips the dock state with exactly the semantics
 * MenuBarPaneTabClickTest pins, and LEAVES THE SUBMENU OPEN with the check
 * flipped in place (R-MENU-3).
 */
final class PanesSubmenuToggleTest extends TestCase
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
    }

    public function testIndicatorDoorOpensViewPanes(): void
    {
        $app = $this->app();
        $this->paint($app);
        $zone = TuiRenderer::chromeScanner()->get(MenuBar::PANES_DOOR_ZONE);
        self::assertNotNull($zone, 'fixture: the painted bar marked the indicator door');

        [$app] = $this->tap($app, $zone->startCol, $zone->startRow);

        $view = self::viewMenuIndex();
        self::assertSame($view, MenuBar::activeMenu(), 'the door opens the View menu');
        self::assertTrue(MenuBar::cursor()->expanded, 'and previews the Panes submenu');
        $absolute = MenuBar::tree()->pathOf('sub:panes');
        self::assertNotNull($absolute);
        self::assertSame(array_slice($absolute, 1), MenuBar::cursor()->rows, 'with the Panes row lit');
        self::assertStringContainsString(
            'Currently:',
            self::plainBar($app),
            'the indicator itself stays painted — it IS the door',
        );
    }

    public function testEnterOnAPanesRowTogglesAndKeepsTheSubmenuOpen(): void
    {
        $app = $this->openDoor();

        $panes = MenuBar::tree()->pathOf('sub:panes');
        $files = MenuBar::tree()->pathOf('pane:' . Pane::Files->value);
        self::assertNotNull($panes);
        self::assertNotNull($files);

        // Enter on the previewed Panes row ENTERS the submenu (the door only
        // previews); Down then walks to Files; Enter chooses the toggle.
        [$app] = $app->update(new KeyMsg(KeyType::Enter));
        self::assertSame(array_slice($panes, 1), array_slice(MenuBar::cursor()->rows, 0, 1), 'fixture: the submenu is entered');
        [$app] = $app->update(new KeyMsg(KeyType::Down));
        self::assertSame(array_slice($files, 1), MenuBar::cursor()->rows, 'fixture: the Files row is highlighted');

        self::assertTrue($app->isDocked(Pane::Files), 'fixture: Files is the default dock');
        [$next] = $app->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($next->isDocked(Pane::Files), 'Enter on the row undocks it');
        self::assertSame(self::viewMenuIndex(), MenuBar::activeMenu(), 'and the submenu stays open (R-MENU-3)');
        self::assertSame(Pane::Chat, $next->pane, 'undocking a pane that did not hold focus leaves focus alone');
        self::assertFalse(self::rowChecked(ShellMenu::tree($next), Pane::Files), 'the check flips in place');
    }

    public function testClickOnAPanesRowTogglesKeepsOpenAndMatchesTheKeyboard(): void
    {
        $app = $this->openDoor();
        $this->paint($app);
        $row = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Skills->value);
        self::assertNotNull($row, 'fixture: the open Panes rows carry the panetab: zones');

        self::assertFalse($app->isDocked(Pane::Skills));
        [$next] = $this->tap($app, $row->startCol, $row->startRow);

        self::assertTrue($next->isDocked(Pane::Skills), 'the completed row click toggles docking');
        self::assertSame(Pane::Skills, $next->pane, 'docking focuses the new pane');
        self::assertSame(self::viewMenuIndex(), MenuBar::activeMenu(), 'the submenu stays open (R-MENU-3)');
        self::assertTrue(self::rowChecked(ShellMenu::tree($next), Pane::Skills), 'the check flips in place');

        // Parity (the MenuActionDispatchTest law, one row): a keyboard Enter
        // dispatch and a row click on the SAME pane land in the same state.
        $clickApp = $this->openDoor();
        $this->paint($clickApp);
        $agentsRow = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Agents->value);
        self::assertNotNull($agentsRow);
        [$clicked] = $this->tap($clickApp, $agentsRow->startCol, $agentsRow->startRow);

        $kbApp = $this->openDoor();
        [$viaKeyboard] = self::consumePaneSelection($kbApp, Pane::Agents->value);

        self::assertTrue($clicked->isDocked(Pane::Agents), 'fixture: the click docked Agents');
        self::assertSame($clicked->isDocked(Pane::Agents), $viaKeyboard->isDocked(Pane::Agents), 'click and keyboard dock alike');
        self::assertSame($clicked->pane, $viaKeyboard->pane, 'and focus alike');
        self::assertSame(MenuBar::activeMenu(), self::viewMenuIndex(), 'the keyboard dispatch keeps it open too (R-MENU-3)');
    }

    public function testTheChatRowOnlyFocuses(): void
    {
        [$app] = self::forgeChromeClick($this->app()->withPane(Pane::Settings), MenuBar::PANES_DOOR_ZONE);
        $this->paint($app);
        $row = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Chat->value);
        self::assertNotNull($row, 'fixture: the Chat row carries its panetab: zone');

        [$next] = $this->tap($app, $row->startCol, $row->startRow);

        self::assertSame(Pane::Chat, $next->pane, 'the Chat row focuses the center column');
        self::assertSame(self::viewMenuIndex(), MenuBar::activeMenu(), 'and the menu stays open too');
    }

    public function testUndockingTheFocusedPaneHandsFocusToChatAndKeepsTheSubmenuOpen(): void
    {
        $app = $this->openDoor();
        $this->paint($app);
        $row = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Skills->value);
        self::assertNotNull($row);

        [$docked] = $this->tap($app, $row->startCol, $row->startRow);
        self::assertTrue($docked->isDocked(Pane::Skills));
        self::assertSame(Pane::Skills, $docked->pane, 'fixture: docking focused it');

        $this->paint($docked);
        $row = TuiRenderer::chromeScanner()->get(MenuBar::PANE_TAB_ZONE_PREFIX . Pane::Skills->value);
        self::assertNotNull($row);
        [$undocked] = $this->tap($docked, $row->startCol, $row->startRow);

        self::assertFalse($undocked->isDocked(Pane::Skills), 'the second click sends it away');
        self::assertSame(Pane::Chat, $undocked->pane, 'undocking the focused pane hands focus back to chat');
        self::assertSame(self::viewMenuIndex(), MenuBar::activeMenu(), 'the submenu never blinked shut');
    }

    private function app(): App
    {
        [$app] = App::new($this->provider, 'test-model')
            ->withChat(new Chat())
            ->update(new WindowSizeMsg(self::FRAME_COLS, self::FRAME_ROWS));

        return $app;
    }

    /** Completed left click through update(); the pair the release returns. */
    private function tap(App $app, int $col, int $row): array
    {
        [$app] = $app->update(new MouseClickMsg($col, $row, MouseButton::Left, MouseAction::Press));

        return $app->update(new MouseReleaseMsg($col, $row, MouseButton::Left, MouseAction::Release));
    }

    /** The door opened by its real dispatch door, so cursor state is genuine. */
    private function openDoor(): App
    {
        $app = $this->app();
        [$app] = self::forgeChromeClick($app, MenuBar::PANES_DOOR_ZONE);

        return $app;
    }

    private function paint(App $app): void
    {
        TuiRenderer::renderView($app, self::FRAME_COLS, self::FRAME_ROWS);
    }

    private static function plainBar(App $app): string
    {
        return \SugarCraft\Core\Util\Ansi::strip(
            TuiRenderer::renderView($app, self::FRAME_COLS, self::FRAME_ROWS)->body,
        );
    }

    private static function rowChecked(MenuTree $tree, Pane $pane): bool
    {
        $absolute = $tree->pathOf('pane:' . $pane->value);
        self::assertNotNull($absolute);
        $node = $tree->node($absolute);
        self::assertNotNull($node);

        return $node->checked;
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

    /**
     * @return array{0:App, 1:?\Closure}
     */
    private static function consumePaneSelection(App $app, string $paneValue): array
    {
        return (new \ReflectionMethod(App::class, 'dispatchMenuSelection'))->invoke(
            $app,
            new MenuSelectedMsg('View', 'x', 'pane:' . $paneValue),
        );
    }

    /**
     * @return array{0:App, 1:?\Closure}
     */
    private static function forgeChromeClick(App $app, string $zoneId): array
    {
        return (new \ReflectionMethod(App::class, 'dispatchChromeClick'))->invoke($app, $zoneId);
    }

    private function resetChromeTracker(): void
    {
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
    }
}
