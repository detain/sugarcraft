<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region;
use SugarCraft\Buffer\Style;

final class BufferTest extends TestCase
{
    public function testNew(): void
    {
        $buf = Buffer::new(20, 3);

        $this->assertSame(20, $buf->width());
        $this->assertSame(3, $buf->height());

        $region = $buf->region();
        $this->assertSame(0, $region->origin->col());
        $this->assertSame(0, $region->origin->row());
        $this->assertSame(20, $region->width());
        $this->assertSame(3, $region->height());
    }

    public function testNewFillsWithBlankCells(): void
    {
        $buf = Buffer::new(3, 2);

        // All cells should be blank
        $this->assertSame(' ', $buf->cellAt(0, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(1, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(2, 1)->rune());
    }

    public function testNewNegativeDimensionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::new(-1, 5);
    }

    public function testNewZeroDimensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::new(0, 5);
    }

    public function testCellAtBoundsCheckColUnderflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(-1, 0);
    }

    public function testCellAtBoundsCheckColOverflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(10, 0);
    }

    public function testCellAtBoundsCheckRowUnderflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(0, -1);
    }

    public function testCellAtBoundsCheckRowOverflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(0, 3);
    }

    public function testCellAtAllCorners(): void
    {
        $buf = Buffer::new(10, 3);

        // No exception means bounds check passes
        $buf->cellAt(0, 0);
        $buf->cellAt(9, 0);
        $buf->cellAt(0, 2);
        $buf->cellAt(9, 2);

        $this->assertTrue(true); // reached here means no exceptions
    }

    public function testWithCellAtReturnsNewInstance(): void
    {
        $buf = Buffer::new(10, 3);
        $original = $buf;
        $cell = Cell::new('X', Style::bold());

        $newBuf = $buf->withCellAt(5, 1, $cell);

        $this->assertNotSame($buf, $newBuf);
        // Original unchanged
        $this->assertSame(' ', $original->cellAt(5, 1)->rune());
        // New buffer has the cell
        $this->assertSame('X', $newBuf->cellAt(5, 1)->rune());
        $this->assertTrue($newBuf->cellAt(5, 1)->style()->hasBold());
    }

    public function testWithCellAtImmutability(): void
    {
        $buf = Buffer::new(5, 2);
        $original = $buf;
        $newBuf = $buf->withCellAt(2, 1, Cell::new('A'));

        // Original must be unchanged
        $this->assertSame(' ', $original->cellAt(2, 1)->rune());
        // New must have the change
        $this->assertSame('A', $newBuf->cellAt(2, 1)->rune());
    }

    public function testWithCellAtStyleNullDefault(): void
    {
        $buf = Buffer::new(5, 2);
        $cell = Cell::new('X');

        $newBuf = $buf->withCellAt(1, 0, $cell);

        $this->assertNull($newBuf->cellAt(1, 0)->style());
    }

    public function testWithCellAtLinkNullDefault(): void
    {
        $buf = Buffer::new(5, 2);
        $cell = Cell::new('X', Style::new());

        $newBuf = $buf->withCellAt(1, 0, $cell);

        $this->assertNull($newBuf->cellAt(1, 0)->link());
    }

    public function testWithCellAtBoundsCheck(): void
    {
        $buf = Buffer::new(5, 2);

        $this->expectException(\OutOfRangeException::class);
        $buf->withCellAt(5, 0, Cell::new('X'));
    }

    public function testWithRegionBlitAtOrigin(): void
    {
        $buf = Buffer::new(10, 3);
        $sub = Buffer::new(2, 2);
        $sub = $sub->withCellAt(0, 0, Cell::new('A'))
                  ->withCellAt(1, 0, Cell::new('B'))
                  ->withCellAt(0, 1, Cell::new('C'))
                  ->withCellAt(1, 1, Cell::new('D'));

        $region = new Region(Position::new(0, 0), 2, 2);
        $result = $buf->withRegion($region, $sub);

        $this->assertSame('A', $result->cellAt(0, 0)->rune());
        $this->assertSame('B', $result->cellAt(1, 0)->rune());
        $this->assertSame('C', $result->cellAt(0, 1)->rune());
        $this->assertSame('D', $result->cellAt(1, 1)->rune());
    }

    public function testWithRegionBlitAtOffset(): void
    {
        $buf = Buffer::new(10, 5);
        $sub = Buffer::new(2, 2);
        $sub = $sub->withCellAt(0, 0, Cell::new('X'))
                  ->withCellAt(1, 1, Cell::new('Y'));

        $region = new Region(Position::new(3, 2), 2, 2);
        $result = $buf->withRegion($region, $sub);

        $this->assertSame('X', $result->cellAt(3, 2)->rune());
        $this->assertSame('Y', $result->cellAt(4, 3)->rune());
    }

    public function testWithRegionClippedAtEdges(): void
    {
        // Source is 3x3, but region only has room for 2x2 at offset
        $buf = Buffer::new(5, 5);
        $sub = Buffer::new(3, 3);
        $sub = $sub->withCellAt(0, 0, Cell::new('S'));

        // Region at bottom-right that would overflow a 5x5 buffer
        $region = new Region(Position::new(4, 4), 2, 2);
        $result = $buf->withRegion($region, $sub);

        // Should not throw; S is at (4,4) which is in bounds
        $this->assertSame('S', $result->cellAt(4, 4)->rune());
    }

    public function testWithRegionSourceSmallerThanRegion(): void
    {
        // Region is 5x5 but source is only 2x2
        // When source coords exceed source bounds, we skip (continue)
        $buf = Buffer::new(10, 10);
        $source = Buffer::new(2, 2);
        $source = $source->withCellAt(0, 0, Cell::new('S'))
                         ->withCellAt(1, 1, Cell::new('T'));

        $region = new Region(Position::new(0, 0), 5, 5);
        $result = $buf->withRegion($region, $source);

        // Only (0,0) and (1,1) should be copied from source
        // All other cells in the 5x5 region remain blank
        $this->assertSame('S', $result->cellAt(0, 0)->rune());
        $this->assertSame('T', $result->cellAt(1, 1)->rune());
        $this->assertSame(' ', $result->cellAt(2, 0)->rune()); // src coords (2,0) >= source dims
        $this->assertSame(' ', $result->cellAt(0, 2)->rune()); // src coords (0,2) >= source dims
    }

    public function testWithRegionNoOpWhenIdentical(): void
    {
        $buf = Buffer::new(3, 2);
        $same = Buffer::new(3, 2);

        $region = new Region(Position::new(0, 0), 3, 2);
        $result = $buf->withRegion($region, $same);

        // All cells should be blank since source is blank
        $this->assertSame(' ', $result->cellAt(0, 0)->rune());
    }

    public function testDiffReturnsEmptyArray(): void
    {
        $buf = Buffer::new(5, 2);
        $other = Buffer::new(5, 2);

        $diff = $buf->diff($other);

        $this->assertIsArray($diff);
        $this->assertEmpty($diff);
    }

    public function testDiffDoesNotThrow(): void
    {
        $buf = Buffer::new(5, 2);
        $other = Buffer::new(5, 2)->withCellAt(2, 1, Cell::new('X'));

        // Should not throw even though buffers differ — actual diff
        // is step-26's job
        $diff = $buf->diff($other);

        $this->assertIsArray($diff);
    }

    public function testRegionReturnsFullBounds(): void
    {
        $buf = Buffer::new(15, 7);

        $region = $buf->region();

        // region() always starts at (0, 0) and spans the full buffer
        $this->assertSame(0, $region->origin->col());
        $this->assertSame(0, $region->origin->row());
        $this->assertSame(15, $region->width());
        $this->assertSame(7, $region->height());
    }

    public function testWideCharThenContinuation(): void
    {
        $buf = Buffer::new(10, 2);
        $wide = Cell::new('中', null, null, 2);
        $continuation = Cell::continuation();

        $buf = $buf->withCellAt(0, 0, $wide)
                   ->withCellAt(1, 0, $continuation);

        $this->assertSame('中', $buf->cellAt(0, 0)->rune());
        $this->assertSame(2, $buf->cellAt(0, 0)->width());
        $this->assertSame('', $buf->cellAt(1, 0)->rune());
        $this->assertSame(0, $buf->cellAt(1, 0)->width());
    }
}
