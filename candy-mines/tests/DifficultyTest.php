<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Mines\Difficulty;
use PHPUnit\Framework\TestCase;

final class DifficultyTest extends TestCase
{
    public function testEasyDimensions(): void
    {
        $this->assertSame(9, Difficulty::EASY->width());
        $this->assertSame(9, Difficulty::EASY->height());
        $this->assertSame(10, Difficulty::EASY->mines());
    }

    public function testMediumDimensions(): void
    {
        $this->assertSame(16, Difficulty::MEDIUM->width());
        $this->assertSame(16, Difficulty::MEDIUM->height());
        $this->assertSame(40, Difficulty::MEDIUM->mines());
    }

    public function testExpertDimensions(): void
    {
        $this->assertSame(30, Difficulty::EXPERT->width());
        $this->assertSame(16, Difficulty::EXPERT->height());
        $this->assertSame(99, Difficulty::EXPERT->mines());
    }

    public function testFromDimensionsMatchesPresets(): void
    {
        $this->assertSame(Difficulty::EASY, Difficulty::fromDimensions(9, 9, 10));
        $this->assertSame(Difficulty::MEDIUM, Difficulty::fromDimensions(16, 16, 40));
        $this->assertSame(Difficulty::EXPERT, Difficulty::fromDimensions(30, 16, 99));
    }

    public function testFromDimensionsReturnsNullForUnknown(): void
    {
        $this->assertNull(Difficulty::fromDimensions(10, 10, 12));
        $this->assertNull(Difficulty::fromDimensions(9, 9, 11));
        $this->assertNull(Difficulty::fromDimensions(8, 8, 10));
    }

    public function testCasesContainsAllDifficulties(): void
    {
        $cases = Difficulty::cases();
        $this->assertCount(3, $cases);
        $this->assertContains(Difficulty::EASY, $cases);
        $this->assertContains(Difficulty::MEDIUM, $cases);
        $this->assertContains(Difficulty::EXPERT, $cases);
    }
}
