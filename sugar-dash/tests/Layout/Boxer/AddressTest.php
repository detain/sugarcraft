<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Boxer\Address;

final class AddressTest extends TestCase
{
    public function testRootFactory(): void
    {
        $address = Address::root();

        $this->assertSame('root', $address->toString());
    }

    public function testChildFactory(): void
    {
        $parent = Address::root();
        $child = Address::child($parent, 0);

        $this->assertSame('root.0', $child->toString());
    }

    public function testChildOfChild(): void
    {
        $root = Address::root();
        $first = Address::child($root, 0);
        $second = Address::child($first, 1);

        $this->assertSame('root.0.1', $second->toString());
    }

    public function testFromString(): void
    {
        $address = Address::fromString('foo.bar.123');

        $this->assertSame('foo.bar.123', $address->toString());
    }

    public function testGetParentOfRoot(): void
    {
        $root = Address::root();
        $parent = $root->getParent();

        $this->assertNull($parent);
    }

    public function testGetParentOfChild(): void
    {
        $root = Address::root();
        $child = Address::child($root, 0);

        $parent = $child->getParent();

        $this->assertNotNull($parent);
        $this->assertSame('root', $parent->toString());
    }

    public function testGetParentOfNestedChild(): void
    {
        $root = Address::root();
        $level1 = Address::child($root, 0);
        $level2 = Address::child($level1, 1);

        $parent = $level2->getParent();

        $this->assertNotNull($parent);
        $this->assertSame('root.0', $parent->toString());
    }

    public function testGetChild(): void
    {
        $address = Address::fromString('foo');
        $child = $address->getChild(5);

        $this->assertSame('foo.5', $child->toString());
    }
}
