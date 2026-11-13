<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Minesweeper\Difficulty;
use PHPUnit\Framework\TestCase;

final class DifficultyTest extends TestCase
{
    public function testEasyDimensions(): void
    {
        $this->assertSame(9, Difficulty::Easy->width());
        $this->assertSame(9, Difficulty::Easy->height());
        $this->assertSame(10, Difficulty::Easy->mines());
    }

    public function testStandardDimensions(): void
    {
        $this->assertSame(10, Difficulty::Standard->width());
        $this->assertSame(10, Difficulty::Standard->height());
        $this->assertSame(12, Difficulty::Standard->mines());
    }

    public function testMediumDimensions(): void
    {
        $this->assertSame(16, Difficulty::Medium->width());
        $this->assertSame(16, Difficulty::Medium->height());
        $this->assertSame(40, Difficulty::Medium->mines());
    }

    public function testExpertDimensions(): void
    {
        $this->assertSame(30, Difficulty::Expert->width());
        $this->assertSame(16, Difficulty::Expert->height());
        $this->assertSame(99, Difficulty::Expert->mines());
    }

    public function testFromDimensionsMatchesPresets(): void
    {
        $this->assertSame(Difficulty::Easy, Difficulty::fromDimensions(9, 9, 10));
        $this->assertSame(Difficulty::Standard, Difficulty::fromDimensions(10, 10, 12));
        $this->assertSame(Difficulty::Medium, Difficulty::fromDimensions(16, 16, 40));
        $this->assertSame(Difficulty::Expert, Difficulty::fromDimensions(30, 16, 99));
    }

    public function testFromDimensionsReturnsNullForUnknown(): void
    {
        $this->assertNull(Difficulty::fromDimensions(10, 10, 13));
        $this->assertNull(Difficulty::fromDimensions(9, 9, 11));
        $this->assertNull(Difficulty::fromDimensions(8, 8, 10));
    }

    public function testCasesContainsAllDifficulties(): void
    {
        $cases = Difficulty::cases();
        $this->assertCount(4, $cases);
        $this->assertContains(Difficulty::Easy, $cases);
        $this->assertContains(Difficulty::Standard, $cases);
        $this->assertContains(Difficulty::Medium, $cases);
        $this->assertContains(Difficulty::Expert, $cases);
    }
}
