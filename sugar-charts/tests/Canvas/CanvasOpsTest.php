<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Canvas;

use CandyCore\Charts\Canvas\Canvas;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the new Canvas mutators added in audit #15:
 * setCellStyle / getCellStyle / setRunes / setString / setLines /
 * fill / fillLine / shiftUp / shiftDown / shiftLeft / shiftRight.
 */
final class CanvasOpsTest extends TestCase
{
    public function testSetStringPlacesGlyphsLeftToRight(): void
    {
        $c = new Canvas(10, 1);
        $c->setString(0, 0, 'hello');
        $this->assertSame('hello', $c->view());
    }

    public function testSetLinesStacksRows(): void
    {
        $c = new Canvas(5, 3);
        $c->setLines(0, 0, ['ab', 'cd', 'ef']);
        $this->assertSame("ab\ncd\nef", $c->view());
    }

    public function testFillPaintsRectangle(): void
    {
        $c = new Canvas(4, 3);
        $c->fill(1, 0, 2, 1, '#');
        $rows = explode("\n", $c->view());
        $this->assertSame(' ##', $rows[0]);
        $this->assertSame(' ##', $rows[1]);
        $this->assertSame('',     $rows[2]); // bottom row untouched, rtrim'd
    }

    public function testFillLineFillsOneRow(): void
    {
        $c = new Canvas(4, 2);
        $c->fillLine(0, '-');
        $rows = explode("\n", $c->view());
        $this->assertSame('----', $rows[0]);
        $this->assertSame('',     $rows[1]);
    }

    public function testShiftDownMovesRows(): void
    {
        $c = new Canvas(3, 3);
        $c->setString(0, 0, 'abc');
        $c->shiftDown(1);
        $rows = explode("\n", $c->view());
        $this->assertSame('',    $rows[0]);
        $this->assertSame('abc', $rows[1]);
    }

    public function testShiftUpMovesRows(): void
    {
        $c = new Canvas(3, 3);
        $c->setString(0, 2, 'xyz');
        $c->shiftUp(1);
        $rows = explode("\n", $c->view());
        $this->assertSame('xyz', $rows[1]);
        $this->assertSame('',    $rows[2]);
    }

    public function testShiftRightDropsLeftEdge(): void
    {
        $c = new Canvas(5, 1);
        $c->setString(0, 0, 'abcde');
        $c->shiftRight(2);
        $rows = explode("\n", $c->view());
        $this->assertSame('  abc', $rows[0]);
    }

    public function testShiftLeftDropsRightEdge(): void
    {
        $c = new Canvas(5, 1);
        $c->setString(0, 0, 'abcde');
        $c->shiftLeft(2);
        $rows = explode("\n", $c->view());
        $this->assertSame('cde', rtrim($rows[0]));
    }

    public function testCellStyleAccessors(): void
    {
        $c = new Canvas(2, 1);
        $c->setCell(0, 0, 'X');
        $this->assertNull($c->getCellStyle(0, 0));
        $style = \CandyCore\Sprinkles\Style::new()->bold();
        $c->setCellStyle(0, 0, $style);
        $this->assertSame($style, $c->getCellStyle(0, 0));
    }

    public function testSetRunesPlacesIterable(): void
    {
        $c = new Canvas(4, 1);
        $c->setRunes(0, 0, ['a', 'b', 'c', 'd']);
        $this->assertSame('abcd', $c->view());
    }
}
