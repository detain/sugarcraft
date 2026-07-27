<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\LightDark;
use SugarCraft\Core\Util\Color;

final class LightDarkTest extends TestCase
{
    public function testPickReturnsLightWhenDarkIsFalse(): void
    {
        $light = Color::hex('#ffffff');
        $dark = Color::hex('#000000');
        $result = LightDark::pick(false, $light, $dark);
        $this->assertSame($light, $result);
    }

    public function testPickReturnsDarkWhenDarkIsTrue(): void
    {
        $light = Color::hex('#ffffff');
        $dark = Color::hex('#000000');
        $result = LightDark::pick(true, $light, $dark);
        $this->assertSame($dark, $result);
    }

    public function testPickerReturnsClosure(): void
    {
        $picker = LightDark::picker(true);
        $this->assertInstanceOf(\Closure::class, $picker);
    }

    public function testPickerClosurePicksDarkOnDarkBackground(): void
    {
        $picker = LightDark::picker(true);
        $light = Color::hex('#ffffff');
        $dark = Color::hex('#000000');
        $result = $picker($light, $dark);
        $this->assertSame($dark, $result);
    }

    public function testPickerClosurePicksLightOnLightBackground(): void
    {
        $picker = LightDark::picker(false);
        $light = Color::hex('#ffffff');
        $dark = Color::hex('#000000');
        $result = $picker($light, $dark);
        $this->assertSame($light, $result);
    }

    public function testPickerCanBeCalledMultipleTimes(): void
    {
        $picker = LightDark::picker(true);
        $red = Color::hex('#ff0000');
        $blue = Color::hex('#0000ff');
        $green = Color::hex('#00ff00');

        $this->assertSame($blue, $picker($red, $blue));
        $this->assertSame($green, $picker($red, $green));
    }

    public function testPickerIsStaticClosure(): void
    {
        $picker = LightDark::picker(false);
        $light = Color::hex('#eee');
        $dark = Color::hex('#111');
        $result = $picker($light, $dark);
        $this->assertNotSame($dark, $result);
        $this->assertSame($light, $result);
    }
}
