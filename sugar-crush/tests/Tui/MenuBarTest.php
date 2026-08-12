<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MenuBarTest extends TestCase
{
    /**
     * Test that cycleMenu(1, 1) cycles from first to second menu.
     * Verifies the forward cycling logic when at the first menu.
     */
    public function testCycleMenuForwardFromFirstToSecond(): void
    {
        $result = $this->invokeCycleMenu(1, 1);
        $this->assertSame(2, $result);
    }

    /**
     * Test that cycleMenu(7, 1) cycles from last to first menu.
     * Verifies the wrap-around when cycling forward past the last menu.
     */
    public function testCycleMenuForwardWrapsFromLastToFirst(): void
    {
        $result = $this->invokeCycleMenu(7, 1);
        $this->assertSame(1, $result);
    }

    /**
     * Test that cycleMenu(2, -1) cycles backwards from second to first.
     * Verifies the backward cycling logic within normal bounds.
     */
    public function testCycleMenuBackwardFromSecondToFirst(): void
    {
        $result = $this->invokeCycleMenu(2, -1);
        $this->assertSame(1, $result);
    }

    /**
     * Test that cycleMenu(1, -1) cycles backwards from first to last.
     * Verifies the wrap-around when cycling backward past the first menu.
     */
    public function testCycleMenuBackwardWrapsFromFirstToLast(): void
    {
        $result = $this->invokeCycleMenu(1, -1);
        $this->assertSame(7, $result);
    }

    /**
     * Test that selectMenuItem returns MenuSelectedMsg with correct menu.
     * Verifies that a valid menu index (1 = first menu "File") returns the
     * appropriate MenuSelectedMsg with menu name 'File' and empty item.
     */
    public function testSelectMenuItemReturnsCorrectMenuMsg(): void
    {
        $result = $this->invokeSelectMenuItem(1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]);
        $this->assertInstanceOf(MenuSelectedMsg::class, $result[1]);
        $this->assertSame('File', $result[1]->menu);
        $this->assertSame('', $result[1]->item);
    }

    /**
     * Test that selectMenuItem(0) returns [0, null] for invalid index.
     * Verifies that index 0 (below valid range) returns null for the msg.
     */
    public function testSelectMenuItemWithZeroIndexReturnsNullMsg(): void
    {
        $result = $this->invokeSelectMenuItem(0);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(0, $result[0]);
        $this->assertNull($result[1]);
    }

    /**
     * Test that selectMenuItem(99) returns [99, null] for out of range index.
     * Verifies that an index beyond the menu count returns null for the msg.
     */
    public function testSelectMenuItemWithOutOfRangeIndexReturnsNullMsg(): void
    {
        $result = $this->invokeSelectMenuItem(99);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(99, $result[0]);
        $this->assertNull($result[1]);
    }

    /**
     * Test that getMenuItems returns array for valid menu name.
     * Verifies that 'File' menu returns its item list including separators.
     */
    public function testGetMenuItemsReturnsArrayForValidMenu(): void
    {
        $items = MenuBar::getMenuItems('File');

        $this->assertIsArray($items);
        $this->assertSame(
            ['New Session', 'Open Session', 'Save Transcript', 'Export Chat', '---', 'Preferences', 'Quit'],
            $items
        );
    }

    /**
     * Test that getMenuItems returns empty array for invalid menu name.
     * Verifies that a non-existent menu returns an empty array.
     */
    public function testGetMenuItemsReturnsEmptyArrayForInvalidMenu(): void
    {
        $items = MenuBar::getMenuItems('NonExistentMenu');

        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }

    /**
     * Test that MenuSelectedMsg stores menu and item correctly.
     * Verifies the readonly properties are set via constructor.
     */
    public function testMenuSelectedMsgStoresMenuAndItem(): void
    {
        $msg = new MenuSelectedMsg('Edit', 'Copy');

        $this->assertSame('Edit', $msg->menu);
        $this->assertSame('Copy', $msg->item);
    }

    /**
     * Test that MenuSelectedMsg has readonly properties.
     * Verifies that the class prevents mutation after construction.
     */
    public function testMenuSelectedMsgIsReadonly(): void
    {
        $reflection = new \ReflectionClass(MenuSelectedMsg::class);
        $this->assertTrue($reflection->isReadOnly());
    }

    /**
     * Invokes the private static cycleMenu method via reflection.
     */
    private function invokeCycleMenu(int $currentMenu, int $direction): int
    {
        $method = new ReflectionMethod(MenuBar::class, 'cycleMenu');
        $method->setAccessible(true);
        return $method->invoke(null, $currentMenu, $direction);
    }

    /**
     * Invokes the private static selectMenuItem method via reflection.
     *
     * @return array{0: int, 1: MenuSelectedMsg|null}
     */
    private function invokeSelectMenuItem(int $menuIndex): array
    {
        $method = new ReflectionMethod(MenuBar::class, 'selectMenuItem');
        $method->setAccessible(true);
        return $method->invoke(null, $menuIndex);
    }
}
