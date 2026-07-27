<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cell;

/**
 * Tests for CellGrid — 2D cell grid with dirty-region tracking.
 */
final class CellGridTest extends TestCase
{
    public function testConstructorSetsDimensions(): void
    {
        $grid = new CellGrid(80, 24);

        $this->assertSame(80, $grid->cols);
        $this->assertSame(24, $grid->rows);
    }

    public function testGetReturnsEmptyCellForOutOfBounds(): void
    {
        $grid = new CellGrid(10, 10);

        $this->assertSame(' ', $grid->get(-1, 0)->char);
        $this->assertSame(' ', $grid->get(0, -1)->char);
        $this->assertSame(' ', $grid->get(10, 0)->char);
        $this->assertSame(' ', $grid->get(0, 10)->char);
    }

    public function testGetReturnsCellInBounds(): void
    {
        $grid = new CellGrid(3, 3);
        $grid->set(1, 1, new Cell(char: 'X'));

        $this->assertSame('X', $grid->get(1, 1)->char);
    }

    public function testSetUpdatesCell(): void
    {
        $grid = new CellGrid(5, 5);
        $cell = new Cell(char: 'A');

        $result = $grid->set(2, 3, $cell);

        $this->assertSame('A', $grid->get(2, 3)->char);
        $this->assertSame($grid, $result, 'set() returns self for chaining');
    }

    public function testSetIgnoresOutOfBounds(): void
    {
        $grid = new CellGrid(5, 5);
        $cell = new Cell(char: 'X');

        $result = $grid->set(-1, 0, $cell);

        $this->assertSame($grid, $result, 'set() returns self even on OOB');
    }

    public function testDirtyRegionInitiallyMaxValues(): void
    {
        $grid = new CellGrid(10, 10);
        $region = $grid->dirtyRegion();

        $this->assertSame(PHP_INT_MAX, $region['minRow']);
        $this->assertSame(-1, $region['maxRow']);
        $this->assertSame(PHP_INT_MAX, $region['minCol']);
        $this->assertSame(-1, $region['maxCol']);
    }

    public function testDirtyRegionAfterSet(): void
    {
        $grid = new CellGrid(10, 10);
        $grid->set(3, 5, new Cell(char: 'X'));

        $region = $grid->dirtyRegion();

        $this->assertSame(3, $region['minRow']);
        $this->assertSame(3, $region['maxRow']);
        $this->assertSame(5, $region['minCol']);
        $this->assertSame(5, $region['maxCol']);
    }

    public function testDirtyRegionExpandsWithMultipleSets(): void
    {
        $grid = new CellGrid(10, 10);
        $grid->set(2, 3, new Cell(char: 'A'));
        $grid->set(7, 8, new Cell(char: 'B'));

        $region = $grid->dirtyRegion();

        $this->assertSame(2, $region['minRow']);
        $this->assertSame(7, $region['maxRow']);
        $this->assertSame(3, $region['minCol']);
        $this->assertSame(8, $region['maxCol']);
    }

    public function testDirtyRegionWithContiguousRange(): void
    {
        $grid = new CellGrid(20, 10);
        $grid->set(0, 0, new Cell(char: 'A'));
        $grid->set(0, 5, new Cell(char: 'B'));

        $region = $grid->dirtyRegion();

        $this->assertSame(0, $region['minRow']);
        $this->assertSame(0, $region['maxRow']);
        $this->assertSame(0, $region['minCol']);
        $this->assertSame(5, $region['maxCol']);
    }

    public function testClearReturnsNewInstanceWithSameDimensions(): void
    {
        $grid = new CellGrid(15, 20);
        $grid->set(5, 5, new Cell(char: 'X'));

        $cleared = $grid->clear();

        $this->assertSame(15, $cleared->cols);
        $this->assertSame(20, $cleared->rows);
        $this->assertSame(' ', $cleared->get(5, 5)->char);
    }

    public function testClearDoesNotMutateOriginal(): void
    {
        $grid = new CellGrid(10, 10);
        $grid->set(1, 1, new Cell(char: 'X'));

        $grid->clear();

        $this->assertSame('X', $grid->get(1, 1)->char);
    }

    public function testResizeShrinksGridPreservingContent(): void
    {
        $grid = new CellGrid(10, 10);
        $grid->set(4, 4, new Cell(char: 'P'));
        $grid->set(8, 8, new Cell(char: 'Q'));

        $resized = $grid->resize(5, 5);

        $this->assertSame(5, $resized->cols);
        $this->assertSame(5, $resized->rows);
        $this->assertSame('P', $resized->get(4, 4)->char, 'cell within new bounds preserved');
        $this->assertSame(' ', $resized->get(8, 8)->char, 'cell outside new bounds returns empty');
    }

    public function testResizeGrowsGridPreservingContent(): void
    {
        $grid = new CellGrid(3, 3);
        $grid->set(2, 2, new Cell(char: 'Z'));

        $resized = $grid->resize(6, 6);

        $this->assertSame(6, $resized->cols);
        $this->assertSame(6, $resized->rows);
        $this->assertSame('Z', $resized->get(2, 2)->char);
        $this->assertSame(' ', $resized->get(5, 5)->char, 'new cells are empty');
    }

    public function testResizeReturnsNewInstance(): void
    {
        $grid = new CellGrid(10, 10);
        $grid->set(1, 1, new Cell(char: 'X'));

        $resized = $grid->resize(5, 5);

        $this->assertNotSame($grid, $resized);
        $this->assertSame(5, $resized->cols);
        $this->assertSame(5, $resized->rows);
        $this->assertSame('X', $grid->get(1, 1)->char, 'original grid cell preserved');
    }

    public function testEqualsTrueForIdenticalGrids(): void
    {
        $a = new CellGrid(5, 5);
        $a->set(2, 2, new Cell(char: 'X'));

        $b = new CellGrid(5, 5);
        $b->set(2, 2, new Cell(char: 'X'));

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsFalseOnDifferentDimensions(): void
    {
        $a = new CellGrid(5, 5);
        $b = new CellGrid(10, 5);

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseOnDifferentContent(): void
    {
        $a = new CellGrid(5, 5);
        $a->set(1, 1, new Cell(char: 'A'));

        $b = new CellGrid(5, 5);
        $b->set(1, 1, new Cell(char: 'B'));

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseOnEmptyGridVsPopulated(): void
    {
        $a = new CellGrid(5, 5);
        $b = new CellGrid(5, 5);
        $b->set(0, 0, new Cell(char: 'X'));

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsTrueForEmptyGrids(): void
    {
        $a = new CellGrid(80, 24);
        $b = new CellGrid(80, 24);

        $this->assertTrue($a->equals($b));
    }

    public function testSetWithStyledCellTracksDirty(): void
    {
        $grid = new CellGrid(10, 10);
        $styledCell = new Cell(char: 'M', fg: 1, attrs: Cell::ATTR_BOLD);

        $grid->set(4, 4, $styledCell);

        $this->assertSame('M', $grid->get(4, 4)->char);
        $this->assertSame(1, $grid->get(4, 4)->fg);
        $region = $grid->dirtyRegion();
        $this->assertSame(4, $region['minRow']);
        $this->assertSame(4, $region['maxRow']);
    }
}
