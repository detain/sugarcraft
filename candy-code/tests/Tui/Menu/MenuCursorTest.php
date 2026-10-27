<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\Menu\MenuCursor;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\MenuTree;

/**
 * Every key at every depth of {@see MenuCursor::key()}, plus the hover
 * preview: movement wraps and skips, right/left each carry their two
 * meanings, escape pops exactly one level per press.
 */
final class MenuCursorTest extends TestCase
{
    /**
     * Menu 1 Session: new, ─, open (disabled), recent ▸ (a, b), quit.
     * Menu 2 Agent: [auto], models ▸ (fast, deep), stop.
     * Menu 3 View: panes ▸ (chat, dock ▸ (left, right)), theme.
     */
    private function cursorTree(): MenuTree
    {
        return MenuTree::new([
            MenuNode::submenu('sub:session', 'Session', [
                MenuNode::command('cmd:new', 'New'),
                MenuNode::separator(),
                MenuNode::command('cmd:open', 'Open', enabled: false),
                MenuNode::submenu('sub:recent', 'Recent', [
                    MenuNode::command('cmd:recent-a', 'Alpha'),
                    MenuNode::command('cmd:recent-b', 'Beta'),
                ]),
                MenuNode::command('cmd:quit', 'Quit'),
            ]),
            MenuNode::submenu('sub:agent', 'Agent', [
                MenuNode::command('cmd:auto', 'Auto'),
                MenuNode::submenu('sub:models', 'Models', [
                    MenuNode::command('cmd:fast', 'Fast'),
                    MenuNode::command('cmd:deep', 'Deep'),
                ]),
                MenuNode::command('cmd:stop', 'Stop'),
            ]),
            MenuNode::submenu('sub:view', 'View', [
                MenuNode::submenu('sub:panes', 'Panes', [
                    MenuNode::command('cmd:chat', 'Chat'),
                    MenuNode::submenu('sub:dock', 'Dock', [
                        MenuNode::command('cmd:dock-left', 'Left'),
                        MenuNode::command('cmd:dock-right', 'Right'),
                    ]),
                ]),
                MenuNode::command('cmd:theme', 'Theme'),
            ]),
        ]);
    }

    public function testClosedAndOpenStates(): void
    {
        $tree = $this->cursorTree();

        $closed = MenuCursor::closed();
        self::assertTrue($closed->isClosed());
        self::assertSame([], $closed->absolutePath());

        $opened = MenuCursor::open(1, $tree);
        self::assertFalse($opened->isClosed());
        self::assertSame(1, $opened->menu);
        // First selectable row: 0 here, but the opening walk must skip on a
        // dropdown that starts with a separator.
        self::assertSame([0], $opened->rows);
        self::assertSame([0, 0], $opened->absolutePath());

        self::assertTrue(MenuCursor::open(0, $tree)->isClosed());
        self::assertTrue(MenuCursor::open(4, $tree)->isClosed());
    }

    public function testOpeningSkipsAheadToTheFirstSelectableRow(): void
    {
        $tree = MenuTree::new([
            MenuNode::submenu('sub:pad', 'Pad', [
                MenuNode::separator(),
                MenuNode::command('cmd:only', 'Only'),
            ]),
        ]);

        self::assertSame([1], MenuCursor::open(1, $tree)->rows);
    }

    public function testDownSkipsSeparatorsAndDisabledRowsAndWraps(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        $cursor = $cursor->key('down', $tree)->cursor;
        self::assertSame([3], $cursor->rows, 'separator and disabled row are skipped');

        $cursor = $cursor->key('down', $tree)->cursor;
        self::assertSame([4], $cursor->rows);

        $cursor = $cursor->key('down', $tree)->cursor;
        self::assertSame([0], $cursor->rows, 'movement wraps around the panel');
    }

    public function testUpWrapsAndSkipsBackwards(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        $cursor = $cursor->key('up', $tree)->cursor;
        self::assertSame([4], $cursor->rows, 'up from the first row lands on the last');

        $cursor = $cursor->key('up', $tree)->cursor;
        self::assertSame([3], $cursor->rows);

        $cursor = $cursor->key('up', $tree)->cursor;
        self::assertSame([0], $cursor->rows, 'up skips the disabled row and the separator');
    }

    public function testVimLettersMirrorTheArrows(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        self::assertSame($cursor->key('down', $tree)->cursor->rows, $cursor->key('j', $tree)->cursor->rows);
        self::assertSame($cursor->key('up', $tree)->cursor->rows, $cursor->key('k', $tree)->cursor->rows);
        self::assertSame($cursor->key('right', $tree)->cursor->rows, $cursor->key('l', $tree)->cursor->rows);
        self::assertSame($cursor->key('left', $tree)->cursor->rows, $cursor->key('h', $tree)->cursor->rows);
    }

    public function testRightOnASubmenuRowEntersIt(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree)->key('down', $tree)->cursor;
        self::assertSame([3], $cursor->rows);

        $step = $cursor->key('right', $tree);
        self::assertSame([3, 0], $step->cursor->rows, 'the child opens at its first row');
        self::assertNull($step->chosen);
        self::assertFalse($step->cursor->expanded, 'entering is not a preview');
    }

    public function testRightOnALeafOpensTheNextMenu(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        $step = $cursor->key('right', $tree);
        self::assertSame(2, $step->cursor->menu);
        self::assertSame([0], $step->cursor->rows);

        $last = MenuCursor::open(3, $tree)->key('down', $tree)->cursor;
        self::assertSame([1], $last->rows, 'Theme is a leaf row');
        self::assertSame(1, $last->key('right', $tree)->cursor->menu, 'the bar wraps');
    }

    public function testLeftPopsAnEnteredSubmenuAndAtMenuDepthOpensThePrevious(): void
    {
        $tree = $this->cursorTree();
        $entered = MenuCursor::open(1, $tree)->key('down', $tree)->cursor->key('right', $tree)->cursor;
        self::assertSame([3, 0], $entered->rows);

        $popped = $entered->key('left', $tree)->cursor;
        self::assertSame([3], $popped->rows);
        self::assertSame(1, $popped->menu, 'left inside a child stays in the same menu');

        $previous = $popped->key('left', $tree)->cursor;
        self::assertSame(3, $previous->menu, 'left at menu depth walks the bar backwards');
        self::assertSame([0], $previous->rows);
    }

    public function testEnterChoosesALeafAndOpensASubmenu(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        $choice = $cursor->key('enter', $tree);
        self::assertNotNull($choice->chosen);
        self::assertSame('cmd:new', $choice->chosen->id);
        self::assertSame($cursor, $choice->cursor, 'choosing leaves the cursor for the caller to close');

        $submenuPress = $cursor->key('down', $tree)->cursor->key('enter', $tree);
        self::assertNull($submenuPress->chosen);
        self::assertSame([3, 0], $submenuPress->cursor->rows);
    }

    public function testEscapePopsExactlyOneLevelPerPress(): void
    {
        $tree = $this->cursorTree();
        $deep = MenuCursor::open(3, $tree)
            ->key('enter', $tree)->cursor
            ->key('down', $tree)->cursor
            ->key('right', $tree)->cursor;
        self::assertSame([0, 1, 0], $deep->rows);

        $one = $deep->key('escape', $tree)->cursor;
        self::assertSame([0, 1], $one->rows);
        self::assertFalse($one->isClosed());

        $two = $one->key('escape', $tree)->cursor;
        self::assertSame([0], $two->rows);

        $three = $two->key('escape', $tree)->cursor;
        self::assertTrue($three->isClosed(), 'at depth one escape closes the menu');
    }

    public function testDepthTwoNavigationTouchesOnlyTheInnermostPanel(): void
    {
        $tree = $this->cursorTree();
        $dock = MenuCursor::open(3, $tree)
            ->key('enter', $tree)->cursor
            ->key('down', $tree)->cursor
            ->key('right', $tree)->cursor;
        self::assertSame([0, 1, 0], $dock->rows);
        self::assertSame([2, 0, 1, 0], $dock->absolutePath());

        $moved = $dock->key('down', $tree)->cursor;
        self::assertSame([0, 1, 1], $moved->rows);

        $wrapped = $moved->key('down', $tree)->cursor;
        self::assertSame([0, 1, 0], $wrapped->rows, 'wrap happens inside the dock panel only');

        $chose = $wrapped->key('enter', $tree)->chosen;
        self::assertNotNull($chose);
        self::assertSame('cmd:dock-left', $chose->id);
    }

    public function testHoverHighlightsAndExpandsWithoutEntering(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(2, $tree);

        $hovered = $cursor->hover([1], $tree);
        self::assertSame([1], $hovered->rows, 'hover does not deepen the cursor');
        self::assertTrue($hovered->expanded, 'a submenu row previews its child');

        $onLeaf = $cursor->hover([2], $tree);
        self::assertSame([2], $onLeaf->rows);
        self::assertFalse($onLeaf->expanded);
    }

    public function testMovementDropsTheHoverPreview(): void
    {
        $tree = $this->cursorTree();
        $hovered = MenuCursor::open(2, $tree)->hover([1], $tree);

        $moved = $hovered->key('down', $tree)->cursor;
        self::assertFalse($moved->expanded);
        self::assertSame([2], $moved->rows);
    }

    public function testHoverIgnoresRowsTheCursorMayNotRestOn(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        self::assertSame($cursor, $cursor->hover([1], $tree), 'a separator cannot be hovered');
        self::assertSame($cursor, $cursor->hover([2], $tree), 'a disabled row cannot be hovered');
        self::assertSame($cursor, $cursor->hover([9], $tree), 'a path off the tree cannot be hovered');
        self::assertSame($cursor, $cursor->hover([], $tree), 'hovering the bar itself is not a row move');
        $dead = MenuCursor::closed();
        self::assertSame($dead, $dead->hover([0], $tree), 'nothing hovers a closed menu');
    }

    public function testKeysAreCaseInsensitive(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        self::assertSame($cursor->key('down', $tree)->cursor->rows, $cursor->key('DOWN', $tree)->cursor->rows);
        self::assertTrue($cursor->key('ESCAPE', $tree)->cursor->isClosed());
    }

    public function testUnboundAndNoSelectableRowCasesLeaveTheCursorAlone(): void
    {
        $tree = $this->cursorTree();
        $cursor = MenuCursor::open(1, $tree);

        self::assertSame($cursor, $cursor->key('x', $tree)->cursor);
        self::assertSame($cursor, $cursor->key('', $tree)->cursor);

        $dead = MenuTree::new([
            MenuNode::submenu('sub:dead', 'Dead', [
                MenuNode::command('cmd:no', 'No', enabled: false),
            ]),
        ]);
        $stuck = MenuCursor::open(1, $dead);
        self::assertSame([0], $stuck->rows, 'a dropdown of dead rows still opens on row zero');
        self::assertSame($stuck, $stuck->key('down', $dead)->cursor);
        self::assertNull($stuck->key('enter', $dead)->chosen, 'a disabled row is never chosen');
    }

    public function testKeysOnAClosedCursorAreInert(): void
    {
        $tree = $this->cursorTree();
        $closed = MenuCursor::closed();

        foreach (['down', 'up', 'right', 'left', 'enter', 'escape'] as $key) {
            self::assertSame($closed, $closed->key($key, $tree)->cursor, $key . ' cannot resurrect a closed cursor');
        }
    }
}
