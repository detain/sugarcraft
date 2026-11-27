<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Menu\MenuCursor;

/**
 * P2-3b: the Menu cursor is an immutable index path; every move returns a
 * new value and leaves the one it came from unchanged.
 */
final class MenuCursorTest extends TestCase
{
    public function testNewIsOnTheFirstRootItem(): void
    {
        $c = MenuCursor::new();
        self::assertSame([0], $c->path());
        self::assertSame(0, $c->depth());
        self::assertSame(0, $c->index());
    }

    public function testWithIndexMovesOnTheActiveLevelAndLeavesTheOriginalUnchanged(): void
    {
        $c = MenuCursor::new()->descend();
        $moved = $c->withIndex(3);

        self::assertNotSame($c, $moved);
        self::assertSame([0, 3], $moved->path());
        self::assertSame(3, $moved->index());
        self::assertSame([0, 0], $c->path());
    }

    public function testWithIndexRaisesANegativeIndexToZero(): void
    {
        self::assertSame([0], MenuCursor::new()->withIndex(-4)->path());
    }

    public function testDescendAppendsALevelAndLeavesTheOriginalUnchanged(): void
    {
        $c = MenuCursor::new()->withIndex(2);
        $deeper = $c->descend();

        self::assertSame([2, 0], $deeper->path());
        self::assertSame(1, $deeper->depth());
        self::assertSame([2], $c->path());
    }

    public function testAscendKeepsTheParentIndexAndLeavesTheOriginalUnchanged(): void
    {
        $c = MenuCursor::new()->withIndex(1)->descend()->withIndex(2);
        $up = $c->ascend();

        self::assertSame([1], $up->path());
        self::assertSame(1, $up->index());
        self::assertSame([1, 2], $c->path());
    }

    public function testAscendAtTheRootReturnsTheSameInstance(): void
    {
        $c = MenuCursor::new()->withIndex(1);
        self::assertSame($c, $c->ascend());
    }
}
