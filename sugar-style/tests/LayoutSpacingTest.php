<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
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

    public function testNegativeHorizontalSpacingThrowsInvalidArgument(): void
    {
        // finding 004: was a raw ValueError from str_repeat(-1); now the
        // uniform house fail-fast (Style::tabWidth shape).
        try {
            Layout::joinHorizontalWithSpacing(0.0, -1, 'a', 'b');
            $this->fail('expected InvalidArgumentException for negative spacing');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(\SugarCraft\Core\I18n\Lang::t('style.spacing_nonneg'), $e->getMessage());
        }
    }

    public function testNegativeVerticalSpacingThrowsLikeHorizontal(): void
    {
        // The vertical leg used to silently mean "no gap" — same throw now.
        $this->expectException(\InvalidArgumentException::class);
        Layout::joinVerticalWithSpacing(0.0, -1, 'a', 'b');
    }

    public function testNegativeSpacingRejectedEvenWithNoBlocks(): void
    {
        // Programmer error is rejected before the empty-input shortcut.
        $this->expectException(\InvalidArgumentException::class);
        Layout::joinHorizontalWithSpacing(0.0, -1);
    }

    public function testZeroAndNullSpacingRemainValid(): void
    {
        $this->assertSame("ab", Layout::joinHorizontalWithSpacing(0.0, 0, 'a', 'b'));
        $this->assertSame("ab", Layout::joinHorizontalWithSpacing(0.0, null, 'a', 'b'));
        $this->assertSame("a\nb", Layout::joinVerticalWithSpacing(0.0, 0, 'a', 'b'));
    }
}
