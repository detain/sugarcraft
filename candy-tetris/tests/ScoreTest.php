<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Score;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    public function testInitialState(): void
    {
        $s = new Score();
        $this->assertSame(0, $s->points);
        $this->assertSame(0, $s->lines);
        $this->assertSame(0, $s->level);
    }

    public function testSingleLineScoreAtLevelZero(): void
    {
        $s = (new Score())->withLines(1);
        $this->assertSame(40, $s->points);
        $this->assertSame(1, $s->lines);
    }

    public function testTetrisScoreAtLevelZero(): void
    {
        $s = (new Score())->withLines(4);
        $this->assertSame(1200, $s->points);
        $this->assertSame(4, $s->lines);
    }

    public function testScoreScalesWithLevel(): void
    {
        $s = (new Score(0, 0, 5))->withLines(2);
        // 100 × (5+1) = 600
        $this->assertSame(600, $s->points);
    }

    public function testLevelRisesEveryTenLines(): void
    {
        $s = (new Score())->withLines(4)->withLines(4)->withLines(4);
        // 12 lines → level 1
        $this->assertSame(12, $s->lines);
        $this->assertSame(1, $s->level);
    }

    public function testWithLinesZeroIsNoop(): void
    {
        $s = new Score(100, 5, 0);
        $this->assertSame($s, $s->withLines(0));
    }

    public function testGravityAcceleratesWithLevel(): void
    {
        $level0 = new Score(0, 0, 0);
        $level9 = new Score(0, 0, 9);
        $level29 = new Score(0, 0, 29);
        $this->assertGreaterThan(
            $level9->framesPerRow(),
            $level0->framesPerRow(),
        );
        $this->assertSame(1, $level29->framesPerRow());
    }

    /**
     * @dataProvider framesPerRowBoundaryProvider
     */
    public function testFramesPerRowBoundaryLevels(int $level, int $expected): void
    {
        $this->assertSame($expected, (new Score(0, 0, $level))->framesPerRow());
    }

    /** @return array<string, array{0:int, 1:int}> */
    public static function framesPerRowBoundaryProvider(): array
    {
        return [
            'level_0'  => [0, 48],
            'level_8'  => [8, 8],
            'level_9'  => [9, 6],
            'level_12' => [12, 5],
            'level_15' => [15, 4],
            'level_18' => [18, 3],
            'level_28' => [28, 2],
            'level_29' => [29, 1],
        ];
    }

    public function testWithDropPointsAddsPointsPreservesLinesAndLevel(): void
    {
        $s = new Score(100, 5, 2);
        $s2 = $s->withDropPoints(42);
        $this->assertSame(142, $s2->points);
        $this->assertSame(5, $s2->lines);
        $this->assertSame(2, $s2->level);
    }

    public function testWithDropPointsZeroIsNoop(): void
    {
        $s = new Score(999, 3, 1);
        $this->assertEquals($s, $s->withDropPoints(0));
    }

    public function testWithLinesTriggersLevelUpAtExactlyTenLines(): void
    {
        // 9 lines → level 0; 10 lines → level 1
        $at9  = (new Score())->withLines(9);
        $at10 = $at9->withLines(1);
        $this->assertSame(0, $at9->level);
        $this->assertSame(1, $at10->level);
    }
}
