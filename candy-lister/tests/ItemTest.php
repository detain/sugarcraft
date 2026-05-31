<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\Item;
use SugarCraft\Lister\StringItem;
use PHPUnit\Framework\TestCase;

final class ItemTest extends TestCase
{
    public function testStringReturnsValueAsString(): void
    {
        $item = new Item(new StringItem('hello'), 1);
        $this->assertSame('hello', $item->string());
    }

    public function testStringWithDifferentStringableValue(): void
    {
        $item = new Item(new StringItem('world'), 42);
        $this->assertSame('world', $item->string());
    }

    public function testStringWithNumericStringable(): void
    {
        $item = new Item(new StringItem('123'), 5);
        $this->assertSame('123', $item->string());
    }

    public function testIdIsStored(): void
    {
        $item = new Item(new StringItem('test'), 99);
        $this->assertSame(99, $item->id);
    }

    public function testValuePropertyIsAccessible(): void
    {
        $stringItem = new StringItem('accessibility');
        $item = new Item($stringItem, 7);
        $this->assertSame('accessibility', (string) $item->value);
    }
}
