<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\Cell;
use SugarCraft\Sprinkles\Style;

final class CellTest extends TestCase
{
    public function testConstruction(): void
    {
        $style = Style::new();
        $cell = new Cell('X', $style);
        $this->assertInstanceOf(Cell::class, $cell);
        $this->assertSame('X', $cell->rune);
        $this->assertSame($style, $cell->style);
    }

    public function testRuneIsString(): void
    {
        $cell = new Cell('a', Style::new());
        $this->assertSame('a', $cell->rune);
    }

    public function testRuneCanBeMultiByte(): void
    {
        $cell = new Cell('世', Style::new());
        $this->assertSame('世', $cell->rune);
    }

    public function testStyleIsPreserved(): void
    {
        $style = Style::new();
        $cell = new Cell('*', $style);
        $this->assertSame('*', $cell->rune);
        $this->assertSame($style, $cell->style);
    }

    public function testReadonlyProperties(): void
    {
        $cell1 = new Cell('A', Style::new());
        $cell2 = new Cell('A', Style::new());
        $this->assertNotSame($cell1, $cell2);
    }
}
