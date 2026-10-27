<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Tui\Menu\ShellMenu;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Components\MenuSelectedMsg;

/**
 * The bar's own arithmetic: which menu the cursor sits on, which row of it
 * is highlighted, and what selecting a row raises. Since P-MENU.d the state
 * is one {@see \SugarCraft\Code\Tui\Menu\MenuCursor} rather than an index
 * pair, and the rows come from {@see ShellMenu}'s placement rather than from
 * command categories — so these tests walk the cursor with the same keys a
 * player presses, and read the dropdowns the tree actually paints.
 */
final class MenuBarTest extends TestCase
{
    protected function setUp(): void
    {
        MenuBar::closeMenu();
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
    }

    /** The strip's menu count, read off the placement tree itself. */
    private function menuCount(): int
    {
        return count(ShellMenu::tree(null)->menus);
    }

    public function testRightKeyCyclesFromFirstToSecondMenu(): void
    {
        MenuBar::openMenu(1);
        MenuBar::handleKey('right');

        $this->assertSame(2, MenuBar::getActiveMenu());
    }

    public function testRightKeyWrapsPastTheLastMenuToTheFirst(): void
    {
        MenuBar::openMenu($this->menuCount());
        MenuBar::handleKey('right');

        $this->assertSame(1, MenuBar::getActiveMenu(), 'the bar is a ring — the last menu walking right lands on the first');
    }

    public function testLeftKeyCyclesFromSecondToFirstMenu(): void
    {
        MenuBar::openMenu(2);
        MenuBar::handleKey('left');

        $this->assertSame(1, MenuBar::getActiveMenu());
    }

    public function testLeftKeyWrapsPastTheFirstMenuToTheLast(): void
    {
        MenuBar::openMenu(1);
        MenuBar::handleKey('left');

        $this->assertSame($this->menuCount(), MenuBar::getActiveMenu());
    }

    /**
     * Selecting the highlighted row of an open menu raises the message for
     * THAT row — the menu names the root it was painted under, the item
     * carries the row's own label, and since P-MENU.d the action names the
     * command by registry name rather than by label text.
     */
    public function testSelectingTheFirstRowRaisesItsMessage(): void
    {
        MenuBar::openMenu(1);
        $msg = MenuBar::selectItem('1');

        $this->assertInstanceOf(MenuSelectedMsg::class, $msg);
        $this->assertSame('Session', $msg->menu);
        $this->assertSame(MenuBar::getMenuItems('Session')[0], $msg->item);
        $this->assertSame('cmd:new', $msg->action, 'the row /new belongs to — dispatched by name');
        $this->assertSame(0, MenuBar::activeMenu(), 'selecting closes the menu');
    }

    public function testSelectingRowZeroIsNothingToSelect(): void
    {
        MenuBar::openMenu(1);

        $this->assertNull(MenuBar::selectItem(0), 'the paths a click names are 1-based; 0 names no row');
        $this->assertSame(1, MenuBar::getActiveMenu(), 'and the menu stays open — a refused click spends nothing');
    }

    public function testSelectingAnOutOfRangePathIsNothingToSelect(): void
    {
        MenuBar::openMenu(1);

        $this->assertNull(MenuBar::selectItem('99'));
        $this->assertNull(MenuBar::selectItem('1.99'));
        $this->assertSame(1, MenuBar::getActiveMenu());
    }

    public function testSelectingWithNoMenuOpenReturnsNull(): void
    {
        $this->assertNull(MenuBar::selectItem('1'));
    }

    /**
     * The Session dropdown: the six session commands first (ISS-104: rows the
     * palette never lists derive their label from the short description), the
     * separator as its own row, then the three submenu titles — a submenu row
     * is a row the bar PAINTS, and itemsOf is what the drift observations
     * count rows through.
     */
    public function testGetMenuItemsReturnsThePlacementRowsForAValidMenu(): void
    {
        $items = MenuBar::getMenuItems('Session');

        $this->assertIsArray($items);
        $this->assertSame(
            ['New session', 'New session…', 'Switch session', 'Rename session…', 'Pin or unpin session', 'Delete session…', '', 'Branch & fork', 'History', 'Share & export'],
            $items,
        );
    }

    /**
     * Every registry row the placement does not deliberately hide is
     * reachable as a `cmd:` leaf somewhere in the tree — the same derivation
     * the old per-category census proved, now proven over the nested shape
     * (the `@more` slot collects whatever the placement did not name).
     */
    public function testEveryCommandRegistryRowAppearsInAMenu(): void
    {
        $reachable = [];
        foreach (ShellMenu::tree(null)->leaves() as $leaf) {
            if (str_starts_with($leaf->id, 'cmd:')) {
                $reachable[] = substr($leaf->id, 4);
            }
        }

        foreach (CommandRegistry::all() as $spec) {
            if (in_array($spec->name, ShellMenu::HIDDEN, true)) {
                $this->assertNotContains($spec->name, $reachable, "/{$spec->name} is hidden from the bar by R-MENU-1 — it must not leak back through @more");
                continue;
            }

            $this->assertContains(
                $spec->name,
                $reachable,
                "/{$spec->name} is missing from every menu of the ShellMenu tree",
            );
        }
    }

    /**
     * A menu name the tree does not carry has no rows — the hand-written
     * 'File'/'Edit'/'Help' tables the bar used to carry were labels no
     * dispatcher answered.
     */
    public function testGetMenuItemsReturnsEmptyArrayForNonPlacementMenu(): void
    {
        $this->assertSame([], MenuBar::getMenuItems('File'));
    }

    public function testGetMenuItemsReturnsEmptyArrayForInvalidMenu(): void
    {
        $items = MenuBar::getMenuItems('NonExistentMenu');

        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }

    public function testMenuSelectedMsgStoresMenuAndItem(): void
    {
        $msg = new MenuSelectedMsg('Edit', 'Copy');

        $this->assertSame('Edit', $msg->menu);
        $this->assertSame('Copy', $msg->item);
        $this->assertNull($msg->action, 'the pre-kit senders build label-only messages and keep the label-lookup door');
    }

    public function testMenuSelectedMsgCarriesAnActionWhenGivenOne(): void
    {
        $msg = new MenuSelectedMsg('Tools', 'Generate image…', 'cmd:generate-form');

        $this->assertSame('cmd:generate-form', $msg->action);
    }

    public function testMenuSelectedMsgIsReadonly(): void
    {
        $reflection = new \ReflectionClass(MenuSelectedMsg::class);
        $this->assertTrue($reflection->isReadOnly());
    }
}
