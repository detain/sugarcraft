<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Select;
use PHPUnit\Framework\TestCase;

enum ColorEnum: string
{
    case Red = 'Red';
    case Green = 'Green';
    case Blue = 'Blue';
}

final class SelectEnumTest extends TestCase
{
    public function testWithEnumCoercesSelectedValueToEnum(): void
    {
        $f = Select::new('color')
            ->withOptions('Red', 'Green', 'Blue')
            ->withEnum(ColorEnum::class);

        $this->assertInstanceOf(ColorEnum::class, $f->value());
        $this->assertSame(ColorEnum::Red, $f->value());
    }

    public function testWithEnumDownArrowChangesSelection(): void
    {
        $f = Select::new('color')
            ->withOptions('Red', 'Green', 'Blue')
            ->withEnum(ColorEnum::class);

        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));

        $this->assertInstanceOf(ColorEnum::class, $f->value());
        $this->assertSame(ColorEnum::Green, $f->value());
    }

    public function testWithEnumUpArrowChangesSelection(): void
    {
        $f = Select::new('color')
            ->withOptions('Red', 'Green', 'Blue')
            ->withEnum(ColorEnum::class);

        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));

        $this->assertInstanceOf(ColorEnum::class, $f->value());
        $this->assertSame(ColorEnum::Green, $f->value());
    }

    public function testWithoutEnumReturnsStringValue(): void
    {
        $f = Select::new('color')
            ->withOptions('Red', 'Green', 'Blue');

        $this->assertSame('Red', $f->value());
    }

    public function testEnumShortFormAlias(): void
    {
        $f = Select::new('color')
            ->withOptions('Red', 'Green', 'Blue')
            ->enum(ColorEnum::class);

        $this->assertInstanceOf(ColorEnum::class, $f->value());
    }

    public function testEnumValueMatchesEnumCaseValue(): void
    {
        $f = Select::new('color')
            ->withOptions('Red', 'Green', 'Blue')
            ->withEnum(ColorEnum::class);

        $this->assertSame('Red', $f->value()->value);
        $this->assertSame('Green', ColorEnum::Green->value);
    }
}
