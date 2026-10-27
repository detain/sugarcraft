<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\AgentViewMode;
use SugarCraft\Code\Tui\Commands\CancelAgentCmd;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\KeyboardHandler;
use SugarCraft\Code\Tui\Pane;

/**
 * Bug 4 (plan_comparison §6, R-MENU wave): while a menu is open, a key the
 * menu ANSWERS must be owned by the menu alone.
 *
 * Before the fix the open-menu block in {@see KeyboardHandler::handle()} fell
 * through whenever the menu index was unchanged by the key — and up/down never
 * change the index, they move the row. The key then reached the Agents
 * dashboard (which moves its selected row), the open skill picker (which moves
 * its cursor) or the generic pane navigation, so one press of ↓ while a menu
 * hovered the screen scrolled two widgets at once. The fix returns from the
 * block on every key the cursor consumes; only a key the cursor hands back
 * untouched ({@see MenuBar::handleKey()} returning the SAME cursor instance)
 * keeps falling through.
 *
 * These tests assert BOTH halves of the law per surface: the menu moved, and
 * the surface underneath did NOT.
 */
final class MenuKeyboardOwnershipTest extends TestCase
{
    private ProviderInterface $provider;
    private KeyboardHandler $handler;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->handler = new KeyboardHandler();
        MenuBar::closeMenu();
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
    }

    private function paneApp(Pane $pane = Pane::Chat): App
    {
        return App::new($this->provider, 'gpt-4')->withPane($pane);
    }

    public function testArrowsMoveTheMenuAndNotTheAgentsDashboardUnderneath(): void
    {
        // A selection already exists on the dashboard, so a stolen press has
        // somewhere visible to move: index 2 → down would make it 3.
        $app = $this->paneApp(Pane::Agents)
            ->withSelectedAgentIndex(2)
            ->withAgentViewMode(AgentViewMode::List);

        MenuBar::openMenu(1);
        self::assertSame(1, MenuBar::getActiveMenu(), 'fixture: a menu is open over the dashboard');
        self::assertSame(0, MenuBar::getActiveItem(), 'fixture: the cursor starts on the first row');

        [$next] = $this->handler->handle('down', $app);

        self::assertSame(1, MenuBar::getActiveItem(), 'the menu row moved');
        self::assertSame(2, $next->selectedAgentIndex, 'the dashboard row under the menu did not move (bug 4)');

        [$next] = $this->handler->handle('up', $next);

        self::assertSame(0, MenuBar::getActiveItem(), 'the menu row moved back');
        self::assertSame(2, $next->selectedAgentIndex, 'the dashboard still never moved');
    }

    public function testArrowsMoveTheMenuAndNotTheOpenSkillPickerUnderneath(): void
    {
        $app = $this->paneApp(Pane::Skills)
            ->withSkillPickerOptions(['alpha', 'beta', 'gamma'])
            ->withSkillPickerIndex(0);

        MenuBar::openMenu(1);
        self::assertSame(1, MenuBar::getActiveMenu(), 'fixture: a menu is open over the picker');

        [$next] = $this->handler->handle('down', $app);

        self::assertSame(1, MenuBar::getActiveItem(), 'the menu row moved');
        self::assertSame(0, $next->skillPickerIndex, 'the picker cursor under the menu did not move (bug 4)');

        [$next] = $this->handler->handle('up', $next);

        self::assertSame(0, MenuBar::getActiveItem(), 'the menu row moved back');
        self::assertSame(0, $next->skillPickerIndex, 'the picker still never moved');
    }

    public function testEnterOverTheDashboardDispatchesTheHighlightedMenuRow(): void
    {
        $app = $this->paneApp(Pane::Agents)->withChat(new Chat())->withSelectedAgentIndex(2);

        MenuBar::openMenu(1);
        // Row 0 of Session is /new — a command leaf, so Enter chooses it and
        // the menu closes; the dashboard must not also see the Enter.
        [$next, $msg] = $this->handler->handle('enter', $app);

        self::assertNotNull($msg, 'the menu owned the Enter and raised its selection');
        self::assertSame('cmd:new', $msg->action ?? null);
        self::assertSame(0, MenuBar::getActiveMenu(), 'selecting closes the menu');
        self::assertSame(2, $next->selectedAgentIndex, 'the dashboard never saw the key');
    }

    public function testKeysTheMenuDeclinesStillReachTheDashboard(): void
    {
        // Ownership is not a wall: 'c' cancels the selected agent and the menu
        // cursor declines it (MenuCursor::key hands back the SAME instance), so
        // the dashboard arm under the menu block must still fire.
        $app = $this->paneApp(Pane::Agents)->withSelectedAgentIndex(1);

        MenuBar::openMenu(1);
        [$next, $cmd] = $this->handler->handle('c', $app);

        self::assertInstanceOf(CancelAgentCmd::class, $cmd, 'a key the menu declines falls through as before');
        self::assertSame(0, MenuBar::getActiveItem(), 'and the menu cursor stayed put');
    }

    public function testEscapeClosesOneSubmenuLevelBeforeTheWholeMenu(): void
    {
        // The one-press-per-level rule the reworded `keys.menu.close` promises:
        // View ▸ Panes entered, Esc pops back to the bar, Esc again closes.
        MenuBar::openMenu(4);
        self::assertSame(4, MenuBar::getActiveMenu(), 'fixture: View is the fourth shell menu');

        [$next, $msg] = $this->handler->handle('right', $this->paneApp(Pane::Chat));
        self::assertNull($msg);
        self::assertCount(2, MenuBar::cursor()->rows, 'the Panes submenu is entered');

        $this->handler->handle('escape', $next);
        self::assertCount(1, MenuBar::cursor()->rows, 'first Esc pops the submenu');

        $this->handler->handle('escape', $next);
        self::assertSame(0, MenuBar::getActiveMenu(), 'second Esc closes the menu');
    }
}
