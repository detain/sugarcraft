<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\DendrogramNode;

/**
 * D-143: DendrogramTest builds trees through the widget and asserts
 * carriers, but the two recursive measurements the layout engine scales
 * from — totalValue() (subtree sum) and depth() (longest-arm height) —
 * had no direct pin on the real class anywhere in the suite.
 */
final class DendrogramNodeTest extends TestCase
{
    public function testLeafIsTheBaseCaseOfBothRecursions(): void
    {
        $leaf = new DendrogramNode('a', 'A', 12.5);

        self::assertSame([], $leaf->children);
        self::assertSame(12.5, $leaf->totalValue());
        self::assertSame(1, $leaf->depth());
        self::assertSame(0.0, (new DendrogramNode('z', 'Z'))->value, 'value defaults to zero');
    }

    public function testTotalValueSumsTheWholeSubtreeNotJustDirectChildren(): void
    {
        //        10
        //      /    \
        //     3      4
        //     |
        //     5
        $deep = (new DendrogramNode('a', 'A', 3.0))
            ->withChildren([new DendrogramNode('a1', 'A1', 5.0)]);
        $root = (new DendrogramNode('r', 'R', 10.0))
            ->withChildren([$deep, new DendrogramNode('b', 'B', 4.0)]);

        self::assertSame(22.0, $root->totalValue());
        self::assertSame(8.0, $deep->totalValue());
    }

    public function testDepthTakesTheLongestArm(): void
    {
        $short = new DendrogramNode('s', 'S');
        $long = (new DendrogramNode('l', 'L'))
            ->withChildren([(new DendrogramNode('l1', 'L1'))->withChildren([$short])]);
        $root = (new DendrogramNode('r', 'R'))->withChildren([$short, $long]);

        self::assertSame(3, $long->depth());
        // The fork under root reaches depth via the LONG arm only: 1(r)+3(l-chain) vs 1+1(s).
        self::assertSame(4, $root->depth());
    }

    public function testWithChildrenClonesWithoutTouchingTheOriginalsChildren(): void
    {
        // children is a PUBLIC mutable slot by design (the Dendrogram
        // builder appends before sealing); the wither must still hand
        // back a fresh carrier.
        $parent = new DendrogramNode('p', 'P');
        $sealed = $parent->withChildren([new DendrogramNode('c', 'C')]);

        self::assertCount(1, $sealed->children);
        self::assertSame([], $parent->children, 'withChildren must not leak into the source node');
        self::assertSame('p', $sealed->id);
        self::assertSame('P', $sealed->label);
        self::assertSame(0.0, $sealed->value);
    }

    public function testWithColorCarriesIdentityOfEveryChildNode(): void
    {
        $child = new DendrogramNode('c', 'C');
        $node = (new DendrogramNode('p', 'P', 7.0))->withChildren([$child]);

        $recolored = $node->withColor(\SugarCraft\Core\Util\Color::hex('#89B4FA'));

        self::assertSame('p', $recolored->id);
        self::assertSame(7.0, $recolored->value);
        self::assertNotSame($node, $recolored);
        // Children travel by identity, not by deep copy: the layout tree
        // is shared, so re-coloring a parent must not fork the subtree.
        self::assertSame([$child], $recolored->children);
    }
}
