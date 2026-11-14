<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\SunburstSegment;

/**
 * D-143: the D-04 hardening test only exercised totalValue() through a
 * hand-rolled fake (SunburstTest.php:399); the REAL segment recursion —
 * the arithmetic the ring angles are computed from — had no direct pin.
 */
final class SunburstSegmentTest extends TestCase
{
    public function testLeafValueIsItsOwnTotal(): void
    {
        $leaf = new SunburstSegment('a', 'A', 4.0);

        self::assertSame(4.0, $leaf->totalValue());
        self::assertSame([], $leaf->children);
    }

    public function testTotalValueRecursesThroughNestedRings(): void
    {
        //  value 1 ring
        //  |- 2
        //  |- 3 -> 4 (grandchild)
        $grand = new SunburstSegment('c1', 'C1', 4.0);
        $inner = (new SunburstSegment('b', 'B', 3.0))->withChildren([$grand]);
        $root = (new SunburstSegment('r', 'R', 1.0))
            ->withChildren([new SunburstSegment('a', 'A', 2.0), $inner]);

        self::assertSame(7.0, $inner->totalValue());
        self::assertSame(10.0, $root->totalValue());
    }

    public function testWithColorRebuildsButKeepsTheSubtreeIdentity(): void
    {
        $child = new SunburstSegment('c', 'C', 1.0);
        $node = (new SunburstSegment('p', 'P', 2.0, Color::hex('#F38BA8')))
            ->withChildren([$child]);

        $recolored = $node->withColor(Color::hex('#89B4FA'));

        self::assertSame('p', $recolored->id);
        self::assertSame('P', $recolored->label);
        self::assertSame(2.0, $recolored->value);
        self::assertSame('#89b4fa', strtolower($recolored->color->toHex()));
        // The rebuild path (clone is impossible across the new object in
        // this wither) must still share child identity.
        self::assertSame([$child], $recolored->children);
        self::assertSame($child, $recolored->children[0]);
    }

    public function testWithColorAcceptsNullToClear(): void
    {
        $node = new SunburstSegment('p', 'P', 1.0, Color::hex('#F38BA8'));

        self::assertNull($node->withColor(null)->color);
    }

    public function testWithChildrenDoesNotTouchTheSourceSegment(): void
    {
        $source = new SunburstSegment('p', 'P', 1.0);
        $sealed = $source->withChildren([new SunburstSegment('c', 'C', 1.0)]);

        self::assertSame([], $source->children);
        self::assertCount(1, $sealed->children);
    }
}
