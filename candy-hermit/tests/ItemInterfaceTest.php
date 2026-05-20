<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\Item;
use PHPUnit\Framework\TestCase;

/**
 * Verify the Item interface contract.
 */
final class ItemInterfaceTest extends TestCase
{
    public function testItemInterfaceExists(): void
    {
        $this->assertTrue(\interface_exists(Item::class));
    }

    public function testItemHasNumberMethod(): void
    {
        $this->assertTrue(\method_exists(Item::class, 'number'));
    }

    public function testItemHasValueMethod(): void
    {
        $this->assertTrue(\method_exists(Item::class, 'value'));
    }

    public function testItemNumberReturnsInt(): void
    {
        $item = new \SugarCraft\Hermit\FilteredItem(42, 'test');
        $this->assertSame(42, $item->number());
    }

    public function testItemValueReturnsString(): void
    {
        $item = new \SugarCraft\Hermit\FilteredItem(1, 'hello');
        $this->assertSame('hello', $item->value());
    }
}
