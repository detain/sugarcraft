<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use PHPUnit\Framework\TestCase;

final class LayoutSpacingTest extends TestCase
{
    public function testJoinHorizontalNoSpacing(): void
    {
        $a = "A";
        $b = "B";
        $result = Layout::joinHorizontal(Position::TOP, $a, $b);
        $this->assertSame("AB", $result);
    }

    public function testJoinHorizontalWithSpacing(): void
    {
        $a = "A";
        $b = "B";
        // Use reflection to call the private joinHorizontalWithSpacing method
        $reflection = new \ReflectionMethod(Layout::class, 'joinHorizontalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::TOP, 2, $a, $b);
        $this->assertSame("A  B", $result);
    }

    public function testJoinHorizontalWithSpacingThreeBlocks(): void
    {
        $a = "A";
        $b = "B";
        $c = "C";
        $reflection = new \ReflectionMethod(Layout::class, 'joinHorizontalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::TOP, 2, $a, $b, $c);
        $this->assertSame("A  B  C", $result);
    }

    public function testJoinHorizontalWithSpacingPreservesMultiline(): void
    {
        $left = "L1\nL2";
        $right = "R1\nR2";
        $reflection = new \ReflectionMethod(Layout::class, 'joinHorizontalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::TOP, 1, $left, $right);
        $this->assertSame("L1 R1\nL2 R2", $result);
    }

    public function testJoinVerticalNoSpacing(): void
    {
        $a = "AAA";
        $b = "B";
        $result = Layout::joinVertical(Position::LEFT, $a, $b);
        $this->assertSame("AAA\nB  ", $result);
    }

    public function testJoinVerticalWithSpacing(): void
    {
        $a = "AAA";
        $b = "B";
        $reflection = new \ReflectionMethod(Layout::class, 'joinVerticalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::LEFT, 2, $a, $b);
        $this->assertSame("AAA\n   \n   \nB  ", $result);
    }

    public function testJoinVerticalWithSpacingThreeBlocks(): void
    {
        $a = "A";
        $b = "B";
        $c = "C";
        $reflection = new \ReflectionMethod(Layout::class, 'joinVerticalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::LEFT, 1, $a, $b, $c);
        // A, then 1 blank row, then B, then 1 blank row, then C
        $this->assertSame("A\n \nB\n \nC", $result);
    }

    public function testJoinHorizontalWithSpacingZeroDoesNotAddGaps(): void
    {
        $a = "A";
        $b = "B";
        $reflection = new \ReflectionMethod(Layout::class, 'joinHorizontalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::TOP, 0, $a, $b);
        $this->assertSame("AB", $result);
    }

    public function testJoinVerticalWithSpacingZeroDoesNotAddGaps(): void
    {
        $a = "AAA";
        $b = "B";
        $reflection = new \ReflectionMethod(Layout::class, 'joinVerticalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::LEFT, 0, $a, $b);
        $this->assertSame("AAA\nB  ", $result);
    }

    public function testJoinHorizontalWithSpacingHandlesDifferentHeights(): void
    {
        $a = "AAA\nBBB";
        $b = "X";
        $reflection = new \ReflectionMethod(Layout::class, 'joinHorizontalWithSpacing');
        $reflection->setAccessible(true);
        $result = $reflection->invoke(null, Position::TOP, 1, $a, $b);
        // a = "AAA\nBBB", b = "X"
        // After vertical padding (TOP): a = same, b = "X\n "
        // Then join with spacing 1: AAA X -> "AAA X", BBB  -> "BBB  "
        $this->assertSame("AAA X\nBBB  ", $result);
    }
}
