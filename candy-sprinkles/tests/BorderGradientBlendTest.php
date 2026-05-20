<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border\BorderGradientBlend;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see BorderGradientBlend} — N-color (1-5) gradient
 * interpolation to 4 border-side colors.
 */
final class BorderGradientBlendTest extends TestCase
{
    public function testOneColorReturnsSameColorOnAllSides(): void
    {
        $c = Color::hex('#ff0000');
        $blend = BorderGradientBlend::fromColors($c);

        $this->assertCount(1, $blend->colors());
        $this->assertCount(4, $blend->sides());

        foreach ($blend->sides() as $side) {
            $this->assertEquals($c->r, $side->r);
            $this->assertEquals($c->g, $side->g);
            $this->assertEquals($c->b, $side->b);
        }
    }

    public function testTwoColorsInterpolatesToFourSides(): void
    {
        $c0 = Color::hex('#000000'); // black
        $c1 = Color::hex('#ffffff'); // white
        $blend = BorderGradientBlend::fromColors($c0, $c1);

        $this->assertCount(2, $blend->colors());
        $this->assertCount(4, $blend->sides());

        // With 2 colors at t=0 and t=1, each side should be at a different blend point
        $sides = $blend->sides();
        // Not all sides should be identical when we have 2 colors
        $unique = array_unique(array_map(fn($s) => $s->toHex(), $sides));
        $this->assertCount(4, $unique, '2 colors should produce 4 distinct side colors');
    }

    public function testThreeColorsInterpolatesProportionally(): void
    {
        $c0 = Color::hex('#ff0000'); // red at t=0
        $c1 = Color::hex('#00ff00'); // green at t=0.5
        $c2 = Color::hex('#0000ff'); // blue at t=1
        $blend = BorderGradientBlend::fromColors($c0, $c1, $c2);

        $this->assertCount(3, $blend->colors());
        $this->assertCount(4, $blend->sides());

        // First side should be close to c0 (red)
        // Last side should be close to c2 (blue)
        $sides = $blend->sides();
        $this->assertEquals('#ff0000', $sides[0]->toHex());
        $this->assertEquals('#0000ff', $sides[3]->toHex());
    }

    public function testFourColorsDirectMapping(): void
    {
        $c0 = Color::hex('#ff0000');
        $c1 = Color::hex('#00ff00');
        $c2 = Color::hex('#0000ff');
        $c3 = Color::hex('#ffff00');
        $blend = BorderGradientBlend::fromColors($c0, $c1, $c2, $c3);

        $this->assertCount(4, $blend->colors());
        $this->assertCount(4, $blend->sides());

        $sides = $blend->sides();
        // Each side should be at a different stop along the 4-color gradient
        // First side closest to c0, last side closest to c3
        $this->assertEquals('#ff0000', $sides[0]->toHex());
        $this->assertEquals('#00ff00', $sides[1]->toHex());
        $this->assertEquals('#0000ff', $sides[2]->toHex());
        $this->assertEquals('#ffff00', $sides[3]->toHex());
    }

    public function testFiveColorsUsesFirstFourForSides(): void
    {
        $c0 = Color::hex('#ff0000');
        $c1 = Color::hex('#00ff00');
        $c2 = Color::hex('#0000ff');
        $c3 = Color::hex('#ffff00');
        $c4 = Color::hex('#ff00ff');
        $blend = BorderGradientBlend::fromColors($c0, $c1, $c2, $c3, $c4);

        $this->assertCount(5, $blend->colors());
        $this->assertCount(4, $blend->sides());
    }

    public function testThrowsOnZeroColors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BorderGradientBlend::fromColors();
    }

    public function testThrowsOnMoreThanFiveColors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BorderGradientBlend::fromColors(
            Color::hex('#ff0000'),
            Color::hex('#00ff00'),
            Color::hex('#0000ff'),
            Color::hex('#ffff00'),
            Color::hex('#ff00ff'),
            Color::hex('#ffffff'),
        );
    }
}
