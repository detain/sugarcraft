<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Cell;
use SugarCraft\Dash\Foundation\Rect;
use SugarCraft\Dash\Foundation\Style;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use PHPUnit\Framework\TestCase;

final class BufferTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBufferImplementsSizer(): void
    {
        $buffer = Buffer::new(10, 10);
        $this->assertInstanceOf(Sizer::class, $buffer);
    }

    public function testBufferImplementsItem(): void
    {
        $buffer = Buffer::new(10, 10);
        $this->assertInstanceOf(Item::class, $buffer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Construction
    // ═══════════════════════════════════════════════════════════════

    public function testNewWithZeroDimensions(): void
    {
        $buffer = Buffer::new(0, 0);
        $this->assertSame('', $buffer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyBuffer(): void
    {
        $buffer = Buffer::new(5, 3);
        $this->assertSame("     \n     \n     ", $buffer->render());
    }

    public function testRenderWithContent(): void
    {
        $buffer = Buffer::new(5, 3)->setString(0, 0, 'hello');
        $this->assertSame("hello\n     \n     ", $buffer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // setString
    // ═══════════════════════════════════════════════════════════════

    public function testSetStringBasic(): void
    {
        $buffer = Buffer::new(10, 5)->setString(0, 0, 'test');
        $this->assertSame('t', $buffer->getCell(0, 0)->rune);
        $this->assertSame('e', $buffer->getCell(1, 0)->rune);
        $this->assertSame('s', $buffer->getCell(2, 0)->rune);
        $this->assertSame('t', $buffer->getCell(3, 0)->rune);
    }

    public function testSetStringTruncatesAtRightEdge(): void
    {
        $buffer = Buffer::new(3, 3)->setString(0, 0, 'hello');
        $this->assertSame('h', $buffer->getCell(0, 0)->rune);
        $this->assertSame('e', $buffer->getCell(1, 0)->rune);
        $this->assertSame('l', $buffer->getCell(2, 0)->rune);
    }

    public function testSetStringAtPosition(): void
    {
        $buffer = Buffer::new(5, 3)->setString(2, 1, 'ab');
        $this->assertSame('ab', $buffer->getCell(2, 1)->rune . $buffer->getCell(3, 1)->rune);
    }

    public function testSetStringOutOfBoundsReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(3, 3)->setString(5, 0, 'test');
        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
    }

    public function testSetStringNegativeCoordinatesReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(3, 3)->setString(-1, 0, 'test');
        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
    }

    public function testSetStringYOutOfBoundsReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(3, 3)->setString(0, 10, 'test');
        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
    }

    public function testSetStringUnicodeCharacters(): void
    {
        $buffer = Buffer::new(3, 3)->setString(0, 0, '日本語');
        $this->assertSame('日', $buffer->getCell(0, 0)->rune);
        $this->assertSame('本', $buffer->getCell(1, 0)->rune);
        $this->assertSame('語', $buffer->getCell(2, 0)->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // fill with Rect+Cell
    // ═══════════════════════════════════════════════════════════════

    public function testFillBasic(): void
    {
        $cell = new Cell('#', new Style());
        $buffer = Buffer::new(3, 3)->fill(new Rect(0, 0, 1, 1), $cell);

        $this->assertSame('#', $buffer->getCell(0, 0)->rune);
        $this->assertSame('#', $buffer->getCell(1, 0)->rune);
        $this->assertSame('#', $buffer->getCell(0, 1)->rune);
        $this->assertSame('#', $buffer->getCell(1, 1)->rune);
        $this->assertSame(' ', $buffer->getCell(2, 0)->rune);
        $this->assertSame(' ', $buffer->getCell(2, 2)->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // getCell
    // ═══════════════════════════════════════════════════════════════

    public function testGetCellOutOfBoundsThrowsException(): void
    {
        $buffer = Buffer::new(3, 3);
        $this->expectException(\OutOfBoundsException::class);
        $buffer->getCell(-1, 0);
    }

    public function testGetCellEmptyReturnsDefaultCell(): void
    {
        $buffer = Buffer::new(3, 3);
        $cell = $buffer->getCell(0, 0);
        $this->assertSame(' ', $cell->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // clear
    // ═══════════════════════════════════════════════════════════════

    public function testClearResetsBuffer(): void
    {
        $buffer = Buffer::new(3, 3)
            ->setString(0, 0, 'abc')
            ->setString(0, 1, 'def')
            ->clear();

        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
        $this->assertSame(' ', $buffer->getCell(0, 1)->rune);
        $this->assertSame(' ', $buffer->getCell(0, 2)->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Buffer::new(3, 3);
        $resized = $original->setSize(5, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // getInnerSize
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $buffer = Buffer::new(10, 20);

        [$w, $h] = $buffer->getInnerSize();

        $this->assertSame(10, $w);
        $this->assertSame(20, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleOperationsAreImmutable(): void
    {
        $buffer = Buffer::new(5, 5)
            ->setString(0, 0, 'a')
            ->setString(1, 0, 'b')
            ->setString(2, 0, 'c');

        $this->assertSame('a', $buffer->getCell(0, 0)->rune);
        $this->assertSame('b', $buffer->getCell(1, 0)->rune);
        $this->assertSame('c', $buffer->getCell(2, 0)->rune);
    }

    public function testChainedOperations(): void
    {
        $cell = new Cell('-', new Style());
        $buffer = Buffer::new(5, 5)
            ->setString(0, 0, 'hello')
            ->fill(new Rect(0, 1, 4, 1), $cell)
            ->setString(0, 2, 'world');

        $this->assertSame('h', $buffer->getCell(0, 0)->rune);
        $this->assertSame('-', $buffer->getCell(0, 1)->rune);
        $this->assertSame('w', $buffer->getCell(0, 2)->rune);
    }

    // ═══════════════════════════════════════════════════════════════
    // Complex scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testDrawBox(): void
    {
        $buffer = Buffer::new(5, 3);
        $cell = new Cell('#', new Style());

        $buffer = $buffer
            ->setString(0, 0, '┌───┐', new Style())
            ->setString(0, 1, '│   │', new Style())
            ->setString(0, 2, '└───┘', new Style());

        $this->assertSame('┌', $buffer->getCell(0, 0)->rune);
    }

    public function testEmptyStringDoesNotCrash(): void
    {
        $buffer = Buffer::new(5, 5)->setString(0, 0, '');
        $this->assertSame(' ', $buffer->getCell(0, 0)->rune);
    }

    public function testSingleCharacterString(): void
    {
        $buffer = Buffer::new(5, 5)->setString(2, 2, 'X');
        $this->assertSame('X', $buffer->getCell(2, 2)->rune);
    }

    public function testLargeBuffer(): void
    {
        $buffer = Buffer::new(100, 50)->setString(0, 0, 'test');
        $this->assertSame('t', $buffer->getCell(0, 0)->rune);
        $this->assertSame(100, $buffer->getInnerSize()[0]);
        $this->assertSame(50, $buffer->getInnerSize()[1]);
    }

    public function testRenderProducesCorrectLineCount(): void
    {
        $buffer = Buffer::new(10, 5);
        $lines = explode("\n", $buffer->render());
        $this->assertCount(5, $lines);
    }

    public function testRenderLineWidthMatchesBufferWidth(): void
    {
        $buffer = Buffer::new(7, 3)->setString(0, 0, 'ab');
        $lines = explode("\n", $buffer->render());
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(7, mb_strlen($line));
        }
    }
}
