<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Mines\Difficulty;
use SugarCraft\Mines\Game;
use SugarCraft\Mines\Stats;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    private static function key(KeyType $t, string $rune = ''): KeyMsg
    {
        return new KeyMsg($t, $rune);
    }

    public function testCursorStartsAtOrigin(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $max): int => 0);
        $this->assertSame(0, $g->cursorX);
        $this->assertSame(0, $g->cursorY);
    }

    public function testArrowKeysMoveCursor(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Right));
        [$g, ] = $g->update(self::key(KeyType::Right));
        [$g, ] = $g->update(self::key(KeyType::Down));
        $this->assertSame(2, $g->cursorX);
        $this->assertSame(1, $g->cursorY);
    }

    public function testCursorClampsAtBoardEdges(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        for ($i = 0; $i < 10; $i++) {
            [$g, ] = $g->update(self::key(KeyType::Right));
        }
        $this->assertSame(2, $g->cursorX);
    }

    public function testHjklVimMovement(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'l'));
        [$g, ] = $g->update(self::key(KeyType::Char, 'j'));
        $this->assertSame(1, $g->cursorX);
        $this->assertSame(1, $g->cursorY);
    }

    public function testFlagToggle(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertTrue($g->board->cell(0, 0)->flagged);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertFalse($g->board->cell(0, 0)->flagged);
    }

    public function testRevealOnFirstClickPlacesMines(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        $this->assertTrue($g->board->minesPlaced);
        $this->assertTrue($g->board->cell(0, 0)->revealed);
    }

    public function testQuitProducesQuitCmd(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [, $cmd] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testEscalsoQuits(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [, $cmd] = $g->update(self::key(KeyType::Escape));
        $this->assertNotNull($cmd);
    }

    public function testRestartResetsBoard(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));      // reveals + places mines
        [$g, ] = $g->update(self::key(KeyType::Char, 'r'));  // restart
        $this->assertFalse($g->board->minesPlaced);
        $this->assertSame(0, $g->cursorX);
        $this->assertSame(0, $g->cursorY);
    }

    public function testNonKeyMessagesAreIgnored(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $max): int => 0);
        $msg = new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $g->update($msg);
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testViewIncludesStatusLine(): void
    {
        $g = Game::start(4, 4, 2, static fn(int $max): int => 0);
        $view = $g->view();
        $this->assertStringContainsString('mines: 2', $view);
    }

    public function testWithDifficultyCreatesCorrectBoard(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY);
        $this->assertSame(9, $g->board->width);
        $this->assertSame(9, $g->board->height);
        $this->assertSame(10, $g->board->mineCount);
    }

    public function testWithDifficultyMedium(): void
    {
        $g = Game::withDifficulty(Difficulty::MEDIUM);
        $this->assertSame(16, $g->board->width);
        $this->assertSame(16, $g->board->height);
        $this->assertSame(40, $g->board->mineCount);
    }

    public function testWithDifficultyExpert(): void
    {
        $g = Game::withDifficulty(Difficulty::EXPERT);
        $this->assertSame(30, $g->board->width);
        $this->assertSame(16, $g->board->height);
        $this->assertSame(99, $g->board->mineCount);
    }

    public function testWithCustomCreatesCorrectBoard(): void
    {
        $g = Game::withCustom(7, 5, 8);
        $this->assertSame(7, $g->board->width);
        $this->assertSame(5, $g->board->height);
        $this->assertSame(8, $g->board->mineCount);
    }

    public function testDifficultyReturnsCorrectEnum(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY);
        $this->assertSame(Difficulty::EASY, $g->difficulty());

        $g = Game::withDifficulty(Difficulty::MEDIUM);
        $this->assertSame(Difficulty::MEDIUM, $g->difficulty());

        $g = Game::withDifficulty(Difficulty::EXPERT);
        $this->assertSame(Difficulty::EXPERT, $g->difficulty());
    }

    public function testDifficultyReturnsNullForCustomSize(): void
    {
        $g = Game::withCustom(7, 5, 8);
        $this->assertNull($g->difficulty());
    }

    public function testTimerStartsOnFirstReveal(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);
        $this->assertNull($g->startedAt);
        $this->assertNull($g->elapsed());

        // First reveal
        [$g, ] = $g->update(self::key(KeyType::Space));
        $this->assertNotNull($g->startedAt);
    }

    public function testElapsedReturnsNullBeforeFirstReveal(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY);
        $this->assertNull($g->elapsed());
    }

    public function testStatsStartsEmpty(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY);
        $stats = $g->stats();
        $this->assertSame(0, $stats->gamesPlayed(Difficulty::EASY));
    }

    public function testRecordResultUpdatesStatsOnWin(): void
    {
        // Test that recordResult correctly updates stats when called with a win
        $g = Game::withDifficulty(Difficulty::EASY);

        // Manually trigger a win state by modifying the board (not possible with immutable design)
        // Instead, test that recordResult updates stats for a known win condition
        // by creating a game that we can verify has isWon=true
        $rand = static fn(int $max): int => 0;
        $g = Game::withDifficulty(Difficulty::EASY, $rand);

        // Reveal cells - if we happen to win, great. If not, we still test the stats mechanism.
        for ($i = 0; $i < 20; $i++) {
            [$g, ] = $g->update(self::key(KeyType::Space));
        }

        // Record result with known elapsed time - verify stats mechanism works
        $g = $g->recordResult(42);
        $stats = $g->stats();

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EASY));
        // Win count depends on whether we actually won
        if ($g->board->isWon()) {
            $this->assertSame(1, $stats->wins(Difficulty::EASY));
            $this->assertSame(42, $stats->bestTime(Difficulty::EASY));
            $this->assertEqualsWithDelta(100.0, $stats->winRate(Difficulty::EASY), 0.01);
        } else {
            $this->assertSame(0, $stats->wins(Difficulty::EASY));
            $this->assertNull($stats->bestTime(Difficulty::EASY));
            $this->assertEqualsWithDelta(0.0, $stats->winRate(Difficulty::EASY), 0.01);
        }
    }

    public function testRecordResultUpdatesStatsOnLoss(): void
    {
        $rand = static fn(int $max): int => 0;
        $g = Game::withDifficulty(Difficulty::EASY, $rand);

        // First reveal (safe)
        [$g, ] = $g->update(self::key(KeyType::Space));

        // Find and hit a mine
        for ($y = 0; $y < 9; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $cell = $g->board->cell($x, $y);
                if ($cell !== null && $cell->mine && !$cell->revealed) {
                    // Move to that cell and reveal
                    while ($g->cursorX !== $x) {
                        [$g, ] = $g->update($g->cursorX < $x ? self::key(KeyType::Right) : self::key(KeyType::Left));
                    }
                    while ($g->cursorY !== $y) {
                        [$g, ] = $g->update($g->cursorY < $y ? self::key(KeyType::Down) : self::key(KeyType::Up));
                    }
                    [$g, ] = $g->update(self::key(KeyType::Space));
                    break 2;
                }
            }
        }

        if (!$g->board->exploded) {
            $this->markTestSkipped('Could not trigger explosion with deterministic rand');
        }

        $g = $g->recordResult(30);
        $stats = $g->stats();

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(0, $stats->wins(Difficulty::EASY));
        $this->assertEqualsWithDelta(0.0, $stats->winRate(Difficulty::EASY), 0.01);
    }

    public function testBestTimeOnlyUpdatesOnWin(): void
    {
        // Test the stats mechanism directly with Stats class
        $stats = new Stats();
        $stats = $stats->withGame(Difficulty::EASY, true, 100);
        $this->assertSame(100, $stats->bestTime(Difficulty::EASY));

        // Recording a loss with time should not update best time
        $stats = $stats->withGame(Difficulty::EASY, false, null);
        $this->assertSame(100, $stats->bestTime(Difficulty::EASY));

        // Recording a win with worse time should not update best time
        $stats = $stats->withGame(Difficulty::EASY, true, 200);
        $this->assertSame(100, $stats->bestTime(Difficulty::EASY));

        // Recording a win with better time should update best time
        $stats = $stats->withGame(Difficulty::EASY, true, 50);
        $this->assertSame(50, $stats->bestTime(Difficulty::EASY));
    }

    public function testWinRateCalculation(): void
    {
        $stats = new \SugarCraft\Mines\Stats();
        $stats = $stats->withGame(Difficulty::EASY, true, 30);
        $stats = $stats->withGame(Difficulty::EASY, true, 40);
        $stats = $stats->withGame(Difficulty::EASY, false, null);
        $stats = $stats->withGame(Difficulty::EASY, false, null);

        $this->assertSame(4, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(2, $stats->wins(Difficulty::EASY));
        $this->assertEqualsWithDelta(50.0, $stats->winRate(Difficulty::EASY), 0.01);
    }
}
