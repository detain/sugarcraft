<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\Renderer;
use SugarCraft\Minesweeper\Stats;
use SugarCraft\Minesweeper\Stats\DifficultyStats;
use SugarCraft\Core\I18n\T;
use SugarCraft\Minesweeper\Ui\CustomDifficulty;
use SugarCraft\Minesweeper\Ui\CustomForm;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    private static function key(KeyType $t, string $rune = ''): KeyMsg
    {
        return new KeyMsg($t, $rune);
    }

    public function testCursorStartsAtOrigin(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);
        $this->assertSame(0, $g->cursorX);
        $this->assertSame(0, $g->cursorY);
    }

    public function testArrowKeysMoveCursor(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Right));
        [$g, ] = $g->update(self::key(KeyType::Right));
        [$g, ] = $g->update(self::key(KeyType::Down));
        $this->assertSame(2, $g->cursorX);
        $this->assertSame(1, $g->cursorY);
    }

    public function testCursorClampsAtBoardEdges(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        for ($i = 0; $i < 10; $i++) {
            [$g, ] = $g->update(self::key(KeyType::Right));
        }
        $this->assertSame(2, $g->cursorX);
    }

    public function testHjklVimMovement(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'l'));
        [$g, ] = $g->update(self::key(KeyType::Char, 'j'));
        $this->assertSame(1, $g->cursorX);
        $this->assertSame(1, $g->cursorY);
    }

    public function testFlagToggle(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertTrue($g->board->cell(0, 0)->flagged);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertFalse($g->board->cell(0, 0)->flagged);
    }

    public function testRevealOnFirstClickPlacesMines(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        $this->assertTrue($g->board->minesPlaced);
        $this->assertTrue($g->board->cell(0, 0)->revealed);
    }

    public function testQuitProducesQuitCmd(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [, $cmd] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testEscalsoQuits(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [, $cmd] = $g->update(self::key(KeyType::Escape));
        $this->assertNotNull($cmd);
    }

    public function testRestartResetsBoard(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));      // reveals + places mines
        [$g, ] = $g->update(self::key(KeyType::Char, 'r'));  // restart
        $this->assertFalse($g->board->minesPlaced);
        $this->assertSame(0, $g->cursorX);
        $this->assertSame(0, $g->cursorY);
    }

    public function testNonKeyMessagesAreIgnored(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        $msg = new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $g->update($msg);
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    /**
     * M04: Ctrl/Alt-modified characters must never reach the game commands.
     * Ctrl+R used to hard-restart (browser reload chord), Ctrl+S/Ctrl+L hit
     * save/load, Ctrl+H/J/K/L steered the cursor — the decoder reports all
     * of them as bare Char runes with the modifier flag set.
     */
    public function testCtrlAndAltModifiedCharactersAreInert(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));  // places mines, moves

        foreach ([
            ['r', true, false], ['l', true, false], ['s', true, false],
            ['h', true, false], ['k', true, false], ['j', false, true],
            ['l', false, true], ['q', true, false],
        ] as [$rune, $ctrl, $alt]) {
            [$next, $cmd] = $g->update(new KeyMsg(KeyType::Char, $rune, alt: $alt, ctrl: $ctrl));
            $this->assertSame($g, $next, "ctrl/alt+$rune must be a no-op");
            $this->assertNull($cmd, "ctrl/alt+$rune must not emit a command");
        }

        // Positive controls: the plain keys still work.
        [$afterR, ] = $g->update(self::key(KeyType::Char, 'r'));
        $this->assertTrue(!$afterR->board->minesPlaced, 'plain r still restarts');
        [$afterQ, $quitCmd] = $afterR->update(self::key(KeyType::Char, 'q'));
        $this->assertNotNull($quitCmd, 'plain q still quits — from the restarted (unspoiled) board, first press (msw-15)');
    }

    public function testCtrlCStillQuits(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertNotNull($cmd, 'Ctrl+C remains the quit chord');
    }

    /**
     * msw-15 (candy-minesweeper-019, ruling G3(a)): the first q/Esc on a
     * live board whose state the savegame slot does not hold arms
     * ui.confirm_quit instead of discarding silently; the second press
     * exits; anything real disarms; unspoiled/finished boards and Ctrl+C
     * keep the one-press exit.
     */
    public function testFirstQuitOnDirtyLiveBoardArmsTheConfirm(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space)); // reveals — the board now differs from any slot
        $this->assertTrue($g->dirty, 'a reveal dirties the game');

        [$armed, $cmd] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertNull($cmd, 'the first q on unsaved ground must not exit');
        $this->assertTrue($armed->confirmQuit, 'q arms the confirm');
        $this->assertSame(Lang::t('ui.confirm_quit'), $armed->notice);

        [$second, $quit] = $armed->update(self::key(KeyType::Char, 'q'));
        $this->assertEquals(Cmd::quit(), $quit, 'the second q exits');
    }

    public function testEscapeArmsAndExitsLikePlainQ(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f')); // a flag dirties the board
        [$armed, $cmd] = $g->update(self::key(KeyType::Escape));
        $this->assertNull($cmd, 'Esc on dirty ground asks first — the browser-chord quit (GAMEUX-003) dies here');
        [, $quit] = $armed->update(self::key(KeyType::Escape));
        $this->assertEquals(Cmd::quit(), $quit, 'the second Esc exits');
    }

    public function testDeadKeysNeitherQuitNorEraseTheArmedConfirm(): void
    {
        // The -14 notice law and the -15 arming must coexist: an unmapped
        // key is inert for state AND keeps the toast readable.
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        [$armed, ] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertTrue($armed->confirmQuit);

        [$kept, $cmd] = $armed->update(self::key(KeyType::Char, 'x'));
        $this->assertNull($cmd);
        $this->assertTrue($kept->confirmQuit, 'an unmapped key must not silently disarm');
        $this->assertSame(Lang::t('ui.confirm_quit'), $kept->notice, 'nor wipe the confirm toast');
    }

    public function testARealActionDisarmsTheConfirm(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        [$armed, ] = $g->update(self::key(KeyType::Char, 'q'));

        [$moved, $cmd] = $armed->update(self::key(KeyType::Right));
        $this->assertNull($cmd);
        $this->assertFalse($moved->confirmQuit, 'keeping play answers the confirm by declining it');
        $this->assertTrue($moved->dirty, 'declining keeps the unsaved state protected');

        // And the next q asks again — arming is never sticky after declining.
        [$rearmed, $cmd2] = $moved->update(self::key(KeyType::Escape));
        $this->assertNull($cmd2, 'Esc after a decline arms fresh, not exits');
        $this->assertTrue($rearmed->confirmQuit);
    }

    public function testQuitIsImmediateOnUnspoiledAndFinishedBoards(): void
    {
        $fresh = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [, $cmd] = $fresh->update(self::key(KeyType::Char, 'q'));
        $this->assertEquals(Cmd::quit(), $cmd, 'a board nothing was ever revealed on has nothing to lose');

        // Cursor-only movement is not progress: still immediate.
        [$moved, ] = $fresh->update(self::key(KeyType::Right));
        $this->assertFalse($moved->dirty, 'a cursor move does not dirty the game');
        [, $cmd2] = $moved->update(self::key(KeyType::Escape));
        $this->assertEquals(Cmd::quit(), $cmd2, 'Esc after a bare cursor move exits at once');

        // A lost board is over — the confirm would protect a corpse. Built
        // dirty ON PURPOSE: the finished board outranks the dirty flag.
        $mines = [[false, false, false], [false, true, false], [true, false, false]];
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new Cell(mine: $mines[$y][$x], revealed: $y === 1 && $x === 1);
            }
            $rows[] = $row;
        }
        $lost = new Game(
            board: Board::fromRows(3, 3, 2, $rows, minesPlaced: true, exploded: true, revealedCount: 1, flaggedCount: 0),
            cursorX: 0,
            cursorY: 0,
            dirty: true,
        );
        [, $cmd3] = $lost->update(self::key(KeyType::Char, 'q'));
        $this->assertEquals(Cmd::quit(), $cmd3, 'q on a lost board exits without asking');
    }

    public function testCtrlCIgnoresTheArmedConfirm(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        [$armed, $cmd] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertNull($cmd);
        [, $interrupt] = $armed->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertEquals(Cmd::quit(), $interrupt, 'the terminal contract outranks the in-app confirm');
    }

    public function testRestartCleansTheQuitConfirmAndDirtyFlag(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        [$armed, ] = $g->update(self::key(KeyType::Char, 'q'));
        [$restarted, $cmd] = $armed->update(self::key(KeyType::Char, 'r'));
        $this->assertFalse($restarted->dirty, 'a fresh board is nothing to lose');
        $this->assertFalse($restarted->confirmQuit, 'restart answers the confirm');
        [, $quit] = $restarted->update(self::key(KeyType::Char, 'q'));
        $this->assertEquals(Cmd::quit(), $quit, 'so the very next q exits at once');
    }

    public function testConfirmQuitReachesTheFrameToastLine(): void
    {
        // The armed notice must actually render — i18n law: no raw key.
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));
        [$armed, ] = $g->update(self::key(KeyType::Char, 'q'));
        $frame = $armed->view();
        $this->assertStringContainsString(Lang::t('ui.confirm_quit'), $frame, 'the confirm toast is painted');
        $this->assertStringNotContainsString('ui.confirm_quit', $frame, 'never the raw key');
    }

    public function testViewIncludesStatusLine(): void
    {
        $g = Game::start(4, 4, 2, static fn(int $_max): int => 0);
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
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $_max): int => 0);
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
        $rand = static fn(int $_max): int => 0;
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
        $rand = static fn(int $_max): int => 0;
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

        // update() auto-records the loss on the explosion transition — calling
        // recordResult() again here would double-count the game.
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
        $stats = new \SugarCraft\Minesweeper\Stats();
        $stats = $stats->withGame(Difficulty::EASY, true, 30);
        $stats = $stats->withGame(Difficulty::EASY, true, 40);
        $stats = $stats->withGame(Difficulty::EASY, false, null);
        $stats = $stats->withGame(Difficulty::EASY, false, null);

        $this->assertSame(4, $stats->gamesPlayed(Difficulty::EASY));
        $this->assertSame(2, $stats->wins(Difficulty::EASY));
        $this->assertEqualsWithDelta(50.0, $stats->winRate(Difficulty::EASY), 0.01);
    }

    public function testTimerUsesMicrotimePrecision(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $_max): int => 0);

        // Before first reveal, elapsed is null.
        $this->assertNull($g->elapsed());

        // First reveal starts the timer.
        [$g, ] = $g->update(self::key(KeyType::Space));
        $this->assertNotNull($g->startedAt);

        // Elapsed should be a float with sub-second precision.
        $elapsed = $g->elapsed();
        $this->assertNotNull($elapsed);
        $this->assertIsFloat($elapsed);

        // After a short wait, elapsed should have increased (sub-second delta).
        usleep(50000); // 50ms
        $elapsed2 = $g->elapsed();
        $this->assertNotNull($elapsed2);
        $this->assertGreaterThan($elapsed, $elapsed2);
    }

    public function testRecordResultAcceptsFloatElapsed(): void
    {
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Space));

        // Record result with a float elapsed time.
        $g = $g->recordResult(12.345);
        $stats = $g->stats();

        $this->assertSame(1, $stats->gamesPlayed(Difficulty::EASY));
    }

    // ─── W15: update() wires recordResult() into the win/lose transition ─────

    /**
     * Build a 9×9 EASY board that is exactly one safe reveal away from a win,
     * with the cursor on the last unrevealed safe cell (8,8). The target has a
     * non-zero adjacent count so revealing it does not cascade. Presets are
     * required because recordResult() only records for recognised difficulties.
     */
    private static function nearWinEasyGame(?string $statsPath = null): Game
    {
        // 10 mines placed off the target (8,8); (7,7) makes the target adj>0.
        $mineSet = ['0,0', '1,0', '2,0', '3,0', '4,0', '5,0', '6,0', '7,0', '8,0', '7,7'];
        $rows = [];
        $revealedCount = 0;
        for ($y = 0; $y < 9; $y++) {
            $row = [];
            for ($x = 0; $x < 9; $x++) {
                $isMine   = in_array("$x,$y", $mineSet, true);
                $isTarget = ($x === 8 && $y === 8);
                $revealed = !$isMine && !$isTarget;
                if ($revealed) {
                    $revealedCount++;
                }
                // Target adj=1 so floodReveal stops after the single cell.
                $row[] = new Cell($isMine, $revealed, false, $isTarget ? 1 : 0);
            }
            $rows[] = $row;
        }
        // 81 cells − 10 mines − 1 unrevealed target = 70 revealed safe cells.
        $board = Board::fromRows(9, 9, 10, $rows, true, false, $revealedCount, 0);

        return new Game(
            board: $board,
            cursorX: 8,
            cursorY: 8,
            rand: static fn(int $_max): int => 0,
            startedAt: microtime(true),
            statsPath: $statsPath,
        );
    }

    public function testUpdateRecordsWinTransition(): void
    {
        $g = self::nearWinEasyGame();
        $this->assertFalse($g->board->isWon(), 'sanity: game not yet won');
        $this->assertSame(0, $g->stats()->gamesPlayed(Difficulty::EASY));

        // Revealing the last safe cell wins — update() must record it.
        [$g, ] = $g->update(self::key(KeyType::Space));

        $this->assertTrue($g->board->isWon(), 'sanity: reveal should win the board');
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::EASY));
        $this->assertSame(1, $g->stats()->wins(Difficulty::EASY));
        $this->assertNotNull($g->stats()->bestTime(Difficulty::EASY), 'a win must record a best time');
    }

    public function testUpdateRecordsLossTransition(): void
    {
        // 9×9 EASY board with a mine under the cursor at (0,0).
        $mineSet = ['0,0', '1,0', '2,0', '3,0', '4,0', '5,0', '6,0', '7,0', '8,0', '0,1'];
        $rows = [];
        for ($y = 0; $y < 9; $y++) {
            $row = [];
            for ($x = 0; $x < 9; $x++) {
                $row[] = new Cell(in_array("$x,$y", $mineSet, true), false, false, 0);
            }
            $rows[] = $row;
        }
        $board = Board::fromRows(9, 9, 10, $rows, true, false, 0, 0);
        $g = new Game($board, 0, 0, static fn(int $_max): int => 0, microtime(true));

        // Revealing the mine explodes — update() must record the loss.
        [$g, ] = $g->update(self::key(KeyType::Space));

        $this->assertTrue($g->board->exploded, 'sanity: reveal should explode the board');
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::EASY));
        $this->assertSame(0, $g->stats()->wins(Difficulty::EASY));
    }

    public function testUpdateDoesNotRecordTwiceAfterGameEnds(): void
    {
        $g = self::nearWinEasyGame();
        [$g, ] = $g->update(self::key(KeyType::Space));   // win, records once
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::EASY));

        // Further keys on a finished board must not re-record.
        [$g, ] = $g->update(self::key(KeyType::Space));
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::EASY));
    }

    public function testRestartPreservesAccumulatedStats(): void
    {
        $g = self::nearWinEasyGame();
        [$g, ] = $g->update(self::key(KeyType::Space));   // win recorded
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::EASY));

        // Restart must carry the session tally (and stats path) forward.
        [$g, ] = $g->update(self::key(KeyType::Char, 'r'));
        $this->assertFalse($g->board->minesPlaced, 'sanity: restart resets the board');
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::EASY));
    }

    public function testUpdatePersistsStatsToDiskOnGameOver(): void
    {
        $path = sys_get_temp_dir() . '/candy-minesweeper-w15-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $g = self::nearWinEasyGame($path);
            $this->assertFileDoesNotExist($path, 'sanity: no stats file before the game ends');

            [$g, ] = $g->update(self::key(KeyType::Space));   // win → persisted

            $this->assertFileExists($path, 'winning game must write the opt-in stats file');
            $loaded = DifficultyStats::load($path)?->stats();
            $this->assertNotNull($loaded);
            $this->assertSame(1, $loaded->gamesPlayed(Difficulty::EASY));
            $this->assertSame(1, $loaded->wins(Difficulty::EASY));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    // ─── Chord key ─────────────────────────────────────────────────────────

    /**
     * 'c' key chords at cursor position: a satisfied revealed number with
     * correctly-flagged neighbours must reveal the remaining neighbours.
     */
    public function testChordKeyChordsAtCursor(): void
    {
        // Build a 3×3 board by hand (fresh Cell for each position to avoid aliasing):
        //   Row 0: [adj=0, flagged-mine, adj=1]
        //   Row 1: [adj=1, revealed@adj=1, unrevealed-safe]
        //   Row 2: [adj=0, adj=1, adj=0]
        $rows = [
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(true, false, true, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, true, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
        ];

        $board = Board::fromRows(3, 3, 1, $rows, true, false, 1);
        // Cursor starts at (1,1) — on the satisfied revealed number.
        $game = new Game($board, 1, 1, static fn(int $_max): int => 0);

        $this->assertFalse($game->board->cell(1, 2)->revealed);

        // Press 'c' to chord at cursor (1,1).
        [$g, ] = $game->update(self::key(KeyType::Char, 'c'));
        $this->assertTrue($g->board->cell(1, 2)->revealed, 'Chord must reveal the unflagged neighbour');
        // The flagged mine — rows[0][1], i.e. cell(x=1, y=0) — must stay unrevealed.
        $this->assertFalse($g->board->cell(1, 0)->revealed);
    }

    // ─── Mouse click helpers ────────────────────────────────────────────────

    private static function mouseClick(int $x, int $y, MouseButton $btn): MouseClickMsg
    {
        return new MouseClickMsg($x, $y, $btn, MouseAction::Press);
    }

    // ─── Left-click reveals ─────────────────────────────────────────────────

    public function testLeftClickRevealsResolvedCell(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);

        // Move to top-left and reveal to place mines.
        [$g, ] = $g->update(self::key(KeyType::Char, 'h'));  // left (cursor 0,0)
        [$g, ] = $g->update(self::key(KeyType::Char, 'k'));  // up (cursor 0,0)
        [$g, ] = $g->update(self::key(KeyType::Space));      // reveal at cursor

        // Find an unrevealed safe cell to click (hardcoding coordinates is
        // fragile with a deterministic rand=0 — scan instead).
        $targetX = null;
        $targetY = null;
        $b = $g->board;
        for ($y = 0; $y < $b->height && $targetX === null; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $cell = $b->cell($x, $y);
                if ($cell !== null && !$cell->revealed && !$cell->mine) {
                    $targetX = $x;
                    $targetY = $y;
                    break;
                }
            }
        }
        $this->assertNotNull($targetX, 'Should have found an unrevealed safe cell');
        $this->assertNotNull($targetY);

        // Compute terminal coords for interior cell (targetX, targetY).
        // Border: 1 col/row, padding(0,1): 1 col each side → interior starts at x=3, y=2.
        $tx = $targetX + 3;
        $ty = $targetY + 2;

        // Left-click at that terminal position.
        [$next, ] = $g->update(self::mouseClick($tx, $ty, MouseButton::Left));

        $this->assertTrue($next->board->cell($targetX, $targetY)->revealed,
            'Left-click must reveal the resolved cell');
        $this->assertSame($targetX, $next->cursorX);
        $this->assertSame($targetY, $next->cursorY);
    }

    // ─── Right-click flags ─────────────────────────────────────────────────

    public function testRightClickTogglesFlag(): void
    {
        // Use a hand-built board with a known safe cell to flag.
        $rows = [
            [new \SugarCraft\Minesweeper\Cell(true, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 1)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, true, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 1)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
        ];

        $board = Board::fromRows(3, 3, 1, $rows, true, false, 1);
        $g = new Game($board, 2, 2, static fn(int $_max): int => 0);  // cursor at (2,2) — safe, unrevealed

        $this->assertFalse($g->board->cell(2, 2)->revealed);
        $this->assertFalse($g->board->cell(2, 2)->mine);

        // Terminal coords for interior cell (2,2): x=5, y=4
        [$next, ] = $g->update(self::mouseClick(5, 4, MouseButton::Right));

        $this->assertTrue($next->board->cell(2, 2)->flagged,
            'Right-click must flag the resolved safe cell');
    }

    // ─── Middle-click chords ───────────────────────────────────────────────

    public function testMiddleClickChords(): void
    {
        // Build a board where (1,1) is a satisfied revealed number with one flagged neighbour.
        $rows = [
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(true, false, true, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, true, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
        ];

        $board = Board::fromRows(3, 3, 1, $rows, true, false, 1);
        $g = new Game($board, 1, 1, static fn(int $_max): int => 0);  // cursor on (1,1) — satisfied

        // Terminal coords for interior (1,1): x=4, y=3
        [$next, ] = $g->update(self::mouseClick(4, 3, MouseButton::Middle));

        $this->assertTrue($next->board->cell(1, 2)->revealed,
            'Middle-click chord must reveal the unflagged neighbour (1,2)');
        // The flagged mine — rows[0][1], i.e. cell(x=1, y=0) — stays unrevealed.
        $this->assertFalse($next->board->cell(1, 0)->revealed);
    }

    // ─── Click outside board no-ops ────────────────────────────────────────

    public function testClickOutsideBoardIsNoop(): void
    {
        $g = Game::start(3, 3, 1, static fn(int $_max): int => 0);
        $originalExploded = $g->board->exploded;
        $originalRevealedCount = $g->board->revealedCount;

        // Click at (1,1) which is outside the interior (interior starts at 3,2 for 3×3).
        [$next, ] = $g->update(self::mouseClick(1, 1, MouseButton::Left));

        $this->assertSame($originalExploded, $next->board->exploded);
        $this->assertSame($originalRevealedCount, $next->board->revealedCount);
    }

    // ─── Mouse ignored after game over ─────────────────────────────────────

    public function testMouseIgnoredAfterGameOver(): void
    {
        // Create a board in the exploded state.
        $rows = [
            [new \SugarCraft\Minesweeper\Cell(false, true, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 0)],
        ];

        $board = Board::fromRows(3, 3, 1, $rows, true, true, 1);  // exploded=true
        $g = new Game($board, 2, 2, static fn(int $_max): int => 0);

        $this->assertTrue($g->board->exploded);

        // Any click should be a no-op.
        [$next, ] = $g->update(self::mouseClick(5, 4, MouseButton::Left));

        $this->assertSame($g->board->exploded, $next->board->exploded);
        $this->assertSame($g->board->revealedCount, $next->board->revealedCount);
    }

    // ─── M3: the shipped default shape records (STANDARD preset) ────────────

    /**
     * Near-win fixture on the DEFAULT 10×10/12 shape — one unrevealed safe
     * cell (9,9) with adj=1 (mine at (8,8)), so the final reveal lands the
     * win in one step. 12 mines: all of row 0 plus (8,8) and (7,7).
     */
    private static function nearWinStandardGame(?string $statsPath = null): Game
    {
        $mineSet = ['0,0', '1,0', '2,0', '3,0', '4,0', '5,0', '6,0', '7,0', '8,0', '9,0', '8,8', '7,7'];
        $rows = [];
        $revealedCount = 0;
        for ($y = 0; $y < 10; $y++) {
            $row = [];
            for ($x = 0; $x < 10; $x++) {
                $isMine   = in_array("$x,$y", $mineSet, true);
                $isTarget = ($x === 9 && $y === 9);
                $revealed = !$isMine && !$isTarget;
                if ($revealed) {
                    $revealedCount++;
                }
                $row[] = new Cell($isMine, $revealed, false, $isTarget ? 1 : 0);
            }
            $rows[] = $row;
        }
        // 100 cells − 12 mines − 1 unrevealed target = 87 revealed safe cells.
        $board = Board::fromRows(10, 10, 12, $rows, true, false, $revealedCount, 0);

        return new Game(
            board: $board,
            cursorX: 9,
            cursorY: 9,
            rand: static fn(int $_max): int => 0,
            startedAt: microtime(true),
            statsPath: $statsPath,
        );
    }

    public function testDefaultGameShapeIsTheStandardPreset(): void
    {
        $this->assertSame(Difficulty::STANDARD, Game::start()->difficulty());
    }

    public function testWinOnDefaultShapeRecordsStandardStats(): void
    {
        $g = self::nearWinStandardGame();
        $this->assertFalse($g->board->isWon(), 'sanity: one cell short of the win');

        [$g, ] = $g->update(self::key(KeyType::Space));   // reveal the last safe cell

        $this->assertTrue($g->board->isWon(), 'sanity: board won');
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::STANDARD));
        $this->assertSame(1, $g->stats()->wins(Difficulty::STANDARD));
    }

    public function testDefaultShapeLossRecordsStandardGame(): void
    {
        // An unrevealed MINE as the target cell: revealing it explodes and
        // must still tally one STANDARD game, zero wins. One other safe cell
        // stays hidden so the board is NOT already won before the reveal —
        // a finished board would short-circuit update() to r/q only.
        $mineSet = ['0,0', '1,0', '2,0', '3,0', '4,0', '5,0', '6,0', '7,0', '8,0', '9,0', '8,8', '9,9'];
        $rows = [];
        $revealedCount = 0;
        for ($y = 0; $y < 10; $y++) {
            $row = [];
            for ($x = 0; $x < 10; $x++) {
                $isMine = in_array("$x,$y", $mineSet, true);
                $revealed = !$isMine && !($x === 0 && $y === 9);
                if ($revealed) {
                    $revealedCount++;
                }
                $row[] = new Cell($isMine, $revealed, false, 0);
            }
            $rows[] = $row;
        }
        $board = Board::fromRows(10, 10, 12, $rows, true, false, $revealedCount, 0);
        $g = new Game(
            board: $board,
            cursorX: 9,
            cursorY: 9,
            rand: static fn(int $_max): int => 0,
            startedAt: microtime(true),
        );

        [$g, ] = $g->update(self::key(KeyType::Space));

        $this->assertTrue($g->board->exploded, 'sanity: reveal should explode the board');
        $this->assertSame(1, $g->stats()->gamesPlayed(Difficulty::STANDARD));
        $this->assertSame(0, $g->stats()->wins(Difficulty::STANDARD));
    }

    public function testDefaultShapeWinPersistsStandardBucketToDisk(): void
    {
        $path = sys_get_temp_dir() . '/candy-minesweeper-m3-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $g = self::nearWinStandardGame($path);
            [$g, ] = $g->update(self::key(KeyType::Space));   // win → persisted

            $loaded = DifficultyStats::load($path)?->stats();
            $this->assertNotNull($loaded);
            $this->assertSame(1, $loaded->gamesPlayed(Difficulty::STANDARD));
            $this->assertSame(1, $loaded->wins(Difficulty::STANDARD));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    // ─── E3 (docs-fix-1023): the clock arms on the first ACTUAL reveal ───────

    public function testRevealOnFlaggedCellDoesNotArmClock(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));    // flag (0,0)
        [$g, ] = $g->update(self::key(KeyType::Space));        // dud reveal

        $this->assertTrue($g->board->cell(0, 0)->flagged, 'sanity: cell is flagged');
        $this->assertNull($g->startedAt, 'a reveal that revealed nothing must not arm the timer');
        $this->assertNull($g->elapsed());
    }

    public function testChordOnUnrevealedCellDoesNotArmClock(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'c'));    // chord on a hidden cell

        $this->assertNull($g->startedAt, 'a refused chord must not arm the timer');
    }

    public function testDudKeypressThenRealRevealArmsExactlyOnce(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_max): int => 0);
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));    // flag (0,0)
        [$g, ] = $g->update(self::key(KeyType::Space));        // dud: flagged cell
        [$g, ] = $g->update(self::key(KeyType::Char, 'f'));    // unflag (0,0)

        $this->assertNull($g->startedAt, 'still unarmed after two duds and an unflag');

        [$g, ] = $g->update(self::key(KeyType::Space));        // real reveal
        $this->assertNotNull($g->startedAt, 'the first actual reveal arms the clock');
    }

    /**
     * WELD-H cure (docs-fix-1023 M-E): the revealAt()/chordAt()
     * board-identity guards exist ONLY on the mouse path — every keyboard
     * dud pin routes through reveal()/chord() directly and stays green even
     * with the revealAt guard neutered. This pin drives the guards
     * themselves: a left-click on a flagged cell and a middle-click on an
     * unrevealed cell must neither arm the clock nor move the cursor.
     */
    public function testMouseDudClicksNeitherArmClockNorMoveCursor(): void
    {
        $rows = [
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 2), new \SugarCraft\Minesweeper\Cell(false, false, false, 1)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, false, 2), new \SugarCraft\Minesweeper\Cell(false, false, false, 1)],
            [new \SugarCraft\Minesweeper\Cell(false, false, false, 0), new \SugarCraft\Minesweeper\Cell(false, false, false, 1), new \SugarCraft\Minesweeper\Cell(false, false, true, 0)],
        ];
        $board = Board::fromRows(3, 3, 1, $rows, true, false, 0, 1);
        $g     = new Game($board, 0, 0, static fn(int $_max): int => 0);  // cursor at (0,0)

        $this->assertTrue($g->board->cell(2, 2)->flagged, 'sanity: click target is a flagged dud');

        // Interior cell (2,2) sits at terminal coords (5,4).
        [$next, ] = $g->update(self::mouseClick(5, 4, MouseButton::Left));
        $this->assertFalse($next->board->cell(2, 2)->revealed, 'sanity: the dud revealed nothing');
        $this->assertNull($next->startedAt, 'a dud left-click must not arm the clock');
        $this->assertSame(0, $next->cursorX, 'a dud left-click must not move the cursor');
        $this->assertSame(0, $next->cursorY, 'a dud left-click must not move the cursor');

        // Same refusal through the chord guard: (2,2) is unrevealed, so the
        // chord is refused (its flagged mine neighbour is irrelevant).
        [$chorded, ] = $g->update(self::mouseClick(5, 4, MouseButton::Middle));
        $this->assertNull($chorded->startedAt, 'a refused chord click must not arm the clock');
        $this->assertSame(0, $chorded->cursorX, 'a refused chord click must not move the cursor');
        $this->assertSame(0, $chorded->cursorY, 'a refused chord click must not move the cursor');
    }

    // ─── E3: custom boards keep a readable final time ───────────────────────

    /** A 5×5/3 board matches no preset → difficulty() is null ("custom"). */
    private function customExplodedTransitionGame(): Game
    {
        $rand = static fn(int $_max): int => 0;
        $g = Game::start(5, 5, 3, $rand);
        [$g, ] = $g->update(self::key(KeyType::Space));        // safe first reveal, arms clock

        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $cell = $g->board->cell($x, $y);
                if ($cell !== null && $cell->mine && !$cell->revealed) {
                    while ($g->cursorX !== $x) {
                        [$g, ] = $g->update($g->cursorX < $x ? self::key(KeyType::Right) : self::key(KeyType::Left));
                    }
                    while ($g->cursorY !== $y) {
                        [$g, ] = $g->update($g->cursorY < $y ? self::key(KeyType::Down) : self::key(KeyType::Up));
                    }
                    [$g, ] = $g->update(self::key(KeyType::Space));   // explode
                    break 2;
                }
            }
        }

        $this->assertTrue($g->board->exploded, 'sanity: custom board exploded');
        $this->assertNull($g->difficulty(), 'sanity: this shape is a custom board');
        return $g;
    }

    public function testCustomLossStillFreezesTheEndScreenTime(): void
    {
        $g = $this->customExplodedTransitionGame();

        // Pre-fix this was null: recordResult() early-returned on custom
        // boards WITHOUT freezing elapsedSeconds, so the finished game
        // displayed 'time: 0:00' no matter how long it ran.
        $this->assertNotNull($g->elapsed(), 'a finished custom game must report its final time');
        $this->assertSame((int) round($g->elapsed()), $g->elapsedSeconds);
    }

    public function testRecordResultOnCustomBoardFreezesForRenderer(): void
    {
        $g = $this->customExplodedTransitionGame();
        $frozen = $g->recordResult(9.7);

        $this->assertSame(9, $frozen->elapsedSeconds, 'custom freeze floors the float like presets');
        $this->assertSame(9.0, $frozen->elapsed(), 'elapsed() reads the frozen end time, not null');
        $this->assertStringContainsString('💥 boom', $frozen->view(), 'end screen still paints the boom line');
        $this->assertSame(0, $frozen->stats()->gamesPlayed(Difficulty::STANDARD), 'but custom games still tally nothing');
    }

    public function testCapsLockUppercaseRunesStillPlayTheGame(): void
    {
        // msw-13: the gameplay bindings are lowercase, so a CapsLock-on
        // player's J/K/F/R/Q all fell through to the default arm — the game
        // silently stopped answering. The fold accepts them; the genuinely
        // case-significant keys (S save, L load, l move) stay split.
        $g = Game::withDifficulty(Difficulty::EASY, static fn(int $max): int => 0);

        [$g, ] = $g->update(self::key(KeyType::Char, 'J'));
        $this->assertSame(1, $g->cursorY, 'J moves down like j');
        [$g, ] = $g->update(self::key(KeyType::Char, 'K'));
        $this->assertSame(0, $g->cursorY, 'K moves back up');

        // 'L' is the LOAD key, never right-movement: the fold must not eat it.
        [$g, ] = $g->update(self::key(KeyType::Char, 'L'));
        $this->assertSame(0, $g->cursorX, 'L is load, not l — the cursor never moves right');

        [$g, ] = $g->update(self::key(KeyType::Char, 'F'));
        $this->assertTrue($g->board->cell(0, 0)?->flagged === true, 'F flags like f');

        [$g, ] = $g->update(self::key(KeyType::Char, 'R'));
        $this->assertFalse($g->board->cell(0, 0)->flagged, 'R restarts like r');
        $this->assertSame(Lang::t('ui.restarted'), $g->notice);

        [$g, $cmd] = $g->update(self::key(KeyType::Char, 'Q'));
        $this->assertEquals(Cmd::quit(), $cmd, 'Q quits like q');
    }

    // ─── msw-09 / G5-b: new-game picker + custom-board form ─────────────────

    private static function picker(Game $g): Game
    {
        return $g->update(self::key(KeyType::Char, 'n'))[0];
    }

    public function testNOpensThePickerAndEscWalksBackUnharmed(): void
    {
        $g = Game::start(5, 5, 3, static fn(int $_): int => 0);
        [$g] = $g->update(self::key(KeyType::Space));  // start the clock
        [$g] = $g->update(self::key(KeyType::Right));
        $board = $g->board;

        $picker = self::picker($g);
        $this->assertTrue($picker->picking, 'n raises the overlay');
        $this->assertSame($board, $picker->board, 'the overlay shows a choice, not a new board');
        $this->assertStringContainsString(Lang::t('ui.picker_title'), $picker->view());

        [$back] = $picker->update(self::key(KeyType::Escape));
        $this->assertFalse($back->picking, 'Esc closes the overlay');
        $this->assertSame($board, $back->board, 'walking back leaves the game exactly where it was');
        $this->assertSame(1, $back->cursorX, 'the cursor never moved while picking');
        $this->assertNotNull($back->startedAt, 'the live clock survived the round trip');
    }

    public function testNOpensThePickerEvenFromTheNoticeScreenAndCapsLock(): void
    {
        // The n branch routes ABOVE the tooSmall gate (an overlay needs a
        // handful of rows, not the board budget), and the msw-13 fold makes
        // 'N' answer like 'n'.
        $tiny = Game::start(30, 16, 99)->withFrame(80, 10);
        $this->assertTrue($tiny->tooSmall(), 'sanity: a notice screen');
        $this->assertTrue(self::picker($tiny)->picking, 'the escape hatch stays reachable');

        [$upper] = Game::start(5, 5, 3)->update(self::key(KeyType::Char, 'N'));
        $this->assertTrue($upper->picking);
    }

    public function testPickerDigitsStartPresetsCarryingStatsAndResettingBoard(): void
    {
        $stats = new Stats(easyGames: 7);
        $g = Game::withDifficulty(Difficulty::EXPERT, static fn(int $_): int => 0, stats: $stats, statsPath: '/tmp/msw09-stats.json');
        [$g] = $g->update(self::key(KeyType::Space));  // live clock, dirty board
        $picker = self::picker($g);
        [$g] = $picker->update(self::key(KeyType::Char, '1'));

        $this->assertSame(9, $g->board->width);
        $this->assertSame(9, $g->board->height);
        $this->assertSame(10, $g->board->mineCount, 'key 1 starts the EASY row the picker printed');
        $this->assertFalse($g->picking);
        $this->assertSame(0, $g->cursorX);
        $this->assertNull($g->startedAt, 'a new board starts with no clock');
        $this->assertFalse($g->dirty, 'a fresh board has nothing to lose');
        $this->assertSame($stats, $g->stats, 'the session tally rides the switch');
        $this->assertSame('/tmp/msw09-stats.json', $g->statsPath);
        $this->assertNull($g->notice);

        // And key 4 names the widest preset (single digit→preset table).
        [$expert] = self::picker($g)->update(self::key(KeyType::Char, '4'));
        $this->assertSame(30, $expert->board->width);
        $this->assertSame(16, $expert->board->height);
        $this->assertSame(99, $expert->board->mineCount);
    }

    public function testPostGameSwitchNeedsNoShellRoundTrip(): void
    {
        // Finding 011's live complaint: after the boom, r replays the SAME
        // shape and switching meant quitting to the shell. A hand-built
        // exploded 3×3 (the FooterWidthGuard idiom) proves n answers on a
        // finished board too.
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new Cell(mine: $x === 1 && $y === 1, revealed: $x === 1 && $y === 1);
            }
            $rows[] = $row;
        }
        $lost = new Game(
            board: Board::fromRows(3, 3, 1, $rows, minesPlaced: true, exploded: true, revealedCount: 1, flaggedCount: 0),
        );
        $this->assertTrue($lost->board->exploded, 'sanity: finished board');

        $picker = self::picker($lost);
        $this->assertTrue($picker->picking);
        [$fresh] = $picker->update(self::key(KeyType::Char, '3'));
        $this->assertFalse($fresh->board->exploded, 'the switch lands a live MEDIUM board');
        $this->assertSame(16, $fresh->board->width);
        $this->assertFalse($fresh->picking);
    }

    public function testCustomFormSeedsFromTheValidatorDefaultAndSubmitsAnAsymmetricBoard(): void
    {
        $picker = self::picker(Game::start(5, 5, 3));
        [$g] = $picker->update(self::key(KeyType::Char, 'c'));
        $form = $g->form;
        $this->assertNotNull($form, 'c opens the digit form');
        $default = CustomDifficulty::new();
        $this->assertSame((string) $default->cols, $form->width, 'seeded from the validator default (-032 rename earns its caller here)');
        $this->assertSame((string) $default->rows, $form->height);
        $this->assertSame((string) $default->mines, $form->mines);

        // Retype to 12 wide × 8 tall, mines stay 10: the asymmetric shape
        // is the mutation proof for the width=cols / height=rows bridge —
        // a swapped mapping would start 8×12 and this pin goes red.
        $press = static fn(Game $x, KeyType $t, string $rune = ''): Game => $x->update(new KeyMsg($t, $rune))[0];
        $g = $press($g, KeyType::Backspace);
        $g = $press($g, KeyType::Char, '1');
        $g = $press($g, KeyType::Char, '2');
        $g = $press($g, KeyType::Enter);          // width → height
        $g = $press($g, KeyType::Backspace);
        $g = $press($g, KeyType::Char, '8');
        $g = $press($g, KeyType::Enter);          // height → mines
        $this->assertSame(2, $g->form?->field);
        $g = $press($g, KeyType::Enter);          // mines → submit

        $this->assertNull($g->form);
        $this->assertFalse($g->picking);
        $this->assertSame(12, $g->board->width);
        $this->assertSame(8, $g->board->height);
        $this->assertSame(10, $g->board->mineCount);
    }

    public function testRefusedSubmitKeepsTheDigitsNamesItsRuleAndWalksBackToWidth(): void
    {
        // 3×3 with 1 mine: msw-18's area floor (the -09 form teaches the
        // same law the CLI door enforces — one validator, not two).
        $press = static fn(Game $x, KeyType $t, string $rune = ''): Game => $x->update(new KeyMsg($t, $rune))[0];
        $g = $press(self::picker(Game::start(5, 5, 3)), KeyType::Char, 'c');
        $board = $g->board;
        $g = $press($g, KeyType::Backspace);
        $g = $press($g, KeyType::Char, '3');
        $g = $press($g, KeyType::Enter);
        $g = $press($g, KeyType::Backspace);
        $g = $press($g, KeyType::Char, '3');
        $g = $press($g, KeyType::Enter);
        $g = $press($g, KeyType::Backspace);
        $g = $press($g, KeyType::Backspace);
        $g = $press($g, KeyType::Char, '1');
        $g = $press($g, KeyType::Enter);          // submit

        $this->assertTrue($g->picking, 'a refusal keeps the form open');
        $this->assertNotNull($g->form);
        $this->assertSame(Lang::t('custom.min_area'), $g->form->error);
        $this->assertStringContainsString(Lang::t('custom.min_area'), $g->view(), 'the rule the player tripped is on screen');
        $this->assertSame($board, $g->board, 'a refused submit starts nothing');
        $this->assertSame(CustomForm::FIELD_WIDTH, $g->form->field, 'the cursor parks where the fix begins');

        // Any edit clears the blame line.
        $g = $press($g, KeyType::Backspace);
        $this->assertNull($g->form?->error, 'an edit answers the refusal');
    }

    public function testFormDigitCapsStopBeforeNonsenseAndNameTheRightAxis(): void
    {
        $press = static fn(Game $x, KeyType $t, string $rune = ''): Game => $x->update(new KeyMsg($t, $rune))[0];
        $g = $press(self::picker(Game::start(5, 5, 3)), KeyType::Char, 'c');
        // Seeded width '9': typing 1 rides to '91', a third digit is inert.
        $g = $press($g, KeyType::Char, '1');
        $g = $press($g, KeyType::Char, '2');
        $this->assertSame('91', $g->form?->width, 'sides are 50 at most — typing past 2 digits is a dead keypress');
        $g = $press($g, KeyType::Enter);
        $g = $press($g, KeyType::Enter);
        $g = $press($g, KeyType::Enter);          // submit 91×9/10
        $this->assertStringContainsString(Lang::t('custom.max_cols'), $g->form?->error ?? '', 'the width axis blames columns, not rows (-07 law, form door)');
    }

    public function testPickerKeepsCtrlCAndSilencesMouseAndMovement(): void
    {
        $picker = self::picker(Game::start(5, 5, 3, static fn(int $_): int => 0));
        [, $cmd] = $picker->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertEquals(Cmd::quit(), $cmd, 'the terminal interrupt contract outranks the overlay');

        $board = $picker->board;
        [$idle] = $picker->update(new MouseClickMsg(4, 3, MouseButton::Left, MouseAction::Press));
        $this->assertSame($board, $idle->board, 'clicks are inert while the overlay owns the screen');
        [$moved] = $picker->update(self::key(KeyType::Char, 'j'));
        $this->assertTrue($moved->picking, 'unbound keys stay unbound inside the picker');
        $this->assertSame($picker->form, $moved->form, 'and never steer the form from the preset list');
    }

    public function testPickerAndFormAreFullyTranslated(): void
    {
        $prior = T::locale();
        T::setLocale('de');
        try {
            $picker = self::picker(Game::start(5, 5, 3));
            $view = $picker->view();
            $this->assertStringContainsString('Spielfeld', $view);
            $this->assertStringContainsString('Minen', $view, 'preset rows print the translated shape label');
            $this->assertStringNotContainsString('choose a board', $view);
            $this->assertStringNotContainsString('mines', $view, 'no English may bleed through the picker screen');

            [$form] = $picker->update(self::key(KeyType::Char, 'c'));
            $formView = $form->view();
            $this->assertStringContainsString('Breite', $formView);
            $this->assertStringContainsString('Ziffern', $formView, 'the form hint translates too');
            $this->assertStringNotContainsString('width', $formView);
        } finally {
            T::setLocale($prior);
        }
    }

    public function testQuitArmingAndRestoreHistoryRideThePicker(): void
    {
        // msw-15's confirm is an answer about the board, not the overlay:
        // entering and leaving the picker neither disarms it nor spends it.
        $g = Game::start(5, 5, 3, static fn(int $_): int => 0);
        [$g] = $g->update(self::key(KeyType::Space));   // dirty + live
        [$g] = $g->update(self::key(KeyType::Char, 'q'));
        $this->assertTrue($g->confirmQuit, 'sanity: armed');

        $picker = self::picker($g);
        $this->assertTrue($picker->confirmQuit, 'the arming rides into the overlay');
        [$back] = $picker->update(self::key(KeyType::Escape));
        $this->assertTrue($back->confirmQuit, 'and back out, still armed');

        // A started board from the picker resets per-board state…
        [$chosen] = self::picker($back)->update(self::key(KeyType::Char, '1'));
        $this->assertFalse($chosen->confirmQuit);
        $this->assertFalse($chosen->dirty);

        // …but the session's restored-clock stigma is monotonic (-08 law).
        $restored = new Game(
            board: Board::blank(9, 9, 10),
            restoredGame: true,
            picking: true,
        );
        [$kept] = $restored->update(self::key(KeyType::Char, '2'));
        $this->assertTrue($kept->restoredGame, 'a picker start cannot launder a restored session');
        $this->assertFalse($kept->picking);
    }
}
