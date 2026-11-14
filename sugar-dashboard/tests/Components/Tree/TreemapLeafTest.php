<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\TreemapLeaf;

/**
 * D-143: both TreemapLeaf doors rebuild through the constructor (not
 * clone), so a parameter reorder in the rebuild silently drops the
 * children or the color — the carry matrix below catches each swap.
 */
final class TreemapLeafTest extends TestCase
{
    public function testDefaultsColorlessChildless(): void
    {
        $leaf = new TreemapLeaf('l1', 'Disk', 128.0);

        self::assertNull($leaf->color);
        self::assertSame([], $leaf->children);
    }

    public function testWithColorCarriesEverySlotIncludingChildren(): void
    {
        $kid = new TreemapLeaf('l2', 'Block', 4.0);
        $leaf = (new TreemapLeaf('l1', 'Disk', 128.0, Color::hex('#F38BA8')))
            ->withChildren([$kid]);

        $recolored = $leaf->withColor(Color::hex('#89B4FA'));

        self::assertSame('l1', $recolored->id);
        self::assertSame('Disk', $recolored->label);
        self::assertSame(128.0, $recolored->value);
        self::assertSame('#89b4fa', strtolower($recolored->color->toHex()));
        self::assertSame([$kid], $recolored->children, 'children must ride the rebuild');
    }

    public function testWithChildrenCarriesColorAndSealsTheSubtree(): void
    {
        $color = Color::hex('#A6E3A1');
        $leaf = new TreemapLeaf('l1', 'Disk', 10.0, $color);
        $kid = new TreemapLeaf('l2', 'Block', 4.0);

        $sealed = $leaf->withChildren([$kid]);

        self::assertSame([$kid], $sealed->children);
        self::assertSame($color, $sealed->color);
        self::assertSame([], $leaf->children, 'the source leaf stays childless');
    }
}
