<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Mines\Difficulty;
use SugarCraft\Mines\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function testDefaultStatsAreZero(): void
    {
        $stats = new Stats();
        $this->assertSame(0, $stats->easyGames);
        $this->assertSame(0, $stats->easyWins);
        $this->assertNull($stats->easyBest);
        $this->assertSame(0, $stats->mediumGames);
        $this->assertSame(0, $stats->mediumWins);
        $this->assertNull($stats->mediumBest);
        $this->assertSame(0, $stats->expertGames);
        $this->assertSame(0, $stats->expertWins);
        $this->assertNull($stats->expertBest);
    }

    public function testWithGameIncrementsGamesPlayed(): void
    {
        $stats = (new Stats())->withGame(Difficulty::EASY, false, null);
        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(0, $stats->wins(Difficulty::EASY));
    }

    public function testWithGameIncrementsWinsOnWin(): void
    {
        $stats = (new Stats())->withGame(Difficulty::EASY, true, 30);
        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(1, $stats->wins(Difficulty::EASY));
    }

    public function testWithGameRecordsBestTime(): void
    {
        $stats = (new Stats())->withGame(Difficulty::EASY, true, 30);
        $this->assertSame(30, $stats->bestTime(Difficulty::EASY));
    }

    public function testWithGameUpdatesBestTimeWhenBetter(): void
    {
        $stats = (new Stats())->withGame(Difficulty::EASY, true, 50);
        $stats = $stats->withGame(Difficulty::EASY, true, 30);
        $this->assertSame(30, $stats->bestTime(Difficulty::EASY));
    }

    public function testWithGameKeepsBestTimeWhenWorse(): void
    {
        $stats = (new Stats())->withGame(Difficulty::EASY, true, 30);
        $stats = $stats->withGame(Difficulty::EASY, true, 50);
        $this->assertSame(30, $stats->bestTime(Difficulty::EASY));
    }

    public function testWithGameIgnoresNullTimeOnLoss(): void
    {
        $stats = (new Stats())->withGame(Difficulty::EASY, false, null);
        $this->assertNull($stats->bestTime(Difficulty::EASY));
    }

    public function testWinRateIsZeroWithNoGames(): void
    {
        $stats = new Stats();
        $this->assertSame(0.0, $stats->winRate(Difficulty::EASY));
    }

    public function testWinRateCalculation(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::EASY, true, 30)
            ->withGame(Difficulty::EASY, true, 40)
            ->withGame(Difficulty::EASY, false, null)
            ->withGame(Difficulty::EASY, false, null);
        $this->assertSame(4, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(2, $stats->wins(Difficulty::EASY));
        $this->assertEqualsWithDelta(50.0, $stats->winRate(Difficulty::EASY), 0.01);
    }

    public function testStatsAreIndependentPerDifficulty(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::EASY, true, 30)
            ->withGame(Difficulty::MEDIUM, true, 50)
            ->withGame(Difficulty::EXPERT, true, 70);

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(30, $stats->bestTime(Difficulty::EASY));

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::MEDIUM));
        $this->assertSame(50, $stats->bestTime(Difficulty::MEDIUM));

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EXPERT));
        $this->assertSame(70, $stats->bestTime(Difficulty::EXPERT));
    }

    public function testGamesPlayedMethod(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::EASY, false, null)
            ->withGame(Difficulty::EASY, false, null);
        $this->assertSame(2, $stats->gamesPlayed(Difficulty::EASY));
    }

    public function testWinsMethod(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::EASY, true, 30)
            ->withGame(Difficulty::EASY, false, null);
        $this->assertSame(1, $stats->wins(Difficulty::EASY));
    }
}
