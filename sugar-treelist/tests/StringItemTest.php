<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\TreeList\StringItem;
use PHPUnit\Framework\TestCase;

final class StringItemTest extends TestCase
{
    public function testConstructorStoresValue(): void
    {
        $item = new StringItem('hello');
        $this->assertSame('hello', $item->value);
    }

    /** sugar-treelist-027: the house factory mirrors the constructor. */
    public function testNewFactoryMirrorsConstructor(): void
    {
        $item = StringItem::new('factory');
        $this->assertInstanceOf(StringItem::class, $item);
        $this->assertSame('factory', $item->value);
        $this->assertSame('factory', (string) $item);
    }

    public function testToStringReturnsValue(): void
    {
        $item = new StringItem('world');
        $this->assertSame('world', (string) $item);
    }

    public function testToStringWithEmptyString(): void
    {
        $item = new StringItem('');
        $this->assertSame('', (string) $item);
    }

    public function testValueIsReadonly(): void
    {
        $item = new StringItem('readonly');
        $reflection = new \ReflectionClass($item);
        $property = $reflection->getProperty('value');
        $this->assertTrue($property->isReadOnly());
    }

    public function testValuePropertyIsString(): void
    {
        $item = new StringItem('test');
        $this->assertIsString($item->value);
    }

    public function testImmutability(): void
    {
        $a = new StringItem('original');
        $b = new StringItem('modified');
        $this->assertNotSame($a, $b);
        $this->assertSame('original', (string) $a);
        $this->assertSame('modified', (string) $b);
    }
}
