<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\NetworkNode;
use SugarCraft\Dashboard\Components\Tree\NetworkShape;

/**
 * D-143: adjacency carrier for the force graph — clone-append of
 * connections plus the Circle default shape and the shape vocabulary.
 */
final class NetworkNodeTest extends TestCase
{
    public function testDefaultShapeIsCircleWithNoConnections(): void
    {
        $node = new NetworkNode('n1', 'Hub');

        self::assertSame(NetworkShape::Circle, $node->shape);
        self::assertSame([], $node->connections);
        self::assertNull($node->icon);
    }

    public function testWithConnectionAppendsIdsInOrderWithoutTouchingTheSource(): void
    {
        $node = (new NetworkNode('n1', 'Hub'))
            ->withConnection('a')
            ->withConnection('b');

        self::assertSame(['a', 'b'], $node->connections);
        self::assertSame([], (new NetworkNode('n1', 'Hub'))->connections);
        self::assertSame('n1', $node->id);
        self::assertSame(NetworkShape::Circle, $node->shape);
    }

    public function testNonDefaultShapeSurvivesTheAppendDoor(): void
    {
        $box = new NetworkNode('q', 'Queue', NetworkShape::Square);

        $grown = $box->withConnection('r');

        self::assertSame(NetworkShape::Square, $grown->shape);
        self::assertSame(['r'], $grown->connections);
    }

    public function testNetworkShapeWireRoster(): void
    {
        self::assertSame(
            ['Circle', 'Square', 'Diamond', 'Hexagon', 'Star'],
            array_map(static fn(NetworkShape $s): string => $s->name, NetworkShape::cases()),
        );
        self::assertSame(
            ['circle', 'square', 'diamond', 'hexagon', 'star'],
            array_map(static fn(NetworkShape $s): string => $s->value, NetworkShape::cases()),
        );
    }
}
