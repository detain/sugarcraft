<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\LockDelayMsg;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Score;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    private function deterministicGame(): Game
    {
        return Game::start(new Bag(static fn(int $_max): int => 0));
    }

    /** @return array<int, array<int, ?Tetromino>> */
    private function emptyGrid(): array
    {
        $grid = [];
        for ($row = 0; $row < Board::ROWS; $row++) {
            $grid[$row] = array_fill(0, Board::COLS, null);
        }
        return $grid;
    }

    /**
     * A T-Spin Double slot: a T piece rotating into (5,14) rotation 1 finds
     * all four bounding corners filled — (5,13), (8,13), (5,17) and (8,17) —
     * and the row-17 fill (cols 5-7) grounds the piece exactly at y=14.
     *
     * @return array<int, array<int, ?Tetromino>>
     */
    private function tspinSlotGrid(): array
    {
        $rows = $this->emptyGrid();
        foreach ([5, 6, 7] as $col) {
            $rows[17][$col] = Tetromino::I;
        }
        $rows[13][5] = Tetromino::I;
        $rows[13][8] = Tetromino::I;
        $rows[17][8] = Tetromino::I;
        return $rows;
    }

    private function key(string $which): KeyMsg
    {
        return match ($which) {
            'left' => new KeyMsg(KeyType::Left, ''),
            'right' => new KeyMsg(KeyType::Right, ''),
            'down' => new KeyMsg(KeyType::Down, ''),
            'up' => new KeyMsg(KeyType::Up, ''),
            default => new KeyMsg(KeyType::Char, $which),
        };
    }

    private function gravity(): GravityMsg
    {
        return new GravityMsg();
    }

    public function testStartSpawnsFirstPiece(): void
    {
        $g = $this->deterministicGame();
        $this->assertNotNull($g->piece);
        $this->assertFalse($g->over);
        $this->assertFalse($g->paused);
    }

    public function testInitReturnsTickClosure(): void
    {
        $g = $this->deterministicGame();
        $cmd = $g->init();
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testStartArmsTheGravityChainOnce(): void
    {
        // The singleton guard: start() declares the chain in flight so no
        // input path can stack a second gravity tick on top of init()'s.
        $g = $this->deterministicGame();
        $this->assertTrue($g->gravityPending);
    }

    public function testQuitKeyDispatchesQuit(): void
    {
        $g = $this->deterministicGame();
        [, $cmd] = $g->update($this->key('q'));
        $this->assertInstanceOf(\Closure::class, $cmd, 'q must dispatch a quit Cmd');
    }

    public function testLeftKeyMovesPieceLeft(): void
    {
        $g = $this->deterministicGame();
        $startX = $g->piece->x;
        [$next] = $g->update($this->key('left'));
        $this->assertSame($startX - 1, $next->piece->x);
    }

    public function testRightKeyMovesPieceRight(): void
    {
        $g = $this->deterministicGame();
        $startX = $g->piece->x;
        [$next] = $g->update($this->key('right'));
        $this->assertSame($startX + 1, $next->piece->x);
    }

    public function testUpKeyRotatesPiece(): void
    {
        $g = $this->deterministicGame();
        $startRot = $g->piece->rotation;
        [$next] = $g->update($this->key('up'));
        $this->assertSame(($startRot + 1) % 4, $next->piece->rotation);
    }

    public function testGravityAdvancesPieceDownOneRow(): void
    {
        $g = $this->deterministicGame();
        $startY = $g->piece->y;
        [$next, $cmd] = $g->update($this->gravity());
        $this->assertSame($startY + 1, $next->piece->y);
        $this->assertInstanceOf(\Closure::class, $cmd, 'gravity must reschedule the next tick');
    }

    public function testHardDropDoesNotStackGravityTickWhilePending(): void
    {
        // CRIT regression (audit finding 1): the old hardDrop re-armed
        // gravity even though init()'s tick was still in flight, permanently
        // adding one timer per drop. The pending-singleton guard means the
        // lock reuses the tick already scheduled — Cmd must come back null.
        $g = $this->deterministicGame();
        $this->assertTrue($g->gravityPending, 'start() leaves one tick in flight');
        [$next, $cmd] = $g->update($this->key(' '));
        $this->assertNotSame($g->piece, $next->piece, 'piece locked + new piece spawned');
        $this->assertNull($cmd, 'a pending gravity tick must not be double-armed');
        $this->assertTrue($next->gravityPending, 'the guard keeps the chain alive exactly once');
    }

    public function testPauseTogglesAndIgnoresMovementUntilUnpaused(): void
    {
        $g = $this->deterministicGame();
        [$paused] = $g->update($this->key('p'));
        $this->assertTrue($paused->paused);

        $startX = $paused->piece->x;
        [$stillPaused] = $paused->update($this->key('left'));
        $this->assertSame($startX, $stillPaused->piece->x, 'paused game ignores movement');

        [$resumed] = $paused->update($this->key('p'));
        $this->assertFalse($resumed->paused);
    }

    public function testGameOverOnlyHonorsQuit(): void
    {
        $g = $this->deterministicGame();
        $over = $g->mutate(['over' => true]);
        [$samePiece1] = $over->update($this->key('left'));
        $this->assertSame($over->piece, $samePiece1->piece);
        [, $cmd] = $over->update($this->key('q'));
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testComboStartsAtZero(): void
    {
        $g = $this->deterministicGame();
        $this->assertSame(0, $g->combo);
        $this->assertFalse($g->backToBack);
    }

    public function testBackToBackStartsFalse(): void
    {
        $g = $this->deterministicGame();
        $this->assertFalse($g->backToBack);
    }

    public function testPerfectClearBonusConstant(): void
    {
        $this->assertSame(5000, Game::PERFECT_CLEAR_BONUS);
    }

    public function testB2BMultiplierConstant(): void
    {
        $this->assertSame(1.5, Game::B2B_MULTIPLIER);
    }

    public function testDefaultLockDelayConstants(): void
    {
        $this->assertSame(500, Game::DEFAULT_LOCK_DELAY_MS);
        $this->assertSame(15, Game::LOCK_DELAY_RESET_CAP);
    }

    public function testHoldKeyStoresPieceInHold(): void
    {
        $g = $this->deterministicGame();
        $this->assertNull($g->hold);
        $this->assertTrue($g->canHold);

        [$next] = $g->update($this->key('c'));

        // After holding, the piece should be stored and a new piece spawned
        $this->assertNotNull($next->hold);
        $this->assertSame($g->piece->kind, $next->hold);
        $this->assertFalse($next->canHold);
    }

    public function testHoldKeySwapWithExistingHold(): void
    {
        // Create a game with lock delay (500 ms) to allow piece to be held twice
        // Bag order with rand=0 is: O, T, S, Z, J, L, I
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500);
        $this->assertSame(Tetromino::O, $g->piece->kind, 'First piece should be O');

        // First hold: piece O goes to hold, new piece T spawns
        [$withHold] = $g->update($this->key('c'));
        $this->assertNotNull($withHold->hold);
        $this->assertSame(Tetromino::O, $withHold->hold, 'Held should be O');
        $this->assertSame(Tetromino::T, $withHold->piece->kind, 'Current piece should be T');
        $this->assertFalse($withHold->canHold);

        // Hard drop to lock the piece and re-enable hold
        // After lock, new piece S spawns (third from bag)
        [$dropped] = $withHold->update($this->key(' '));
        $this->assertTrue($dropped->canHold, 'Hold should be re-enabled after lock');
        $this->assertSame(Tetromino::S, $dropped->piece->kind, 'New piece after hard drop should be S');

        // Second hold: piece S goes to hold, held piece O spawns
        [$swapped] = $dropped->update($this->key('c'));
        $this->assertSame(Tetromino::O, $swapped->piece->kind, 'Should swap to held piece O');
        $this->assertSame(Tetromino::S, $swapped->hold, 'Current piece S should now be held');
        $this->assertFalse($swapped->canHold);
    }

    public function testHoldDisabledAfterHoldUntilLock(): void
    {
        $g = $this->deterministicGame();
        [$held] = $g->update($this->key('c'));
        $this->assertFalse($held->canHold);

        // Trying to hold again should not change anything
        [$stillSame] = $held->update($this->key('c'));
        $this->assertSame($held->piece, $stillSame->piece);
    }

    public function testHoldIgnoredWhilePaused(): void
    {
        // MAJ(5): 'c' used to mutate BEFORE the pause guard ran.
        $g = $this->deterministicGame();
        [$paused] = $g->update($this->key('p'));
        [$afterC] = $paused->update($this->key('c'));
        $this->assertSame($paused->piece, $afterC->piece, 'paused hold must not swap the piece');
        $this->assertNull($afterC->hold);
        $this->assertTrue($afterC->canHold);
    }

    public function testFirstHoldTopsOutWhenReplacementSpawnIsBlocked(): void
    {
        // MED(5): the empty-hold branch used to spawn with no fits() check.
        // Fill the spawn zone (cols 3-5, rows 0-1) so the replacement O cannot
        // fit — the hold must top out instead of silently spawning inside junk.
        $rows = $this->emptyGrid();
        for ($row = 0; $row < 2; $row++) {
            foreach ([3, 4, 5] as $col) {
                $rows[$row][$col] = Tetromino::I;
            }
        }
        $g = $this->deterministicGame()
            ->mutate(['board' => new Board($rows), 'piece' => new Piece(Tetromino::T, 0, 5, 15)]);
        $this->assertFalse($g->over);
        [$result] = $g->update($this->key('c'));
        $this->assertTrue($result->over, 'holding into a blocked spawn zone must top out');
        $this->assertNotNull($result->hold, 'the current piece is still recorded as held');
    }

    // ------------------------------------------------------------------
    // Lock delay — real-time window (audit finding 8 half 1)
    // ------------------------------------------------------------------

    public function testLockDelayWindowOpensInsteadOfLockingOnGround(): void
    {
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]); // grounded: cells on floor row 23
        $this->assertSame(500, $g->lockDelayMs);

        [$waiting, $cmd] = $g->update($this->gravity());
        $this->assertSame($g->piece, $waiting->piece, 'a grounded piece with delay armed must wait');
        $this->assertIsInt($waiting->lockWindowGeneration);
        $this->assertFalse($waiting->gravityPending, 'the gravity chain parks while the window owns timing');
        $this->assertInstanceOf(\Closure::class, $cmd);

        $req = $cmd();
        $this->assertInstanceOf(TickRequest::class, $req);
        $this->assertSame(0.5, $req->seconds, 'the window is 500 ms of real time');
        $produced = ($req->produce)();
        $this->assertInstanceOf(LockDelayMsg::class, $produced);
        $this->assertSame($waiting->lockWindowGeneration, $produced->generation);

        [$locked, $rearm] = $waiting->update($produced);
        $this->assertNotSame($waiting->piece, $locked->piece, 'the matching generation locks the piece');
        $this->assertNull($locked->lockWindowGeneration, 'locking closes the window');
        $this->assertTrue($locked->gravityPending, 'the lock re-arms the parked gravity chain');
        $this->assertInstanceOf(\Closure::class, $rearm);
    }

    public function testLockDelayWindowIsRealTimeNotGravityUnits(): void
    {
        // MED(8): the old countdown ticked in GRAVITY units — 15 ticks was
        // ~12 s at level 0 and ~50 ms at max speed. The window is now fixed
        // wall-clock milliseconds regardless of gravity interval.
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]);
        [$waiting, $cmd] = $g->update($this->gravity());
        $req = $cmd();

        $gravitySeconds = $waiting->score->gravityIntervalUs() / 1e6; // 0.800016 at level 0
        $this->assertSame(800016, $waiting->score->gravityIntervalUs());
        $this->assertSame(0.5, $req->seconds);
        $this->assertLessThan($gravitySeconds, $req->seconds, 'lock window must not scale with gravity speed');
    }

    public function testMovementResetsLockDelayWindow(): void
    {
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]);
        [$waiting, $firstCmd] = $g->update($this->gravity());
        $this->assertNotNull($firstCmd, 'grounding opens the window');
        $gen1 = $waiting->lockWindowGeneration;

        [$afterLeft, $leftCmd] = $waiting->update($this->key('left'));
        $this->assertNotNull($leftCmd, 'a grounded successful move re-arms the full window');
        $this->assertSame($gen1 + 1, $afterLeft->lockWindowGeneration);
        $this->assertSame(1, $afterLeft->lockResets);

        [$afterRight, $rightCmd] = $afterLeft->update($this->key('right'));
        $this->assertNotNull($rightCmd);
        $this->assertSame($gen1 + 2, $afterRight->lockWindowGeneration);
        $this->assertSame(2, $afterRight->lockResets);
    }

    public function testLockDelayResetCapForcesLock(): void
    {
        // MED(8) infinity-spin cap: after 15 grounded resets no further input
        // re-arms — the live window generation locks the piece.
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]);
        [$state, $cmd] = $g->update($this->gravity());
        $liveGen = $state->lockWindowGeneration;

        $sequence = ['left', 'right']; // T at x 3↔2: every input is a grounded success
        $nullsAfterCap = 0;
        for ($i = 0; $i < 17; $i++) {
            [$state, $cmd] = $state->update($this->key($sequence[$i % 2]));
            if ($cmd === null) {
                $nullsAfterCap++;
            } else {
                $liveGen = $state->lockWindowGeneration;
            }
        }

        $this->assertSame(Game::LOCK_DELAY_RESET_CAP, $state->lockResets, 'reset budget clamps at 15');
        $this->assertSame(2, $nullsAfterCap, 'inputs 16 and 17 return no new timer');
        $this->assertSame(16, $liveGen, 'generation stopped advancing at the cap');

        [$locked] = $state->update(new LockDelayMsg($liveGen));
        $this->assertNotSame($state->piece, $locked->piece, 'the live window generation locks the piece');
    }

    public function testAirborneInputClosesTheLockWindow(): void
    {
        // Moving off a surface cancels the armed window; the still-queued
        // stale LockDelayMsg is then inert (next test) and gravity resumes.
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['lockWindowGeneration' => 7, 'lockGeneration' => 7]); // spawn O is airborne at y=0
        [$moved, $cmd] = $g->update($this->key('left'));
        $this->assertNull($moved->lockWindowGeneration, 'floating off the surface closes the window');
        $this->assertNull($cmd, 'gravity is still pending from start — no duplicate arm');
    }

    public function testStaleLockDelayGenerationIsInert(): void
    {
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]);
        [$waiting, $cmd] = $g->update($this->gravity());
        $this->assertNotNull($cmd);

        [$same, $sameCmd] = $waiting->update(new LockDelayMsg(999));
        $this->assertSame($waiting->piece, $same->piece, 'a stale generation must not lock the piece');
        $this->assertNull($sameCmd);
    }

    public function testLockDelayWindowSurvivesPause(): void
    {
        // Paused mid-window: the pending timer extends by one full window
        // instead of locking a frozen game out from under the player.
        $g = Game::startWithLockDelay(new Bag(static fn(int $_max): int => 0), 500)
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]);
        [$waiting, $cmd] = $g->update($this->gravity());
        $gen = $waiting->lockWindowGeneration;
        [$paused] = $waiting->update($this->key('p'));

        [$extended, $again] = $paused->update(new LockDelayMsg($gen));
        $this->assertInstanceOf(\Closure::class, $again, 'a paused window re-arms, it never locks');
        $this->assertSame($paused->piece, $extended->piece);
        $this->assertSame($gen, $extended->lockWindowGeneration);
    }

    // ------------------------------------------------------------------
    // Garbage rows — bottom-insert, content rides up (audit finding 6)
    // ------------------------------------------------------------------

    public function testAddGarbageShiftsExistingRowsUp(): void
    {
        // MED(6): garbage now enters at the BOTTOM and lifts existing content
        // up — the test name has finally become true. A full row at 20 rides
        // to 19; the garbage fills row 23 with a hole at column 3.
        $rows = $this->emptyGrid();
        for ($col = 0; $col < Board::COLS; $col++) {
            $rows[20][$col] = Tetromino::I;
        }
        $g = $this->deterministicGame()->mutate(['board' => new Board($rows)]);

        $result = $g->addGarbageRows(1, static fn(int $_max): int => 3);
        $resultRows = $result->board->rows();

        $this->assertCount(Board::ROWS, $resultRows, 'row count is preserved — nothing is destroyed');
        foreach ($resultRows[19] as $cell) {
            $this->assertNotNull($cell, 'the previously placed row must be lifted to row 19');
        }
        $this->assertNull($resultRows[23][3], 'the garbage row carries its hole at column 3');
        $this->assertSame(1, count(array_filter($resultRows[23], static fn($c) => $c === null)));
    }

    public function testAddGarbageLiftsTheActivePiece(): void
    {
        // An airborne piece rides the lift; a piece that cannot be lifted
        // (spawn row would leave the board) keeps its place instead.
        $rows = $this->emptyGrid();
        for ($col = 0; $col < Board::COLS; $col++) {
            $rows[20][$col] = Tetromino::I;
        }
        $g = $this->deterministicGame()->mutate([
            'board' => new Board($rows),
            'piece' => new Piece(Tetromino::I, 1, 0, 10),
        ]);

        $lifted = $g->addGarbageRows(1, static fn(int $_max): int => 3);
        $this->assertSame(9, $lifted->piece->y, 'an airborne piece rides the garbage lift up one row');

        $spawned = $this->deterministicGame()->mutate(['board' => new Board($g->board->rows())])
            ->addGarbageRows(1, static fn(int $_max): int => 3);
        $this->assertSame(0, $spawned->piece->y, 'a piece already at the ceiling keeps its row');
        $this->assertFalse($spawned->over);
    }

    public function testAddGarbageNeverDestroysBottomRows(): void
    {
        // The old top-insert silently deleted the bottom rows. Under the
        // lift design the bottom two full rows survive, pushed up one.
        $rows = $this->emptyGrid();
        for ($col = 0; $col < Board::COLS; $col++) {
            $rows[Board::ROWS - 2][$col] = Tetromino::I;
            $rows[Board::ROWS - 1][$col] = Tetromino::I;
        }
        $g = $this->deterministicGame()->mutate(['board' => new Board($rows)]);

        $result = $g->addGarbageRows(1, static fn(int $_max): int => 3);
        $resultRows = $result->board->rows();

        foreach ([Board::ROWS - 3, Board::ROWS - 2] as $row) {
            foreach ($resultRows[$row] as $cell) {
                $this->assertNotNull($cell, "content row survived the lift at row {$row}");
            }
        }
        $this->assertFalse($result->over);
    }

    public function testAddGarbageInsertsOneHolePerRow(): void
    {
        $g = $this->deterministicGame();
        // Use deterministic rand that returns 2 for the hole position
        $result = $g->addGarbageRows(2, static fn(int $_max): int => 2);
        $rows = $result->board->rows();

        // Each garbage row sits at the BOTTOM with exactly one hole.
        for ($b = Board::ROWS - 2; $b < Board::ROWS; $b++) {
            $holeCount = 0;
            $filledCount = 0;
            foreach ($rows[$b] as $col => $cell) {
                if ($cell === null) {
                    $holeCount++;
                    $this->assertSame(2, $col, "Hole should be at column 2 for row $b");
                } else {
                    $filledCount++;
                }
            }
            $this->assertSame(1, $holeCount, "Row $b should have exactly one hole");
            $this->assertSame(Board::COLS - 1, $filledCount, "Row $b should have COLS-1 filled cells");
        }
    }

    public function testAddGarbageTopsOutWhenStackOverflows(): void
    {
        $g = $this->deterministicGame();
        $rows = $g->board->rows();

        // Fill rows 0 and 1 (the topmost rows that would be displaced by 2
        // garbage rows) — the lift has nowhere to put them.
        for ($r = 0; $r < 2; $r++) {
            for ($col = 0; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }

        $boardWithTopRows = new Board($rows);
        $gWithTopRows = $g->mutate(['board' => $boardWithTopRows]);

        $result = $gWithTopRows->addGarbageRows(2, static fn(int $_max): int => 0);

        $this->assertTrue($result->over, 'garbage that would push content off the ceiling tops out');
        $this->assertSame($boardWithTopRows, $result->board, 'the refused garbage leaves the board untouched');
    }

    public function testAddGarbageThrowsWhenCountTooLarge(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->deterministicGame()->addGarbageRows(Board::ROWS);
    }

    public function testAddGarbageZeroOrNegativeCountIsNoOp(): void
    {
        $g = $this->deterministicGame();
        $originalBoard = $g->board;

        $resultZero = $g->addGarbageRows(0, static fn(int $_max): int => 0);
        $this->assertSame($originalBoard, $resultZero->board, 'addGarbageRows(0) should return same board');

        $resultNeg = $g->addGarbageRows(-5, static fn(int $_max): int => 0);
        $this->assertSame($originalBoard, $resultNeg->board, 'addGarbageRows(-5) should return same board');
    }

    public function testHardDropAwardsTwoPointsPerCell(): void
    {
        // Create a fresh game, manually position piece at y=0, then hard drop.
        // The board starts empty so no line clears happen.
        // Tetromino::O at rot 0 (height 2, cells at y=0,1) spawns at y=0.
        // It falls until bottom cell (y+1) hits floor at row 23 → lands at y=22.
        // Fall distance = 22 cells × 2 = 44 points.
        $g = $this->deterministicGame();
        $pieceAtY0 = new Piece(Tetromino::O, 0, 3, 0);
        $game = $g->mutate(['piece' => $pieceAtY0, 'score' => new Score()]);

        $startPoints = $game->score->points;
        [$dropped] = $game->update($this->key(' '));

        // Score increase = 2 × fall distance. Board is empty so no line-clear bonus.
        // O falls from y=0 to y=22 (floor) = 22 cells → 44 points.
        $this->assertSame(44, $dropped->score->points - $startPoints,
            'Hard drop must award 2 points per cell fallen (44 = 22 cells × 2)');
    }

    public function testSoftDropAwardsOnePointPerCell(): void
    {
        // Create a game and manually set piece at y=0, then soft drop one cell.
        // Verify 1 point is awarded when the move succeeds.
        $g = $this->deterministicGame();
        $pieceAtY0 = new Piece(Tetromino::T, 0, 3, 0);
        $game = $g->mutate(['piece' => $pieceAtY0, 'score' => new Score()]);

        $startPoints = $game->score->points;

        // Soft drop one cell - piece is at y=0, cell at y=1 is free (empty board)
        [$dropped] = $game->update($this->key('down'));
        $this->assertSame(1, $dropped->score->points - $startPoints,
            'Soft drop must award 1 point per cell successfully moved');
    }

    public function testTopOutIsDrivenThroughRealLockAndSpawn(): void
    {
        // Regression: the game-over branch of lockAndSpawn() was only ever
        // reached by hand-building over=true. Drive it for real: block the
        // spawn zone so the *next* piece cannot fit after the current one
        // locks. Fill cols 1-9 of rows 0-1 (col 0 left empty → neither row is
        // full, so nothing clears on lock).
        $g = $this->deterministicGame(); // bag: O, T, ...
        $rows = $g->board->rows();
        for ($r = 0; $r < 2; $r++) {
            for ($col = 1; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }
        $board = new Board($rows);
        // Park the current piece harmlessly on the floor (cols 1-2, rows 22-23)
        // so its lock completes no line and the spawn zone stays blocked.
        $current = new Piece(Tetromino::O, 0, 0, 22);
        $game = $g->mutate(['board' => $board, 'piece' => $current]);
        $this->assertFalse($game->over);

        // Hard drop → lockAndSpawn → next piece (T) spawns into cols 3-5 of
        // rows 0-1 (all filled) → real top-out.
        [$afterDrop, $cmd] = $game->update($this->key(' '));
        $this->assertTrue($afterDrop->over, 'spawning into a blocked zone must top-out via lockAndSpawn');
        $this->assertNull($cmd, 'a topped-out game schedules no further gravity tick');
    }

    public function testLineClearThroughLockAndSpawnUpdatesScore(): void
    {
        // End-to-end: complete a row by dropping a piece and assert the clear
        // propagates to Score (lines + points) via lockAndSpawn.
        $g = $this->deterministicGame();
        $rows = $g->board->rows();
        // Fill the floor row everywhere except cols 1-2 — exactly where an O
        // piece dropped from x=0 lands (O rot0 occupies cols x+1, x+2).
        for ($col = 0; $col < Board::COLS; $col++) {
            if ($col === 1 || $col === 2) {
                continue;
            }
            $rows[Board::ROWS - 1][$col] = Tetromino::I;
        }
        $board = new Board($rows);
        $o = new Piece(Tetromino::O, 0, 0, 0);
        $game = $g->mutate(['board' => $board, 'piece' => $o, 'score' => new Score()]);
        $this->assertSame(0, $game->score->lines);
        $this->assertSame(0, $game->score->points);

        [$after] = $game->update($this->key(' ')); // hard drop
        $this->assertSame(1, $after->score->lines, 'completing the floor row must clear exactly one line');
        $this->assertGreaterThan(0, $after->score->points, 'a line clear must award points');
    }

    public function testWallKickSelectsNonNaiveCandidateOnOccupiedBoard(): void
    {
        // Regression: SRS kick selection was only exercised on an empty board
        // (where the naive rotation always fits). Here the naive clockwise
        // rotation of a T collides with a locked block, and only the SRS
        // [-1,0] kick fits → the piece must shift left by one column.
        $g = $this->deterministicGame();
        $rows = $g->board->rows();
        $rows[12][5] = Tetromino::I; // blocks the naive rot-1 cell (5,12) only
        $board = new Board($rows);
        $t = new Piece(Tetromino::T, 0, 4, 10);
        $game = $g->mutate(['board' => $board, 'piece' => $t]);

        // Naive CW rotation (no kick) collides with the block.
        $this->assertFalse($game->board->fits($t->rotated(1)), 'naive CW rotation must collide with the block');

        // Rotate CW through the game: SRS must apply the [-1,0] wall kick.
        [$rotated] = $game->update($this->key('up'));
        $this->assertSame(1, $rotated->piece->rotation, 'piece must have rotated clockwise');
        $this->assertSame(3, $rotated->piece->x, 'SRS must apply the [-1,0] wall kick (x: 4 → 3)');
        $this->assertSame(10, $rotated->piece->y, 'the [-1,0] kick must not change y');
        $this->assertTrue($rotated->board->fits($rotated->piece), 'the kicked position must fit');
    }

    public function testComboCounterIncreasesOnConsecutiveClears(): void
    {
        // Build a game with combo=2 and verify it increments on next clear
        $g = $this->deterministicGame();
        $game = $g->mutate(['combo' => 2]);

        // Place a piece that will clear a line
        $rows = $game->board->rows();
        for ($col = 0; $col < Board::COLS; $col++) {
            if ($col === 1 || $col === 2) {
                continue;
            }
            $rows[Board::ROWS - 1][$col] = Tetromino::I;
        }
        $board = new Board($rows);
        $o = new Piece(Tetromino::O, 0, 0, 0);
        $game = $game->mutate(['board' => $board, 'piece' => $o, 'score' => new Score()]);

        [$after] = $game->update($this->key(' '));
        $this->assertSame(3, $after->combo, 'Combo should increment from 2 to 3 after a line clear');
    }

    public function testComboResetsToZeroOnNoClear(): void
    {
        // Build a game with combo=5
        $g = $this->deterministicGame();
        $game = $g->mutate(['combo' => 5]);

        // Hard drop without clearing any lines (place piece on empty area)
        $piece = new Piece(Tetromino::I, 0, 0, 0);
        $game = $game->mutate(['piece' => $piece]);

        [$after] = $game->update($this->key(' '));
        $this->assertSame(0, $after->combo, 'Combo should reset to 0 when no lines are cleared');
    }

    public function testHoldSwapFailsWhenHeldPieceDoesNotFit(): void
    {
        // Create a game where hold is active and hold contains a piece
        $g = $this->deterministicGame();

        // First hold to put something in hold
        [$withHold] = $g->update($this->key('c'));
        $this->assertNotNull($withHold->hold);

        // Now hard drop and spawn new piece, then fill the board so held piece won't fit
        [$dropped] = $withHold->update($this->key(' '));

        // Fill the spawn zone (top rows) except one column
        $rows = $dropped->board->rows();
        for ($r = 0; $r < 4; $r++) {  // HIDDEN_ROWS = 4
            for ($col = 0; $col < Board::COLS; $col++) {
                if ($col !== 0) {  // Leave column 0 empty for spawn
                    $rows[$r][$col] = Tetromino::I;
                }
            }
        }
        $blockedBoard = new Board($rows);
        $game = $dropped->mutate(['board' => $blockedBoard]);

        // After hard drop, canHold is re-enabled (true)
        $this->assertTrue($game->canHold);

        // Now hold should swap with held piece, but held piece (O) won't fit in column 0
        // because O needs 2 columns. So the hold swap should fail (return same game).
        [$result] = $game->update($this->key('c'));
        $this->assertSame($game->piece, $result->piece, 'Hold swap should fail when held piece cannot fit');
        $this->assertTrue($result->canHold, 'canHold should remain true after failed swap (unchanged state)');
    }

    public function testBackToBackAfterTetrisClear(): void
    {
        // Build a game with backToBack=false and clear a Tetris (4 lines)
        $g = $this->deterministicGame();

        // Set up 4 complete rows at the bottom
        $rows = $g->board->rows();
        for ($row = Board::ROWS - 4; $row < Board::ROWS; $row++) {
            for ($col = 0; $col < Board::COLS; $col++) {
                $rows[$row][$col] = Tetromino::I;
            }
        }
        $board = new Board($rows);

        // Place I piece to clear all 4 rows
        $i = new Piece(Tetromino::I, 0, 0, 0);
        $game = $g->mutate(['board' => $board, 'piece' => $i, 'backToBack' => false]);

        [$after] = $game->update($this->key(' '));
        $this->assertTrue($after->backToBack, 'Tetris clear should set backToBack=true');
    }

    public function testB2BBonusAppliedOnConsecutiveTetris(): void
    {
        // MED(9) de-vacuumed: exact arithmetic instead of "points > 0".
        // Same Tetris setup as testBackToBackAfterTetrisClear, but B2B is
        // already armed. The I from (0,0) rests with cells in row 19 (rows
        // 20-23 pre-filled): 18 cells fallen = 36 drop points.
        //   base tetris 1200 + b2b extra 1200*0.5=600 + combo 1*10=10 = 1846.
        $g = $this->deterministicGame();

        $rows = $g->board->rows();
        for ($row = Board::ROWS - 4; $row < Board::ROWS; $row++) {
            for ($col = 0; $col < Board::COLS; $col++) {
                $rows[$row][$col] = Tetromino::I;
            }
        }
        $board = new Board($rows);

        $i = new Piece(Tetromino::I, 0, 0, 0);
        $game = $g->mutate(['board' => $board, 'piece' => $i, 'backToBack' => true]);

        $startPoints = $game->score->points;
        [$after] = $game->update($this->key(' '));

        $pointsEarned = $after->score->points - $startPoints;
        $this->assertSame(1846, $pointsEarned,
            '36 drop + 1200 base + 600 back-to-back + 10 combo (level 0)');
        $this->assertSame(4, $after->score->lines, 'Should have cleared 4 lines');
    }

    // ------------------------------------------------------------------
    // T-Spin semantics — last successful input was a rotation (findings 3/4)
    // ------------------------------------------------------------------

    public function testRotationFlagTracksSuccessfulInputs(): void
    {
        $g = $this->deterministicGame();
        $this->assertFalse($g->lastActionWasRotation, 'spawn starts with no rotation credit');
        [$afterUp] = $g->update($this->key('up'));
        $this->assertTrue($afterUp->lastActionWasRotation, 'a successful rotate arms the spin credit');
        [$afterMove] = $afterUp->update($this->key('left'));
        $this->assertFalse($afterMove->lastActionWasRotation, 'a later successful move disarms it');
    }

    public function testHumanTSpinScoresWhenRotationIsLastInput(): void
    {
        // MAJ(3): rotation → settle → lock through the REAL key path. The
        // piece rotates at (5,12), gravity (not an input) settles it into the
        // slot at (5,14) rotation 1 with all four corners filled.
        $g = $this->deterministicGame()->mutate([
            'board' => new Board($this->tspinSlotGrid()),
            'piece' => new Piece(Tetromino::T, 0, 5, 12),
            'score' => new Score(),
        ]);
        [$spun, $cmd] = $g->update($this->key('up'));
        $this->assertSame(1, $spun->piece->rotation);
        $this->assertTrue($spun->lastActionWasRotation);
        $this->assertNull($cmd, 'airborne rotation keeps the pending gravity tick');

        $startPoints = $spun->score->points;
        $state = $spun;
        for ($i = 0; $i < 30; $i++) {
            [$state, $cmd] = $state->update($this->gravity());
            if ($state->score->points !== $startPoints) {
                break;
            }
        }
        $this->assertSame(400, $state->score->points - $startPoints, 'a human T-Spin pays 400 at level 0');
        $this->assertSame(0, $state->score->lines, 'the slot clear check is about the spin, not lines');
        $this->assertTrue($state->backToBack, 'a full T-Spin arms back-to-back');
    }

    public function testMoveAfterRotationIsNotATSpin(): void
    {
        // Same slot, but the LAST successful input before settling is a move.
        $g = $this->deterministicGame()->mutate([
            'board' => new Board($this->tspinSlotGrid()),
            'piece' => new Piece(Tetromino::T, 0, 5, 10),
            'score' => new Score(),
        ]);
        [$spun] = $g->update($this->key('up'));
        [$moved] = $spun->update($this->key('left'));
        $this->assertFalse($moved->lastActionWasRotation);

        $startPoints = $moved->score->points;
        $state = $moved;
        for ($i = 0; $i < 40; $i++) {
            [$state, $cmd] = $state->update($this->gravity());
            if ($state->score->points !== $startPoints) {
                break;
            }
        }
        $this->assertSame(0, $state->score->points - $startPoints, 'move-then-lock never earns T-Spin credit');
    }

    public function testAiRotateOnlyNeverTSpins(): void
    {
        // MAJ(4): the old delta-compare detector credited the AI's
        // un-synced rotation on an empty floor/wall corner as +400. The flag
        // is cleared before every AI lock, so the phantom is dead.
        $g = $this->deterministicGame()->mutate(['score' => new Score()]);
        $after = $g->applyAiMove(1, 0);
        $this->assertSame(0, $after->score->points - $g->score->points,
            'AI placement can never claim a T-Spin through applyAiMove');
    }

    public function testB2BTSpinRidesTheMultiplier(): void
    {
        // MED(7) implemented: README advertises B2B × T-Spin = base × 1.5.
        // 400 × 1.5 = 600 at level 0 (spin award rides the multiplier; the
        // T-Spin itself does not re-trigger the line-clear B2B extra term).
        $g = $this->deterministicGame()->mutate([
            'board' => new Board($this->tspinSlotGrid()),
            'piece' => new Piece(Tetromino::T, 1, 5, 14), // pre-spun, grounded in the slot
            'backToBack' => true,
            'lastActionWasRotation' => true,
            'score' => new Score(),
        ]);
        [$locked] = $g->update($this->gravity());
        $this->assertSame(600, $locked->score->points - $g->score->points,
            'back-to-back T-Spin = (int)(400 × 1.5) × (level+1)');
    }

    public function testViewReturnsRendererOutput(): void
    {
        $g = Game::start();
        $view = $g->view();
        $this->assertIsString($view);
        $this->assertNotEmpty($view);
    }

    public function testGameSubscriptionsReturnsNull(): void
    {
        $g = Game::start();
        $this->assertNull($g->subscriptions());
    }

    public function testAddGarbageRowsWithZeroIsNoop(): void
    {
        $g = Game::start();
        $originalRows = $g->board->rows();
        $result = $g->addGarbageRows(0);
        $this->assertSame($originalRows, $result->board->rows());
    }

    public function testAddGarbageRowsNegativeIsNoop(): void
    {
        $g = Game::start();
        $originalRows = $g->board->rows();
        $result = $g->addGarbageRows(-5);
        $this->assertSame($originalRows, $result->board->rows());
    }
}
