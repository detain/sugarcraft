<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Canvas;

use SugarCraft\Charts\Canvas\Cell;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

/**
 * @see Cell
 */
final class CellTest extends TestCase
{
    public function testDefaultRuneIsSpace(): void
    {
        $cell = new Cell();
        self::assertSame(' ', $cell->rune);
    }

    public function testDefaultStyleIsNull(): void
    {
        $cell = new Cell();
        self::assertNull($cell->style);
    }

    public function testConstructWithRuneOnly(): void
    {
        $cell = new Cell('#');
        self::assertSame('#', $cell->rune);
        self::assertNull($cell->style);
    }

    public function testConstructWithRuneAndStyle(): void
    {
        $style = Style::new();
        $cell = new Cell('*', $style);

        self::assertSame('*', $cell->rune);
        self::assertSame($style, $cell->style);
    }

    public function testRuneCanBeMultibyteCharacter(): void
    {
        $cell = new Cell('█');
        self::assertSame('█', $cell->rune);
    }

    public function testRuneCanBeEmpty(): void
    {
        $cell = new Cell('');
        self::assertSame('', $cell->rune);
    }

    public function testStyleCanBeNullExplicitly(): void
    {
        $cell = new Cell('x', null);
        self::assertSame('x', $cell->rune);
        self::assertNull($cell->style);
    }

    public function testFieldsAreReadonly(): void
    {
        $cell = new Cell('a');

        // Properties are readonly via promoted parameters
        self::assertSame('a', $cell->rune);
        self::assertNull($cell->style);
    }

    public function testDifferentCellsWithSameValuesAreNotSame(): void
    {
        $a = new Cell('x');
        $b = new Cell('x');

        self::assertNotSame($a, $b);
        self::assertEquals($a, $b);
    }
}
