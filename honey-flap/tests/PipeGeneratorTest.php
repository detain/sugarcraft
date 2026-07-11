<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use SugarCraft\Flap\Game;
use SugarCraft\Flap\PipeGenerator;
use PHPUnit\Framework\TestCase;

final class PipeGeneratorTest extends TestCase
{
    public function testGapHeightAtScoreZero(): void
    {
        $this->assertSame(PipeGenerator::GAP_DEFAULT, PipeGenerator::gapHeightForScore(0));
    }

    public function testGapShrinksAsScoreIncreases(): void
    {
        // Score 0 → 6, score 5 → 5 (one shrink step at interval 5)
        $this->assertSame(6, PipeGenerator::gapHeightForScore(0));
        $this->assertSame(5, PipeGenerator::gapHeightForScore(5));
        $this->assertSame(4, PipeGenerator::gapHeightForScore(10));
    }

    public function testGapFloorIsRespected(): void
    {
        // GAP_MIN = 3, score 15 → 3
        $this->assertSame(3, PipeGenerator::gapHeightForScore(15));
        $this->assertSame(3, PipeGenerator::gapHeightForScore(100));
    }

    public function testGapIsConstantBetweenShrinkBoundaries(): void
    {
        // Interval = 5, so within 0-4 gap should stay at 6
        for ($score = 0; $score < 5; $score++) {
            $this->assertSame(6, PipeGenerator::gapHeightForScore($score));
        }
        // At exactly 5, gap shrinks to 5
        $this->assertSame(5, PipeGenerator::gapHeightForScore(5));
    }

    public function testMakePipeUsesCurrentScoreForGapHeight(): void
    {
        $rand = static fn(int $max): int => 0;
        // Score 0 → gap height 6
        $pipe0 = PipeGenerator::makePipe(0, $rand);
        $this->assertSame(6, $pipe0->gapHeight);
        // Score 10 → gap height 4
        $pipe10 = PipeGenerator::makePipe(10, $rand);
        $this->assertSame(4, $pipe10->gapHeight);
        // Score 15 → gap height 3 (floor)
        $pipe15 = PipeGenerator::makePipe(15, $rand);
        $this->assertSame(3, $pipe15->gapHeight);
    }

    public function testMakePipeIsDeterministicWithSeededRand(): void
    {
        $rand = static fn(int $max): int => intdiv($max, 2);
        $a = PipeGenerator::makePipe(0, $rand);
        $b = PipeGenerator::makePipe(0, $rand);
        $this->assertSame($a->gapY, $b->gapY);
        $this->assertSame($a->gapHeight, $b->gapHeight);
    }

    public function testMakePipePositionsGapWithinBounds(): void
    {
        $rand = static fn(int $max): int => 0;
        $pipe = PipeGenerator::makePipe(0, $rand);
        // With rand=0, gapY should be at minY for gapHeight=6
        // minY = 3 + 3 = 6, maxY = 18 - 3 - 3 = 12
        // gapY = 6 + 0 = 6
        $this->assertGreaterThanOrEqual(6, $pipe->gapY);
        $this->assertLessThanOrEqual(12, $pipe->gapY);
    }

    public function testMakePipePlacesGapAtUpperBoundWhenRandMaxes(): void
    {
        // rand returning its max drives gapY to the top of the allowed band —
        // the branch the rand=0 case never exercises.
        $rand = static fn(int $max): int => $max;
        $pipe = PipeGenerator::makePipe(0, $rand);
        // gapHeight 6 → halfGap 3 → minY = 6, maxY = 12, gapY = 6 + (12-6) = 12.
        $this->assertSame(12, $pipe->gapY);
    }

    public function testMakePipeGapStaysInsidePlayfieldAcrossRandRange(): void
    {
        // For every rand offset across the band, the gap span (gapY ± halfGap)
        // must clear both the ceiling (row 0) and the floor (row HEIGHT).
        foreach ([0, 1, 2, 3, 6] as $offset) {
            $rand = static fn(int $max): int => min($offset, $max);
            $pipe = PipeGenerator::makePipe(0, $rand);
            $halfGap = intdiv($pipe->gapHeight, 2);
            $this->assertGreaterThanOrEqual(0, $pipe->gapY - $halfGap, 'gap top must stay below the ceiling');
            $this->assertLessThan(Game::HEIGHT, $pipe->gapY + $halfGap, 'gap bottom must stay above the floor');
        }
    }
}
