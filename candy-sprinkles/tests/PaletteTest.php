<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Palette;
use PHPUnit\Framework\TestCase;

final class PaletteTest extends TestCase
{
    public function testNamedAnsiColoursMapToTheirIndex(): void
    {
        // Pair each factory with the ANSI index lipgloss documents.
        $cases = [
            [Palette::black(),        0],
            [Palette::red(),          1],
            [Palette::green(),        2],
            [Palette::yellow(),       3],
            [Palette::blue(),         4],
            [Palette::magenta(),      5],
            [Palette::cyan(),         6],
            [Palette::white(),        7],
            [Palette::brightBlack(),  8],
            [Palette::brightRed(),    9],
            [Palette::brightGreen(),  10],
            [Palette::brightYellow(), 11],
            [Palette::brightBlue(),   12],
            [Palette::brightMagenta(), 13],
            [Palette::brightCyan(),    14],
            [Palette::brightWhite(),   15],
        ];

        foreach ($cases as [$colour, $index]) {
            $this->assertInstanceOf(Color::class, $colour);
            $this->assertSame(Color::ansi($index)->toHex(), $colour->toHex());
        }
    }

    public function testGrayIsAliasForBrightBlack(): void
    {
        $this->assertSame(
            Palette::brightBlack()->toHex(),
            Palette::gray()->toHex(),
        );
    }

    public function testAllReturnsSixteenColoursInIndexOrder(): void
    {
        $all = Palette::all();
        $this->assertCount(16, $all);
        foreach ($all as $i => $c) {
            $this->assertSame(Color::ansi($i)->toHex(), $c->toHex(), "slot {$i}");
        }
    }

    public function testHasDarkBackgroundDelegatesToColorIsDark(): void
    {
        // Black is dark; bright white is not.
        $this->assertTrue(Palette::hasDarkBackground(Color::rgb(0, 0, 0)));
        $this->assertFalse(Palette::hasDarkBackground(Color::rgb(255, 255, 255)));
    }

    public function testHasDarkBackgroundReturnsNullForUnknown(): void
    {
        // Unknown background → null (caller should NOT treat as a default).
        $this->assertNull(Palette::hasDarkBackground(null));
    }
}
