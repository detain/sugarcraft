<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Cell;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\Style;
use SugarCraft\Dash\Foundation\Rect;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\ColorProfile;

final class BufferTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Snapshot tests
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyBuffer(): void
    {
        $buffer = Buffer::new(5, 3);

        // Empty 5x3 buffer renders as 3 lines of 5 spaces
        $expected = "     \n     \n     ";
        $this->assertSame($expected, $buffer->render());
    }

    public function testRenderWithCells(): void
    {
        $buffer = Buffer::new(5, 3)
            ->setCell(0, 0, new Cell('H', new Style()))
            ->setCell(1, 0, new Cell('i', new Style()));

        // 5x3 buffer: Hi at (0,0) and (1,0), rest spaces
        $expected = "Hi   \n     \n     ";
        $this->assertSame($expected, $buffer->render());
    }

    public function testRenderWithMixedStyles(): void
    {
        $redStyle = (new Style())->withForeground(Color::hex('#FF0000'));
        $blueStyle = (new Style())->withForeground(Color::hex('#0000FF'));

        $buffer = Buffer::new(5, 1)
            ->setCell(0, 0, new Cell('A', $redStyle))
            ->setCell(1, 0, new Cell('B', $blueStyle));

        $rendered = $buffer->render();

        // Should contain ANSI sequences for color changes
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[3[0-9;]*m/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Behavior tests - withers return new instances
    // ═══════════════════════════════════════════════════════════════

    public function testSetCellReturnsNewInstance(): void
    {
        $original = Buffer::new(3, 3);
        $modified = $original->setCell(0, 0, new Cell('X', new Style()));

        $this->assertNotSame($original, $modified);
        // Original should be unchanged (empty cell renders as space)
        $this->assertSame(' ', $original->getCell(0, 0)->rune);
        // Modified should have the new cell
        $this->assertSame('X', $modified->getCell(0, 0)->rune);
    }

    public function testFillReturnsNewInstance(): void
    {
        $original = Buffer::new(3, 3);
        $modified = $original->fill(new Rect(0, 0, 2, 2), new Cell('#', new Style()));

        $this->assertNotSame($original, $modified);
        // Original should be unchanged (empty cells render as space)
        $this->assertSame(' ', $original->getCell(0, 0)->rune);
        // Modified should have the filled cells
        $this->assertSame('#', $modified->getCell(0, 0)->rune);
        $this->assertSame('#', $modified->getCell(2, 2)->rune);
    }

    public function testSetStringReturnsNewInstance(): void
    {
        $original = Buffer::new(5, 3);
        $modified = $original->setString(0, 0, 'hello');

        $this->assertNotSame($original, $modified);
        // Original should be unchanged
        $this->assertSame(' ', $original->getCell(0, 0)->rune);
        // Modified should have the string
        $this->assertSame('h', $modified->getCell(0, 0)->rune);
        $this->assertSame('e', $modified->getCell(1, 0)->rune);
    }

    public function testClearReturnsNewInstance(): void
    {
        $original = Buffer::new(3, 3)
            ->setString(0, 0, 'abc');
        $cleared = $original->clear();

        $this->assertNotSame($original, $cleared);
        // Original should still have the string
        $this->assertSame('a', $original->getCell(0, 0)->rune);
        // Cleared should have empty cells
        $this->assertSame(' ', $cleared->getCell(0, 0)->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // Coercion tests - edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testGetCellOutOfBoundsThrowsException(): void
    {
        $buffer = Buffer::new(3, 3);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/out of bounds/');
        $buffer->getCell(10, 0);
    }

    public function testGetCellNegativeCoordinatesThrowsException(): void
    {
        $buffer = Buffer::new(3, 3);

        $this->expectException(\OutOfBoundsException::class);
        $buffer->getCell(-1, 0);
    }

    public function testSetCellOutOfBoundsThrowsException(): void
    {
        $buffer = Buffer::new(3, 3);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessageMatches('/out of bounds/');
        $buffer->setCell(10, 0, new Cell('X', new Style()));
    }

    public function testSetStringTruncatesAtEdge(): void
    {
        // Width 3 buffer, string "hello" (5 chars) starting at x=0
        $buffer = Buffer::new(3, 3)->setString(0, 0, 'hello');

        // Only first 3 characters fit
        $this->assertSame('h', $buffer->getCell(0, 0)->rune);
        $this->assertSame('e', $buffer->getCell(1, 0)->rune);
        $this->assertSame('l', $buffer->getCell(2, 0)->rune);
        // Position beyond width throws OutOfBoundsException
        $this->expectException(\OutOfBoundsException::class);
        $buffer->getCell(3, 0);
    }

    public function testFillRectExceedingBoundsClipsCorrectly(): void
    {
        // 3x3 buffer, fill rect that exceeds bounds (0,0 to 5,5)
        $buffer = Buffer::new(3, 3)
            ->fill(new Rect(0, 0, 5, 5), new Cell('#', new Style()));

        // Should fill all cells within bounds (0,0 through 2,2)
        $this->assertSame('#', $buffer->getCell(0, 0)->rune);
        $this->assertSame('#', $buffer->getCell(2, 2)->rune);
        // Position outside height throws
        $this->expectException(\OutOfBoundsException::class);
        $buffer->getCell(0, 3);
    }

    public function testClearOnEmptyBuffer(): void
    {
        $buffer = Buffer::new(3, 3);
        $cleared = $buffer->clear();

        // Should return a new instance with all empty cells
        $this->assertNotSame($buffer, $cleared);
        $this->assertSame(' ', $cleared->getCell(0, 0)->rune);
        $this->assertSame(' ', $cleared->getCell(2, 2)->rune);
    }

    public function testSetStringOutOfBoundsReturnsSameInstance(): void
    {
        // setString with x out of bounds returns $this (no-op)
        $buffer = Buffer::new(3, 3);
        $result = $buffer->setString(5, 0, 'test');

        // Returns same instance (not cloned since it's a no-op)
        $this->assertSame($buffer, $result);
    }

    public function testSetStringNegativeCoordinatesReturnsSameInstance(): void
    {
        $buffer = Buffer::new(3, 3);
        $result = $buffer->setString(-1, 0, 'test');

        // Returns same instance (no-op)
        $this->assertSame($buffer, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell-level mutations
    // ═══════════════════════════════════════════════════════════════

    public function testSetCellAndGetCell(): void
    {
        $buffer = Buffer::new(3, 3)
            ->setCell(1, 1, new Cell('X', new Style()));

        $cell = $buffer->getCell(1, 1);
        $this->assertSame('X', $cell->rune);
    }

    public function testFillWritesAllCellsInRect(): void
    {
        $buffer = Buffer::new(5, 5)
            ->fill(new Rect(1, 1, 3, 3), new Cell('#', new Style()));

        // Verify all 9 cells in the 3x3 region (1,1 to 3,3)
        $this->assertSame('#', $buffer->getCell(1, 1)->rune);
        $this->assertSame('#', $buffer->getCell(2, 1)->rune);
        $this->assertSame('#', $buffer->getCell(3, 1)->rune);
        $this->assertSame('#', $buffer->getCell(1, 2)->rune);
        $this->assertSame('#', $buffer->getCell(2, 2)->rune);
        $this->assertSame('#', $buffer->getCell(3, 2)->rune);
        $this->assertSame('#', $buffer->getCell(1, 3)->rune);
        $this->assertSame('#', $buffer->getCell(2, 3)->rune);
        $this->assertSame('#', $buffer->getCell(3, 3)->rune);

        // Cells outside should be empty
        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
        $this->assertSame(' ', $buffer->getCell(4, 4)->rune);
    }

    public function testSetStringWritesCellsHorizontally(): void
    {
        $buffer = Buffer::new(10, 3)->setString(0, 0, 'hello');

        // Each character should be in its own cell
        $this->assertSame('h', $buffer->getCell(0, 0)->rune);
        $this->assertSame('e', $buffer->getCell(1, 0)->rune);
        $this->assertSame('l', $buffer->getCell(2, 0)->rune);
        $this->assertSame('l', $buffer->getCell(3, 0)->rune);
        $this->assertSame('o', $buffer->getCell(4, 0)->rune);
        // Rest should be empty
        $this->assertSame(' ', $buffer->getCell(5, 0)->rune);
    }

    public function testFillWithStyleIsPreserved(): void
    {
        $redStyle = (new Style())->withForeground(Color::hex('#FF0000'));
        $buffer = Buffer::new(3, 3)
            ->fill(new Rect(0, 0, 2, 2), new Cell('#', $redStyle));

        $cell = $buffer->getCell(0, 0);
        $this->assertSame('#', $cell->rune);
        $this->assertNotNull($cell->style->foreground);
    }

    // ═══════════════════════════════════════════════════════════════
    // Drawable interface
    // ═══════════════════════════════════════════════════════════════

    public function testImplementsDrawable(): void
    {
        $buffer = Buffer::new(3, 3);
        $this->assertInstanceOf(Drawable::class, $buffer);
    }

    public function testImplementsSizer(): void
    {
        $buffer = Buffer::new(3, 3);
        $this->assertInstanceOf(Sizer::class, $buffer);
    }

    public function testImplementsItem(): void
    {
        $buffer = Buffer::new(3, 3);
        $this->assertInstanceOf(Item::class, $buffer);
    }

    public function testSetRectUpdatesInternalRect(): void
    {
        $buffer = Buffer::new(3, 3);
        $modified = $buffer->setRect(new Rect(0, 0, 4, 4));

        // Should return new instance
        $this->assertNotSame($buffer, $modified);
        // Original should be unchanged
        $this->assertSame([3, 3], $buffer->getInnerSize());
        // Modified should have new size
        $this->assertSame([5, 5], $modified->getInnerSize());
    }

    public function testGetRectReturnsCurrentRect(): void
    {
        $buffer = Buffer::new(5, 4);
        $rect = $buffer->getRect();

        $this->assertInstanceOf(Rect::class, $rect);
        $this->assertSame(0, $rect->minX);
        $this->assertSame(0, $rect->minY);
        $this->assertSame(4, $rect->maxX);  // width - 1
        $this->assertSame(3, $rect->maxY);  // height - 1
        $this->assertSame(5, $rect->dx());
        $this->assertSame(4, $rect->dy());
    }

    public function testDrawRendersIntoTargetBuffer(): void
    {
        // Create source buffer with content
        $source = Buffer::new(3, 2)
            ->setCell(0, 0, new Cell('A', new Style()))
            ->setCell(1, 0, new Cell('B', new Style()))
            ->setCell(2, 1, new Cell('C', new Style()));

        // Create target buffer
        $target = Buffer::new(5, 4);

        // Draw source into target
        $source->draw($target);

        // Verify target now has source's content
        $this->assertSame('A', $target->getCell(0, 0)->rune);
        $this->assertSame('B', $target->getCell(1, 0)->rune);
        $this->assertSame('C', $target->getCell(2, 1)->rune);
    }

    public function testDrawRespectsTargetBounds(): void
    {
        // Source buffer larger than target
        $source = Buffer::new(10, 10)
            ->setCell(0, 0, new Cell('X', new Style()));

        $target = Buffer::new(3, 3);

        // Draw should not throw, but clip at bounds
        $source->draw($target);

        // Only content within target bounds should be drawn
        $this->assertSame('X', $target->getCell(0, 0)->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // getInnerSize
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $buffer = Buffer::new(7, 4);

        [$width, $height] = $buffer->getInnerSize();

        $this->assertSame(7, $width);
        $this->assertSame(4, $height);
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize (Sizer interface)
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Buffer::new(3, 3);
        $resized = $original->setSize(5, 5);

        $this->assertNotSame($original, $resized);
        // Original should be unchanged
        $this->assertSame([3, 3], $original->getInnerSize());
        // New buffer should have new size
        $this->assertSame([5, 5], $resized->getInnerSize());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNewWithZeroDimensions(): void
    {
        $buffer = Buffer::new(0, 0);

        $this->assertSame('', $buffer->render());
    }

    public function testEmptyStringSetStringDoesNothing(): void
    {
        // Empty string setString does nothing - 3x3 buffer renders as 3 rows of 3 spaces
        $buffer = Buffer::new(3, 3)->setString(0, 0, '');
        $this->assertSame("   \n   \n   ", $buffer->render());
    }

    public function testSetStringWithStyle(): void
    {
        $redStyle = (new Style())->withForeground(Color::hex('#FF0000'));
        $buffer = Buffer::new(5, 1)->setString(0, 0, 'Hi', $redStyle);

        $cell = $buffer->getCell(0, 0);
        $this->assertSame('H', $cell->rune);
        $this->assertNotNull($cell->style->foreground);
    }

    public function testFillWithNullStyleCellRendersCorrectly(): void
    {
        // A cell with null style (default) should render without ANSI codes
        $buffer = Buffer::new(3, 1)
            ->setCell(0, 0, new Cell('A', new Style()))
            ->setCell(1, 0, new Cell('B', new Style()));

        $rendered = $buffer->render();
        // Should contain the characters
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testRenderWithBoldStyle(): void
    {
        $boldStyle = (new Style())->withBold(true);
        $buffer = Buffer::new(1, 1)
            ->setCell(0, 0, new Cell('X', $boldStyle));

        $rendered = $buffer->render();

        // Should contain ANSI bold code
        $this->assertStringContainsString("\x1b[1m", $rendered);
        $this->assertStringContainsString('X', $rendered);
    }

    public function testMultipleFillCallsAreImmutable(): void
    {
        $buffer = Buffer::new(5, 5)
            ->fill(new Rect(0, 0, 1, 1), new Cell('A', new Style()))
            ->fill(new Rect(2, 2, 3, 3), new Cell('B', new Style()));

        // First fill with Rect(0,0,1,1) covers 2x2 area: (0,0), (1,0), (0,1), (1,1)
        $this->assertSame('A', $buffer->getCell(0, 0)->rune);
        $this->assertSame('A', $buffer->getCell(1, 1)->rune);
        // Second fill with Rect(2,2,3,3) covers (2,2), (3,2), (2,3), (3,3)
        $this->assertSame('B', $buffer->getCell(2, 2)->rune);
        // Cells between fills should be empty
        $this->assertSame(' ', $buffer->getCell(1, 2)->rune);
    }

    public function testChainedWithersCreateCorrectFinalState(): void
    {
        $buffer = Buffer::new(5, 5)
            ->setString(0, 0, 'AB')
            ->setCell(4, 4, new Cell('Z', new Style()))
            ->clear();

        // After clear, all cells should be empty
        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
        $this->assertSame(' ', $buffer->getCell(4, 4)->rune);
    }
}
