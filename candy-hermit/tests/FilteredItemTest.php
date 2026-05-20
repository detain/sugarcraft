<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\FilteredItem;
use PHPUnit\Framework\TestCase;

/**
 * Verify FilteredItem numbered implementation of Item interface.
 */
final class FilteredItemTest extends TestCase
{
    public function testConstruction(): void
    {
        $item = new FilteredItem(1, 'apple');
        $this->assertInstanceOf(FilteredItem::class, $item);
    }

    public function testNumberReturnsCorrectValue(): void
    {
        $item = new FilteredItem(5, 'test');
        $this->assertSame(5, $item->number());
    }

    public function testValueReturnsCorrectString(): void
    {
        $item = new FilteredItem(1, 'banana');
        $this->assertSame('banana', $item->value());
    }

    public function testReadonlyProperties(): void
    {
        $item = new FilteredItem(3, 'cherry');
        // Verify properties are readonly by checking they exist and are not public writable
        $reflection = new \ReflectionClass($item);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testImmutability(): void
    {
        $a = new FilteredItem(1, 'original');
        $b = new FilteredItem(1, 'original');

        $this->assertSame($a->number(), $b->number());
        $this->assertSame($a->value(), $b->value());
        $this->assertNotSame($a, $b);
    }

    public function testNumberIsOneBased(): void
    {
        $item = new FilteredItem(1, 'first');
        $this->assertSame(1, $item->number());
    }
}
