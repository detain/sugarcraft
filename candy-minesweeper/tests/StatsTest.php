<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Stats;
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
        $stats = (new Stats())->withGame(Difficulty::Easy, false, null);
        $this->assertSame(1, $stats->gamesPlayed(Difficulty::Easy));
        $this->assertSame(0, $stats->wins(Difficulty::Easy));
    }

    public function testWithGameIncrementsWinsOnWin(): void
    {
        $stats = (new Stats())->withGame(Difficulty::Easy, true, 30);
        $this->assertSame(1, $stats->gamesPlayed(Difficulty::Easy));
        $this->assertSame(1, $stats->wins(Difficulty::Easy));
    }

    public function testWithGameRecordsBestTime(): void
    {
        $stats = (new Stats())->withGame(Difficulty::Easy, true, 30);
        $this->assertSame(30, $stats->bestTime(Difficulty::Easy));
    }

    public function testWithGameUpdatesBestTimeWhenBetter(): void
    {
        $stats = (new Stats())->withGame(Difficulty::Easy, true, 50);
        $stats = $stats->withGame(Difficulty::Easy, true, 30);
        $this->assertSame(30, $stats->bestTime(Difficulty::Easy));
    }

    public function testWithGameKeepsBestTimeWhenWorse(): void
    {
        $stats = (new Stats())->withGame(Difficulty::Easy, true, 30);
        $stats = $stats->withGame(Difficulty::Easy, true, 50);
        $this->assertSame(30, $stats->bestTime(Difficulty::Easy));
    }

    public function testWithGameIgnoresNullTimeOnLoss(): void
    {
        $stats = (new Stats())->withGame(Difficulty::Easy, false, null);
        $this->assertNull($stats->bestTime(Difficulty::Easy));
    }

    public function testWinRateIsZeroWithNoGames(): void
    {
        $stats = new Stats();
        $this->assertSame(0.0, $stats->winRate(Difficulty::Easy));
    }

    public function testWinRateCalculation(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Easy, true, 30)
            ->withGame(Difficulty::Easy, true, 40)
            ->withGame(Difficulty::Easy, false, null)
            ->withGame(Difficulty::Easy, false, null);
        $this->assertSame(4, $stats->gamesPlayed(Difficulty::Easy));
        $this->assertSame(2, $stats->wins(Difficulty::Easy));
        $this->assertEqualsWithDelta(50.0, $stats->winRate(Difficulty::Easy), 0.01);
    }

    public function testStatsAreIndependentPerDifficulty(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Easy, true, 30)
            ->withGame(Difficulty::Medium, true, 50)
            ->withGame(Difficulty::Expert, true, 70);

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::Easy));
        $this->assertSame(30, $stats->bestTime(Difficulty::Easy));

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::Medium));
        $this->assertSame(50, $stats->bestTime(Difficulty::Medium));

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::Expert));
        $this->assertSame(70, $stats->bestTime(Difficulty::Expert));
    }

    public function testGamesPlayedMethod(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Easy, false, null)
            ->withGame(Difficulty::Easy, false, null);
        $this->assertSame(2, $stats->gamesPlayed(Difficulty::Easy));
    }

    public function testWinsMethod(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Easy, true, 30)
            ->withGame(Difficulty::Easy, false, null);
        $this->assertSame(1, $stats->wins(Difficulty::Easy));
    }

    // ─── wins() per difficulty (uncovered Medium/Expert branches) ─────────────

    public function testWinsReturnsCorrectCountForMedium(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Medium, true, 45)
            ->withGame(Difficulty::Medium, true, 60)
            ->withGame(Difficulty::Medium, false, null);
        $this->assertSame(2, $stats->wins(Difficulty::Medium));
        $this->assertSame(3, $stats->gamesPlayed(Difficulty::Medium));
    }

    public function testWinsReturnsCorrectCountForExpert(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Expert, true, 120)
            ->withGame(Difficulty::Expert, false, null)
            ->withGame(Difficulty::Expert, false, null);
        $this->assertSame(1, $stats->wins(Difficulty::Expert));
        $this->assertSame(3, $stats->gamesPlayed(Difficulty::Expert));
    }

    public function testWinRateForMediumAndExpert(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Medium, true, 50)
            ->withGame(Difficulty::Medium, false, null)
            ->withGame(Difficulty::Medium, true, 40)
            ->withGame(Difficulty::Expert, true, 100)
            ->withGame(Difficulty::Expert, false, null);
        $this->assertEqualsWithDelta(66.67, $stats->winRate(Difficulty::Medium), 0.01);
        $this->assertEqualsWithDelta(50.0, $stats->winRate(Difficulty::Expert), 0.01);
    }

    public function testBestTimePerDifficulty(): void
    {
        $stats = (new Stats())
            ->withGame(Difficulty::Medium, true, 60)
            ->withGame(Difficulty::Medium, true, 45)  // better
            ->withGame(Difficulty::Expert, true, 200)
            ->withGame(Difficulty::Expert, true, 150); // better
        $this->assertSame(45, $stats->bestTime(Difficulty::Medium));
        $this->assertSame(150, $stats->bestTime(Difficulty::Expert));
        // Easy should remain unset
        $this->assertNull($stats->bestTime(Difficulty::Easy));
    }
}
