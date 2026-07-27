<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use SugarCraft\Veil\Position;
use PHPUnit\Framework\TestCase;

final class PositionTest extends TestCase
{
    // ─── yOffset ─────────────────────────────────────────────────────────────────

    public function testYOffsetTopReturnsZero(): void
    {
        $this->assertSame(0, Position::TOP->yOffset(5, 20));
        $this->assertSame(0, Position::TOP_RIGHT->yOffset(5, 20));
        $this->assertSame(0, Position::TOP_LEFT->yOffset(5, 20));
    }

    public function testYOffsetBottomReturnsBgMinusFg(): void
    {
        $this->assertSame(15, Position::BOTTOM->yOffset(5, 20));
        $this->assertSame(15, Position::BOTTOM_RIGHT->yOffset(5, 20));
        $this->assertSame(15, Position::BOTTOM_LEFT->yOffset(5, 20));
    }

    public function testYOffsetCenterReturnsFlooredHalf(): void
    {
        // (20 - 5) / 2 = 7.5 → floor = 7
        $this->assertSame(7, Position::CENTER->yOffset(5, 20));
        $this->assertSame(7, Position::LEFT->yOffset(5, 20));
        $this->assertSame(7, Position::RIGHT->yOffset(5, 20));
    }

    public function testYOffsetEvenDifference(): void
    {
        // (20 - 10) / 2 = 5 exactly
        $this->assertSame(5, Position::CENTER->yOffset(10, 20));
    }

    public function testYOffsetFgLargerThanBgReturnsNegative(): void
    {
        // fg=20, bg=10 → (10 - 20) / 2 = -5
        $this->assertSame(-5, Position::CENTER->yOffset(20, 10));
    }

    // ─── xOffset ─────────────────────────────────────────────────────────────────

    public function testXOffsetLeftReturnsZero(): void
    {
        $this->assertSame(0, Position::LEFT->xOffset(5, 20));
        $this->assertSame(0, Position::TOP_LEFT->xOffset(5, 20));
        $this->assertSame(0, Position::BOTTOM_LEFT->xOffset(5, 20));
    }

    public function testXOffsetRightReturnsBgMinusFg(): void
    {
        $this->assertSame(15, Position::RIGHT->xOffset(5, 20));
        $this->assertSame(15, Position::TOP_RIGHT->xOffset(5, 20));
        $this->assertSame(15, Position::BOTTOM_RIGHT->xOffset(5, 20));
    }

    public function testXOffsetCenterReturnsFlooredHalf(): void
    {
        // (20 - 5) / 2 = 7.5 → floor = 7
        $this->assertSame(7, Position::CENTER->xOffset(5, 20));
        $this->assertSame(7, Position::TOP->xOffset(5, 20));
        $this->assertSame(7, Position::BOTTOM->xOffset(5, 20));
    }

    public function testXOffsetEvenDifference(): void
    {
        // (20 - 10) / 2 = 5 exactly
        $this->assertSame(5, Position::CENTER->xOffset(10, 20));
    }

    public function testXOffsetFgLargerThanBgReturnsNegative(): void
    {
        // fg=20, bg=10 → (10 - 20) / 2 = -5
        $this->assertSame(-5, Position::CENTER->xOffset(20, 10));
    }

    // ─── All cases covered ───────────────────────────────────────────────────────

    public function testAllPositionCases(): void
    {
        $cases = [
            Position::TOP,
            Position::RIGHT,
            Position::BOTTOM,
            Position::LEFT,
            Position::CENTER,
            Position::TOP_RIGHT,
            Position::BOTTOM_RIGHT,
            Position::BOTTOM_LEFT,
            Position::TOP_LEFT,
        ];

        $this->assertCount(9, $cases);

        foreach ($cases as $case) {
            $this->assertIsInt($case->yOffset(5, 20));
            $this->assertIsInt($case->xOffset(5, 20));
        }
    }
}
