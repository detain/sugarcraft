<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Buffer;
use SugarCraft\Dash\Grid\HAlign;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Item;
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

    public function testNewCreatesEmptyBuffer(): void
    {
        $buffer = Buffer::new(3, 3);

        $this->assertSame(3, $buffer->getWidthConstraint());
        $this->assertSame(3, $buffer->getHeightConstraint());
        $this->assertSame("   \n   \n   ", $buffer->render());
    }

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

        $expected = "     \n     \n     ";
        $this->assertSame($expected, $buffer->render());
    }

    public function testRenderWithContent(): void
    {
        $buffer = Buffer::new(5, 3)->setString(0, 0, 'hello');

        $expected = "hello\n     \n     ";
        $this->assertSame($expected, $buffer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // setString
    // ═══════════════════════════════════════════════════════════════

    public function testSetStringBasic(): void
    {
        $buffer = Buffer::new(10, 5)->setString(0, 0, 'test');

        $this->assertSame('t', $buffer->getCell(0, 0));
        $this->assertSame('e', $buffer->getCell(1, 0));
        $this->assertSame('s', $buffer->getCell(2, 0));
        $this->assertSame('t', $buffer->getCell(3, 0));
    }

    public function testSetStringTruncatesAtRightEdge(): void
    {
        $buffer = Buffer::new(3, 3)->setString(0, 0, 'hello');

        // Only 'hel' fits in width 3
        $this->assertSame('h', $buffer->getCell(0, 0));
        $this->assertSame('e', $buffer->getCell(1, 0));
        $this->assertSame('l', $buffer->getCell(2, 0));
        $this->assertSame('', $buffer->getCell(3, 0));
    }

    public function testSetStringAtPosition(): void
    {
        $buffer = Buffer::new(5, 3)->setString(2, 1, 'ab');

        $this->assertSame('', $buffer->getCell(1, 1));
        $this->assertSame('a', $buffer->getCell(2, 1));
        $this->assertSame('b', $buffer->getCell(3, 1));
    }

    public function testSetStringOutOfBoundsReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(3, 3)->setString(5, 0, 'test');

        // Should be unchanged - all cells empty
        $this->assertSame('', $buffer->getCell(0, 0));
    }

    public function testSetStringNegativeCoordinatesReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(3, 3)->setString(-1, 0, 'test');

        $this->assertSame('', $buffer->getCell(0, 0));
    }

    public function testSetStringYOutOfBoundsReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(3, 3)->setString(0, 10, 'test');

        $this->assertSame('', $buffer->getCell(0, 0));
    }

    public function testSetStringUnicodeCharacters(): void
    {
        $buffer = Buffer::new(3, 3)->setString(0, 0, '日本語');

        // Each character should be properly stored
        $this->assertSame('日', $buffer->getCell(0, 0));
        $this->assertSame('本', $buffer->getCell(1, 0));
        $this->assertSame('語', $buffer->getCell(2, 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // setLine
    // ═══════════════════════════════════════════════════════════════

    public function testSetLineLeftAligned(): void
    {
        $buffer = Buffer::new(5, 3)->setLine(0, 'ab', HAlign::Left);

        $this->assertSame('a', $buffer->getCell(0, 0));
        $this->assertSame('b', $buffer->getCell(1, 0));
        $this->assertSame('', $buffer->getCell(2, 0));
    }

    public function testSetLineRightAligned(): void
    {
        $buffer = Buffer::new(5, 3)->setLine(0, 'ab', HAlign::Right);

        // "ab" should start at x=3 (padding of 3)
        $this->assertSame('', $buffer->getCell(0, 0));
        $this->assertSame('', $buffer->getCell(2, 0));
        $this->assertSame('a', $buffer->getCell(3, 0));
        $this->assertSame('b', $buffer->getCell(4, 0));
    }

    public function testSetLineCenterAligned(): void
    {
        $buffer = Buffer::new(5, 3)->setLine(0, 'a', HAlign::Center);

        // "a" centered in width 5: 2 spaces left, 2 spaces right
        $this->assertSame('', $buffer->getCell(0, 0));
        $this->assertSame('', $buffer->getCell(1, 0));
        $this->assertSame('a', $buffer->getCell(2, 0));
        $this->assertSame('', $buffer->getCell(3, 0));
        $this->assertSame('', $buffer->getCell(4, 0));
    }

    public function testSetLineTruncatesLongerLines(): void
    {
        $buffer = Buffer::new(3, 3)->setLine(0, 'hello');

        // Only first 3 chars fit
        $this->assertSame('h', $buffer->getCell(0, 0));
        $this->assertSame('e', $buffer->getCell(1, 0));
        $this->assertSame('l', $buffer->getCell(2, 0));
    }

    public function testSetLineOutOfBoundsYReturnsSameBuffer(): void
    {
        $buffer = Buffer::new(5, 3)->setLine(10, 'test');

        $this->assertSame("     \n     \n     ", $buffer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // fill
    // ═══════════════════════════════════════════════════════════════

    public function testFillBasic(): void
    {
        $buffer = Buffer::new(3, 3)->fill(0, 0, 2, 2, '#');

        // Top-left 2x2 area should be filled with '#'
        $this->assertSame('#', $buffer->getCell(0, 0));
        $this->assertSame('#', $buffer->getCell(1, 0));
        $this->assertSame('#', $buffer->getCell(0, 1));
        $this->assertSame('#', $buffer->getCell(1, 1));
        // Rest should be empty
        $this->assertSame('', $buffer->getCell(2, 0));
        $this->assertSame('', $buffer->getCell(2, 2));
    }

    public function testFillTruncatesAtEdges(): void
    {
        $buffer = Buffer::new(3, 3)->fill(2, 2, 5, 5, '*');

        // Should fill positions (2,2), (2 is max x and y since width=height=3)
        $this->assertSame('*', $buffer->getCell(2, 2));
        // Out of bounds positions should be unchanged
        $this->assertSame('', $buffer->getCell(0, 0));
    }

    public function testFillWithSpaceCharacter(): void
    {
        $buffer = Buffer::new(3, 3)
            ->setString(0, 0, 'abc')
            ->fill(1, 0, 1, 1, ' ');

        $this->assertSame('a', $buffer->getCell(0, 0));
        $this->assertSame(' ', $buffer->getCell(1, 0));
        $this->assertSame('c', $buffer->getCell(2, 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // fillRow and fillColumn
    // ═══════════════════════════════════════════════════════════════

    public function testFillRowBasic(): void
    {
        $buffer = Buffer::new(5, 3)->fillRow(1, '-');

        $this->assertSame('', $buffer->getCell(0, 0));
        $this->assertSame('-', $buffer->getCell(0, 1));
        $this->assertSame('-', $buffer->getCell(4, 1));
        $this->assertSame('', $buffer->getCell(0, 2));
    }

    public function testFillColumnBasic(): void
    {
        $buffer = Buffer::new(5, 3)->fillColumn(2, '|');

        $this->assertSame('', $buffer->getCell(0, 0));
        $this->assertSame('|', $buffer->getCell(2, 0));
        $this->assertSame('|', $buffer->getCell(2, 2));
        $this->assertSame('', $buffer->getCell(3, 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // getCell
    // ═══════════════════════════════════════════════════════════════

    public function testGetCellOutOfBoundsReturnsEmpty(): void
    {
        $buffer = Buffer::new(3, 3);

        $this->assertSame('', $buffer->getCell(-1, 0));
        $this->assertSame('', $buffer->getCell(0, -1));
        $this->assertSame('', $buffer->getCell(10, 0));
        $this->assertSame('', $buffer->getCell(0, 10));
    }

    public function testGetCellEmptyReturnsEmpty(): void
    {
        $buffer = Buffer::new(3, 3);

        $this->assertSame('', $buffer->getCell(0, 0));
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

        $this->assertSame('', $buffer->getCell(0, 0));
        $this->assertSame('', $buffer->getCell(0, 1));
        $this->assertSame('', $buffer->getCell(0, 2));
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Buffer::new(3, 3);
        $resized = $original->setSize(5, 5);

        $this->assertNotSame($original, $resized);
        // Original should be unchanged
        $this->assertSame(3, $original->getWidthConstraint());
        $this->assertSame(3, $original->getHeightConstraint());
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

    public function testWithSizeCreatesNewBuffer(): void
    {
        $original = Buffer::new(3, 3);
        $resized = $original->withSize(5, 5);

        $this->assertNotSame($original, $resized);
        $this->assertSame(5, $resized->getWidthConstraint());
        $this->assertSame(5, $resized->getHeightConstraint());
        // Original unchanged
        $this->assertSame(3, $original->getWidthConstraint());
    }

    public function testMultipleOperationsAreImmutable(): void
    {
        $buffer = Buffer::new(5, 5)
            ->setString(0, 0, 'a')
            ->setString(1, 0, 'b')
            ->setString(2, 0, 'c');

        $this->assertSame('a', $buffer->getCell(0, 0)); // Each setString stores at its position
        $this->assertSame('b', $buffer->getCell(1, 0));
        $this->assertSame('c', $buffer->getCell(2, 0));
    }

    public function testChainedOperations(): void
    {
        $buffer = Buffer::new(5, 5)
            ->setString(0, 0, 'hello')
            ->fill(0, 1, 5, 1, '-')
            ->setString(0, 2, 'world');

        $this->assertSame('h', $buffer->getCell(0, 0)); // First char of 'hello'
        $this->assertSame('-', $buffer->getCell(0, 1)); // Fill character
        $this->assertSame('w', $buffer->getCell(0, 2)); // First char of 'world'
    }

    // ═══════════════════════════════════════════════════════════════
    // Complex scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testDrawBox(): void
    {
        $buffer = Buffer::new(5, 3);

        // Draw a simple box border
        $buffer = $buffer
            ->setString(0, 0, '┌───┐')
            ->setString(0, 1, '│   │')
            ->setString(0, 2, '└───┘');

        $this->assertSame('┌───┐', trim($buffer->getCell(0, 0) . $buffer->getCell(1, 0) . $buffer->getCell(2, 0) . $buffer->getCell(3, 0) . $buffer->getCell(4, 0)));
    }

    public function testEmptyStringDoesNotCrash(): void
    {
        $buffer = Buffer::new(5, 5)->setString(0, 0, '');

        $this->assertSame('', $buffer->getCell(0, 0));
        $this->assertSame('', $buffer->getCell(1, 0));
    }

    public function testSingleCharacterString(): void
    {
        $buffer = Buffer::new(5, 5)->setString(2, 2, 'X');

        $this->assertSame('X', $buffer->getCell(2, 2));
        $this->assertSame('', $buffer->getCell(1, 2));
        $this->assertSame('', $buffer->getCell(3, 2));
    }

    public function testLargeBuffer(): void
    {
        $buffer = Buffer::new(100, 50)->setString(0, 0, 'test');

        $this->assertSame('t', $buffer->getCell(0, 0)); // First character of 'test'
        $this->assertSame(100, $buffer->getWidthConstraint());
        $this->assertSame(50, $buffer->getHeightConstraint());
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
