<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Widgets\Menu\MenuItem;

/**
 * P2-3b: a Menu tree entry: label, optional id, optional children, and the
 * immutability of every with*() setter.
 */
final class MenuItemTest extends TestCase
{
    public function testNewWithLabelOnlyIsALeafWithNoId(): void
    {
        $item = MenuItem::new('Quit');
        self::assertSame('Quit', $item->label());
        self::assertNull($item->id());
        self::assertSame([], $item->children());
        self::assertFalse($item->hasChildren());
    }

    public function testNewWithChildrenIsABranch(): void
    {
        $child = MenuItem::new('Open', 'open');
        $item = MenuItem::new('File', 'file', ['x' => $child]);
        self::assertSame('file', $item->id());
        self::assertTrue($item->hasChildren());
        self::assertSame([$child], $item->children(), 'children are reindexed to a list');
    }

    public function testNewRejectsAChildThatIsNotAMenuItem(): void
    {
        $this->expectException(\TypeError::class);
        MenuItem::new('File', null, ['Open']);
    }

    public function testLabelIsSanitizedToOneLine(): void
    {
        $item = MenuItem::new("A\x1b[31m\r\nB\tC");
        self::assertSame('A B C', $item->label());
        self::assertSame(Sanitize::untrustedForMarkedFrames('A B C'), $item->label());
    }

    public function testWithLabelLeavesTheOriginalUnchanged(): void
    {
        $item = MenuItem::new('Old', 'id');
        $renamed = $item->withLabel("New\nName");
        self::assertNotSame($item, $renamed);
        self::assertSame('New Name', $renamed->label());
        self::assertSame('id', $renamed->id());
        self::assertSame('Old', $item->label());
    }

    public function testWithIdLeavesTheOriginalUnchanged(): void
    {
        $item = MenuItem::new('Label', 'a');
        $changed = $item->withId('b');
        self::assertSame('b', $changed->id());
        self::assertNull($changed->withId(null)->id());
        self::assertSame('a', $item->id());
    }

    public function testWithChildrenLeavesTheOriginalUnchanged(): void
    {
        $item = MenuItem::new('Leaf');
        $branch = $item->withChildren([MenuItem::new('Child')]);
        self::assertTrue($branch->hasChildren());
        self::assertFalse($item->hasChildren());
        self::assertFalse($branch->withChildren([])->hasChildren());
        self::assertTrue($branch->hasChildren());
    }

    public function testWithChildrenRejectsAnEntryThatIsNotAMenuItem(): void
    {
        $this->expectException(\TypeError::class);
        MenuItem::new('File')->withChildren([42]);
    }

    public function testListOfReindexesAndKeepsOrder(): void
    {
        $a = MenuItem::new('A');
        $b = MenuItem::new('B');
        self::assertSame([$a, $b], MenuItem::listOf(['k' => $a, 'j' => $b]));
    }
}
