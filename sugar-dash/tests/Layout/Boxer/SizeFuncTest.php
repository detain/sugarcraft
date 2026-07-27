<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Boxer\Node;
use SugarCraft\Dash\Layout\Boxer\SizeFunc;

final class SizeFuncTest extends TestCase
{
    public function testEvenWithNoChildrenReturnsEmpty(): void
    {
        $node = Node::leaf('0');
        $result = SizeFunc::even($node, 100);

        $this->assertSame([], $result);
    }

    public function testEvenWithTwoChildrenDividesEvenly(): void
    {
        $node = Node::horizontal(Node::leaf('0'), Node::leaf('1'));
        $result = SizeFunc::even($node, 100);

        $this->assertCount(2, $result);
        $this->assertSame(50, $result[0]);
        $this->assertSame(50, $result[1]);
    }

    public function testEvenWithThreeChildrenDividesWithRemainder(): void
    {
        $node = Node::horizontal(Node::leaf('0'), Node::leaf('1'), Node::leaf('2'));
        $result = SizeFunc::even($node, 100);

        $this->assertCount(3, $result);
        $this->assertSame(34, $result[0]);
        $this->assertSame(33, $result[1]);
        $this->assertSame(33, $result[2]);
    }

    public function testEvenWithFourChildrenDividesEvenly(): void
    {
        $node = Node::horizontal(
            Node::leaf('0'),
            Node::leaf('1'),
            Node::leaf('2'),
            Node::leaf('3')
        );
        $result = SizeFunc::even($node, 100);

        $this->assertCount(4, $result);
        $this->assertSame(25, $result[0]);
        $this->assertSame(25, $result[1]);
        $this->assertSame(25, $result[2]);
        $this->assertSame(25, $result[3]);
    }

    public function testEvenWithRemainder(): void
    {
        $node = Node::horizontal(Node::leaf('0'), Node::leaf('1'), Node::leaf('2'));
        $result = SizeFunc::even($node, 10);

        $this->assertCount(3, $result);
        $this->assertSame(4, $result[0]);
        $this->assertSame(3, $result[1]);
        $this->assertSame(3, $result[2]);
    }

    public function testFromCreatesSizeFunc(): void
    {
        $func = SizeFunc::from(fn($node, $total) => [50, 50]);

        $this->assertInstanceOf(SizeFunc::class, $func);
    }

    public function testInvokeCallsTheCallable(): void
    {
        $called = false;
        $func = SizeFunc::from(function ($node, $total) use (&$called) {
            $called = true;
            return [$total];
        });

        $node = Node::leaf('0');
        $result = $func($node, 100);

        $this->assertTrue($called);
        $this->assertSame([100], $result);
    }

    public function testEvenIsVerticalAware(): void
    {
        // Even should work for both horizontal and vertical nodes
        $hNode = Node::horizontal(Node::leaf('0'), Node::leaf('1'));
        $vNode = Node::vertical(Node::leaf('0'), Node::leaf('1'));

        $hResult = SizeFunc::even($hNode, 100);
        $vResult = SizeFunc::even($vNode, 100);

        $this->assertSame($hResult, $vResult);
    }
}
