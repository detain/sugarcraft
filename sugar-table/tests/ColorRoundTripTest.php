<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Buffer\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Table;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip audit for the private styleToAnsi()/parseAnsiToStyle() pair.
 *
 * parseAnsiToStyle() maps the standard SGR palette (30-37/40-47/90-97/100-107)
 * to concrete RGB values, while styleToAnsi() always emits truecolor
 * (38;2/48;2) sequences. The palette codes themselves therefore do not
 * survive a round trip (by design) — but the RESOLVED Style must: parsing
 * what styleToAnsi() emitted has to reproduce the identical Style, or cell
 * colors would drift on every re-render that funnels through the pair.
 */
final class ColorRoundTripTest extends TestCase
{
    private Table $table;
    private \ReflectionMethod $toAnsi;
    private \ReflectionMethod $toStyle;

    protected function setUp(): void
    {
        $this->table = Table::fromColumns([Column::new('id', 'ID', 5)]);
        $this->toAnsi = new \ReflectionMethod(Table::class, 'styleToAnsi');
        $this->toStyle = new \ReflectionMethod(Table::class, 'parseAnsiToStyle');
    }

    private function roundTrip(Style $style): Style
    {
        $ansi = $this->toAnsi->invoke($this->table, $style);
        return $this->toStyle->invoke($this->table, $ansi);
    }

    /** @return iterable<string, array{int}> */
    public static function standardFgCodes(): iterable
    {
        foreach ([...range(30, 37), ...range(90, 97)] as $code) {
            yield "fg-{$code}" => [$code];
        }
    }

    /** @return iterable<string, array{int}> */
    public static function standardBgCodes(): iterable
    {
        foreach ([...range(40, 47), ...range(100, 107)] as $code) {
            yield "bg-{$code}" => [$code];
        }
    }

    /** @dataProvider standardFgCodes */
    public function testStandardForegroundRoundTripsStable(int $code): void
    {
        $parsed = $this->toStyle->invoke($this->table, (string) $code);
        $this->assertNotNull($parsed->fg(), "SGR {$code} must resolve to a concrete fg");

        $again = $this->roundTrip($parsed);
        $this->assertSame($parsed->fg(), $again->fg());
        $this->assertNull($again->bg());
        $this->assertSame(0, $again->attrs());
    }

    /** @dataProvider standardBgCodes */
    public function testStandardBackgroundRoundTripsStable(int $code): void
    {
        $parsed = $this->toStyle->invoke($this->table, (string) $code);
        $this->assertNotNull($parsed->bg(), "SGR {$code} must resolve to a concrete bg");

        $again = $this->roundTrip($parsed);
        $this->assertSame($parsed->bg(), $again->bg());
        $this->assertNull($again->fg());
        $this->assertSame(0, $again->attrs());
    }

    public function testBlackForegroundSurvivesDespiteZeroRgb(): void
    {
        // fg 0x000000 is int 0 — an easy null-vs-0 confusion target in the
        // `fg() !== null` emit guard; pin it explicitly.
        $parsed = $this->toStyle->invoke($this->table, '30');
        $this->assertSame(0x000000, $parsed->fg());
        $this->assertSame(0x000000, $this->roundTrip($parsed)->fg());
    }

    public function testCombinedAttrsAndColorsRoundTripStable(): void
    {
        // bold red-on-white ("1;31;47") — attrs + fg + bg together.
        $parsed = $this->toStyle->invoke($this->table, '1;31;47');
        $again = $this->roundTrip($parsed);

        $this->assertSame($parsed->fg(), $again->fg());
        $this->assertSame($parsed->bg(), $again->bg());
        $this->assertSame($parsed->attrs(), $again->attrs());
        $this->assertSame(Style::ATTR_BOLD, $again->attrs());
    }

    public function test256ColorPaletteMatchesStandardCodes(): void
    {
        // 38;5;1 (256-color red) and SGR 31 must agree on RGB, or the same
        // logical color would render differently depending on input syntax.
        $viaIndexed = $this->toStyle->invoke($this->table, '38;5;1');
        $viaStandard = $this->toStyle->invoke($this->table, '31');
        $this->assertSame($viaStandard->fg(), $viaIndexed->fg());
    }
}
