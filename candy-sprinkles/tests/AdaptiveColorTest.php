<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\AdaptiveColor;
use SugarCraft\Core\Util\Color;

final class AdaptiveColorTest extends TestCase
{
    public function testConstruction(): void
    {
        $light = Color::hex('#ffffff');
        $dark = Color::hex('#000000');
        $ac = new AdaptiveColor($light, $dark);
        $this->assertInstanceOf(AdaptiveColor::class, $ac);
        $this->assertSame($light, $ac->light);
        $this->assertSame($dark, $ac->dark);
    }

    public function testPickReturnsLightWhenDarkIsFalse(): void
    {
        $ac = new AdaptiveColor(Color::hex('#fff'), Color::hex('#000'));
        $result = $ac->pick(false);
        $this->assertSame($ac->light, $result);
    }

    public function testPickReturnsDarkWhenDarkIsTrue(): void
    {
        $ac = new AdaptiveColor(Color::hex('#fff'), Color::hex('#000'));
        $result = $ac->pick(true);
        $this->assertSame($ac->dark, $result);
    }

    public function testInstancesAreIndependent(): void
    {
        $ac1 = new AdaptiveColor(Color::hex('#fff'), Color::hex('#000'));
        $ac2 = new AdaptiveColor(Color::hex('#fff'), Color::hex('#000'));
        $this->assertNotSame($ac1, $ac2);
    }

    public function testReadonlyProperties(): void
    {
        $light = Color::hex('#ffffff');
        $dark = Color::hex('#000000');
        $ac = new AdaptiveColor($light, $dark);
        $this->assertSame($light, $ac->light);
        $this->assertSame($dark, $ac->dark);
    }

    public function testDifferentColorsCanBePicked(): void
    {
        $ac = new AdaptiveColor(Color::hex('#ff0000'), Color::hex('#00ff00'));
        $this->assertSame('#ff0000', $ac->pick(false)->toHex());
        $this->assertSame('#00ff00', $ac->pick(true)->toHex());
    }
}
