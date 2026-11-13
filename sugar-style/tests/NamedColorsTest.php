<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Markup;
use SugarCraft\Style\NamedColors;
use SugarCraft\Style\Style;
use SugarCraft\Style\StyleParser;

/**
 * Pins for finding 023: named colours are palette SLOTS, not absolute
 * RGB, in both parsers — one shared table, and the terminal's own
 * palette wins the final colour at every tier (TrueColor included).
 */
final class NamedColorsTest extends TestCase
{
    /**
     * @return array<string,array{string,int}>
     */
    public static function nameSlots(): array
    {
        return [
            'black' => ['black', 0],
            'red' => ['red', 1],
            'green' => ['green', 2],
            'yellow' => ['yellow', 3],
            'blue' => ['blue', 4],
            'magenta' => ['magenta', 5],
            'cyan' => ['cyan', 6],
            'white' => ['white', 7],
            'bright-black' => ['bright-black', 8],
            'bright-red' => ['bright-red', 9],
            'bright-green' => ['bright-green', 10],
            'bright-yellow' => ['bright-yellow', 11],
            'bright-blue' => ['bright-blue', 12],
            'bright-magenta' => ['bright-magenta', 13],
            'bright-cyan' => ['bright-cyan', 14],
            'bright-white' => ['bright-white', 15],
        ];
    }

    #[DataProvider('nameSlots')]
    public function testNameRoutesToItsAnsiSlot(string $name, int $slot): void
    {
        $color = NamedColors::color($name);

        $this->assertInstanceOf(Color::class, $color);
        $this->assertSame($slot, $color->ansiIndex, "{$name} must route to slot {$slot}");
        // Slot-wise agreement with sugar-core's ruled xterm-ANSI16 table.
        $this->assertSame(Color::ANSI16_RGB[$slot], [$color->r, $color->g, $color->b]);
    }

    public function testUnknownNameYieldsNullSoCallersKeepTheirOwnHandling(): void
    {
        $this->assertNull(NamedColors::color('beige'));
        $this->assertNull(NamedColors::color(''));
    }

    public function testRosterIsCaseInsensitive(): void
    {
        $this->assertSame(1, NamedColors::color('Red')?->ansiIndex);
        $this->assertSame(13, NamedColors::color('BRIGHT-MAGENTA')?->ansiIndex);
    }

    public function testTrueColourNamedForegroundEmitsThePaletteCodeNotAbsoluteRgb(): void
    {
        // The disclosed wire change of finding 023: [red] used to emit
        // 38;2;205;0;0 at TrueColor, overriding the user's palette. The
        // slot spelling survives at every tier instead.
        $cells = Markup::parse('[red]x[/]', Style::new());
        $this->assertSame("\x1b[31mx\x1b[0m", $cells[0]->style->render('x'));

        // A bright slot (8-15) reaches the 100-band as a BACKGROUND (40+
        // band only for 0-7, 100+n-8 above), foreground included.
        $bg = Markup::parse('[bg:bright-magenta]x[/]', Style::new());
        $this->assertSame("\x1b[105mx\x1b[0m", $bg[0]->style->render('x'));
    }

    public function testStyleParserNamesRouteThroughTheSameSlotsAsMarkup(): void
    {
        $cells = StyleParser::parse('[x](fg:magenta)', Style::new());
        $this->assertSame(5, $cells[0]->style->getForeground()->ansiIndex);

        // Hex values stay absolute — only the sixteen names defer to slots.
        $hex = StyleParser::parse('[x](fg:#ff0000)', Style::new());
        $this->assertNull($hex[0]->style->getForeground()->ansiIndex);
        $this->assertSame("\x1b[38;2;255;0;0mx\x1b[0m", $hex[0]->style->render('x'));
    }

    public function testNamedRgbAccessorPinsStillHoldThroughTheSlot(): void
    {
        // The pre-existing consumer contract: Color::ansi() carries core's
        // xterm RGB alongside the slot, so r/g/b readers see 205/0/0 for
        // red exactly as the old absolute tables did.
        $cells = Markup::parse('[red]x[/]', Style::new());
        $fg = $cells[0]->style->getForeground();
        $this->assertSame(205, $fg->r);
        $this->assertSame(0, $fg->g);
        $this->assertSame(0, $fg->b);
    }
}
