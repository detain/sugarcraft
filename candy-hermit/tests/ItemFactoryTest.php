<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\{FilteredItem, Item, ItemFactory};
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Hermit\ItemFactory
 */
final class ItemFactoryTest extends TestCase
{
    private ItemFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ItemFactory();
    }

    public function testCoerceWithEmptyArray(): void
    {
        $result = $this->factory->coerce([]);

        $this->assertSame([], $result);
    }

    public function testCoerceWithPureStringsAssignsSequentialNumbers(): void
    {
        $result = $this->factory->coerce(['apple', 'banana', 'cherry']);

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]->number());
        $this->assertSame('apple', $result[0]->value());
        $this->assertSame(2, $result[1]->number());
        $this->assertSame('banana', $result[1]->value());
        $this->assertSame(3, $result[2]->number());
        $this->assertSame('cherry', $result[2]->value());
    }

    public function testCoerceWithPureFilteredItemsPassesThrough(): void
    {
        $items = [
            new FilteredItem(5, 'first'),
            new FilteredItem(10, 'second'),
        ];

        $result = $this->factory->coerce($items);

        $this->assertCount(2, $result);
        $this->assertSame($items[0], $result[0]);
        $this->assertSame($items[1], $result[1]);
        // Original numbers preserved
        $this->assertSame(5, $result[0]->number());
        $this->assertSame(10, $result[1]->number());
    }

    public function testCoerceWithMixedItemsAndStrings(): void
    {
        $existingItem = new FilteredItem(99, 'pre-existing');
        $result = $this->factory->coerce([$existingItem, 'apple', 'banana']);

        $this->assertCount(3, $result);
        // Existing Item passes through unchanged
        $this->assertSame($existingItem, $result[0]);
        $this->assertSame(99, $result[0]->number());
        $this->assertSame('pre-existing', $result[0]->value());
        // Strings get sequential numbers starting at 1
        $this->assertSame(1, $result[1]->number());
        $this->assertSame('apple', $result[1]->value());
        $this->assertSame(2, $result[2]->number());
        $this->assertSame('banana', $result[2]->value());
    }

    public function testCoercePreservesStringIdentity(): void
    {
        $result = $this->factory->coerce(['test']);

        $this->assertInstanceOf(FilteredItem::class, $result[0]);
        $this->assertSame('test', $result[0]->value());
    }

    public function testCoerceHandlesEmptyString(): void
    {
        $result = $this->factory->coerce(['']);

        $this->assertCount(1, $result);
        $this->assertSame('', $result[0]->value());
        $this->assertSame(1, $result[0]->number());
    }

    public function testCoerceHandlesNumericStrings(): void
    {
        $result = $this->factory->coerce(['123', '456']);

        $this->assertCount(2, $result);
        $this->assertSame('123', $result[0]->value());
        $this->assertSame('456', $result[1]->value());
    }

    public function testCoerceHandlesNonSequentialArrayKeys(): void
    {
        // Arrays with non-sequential keys should still produce sequential output
        $result = $this->factory->coerce([
            10 => 'first',
            20 => 'second',
            30 => 'third',
        ]);

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]->number());
        $this->assertSame('first', $result[0]->value());
        $this->assertSame(2, $result[1]->number());
        $this->assertSame('second', $result[1]->value());
        $this->assertSame(3, $result[2]->number());
        $this->assertSame('third', $result[2]->value());
    }

    public function testCoerceReturnsListOfItem(): void
    {
        $result = $this->factory->coerce(['a', 'b']);

        foreach ($result as $item) {
            $this->assertInstanceOf(Item::class, $item);
        }
    }

    public function testCoerceSingleStringBecomesFilteredItem(): void
    {
        $result = $this->factory->coerce(['single']);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(FilteredItem::class, $result[0]);
        $this->assertSame(1, $result[0]->number());
        $this->assertSame('single', $result[0]->value());
    }

    public function testCoerceWithFilteredItemSubclass(): void
    {
        // FilteredItem is readonly and final, so we can only test with FilteredItem itself
        $item = new FilteredItem(7, 'custom');
        $result = $this->factory->coerce([$item]);

        $this->assertCount(1, $result);
        $this->assertSame($item, $result[0]);
        $this->assertSame(7, $result[0]->number());
        $this->assertSame('custom', $result[0]->value());
    }

    public function testCoerceLargeBatch(): void
    {
        $strings = [];
        for ($i = 1; $i <= 100; $i++) {
            $strings[] = "item_{$i}";
        }

        $result = $this->factory->coerce($strings);

        $this->assertCount(100, $result);
        for ($i = 0; $i < 100; $i++) {
            $this->assertSame($i + 1, $result[$i]->number());
            $this->assertSame("item_" . ($i + 1), $result[$i]->value());
        }
    }
}
