<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Cell;
use SugarCraft\Dash\Foundation\Color;
use SugarCraft\Dash\Foundation\Style;

final class CellTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Construction
    // ═══════════════════════════════════════════════════════════════

    public function testConstructorSetsRune(): void
    {
        $style = new Style();
        $cell = new Cell('X', $style);

        $this->assertSame('X', $cell->rune);
    }

    public function testConstructorSetsStyle(): void
    {
        $style = new Style();
        $cell = new Cell('X', $style);

        $this->assertSame($style, $cell->style);
    }

    // ═══════════════════════════════════════════════════════════════
    // Immutability
    // ═══════════════════════════════════════════════════════════════

    public function testCellIsReadonly(): void
    {
        $style = new Style();
        $cell = new Cell('X', $style);

        // A readonly class instance equals itself
        $this->assertSame($cell, $cell);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyRune(): void
    {
        $style = new Style();
        $cell = new Cell('', $style);

        $this->assertSame('', $cell->rune);
        $this->assertSame($style, $cell->style);
    }

    public function testMultiByteRune(): void
    {
        $style = new Style();
        $cell = new Cell('日本語', $style);

        $this->assertSame('日本語', $cell->rune);
    }

    public function testEmojiRune(): void
    {
        $style = new Style();
        $cell = new Cell('🎉', $style);

        $this->assertSame('🎉', $cell->rune);
    }

    public function testNullForegroundStyle(): void
    {
        $style = new Style(foreground: null);
        $cell = new Cell('X', $style);

        $this->assertNull($cell->style->foreground);
    }

    public function testNullBackgroundStyle(): void
    {
        $style = new Style(background: null);
        $cell = new Cell('X', $style);

        $this->assertNull($cell->style->background);
    }

    // ═══════════════════════════════════════════════════════════════
    // Equality
    // ═══════════════════════════════════════════════════════════════

    public function testSameValuesAreEqual(): void
    {
        $style = new Style();
        $cell1 = new Cell('X', $style);
        $cell2 = new Cell('X', $style);

        // Both cells have identical state
        $this->assertSame($cell1->rune, $cell2->rune);
        $this->assertSame($cell1->style, $cell2->style);
    }

    public function testDifferentRunesNotEqual(): void
    {
        $style = new Style();
        $cellA = new Cell('A', $style);
        $cellB = new Cell('B', $style);

        $this->assertNotSame($cellA->rune, $cellB->rune);
    }

    public function testDifferentStylesNotEqual(): void
    {
        $styleA = new Style();
        $styleB = new Style(bold: true);
        $cellA = new Cell('X', $styleA);
        $cellB = new Cell('X', $styleB);

        $this->assertNotSame($cellA->style, $cellB->style);
    }

    public function testCellWithStyledForeground(): void
    {
        $color = Color::hex('#FF0000');
        $style = new Style(foreground: $color);
        $cell = new Cell('R', $style);

        $this->assertSame('R', $cell->rune);
        $this->assertNotNull($cell->style->foreground);
        $this->assertSame($color, $cell->style->foreground);
    }

    public function testCellWithStyledBackground(): void
    {
        $color = Color::hex('#0000FF');
        $style = new Style(background: $color);
        $cell = new Cell('B', $style);

        $this->assertSame('B', $cell->rune);
        $this->assertNotNull($cell->style->background);
        $this->assertSame($color, $cell->style->background);
    }

    public function testCellWithBoldStyle(): void
    {
        $style = new Style(bold: true);
        $cell = new Cell('X', $style);

        $this->assertTrue($cell->style->bold);
    }

    public function testCellWithComplexStyle(): void
    {
        $fg = Color::hex('#FFFFFF');
        $bg = Color::hex('#000000');
        $style = new Style(
            foreground: $fg,
            background: $bg,
            bold: true,
            italic: true,
            underline: true,
        );
        $cell = new Cell('S', $style);

        $this->assertSame('S', $cell->rune);
        $this->assertSame($fg, $cell->style->foreground);
        $this->assertSame($bg, $cell->style->background);
        $this->assertTrue($cell->style->bold);
        $this->assertTrue($cell->style->italic);
        $this->assertTrue($cell->style->underline);
    }
}
