<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Canvas;

use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CanvasTest extends TestCase
{
    public function testEmptyCanvasRendersBlankRows(): void
    {
        $c = new Canvas(4, 2);
        // rtrim trims trailing spaces; empty rows collapse to ''.
        $this->assertSame("\n", $c->view());
    }

    public function testZeroDimensionsRender(): void
    {
        $this->assertSame('', (new Canvas(0, 0))->view());
    }

    public function testSetAndGetCell(): void
    {
        $c = new Canvas(3, 2);
        $c->setCell(1, 0, 'X');
        $this->assertSame('X', $c->getCell(1, 0)->rune);
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testOutOfBoundsSetIsNoOp(): void
    {
        $c = new Canvas(2, 2);
        $c->setCell(5, 5, 'X');
        $c->setCell(-1, 0, 'X');
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testRenderPlainContent(): void
    {
        $c = new Canvas(3, 2);
        $c->setCell(0, 0, 'a');
        $c->setCell(1, 0, 'b');
        $c->setCell(2, 0, 'c');
        $c->setCell(0, 1, 'd');
        $this->assertSame("abc\nd", $c->view());
    }

    public function testStyledCellWrapsWithSgr(): void
    {
        $c = new Canvas(1, 1);
        $c->setCell(0, 0, 'X', Style::new()->foreground(Color::hex('#ff0000')));
        $this->assertStringContainsString("\x1b[", $c->view());
        $this->assertStringContainsString('X', $c->view());
    }

    public function testClearResetsCells(): void
    {
        $c = new Canvas(2, 1);
        $c->setCell(0, 0, 'X');
        $c->clear();
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testNegativeDimensionsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Canvas(-1, 1);
    }

    public function testNegativeHeightRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Canvas(1, -1);
    }

    // ─── setCellStyle Tests ───────────────────────────────────────────────

    public function testSetCellStyleUpdatesStyleOnly(): void
    {
        $c = new Canvas(2, 1);
        $c->setCell(0, 0, 'X');
        $c->setCellStyle(0, 0, Style::new()->bold());
        $this->assertSame('X', $c->getCell(0, 0)->rune);
        $this->assertNotNull($c->getCellStyle(0, 0));
    }

    public function testSetCellStyleOutOfBoundsNoOp(): void
    {
        $c = new Canvas(2, 2);
        $c->setCellStyle(5, 5, Style::new()->bold());
        $c->setCellStyle(-1, 0, Style::new()->bold());
        // Should not throw
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testGetCellStyleReturnsNullWhenUnset(): void
    {
        $c = new Canvas(2, 2);
        $this->assertNull($c->getCellStyle(0, 0));
    }

    // ─── setRunes Tests ───────────────────────────────────────────────────

    public function testSetRunesPlacesMultipleRunes(): void
    {
        $c = new Canvas(5, 1);
        $c->setRunes(0, 0, ['a', 'b', 'c']);
        $this->assertSame('a', $c->getCell(0, 0)->rune);
        $this->assertSame('b', $c->getCell(1, 0)->rune);
        $this->assertSame('c', $c->getCell(2, 0)->rune);
    }

    public function testSetRunesWithGenerator(): void
    {
        $c = new Canvas(5, 1);
        $gen = function () {
            yield 'x';
            yield 'y';
        };
        $c->setRunes(1, 0, $gen());
        $this->assertSame('x', $c->getCell(1, 0)->rune);
        $this->assertSame('y', $c->getCell(2, 0)->rune);
    }

    // ─── setString Tests ──────────────────────────────────────────────────

    public function testSetStringPlacesMultibyteString(): void
    {
        $c = new Canvas(5, 1);
        $c->setString(0, 0, 'abc');
        $this->assertSame('a', $c->getCell(0, 0)->rune);
        $this->assertSame('b', $c->getCell(1, 0)->rune);
        $this->assertSame('c', $c->getCell(2, 0)->rune);
    }

    public function testSetStringTruncatesOutOfBounds(): void
    {
        $c = new Canvas(2, 1);
        $c->setString(0, 0, 'abcdef');
        $this->assertSame('a', $c->getCell(0, 0)->rune);
        $this->assertSame('b', $c->getCell(1, 0)->rune);
        $this->assertSame(' ', $c->getCell(2, 0)->rune);
    }

    // ─── setLines Tests ──────────────────────────────────────────────────

    public function testSetLinesPlacesMultipleRows(): void
    {
        $c = new Canvas(5, 3);
        $c->setLines(0, 0, ['first', 'second', 'third']);
        $this->assertSame('f', $c->getCell(0, 0)->rune);
        $this->assertSame('s', $c->getCell(0, 1)->rune);
        $this->assertSame('t', $c->getCell(0, 2)->rune);
    }

    // ─── fill Tests ──────────────────────────────────────────────────────

    public function testFillRectangle(): void
    {
        $c = new Canvas(4, 3);
        $c->fill(0, 0, 2, 1, '#');
        $this->assertSame('#', $c->getCell(0, 0)->rune);
        $this->assertSame('#', $c->getCell(2, 0)->rune);
        $this->assertSame('#', $c->getCell(0, 1)->rune);
        $this->assertSame('#', $c->getCell(2, 1)->rune);
        $this->assertSame(' ', $c->getCell(3, 0)->rune);
    }

    public function testFillSwappedCoordinates(): void
    {
        $c = new Canvas(4, 3);
        $c->fill(2, 1, 0, 0, '#'); // x0 > x1, y0 > y1 - should auto-swap
        $this->assertSame('#', $c->getCell(0, 0)->rune);
        $this->assertSame('#', $c->getCell(2, 1)->rune);
    }

    public function testFillClampsToBounds(): void
    {
        $c = new Canvas(3, 3);
        $c->fill(-5, -5, 100, 100, '#'); // Should clamp to canvas bounds
        $this->assertSame('#', $c->getCell(0, 0)->rune);
        $this->assertSame('#', $c->getCell(2, 2)->rune);
    }

    public function testFillLine(): void
    {
        $c = new Canvas(5, 3);
        $c->fillLine(1, '-');
        $this->assertSame('-', $c->getCell(0, 1)->rune);
        $this->assertSame('-', $c->getCell(4, 1)->rune);
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    public function testFillLineOutOfBoundsNoOp(): void
    {
        $c = new Canvas(3, 3);
        $c->fillLine(10, '-');
        $c->fillLine(-1, '-');
        // Should not throw
        $this->assertSame(' ', $c->getCell(0, 0)->rune);
    }

    // ─── Shift Tests ──────────────────────────────────────────────────────

    public function testShiftDown(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftDown(1);
        $this->assertSame(' ', $c->getCell(1, 1)->rune);
        $this->assertSame('X', $c->getCell(1, 2)->rune);
    }

    public function testShiftDownZeroOrNegativeNoOp(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftDown(0);
        $c->shiftDown(-1);
        $this->assertSame('X', $c->getCell(1, 1)->rune);
    }

    public function testShiftUp(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftUp(1);
        $this->assertSame(' ', $c->getCell(1, 1)->rune);
        $this->assertSame('X', $c->getCell(1, 0)->rune);
    }

    public function testShiftUpZeroOrNegativeNoOp(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftUp(0);
        $c->shiftUp(-1);
        $this->assertSame('X', $c->getCell(1, 1)->rune);
    }

    public function testShiftLeft(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftLeft(1);
        $this->assertSame(' ', $c->getCell(1, 1)->rune);
        $this->assertSame('X', $c->getCell(0, 1)->rune);
    }

    public function testShiftLeftZeroOrNegativeNoOp(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftLeft(0);
        $c->shiftLeft(-1);
        $this->assertSame('X', $c->getCell(1, 1)->rune);
    }

    public function testShiftRight(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftRight(1);
        $this->assertSame(' ', $c->getCell(1, 1)->rune);
        $this->assertSame('X', $c->getCell(2, 1)->rune);
    }

    public function testShiftRightZeroOrNegativeNoOp(): void
    {
        $c = new Canvas(3, 3);
        $c->setCell(1, 1, 'X');
        $c->shiftRight(0);
        $c->shiftRight(-1);
        $this->assertSame('X', $c->getCell(1, 1)->rune);
    }

    // ─── GetCell Out-of-Bounds Tests ───────────────────────────────────────

    public function testGetCellOutOfBoundsReturnsPlaceholder(): void
    {
        $c = new Canvas(2, 2);
        $cell = $c->getCell(10, 10);
        $this->assertSame('', $cell->rune);

        $cell2 = $c->getCell(-1, -1);
        $this->assertSame('', $cell2->rune);
    }
}
