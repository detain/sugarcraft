<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Position;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    public function testWidthSingleLine(): void
    {
        $this->assertSame(11, Layout::width('hello world'));
    }

    public function testWidthMultiLine(): void
    {
        $this->assertSame(11, Layout::width("hi\nhello world\na"));
    }

    public function testHeight(): void
    {
        $this->assertSame(1, Layout::height('hello'));
        $this->assertSame(3, Layout::height("a\nb\nc"));
        $this->assertSame(1, Layout::height(''));
    }

    public function testSize(): void
    {
        $this->assertSame([5, 2], Layout::size("hello\nworld"));
    }

    public function testJoinHorizontalSameHeight(): void
    {
        $a = "1\n2";
        $b = "X\nY";
        $this->assertSame("1X\n2Y", Layout::joinHorizontal(Position::TOP, $a, $b));
    }

    public function testJoinHorizontalDifferentHeights(): void
    {
        $a = "AAA\nBBB\nCCC";
        $b = "X";
        // Top anchors X at top, pads with empty.
        $top = Layout::joinHorizontal(Position::TOP, $a, $b);
        $this->assertSame("AAAX\nBBB \nCCC ", $top);
        // Bottom anchors X at bottom.
        $bot = Layout::joinHorizontal(Position::BOTTOM, $a, $b);
        $this->assertSame("AAA \nBBB \nCCCX", $bot);
        // Center anchors X in middle.
        $mid = Layout::joinHorizontal(Position::CENTER, $a, $b);
        $this->assertSame("AAA \nBBBX\nCCC ", $mid);
    }

    public function testJoinHorizontalEmptyArgs(): void
    {
        $this->assertSame('', Layout::joinHorizontal(Position::TOP));
    }

    public function testJoinVerticalSameWidth(): void
    {
        $this->assertSame("hi\nho", Layout::joinVertical(Position::LEFT, 'hi', 'ho'));
    }

    public function testJoinVerticalDifferentWidths(): void
    {
        $a = 'AAA';
        $b = 'X';
        $this->assertSame("AAA\nX  ", Layout::joinVertical(Position::LEFT, $a, $b));
        $this->assertSame("AAA\n  X", Layout::joinVertical(Position::RIGHT, $a, $b));
        $this->assertSame("AAA\n X ", Layout::joinVertical(Position::CENTER, $a, $b));
    }

    public function testPlaceHorizontalCenters(): void
    {
        // gap = 7-2 = 5, round(0.5*5) = round(2.5) → PHP rounds half-up to 3
        // so left=3, right=2 (the "odd extra" goes to the left)
        $this->assertSame('   hi  ', Layout::placeHorizontal(7, Position::CENTER, 'hi'));
    }

    public function testPlaceVerticalAddsRows(): void
    {
        $out = Layout::placeVertical(5, Position::CENTER, 'hi');
        // 'hi' has width 2; vertical placement keeps it on 1 row, pads above and below
        $lines = explode("\n", $out);
        $this->assertCount(5, $lines);
        $this->assertSame('hi', $lines[2]);
    }

    public function testPlaceFillsRectangle(): void
    {
        $out = Layout::place(5, 3, Position::CENTER, Position::CENTER, 'hi');
        // 5x3, 'hi' width=2 → h-gap=3, round(1.5)=2 left, 1 right
        // 3-1=2 vertical rows extra, round(1.0)=1 top, 1 bottom
        $expect = "     \n  hi \n     ";
        $this->assertSame($expect, $out);
    }

    public function testPlaceTopLeft(): void
    {
        $out = Layout::place(5, 3, Position::LEFT, Position::TOP, 'hi');
        $this->assertSame("hi   \n     \n     ", $out);
    }

    public function testPlaceCustomFill(): void
    {
        $out = Layout::place(4, 2, Position::CENTER, Position::CENTER, '', '.');
        $this->assertSame("....\n....", $out);
    }

    public function testJoinHorizontalPreservesMultilineEachBlock(): void
    {
        $left = "L1\nL2";
        $right = "R1\nR2";
        $this->assertSame("L1R1\nL2R2", Layout::joinHorizontal(Position::TOP, $left, $right));
    }
}
