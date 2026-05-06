<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see Style::borderForegroundBlend()} — picks four
 * gradient stops along [start, end] and seeds them onto the four
 * border sides.
 */
final class BorderBlendTest extends TestCase
{
    public function testFourSidesPickDistinctColours(): void
    {
        $start = Color::hex('#000000');
        $end   = Color::hex('#ffffff');
        $rendered = Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->borderForegroundBlend($start, $end)
            ->render('x');

        // Each of the four blended fractions emits a distinct SGR
        // foreground.
        $stops = ['38;2;0;0;0', '38;2;85;85;85', '38;2;170;170;170', '38;2;255;255;255'];
        foreach ($stops as $sgr) {
            $this->assertStringContainsString($sgr, $rendered, "missing SGR $sgr");
        }
    }

    public function testIdenticalStartEndCollapsesToOneColour(): void
    {
        $solid = Color::hex('#ff5f87');
        $rendered = Style::new()
            ->border(Border::normal())
            ->borderForegroundBlend($solid, $solid)
            ->render('x');
        $this->assertStringContainsString('38;2;255;95;135', $rendered);
    }
}
