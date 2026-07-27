<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use SugarCraft\Palette\StandardColors;
use SugarCraft\Palette\Color;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Palette\StandardColors
 */
final class StandardColorsTest extends TestCase
{
    public function testAllReturnsArrayOf16Colors(): void
    {
        $all = StandardColors::all();

        $this->assertCount(16, $all);
        $this->assertContainsOnlyInstancesOf(Color::class, $all);
    }

    public function testAllReturnsSameInstanceOnRepeatedCalls(): void
    {
        $first = StandardColors::all();
        $second = StandardColors::all();

        $this->assertSame($first, $second);
    }

    public function testFromIndexReturnsColorForValidIndex(): void
    {
        $color = StandardColors::fromIndex(0);

        $this->assertInstanceOf(Color::class, $color);
    }

    /**
     * @dataProvider validIndexProvider
     */
    public function testFromIndexReturnsValidColorForIndex(int $index): void
    {
        $color = StandardColors::fromIndex($index);

        $this->assertInstanceOf(Color::class, $color);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function validIndexProvider(): array
    {
        return [
            'index 0' => [0],
            'index 1' => [1],
            'index 7' => [7],
            'index 8' => [8],
            'index 15' => [15],
        ];
    }

    public function testFromIndexThrowsOutOfBoundsForNegativeIndex(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        StandardColors::fromIndex(-1);
    }

    public function testFromIndexThrowsOutOfBoundsForIndexAbove15(): void
    {
        $this->expectException(\OutOfBoundsException::class);

        StandardColors::fromIndex(16);
    }

    public function testCatalogReturnsListOf16ColorNames(): void
    {
        $catalog = StandardColors::catalog();

        $this->assertCount(16, $catalog);
        $this->assertContainsOnly('string', $catalog);
    }

    public function testCatalogReturnsExpectedColorNames(): void
    {
        $catalog = StandardColors::catalog();

        $expected = [
            'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white',
            'brightBlack', 'brightRed', 'brightGreen', 'brightYellow',
            'brightBlue', 'brightMagenta', 'brightCyan', 'brightWhite',
        ];

        $this->assertSame($expected, $catalog);
    }

    public function testBasicColorsAreInitialized(): void
    {
        $this->assertInstanceOf(Color::class, StandardColors::$black);
        $this->assertInstanceOf(Color::class, StandardColors::$red);
        $this->assertInstanceOf(Color::class, StandardColors::$green);
        $this->assertInstanceOf(Color::class, StandardColors::$yellow);
        $this->assertInstanceOf(Color::class, StandardColors::$blue);
        $this->assertInstanceOf(Color::class, StandardColors::$magenta);
        $this->assertInstanceOf(Color::class, StandardColors::$cyan);
        $this->assertInstanceOf(Color::class, StandardColors::$white);
    }

    public function testBrightColorsAreInitialized(): void
    {
        $this->assertInstanceOf(Color::class, StandardColors::$brightBlack);
        $this->assertInstanceOf(Color::class, StandardColors::$brightRed);
        $this->assertInstanceOf(Color::class, StandardColors::$brightGreen);
        $this->assertInstanceOf(Color::class, StandardColors::$brightYellow);
        $this->assertInstanceOf(Color::class, StandardColors::$brightBlue);
        $this->assertInstanceOf(Color::class, StandardColors::$brightMagenta);
        $this->assertInstanceOf(Color::class, StandardColors::$brightCyan);
        $this->assertInstanceOf(Color::class, StandardColors::$brightWhite);
    }

    public function testAllColorsAreUnique(): void
    {
        $all = StandardColors::all();
        $values = array_map(fn(Color $c) => $c->toHex(), $all);

        $unique = array_unique($values);

        $this->assertCount(16, $unique, 'All 16 standard colors must be unique');
    }

    public function testBlackIsRGBZero(): void
    {
        $black = StandardColors::$black;

        $this->assertSame(0x00, $black->r);
        $this->assertSame(0x00, $black->g);
        $this->assertSame(0x00, $black->b);
    }

    public function testWhiteIsExpectedRGB(): void
    {
        $white = StandardColors::$white;

        // Standard ANSI white is typically #e5e5e5
        $this->assertSame(0xe5, $white->r);
        $this->assertSame(0xe5, $white->g);
        $this->assertSame(0xe5, $white->b);
    }

    public function testBrightBlackIsGrey(): void
    {
        $grey = StandardColors::$brightBlack;

        // Bright black (grey) should have equal RGB components
        $this->assertSame($grey->r, $grey->g);
        $this->assertSame($grey->g, $grey->b);
    }

    public function testBrightWhiteIsMaximumRGB(): void
    {
        $brightWhite = StandardColors::$brightWhite;

        $this->assertSame(0xff, $brightWhite->r);
        $this->assertSame(0xff, $brightWhite->g);
        $this->assertSame(0xff, $brightWhite->b);
    }

    public function testAllContainsAllCatalogColors(): void
    {
        $all = StandardColors::all();
        $catalog = StandardColors::catalog();

        $this->assertCount(count($catalog), $all);
    }
}
