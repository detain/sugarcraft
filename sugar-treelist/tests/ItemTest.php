<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\TreeList\Item;
use SugarCraft\TreeList\Model;
use SugarCraft\TreeList\StringItem;
use PHPUnit\Framework\TestCase;

/**
 * Item wrapper pins. sugar-treelist-026: the constructor went private and
 * the sanctioned door is the @internal Item::new() mint Model's intake calls
 * — every construction below goes through it (the old `new Item(...)` shape
 * is now impossible from outside, which the first test pins).
 */
final class ItemTest extends TestCase
{
    public function testStringReturnsValueAsString(): void
    {
        $item = Item::new(new StringItem('hello'), 1);
        $this->assertSame('hello', $item->string());
    }

    public function testStringWithDifferentStringableValue(): void
    {
        $item = Item::new(new StringItem('world'), 42);
        $this->assertSame('world', $item->string());
    }

    public function testStringWithNumericStringable(): void
    {
        $item = Item::new(new StringItem('123'), 5);
        $this->assertSame('123', $item->string());
    }

    public function testIdIsStored(): void
    {
        $item = Item::new(new StringItem('test'), 99);
        $this->assertSame(99, $item->id);
    }

    public function testValuePropertyIsAccessible(): void
    {
        $stringItem = new StringItem('accessibility');
        $item       = Item::new($stringItem, 7);
        $this->assertSame('accessibility', (string) $item->value);
    }

    /**
     * sugar-treelist-026 acceptance: external `new Item(...)` is impossible,
     * so the forged/duplicate-id cursor-teleport reproducer has no door.
     */
    public function testConstructorIsPrivateExternalBuildFails(): void
    {
        $ctor = (new \ReflectionClass(Item::class))->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate(), 'Item must not be constructible outside Model');

        try {
            /** @phpstan-ignore-next-line intentionally illegal */
            $forged = new Item(new StringItem('x'), 1);
            $this->fail('external new Item() succeeded: ' . $forged->string());
        } catch (\Error $e) {
            $this->assertStringContainsString('Item', $e->getMessage());
        }
    }

    /**
     * A hand-minted item cannot smuggle a colliding id into a model: Item is
     * not \Stringable, so the intake door refuses it by contract.
     */
    public function testMintedItemIsNotAcceptedBackThroughIntake(): void
    {
        $m = Model::new()->withItemsFromArray(['a', 'b', 'c']);
        try {
            $m->withItem(Item::new(new StringItem('d'), 0)); // forged id 0 collides with 'a'
            $this->fail('Item passed the intake door — wrapper leaked into value position');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('withItem', $e->getMessage());
        }
    }
}
