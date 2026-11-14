<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\MindMapNode;

/**
 * D-143: addChild is the constructor door (builds the child with the
 * node's own defaults) on top of the withChild clone-append; the two
 * must agree or the two build styles produce divergent trees.
 */
final class MindMapNodeTest extends TestCase
{
    public function testWithChildAppendsToACloneAndLeavesTheSourceClean(): void
    {
        $root = new MindMapNode('idea');
        $leaf = new MindMapNode('detail');

        $grown = $root->withChild($leaf);

        self::assertSame([$leaf], $grown->children);
        self::assertSame([], $root->children);
        self::assertSame('idea', $grown->text);
    }

    public function testAddChildConstructsWithSameDefaultsAsTheManualDoor(): void
    {
        $root = new MindMapNode('idea');

        $viaAdd = $root->addChild('plain');
        self::assertCount(1, $viaAdd->children);
        $child = $viaAdd->children[0];
        self::assertSame('plain', $child->text);
        self::assertNull($child->color);
        self::assertNull($child->icon);

        // Explicit slots travel to the constructed child.
        $color = Color::hex('#A6E3A1');
        $viaStyled = $root->addChild('rich', $color, '🌳');
        $styled = $viaStyled->children[0];
        self::assertSame($color, $styled->color);
        self::assertSame('🌳', $styled->icon);
    }

    public function testAppendOrderIsPreservedAcrossBothDoors(): void
    {
        $root = (new MindMapNode('idea'))
            ->addChild('first')
            ->withChild(new MindMapNode('second'))
            ->addChild('third');

        self::assertSame(
            ['first', 'second', 'third'],
            array_map(static fn(MindMapNode $n): string => $n->text, $root->children),
        );
    }
}
