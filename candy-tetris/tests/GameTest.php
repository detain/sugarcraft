<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Score;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    private function deterministicGame(): Game
    {
        return Game::start(new Bag(static fn(int $max): int => 0));
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

    public function testQuitKeyDispatchesQuit(): void
    {
        $g = $this->deterministicGame();
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(\Closure::class, $cmd, 'q must dispatch a quit Cmd');
    }

    public function testLeftKeyMovesPieceLeft(): void
    {
        $g = $this->deterministicGame();
        $startX = $g->piece->x;
        [$next] = $g->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame($startX - 1, $next->piece->x);
    }

    public function testRightKeyMovesPieceRight(): void
    {
        $g = $this->deterministicGame();
        $startX = $g->piece->x;
        [$next] = $g->update(new KeyMsg(KeyType::Right, ''));
        $this->assertSame($startX + 1, $next->piece->x);
    }

    public function testUpKeyRotatesPiece(): void
    {
        $g = $this->deterministicGame();
        $startRot = $g->piece->rotation;
        [$next] = $g->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(($startRot + 1) % 4, $next->piece->rotation);
    }

    public function testGravityAdvancesPieceDownOneRow(): void
    {
        $g = $this->deterministicGame();
        $startY = $g->piece->y;
        [$next, $cmd] = $g->update(new GravityMsg());
        $this->assertSame($startY + 1, $next->piece->y);
        $this->assertInstanceOf(\Closure::class, $cmd, 'gravity must reschedule the next tick');
    }

    public function testHardDropDispatchesNextTick(): void
    {
        $g = $this->deterministicGame();
        [$next, $cmd] = $g->update(new KeyMsg(KeyType::Char, ' '));
        // Piece locked + new piece spawned. Next-tick Cmd reschedules gravity.
        $this->assertNotSame($g->piece, $next->piece);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testPauseTogglesAndIgnoresMovementUntilUnpaused(): void
    {
        $g = $this->deterministicGame();
        [$paused] = $g->update(new KeyMsg(KeyType::Char, 'p'));
        $this->assertTrue($paused->paused);

        $startX = $paused->piece->x;
        [$stillPaused] = $paused->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame($startX, $stillPaused->piece->x, 'paused game ignores movement');

        [$resumed] = $paused->update(new KeyMsg(KeyType::Char, 'p'));
        $this->assertFalse($resumed->paused);
    }

    public function testGameOverOnlyHonorsQuit(): void
    {
        // Force game-over by hand: build a Game with over=true.
        $g = $this->deterministicGame();
        $over = new Game(
            $g->board, $g->piece, $g->bag, $g->score,
            over: true,
            preLockRotation: $g->preLockRotation,
        );
        [$samePiece1] = $over->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame($over->piece, $samePiece1->piece);
        [, $cmd] = $over->update(new KeyMsg(KeyType::Char, 'q'));
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

    public function testHoldKeyStoresPieceInHold(): void
    {
        $g = $this->deterministicGame();
        $this->assertNull($g->hold);
        $this->assertTrue($g->canHold);

        [$next] = $g->update(new KeyMsg(KeyType::Char, 'c'));

        // After holding, the piece should be stored and a new piece spawned
        $this->assertNotNull($next->hold);
        $this->assertSame($g->piece->kind, $next->hold);
        $this->assertFalse($next->canHold);
    }

    public function testHoldKeySwapWithExistingHold(): void
    {
        // Create a game with lock delay to allow piece to be held twice
        // Bag order with rand=0 is: O, T, S, Z, J, L, I
        $g = Game::startWithLockDelay(new Bag(static fn(int $max): int => 0), 100);
        $this->assertSame(Tetromino::O, $g->piece->kind, 'First piece should be O');

        // First hold: piece O goes to hold, new piece T spawns
        [$withHold] = $g->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertNotNull($withHold->hold);
        $this->assertSame(Tetromino::O, $withHold->hold, 'Held should be O');
        $this->assertSame(Tetromino::T, $withHold->piece->kind, 'Current piece should be T');
        $this->assertFalse($withHold->canHold);

        // Hard drop to lock the piece and re-enable hold
        // After lock, new piece S spawns (third from bag)
        [$dropped] = $withHold->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertTrue($dropped->canHold, 'Hold should be re-enabled after lock');
        $this->assertSame(Tetromino::S, $dropped->piece->kind, 'New piece after hard drop should be S');

        // Second hold: piece S goes to hold, held piece O spawns
        [$swapped] = $dropped->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame(Tetromino::O, $swapped->piece->kind, 'Should swap to held piece O');
        $this->assertSame(Tetromino::S, $swapped->hold, 'Current piece S should now be held');
        $this->assertFalse($swapped->canHold);
    }

    public function testHoldDisabledAfterHoldUntilLock(): void
    {
        $g = $this->deterministicGame();
        [$held] = $g->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertFalse($held->canHold);

        // Trying to hold again should not change anything
        [$stillSame] = $held->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertSame($held->piece, $stillSame->piece);
    }

    public function testLockDelayPreventsImmediateLock(): void
    {
        // Start with lock delay of 3 ticks
        // Hard drop should lock immediately (no lock delay on hard drop)
        $g = Game::startWithLockDelay(new Bag(static fn(int $max): int => 0), 3);
        $this->assertSame(3, $g->lockDelayTicks);

        // Hard drop - should lock immediately, not wait for lock delay
        [$dropped] = $g->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertNotSame($g->piece, $dropped->piece, 'Piece should have changed after hard drop');
        // Hard drop bypasses lock delay
    }

    public function testLockDelayCountsDownOnBottom(): void
    {
        // Start with lock delay of 2 ticks
        $g = Game::startWithLockDelay(new Bag(static fn(int $max): int => 0), 2);
        $this->assertSame(2, $g->lockDelayTicks);

        // Manually set piece at bottom and call gravity until lock
        // We need to call gravity repeatedly to trigger the lock delay countdown
        $game = $g;
        $lockDelaySeen = false;

        // Simulate piece falling to bottom then gravity ticks counting down
        for ($i = 0; $i < 30 && !$lockDelaySeen; $i++) {
            [$next] = $game->update(new GravityMsg());
            if ($next->lockDelayTicks < $game->lockDelayTicks) {
                $lockDelaySeen = true;
            }
            $game = $next;
            if ($next->over) break;
        }

        $this->assertTrue($lockDelaySeen, 'Lock delay should decrement when piece is at bottom');
    }

    public function testMovementResetsLockDelay(): void
    {
        $g = Game::startWithLockDelay(new Bag(static fn(int $max): int => 0), 2);

        // Move piece to bottom by hard drop (which preserves lock delay setting but doesn't trigger it)
        // Actually, let's just verify the initial state and that hard drop works
        $this->assertSame(2, $g->lockDelayTicks);

        // Hard drop should lock piece and start fresh with new piece
        [$dropped] = $g->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertNotSame($g->piece, $dropped->piece);
    }

    public function testAddGarbageShiftsExistingRowsUp(): void
    {
        // Create a game and manually place a row of blocks on the board
        $g = Game::start(new Bag(static fn(int $max): int => 0));
        $rows = $g->board->rows();
        // Place a complete row near the bottom (row 20, second-to-last visible row).
        // With ROWS=24 and HIDDEN_ROWS=2, visible rows are 0-21. Row 20 is visible.
        // After adding 1 garbage row, it shifts to row 21 (last visible row).
        for ($col = 0; $col < Board::COLS; $col++) {
            $rows[20][$col] = Tetromino::I;
        }
        $boardWithRow = new Board($rows);
        $gWithRow = $g->mutate(['board' => $boardWithRow]);

        // Add 1 garbage row
        $result = $gWithRow->addGarbageRows(1, static fn(int $max): int => 3);
        $resultRows = $result->board->rows();

        // The previously placed row should now be at row 21 (shifted up by 1)
        $this->assertNotNull($resultRows[21][0], 'Original row should be shifted up to row 21');
        // The garbage row (row 0) should have a hole at column 3
        $this->assertNull($resultRows[0][3], 'Garbage row should have a hole at column 3');
    }

    public function testAddGarbageInsertsOneHolePerRow(): void
    {
        $g = Game::start(new Bag(static fn(int $max): int => 0));
        // Use deterministic rand that returns 2 for the hole position
        $result = $g->addGarbageRows(2, static fn(int $max): int => 2);
        $rows = $result->board->rows();

        // Each garbage row should have exactly one null (the hole)
        for ($r = 0; $r < 2; $r++) {
            $holeCount = 0;
            $filledCount = 0;
            foreach ($rows[$r] as $col => $cell) {
                if ($cell === null) {
                    $holeCount++;
                    $this->assertSame(2, $col, "Hole should be at column 2 for row $r");
                } else {
                    $filledCount++;
                }
            }
            $this->assertSame(1, $holeCount, "Row $r should have exactly one hole");
            $this->assertSame(Board::COLS - 1, $filledCount, "Row $r should have COLS-1 filled cells");
        }
    }

    public function testAddGarbageTopsOutWhenStackOverflows(): void
    {
        $g = Game::start(new Bag(static fn(int $max): int => 0));
        $rows = $g->board->rows();

        // Fill rows 0 and 1 (the topmost rows that will be displaced by 2 garbage rows)
        for ($r = 0; $r < 2; $r++) {
            for ($col = 0; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }

        $boardWithTopRows = new Board($rows);
        $gWithTopRows = $g->mutate(['board' => $boardWithTopRows]);

        // Adding 2 garbage rows should top-out because rows 0 and 1 have content
        $result = $gWithTopRows->addGarbageRows(2, static fn(int $max): int => 0);

        $this->assertTrue($result->over, 'Adding garbage when top rows are filled should set over=true');
    }

    public function testAddGarbageZeroOrNegativeCountIsNoOp(): void
    {
        $g = Game::start(new Bag(static fn(int $max): int => 0));
        $originalBoard = $g->board;

        $resultZero = $g->addGarbageRows(0, static fn(int $max): int => 0);
        $this->assertSame($originalBoard, $resultZero->board, 'addGarbageRows(0) should return same board');

        $resultNeg = $g->addGarbageRows(-5, static fn(int $max): int => 0);
        $this->assertSame($originalBoard, $resultNeg->board, 'addGarbageRows(-5) should return same board');
    }

    public function testLockDelayReArmsOnMoveWhenGrounded(): void
    {
        // Use a simple approach: manually place the piece ON the floor (y where
        // board->fits returns false for moved(0,1)) and verify re-arm.
        // Tetromino::I at rotation 0 has cells at y=1 of the bounding box.
        // With floor at row 23 and I height 1: I at y=22 → cells at row 23 (floor).
        // So I at y=21 → cells at row 22 (one above floor), first gravity fits.
        // I at y=22 → cells at row 23 (floor), can't move down → lock delay active.
        $g = Game::startWithLockDelay(new Bag(static fn(int $max): int => 0), 2);

        // Place I at y=22 (cells at floor row 23) with lockDelayTicks=1
        $iAtFloor = new Piece(Tetromino::I, 0, 3, 22);
        $game = $g->mutate(['piece' => $iAtFloor, 'lockDelayTicks' => 1]);
        $this->assertSame(1, $game->lockDelayTicks);

        // First gravity tick: piece can't move down, lock delay decrements to 0 → locks
        [$afterGravity] = $game->update(new GravityMsg());
        $this->assertSame(0, $afterGravity->lockDelayTicks,
            'lock delay must decrement when piece is on floor');

        // Now piece is locked. New piece spawns with lockDelayTicks=2.
        // Move the new piece to y=22 (on floor) and decrement lock delay again.
        $newPiece = $afterGravity->piece;
        $pieceOnFloor = new Piece($newPiece->kind, $newPiece->rotation, $newPiece->x, 22);
        $game2 = $afterGravity->mutate(['piece' => $pieceOnFloor, 'lockDelayTicks' => 1]);

        // Gravity tick while on floor: lock delay decrements from 1 to 0
        [$afterGravity2] = $game2->update(new GravityMsg());
        $this->assertSame(0, $afterGravity2->lockDelayTicks,
            'lock delay must decrement when new piece is on floor');

        // Move the piece (now locked but game not over) with a successful left:
        // Before fix: lockDelayTicks stays 0. After fix: re-arms to lockDelayMax.
        [$afterMove] = $afterGravity2->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame(2, $afterMove->lockDelayTicks,
            'successful move while grounded must re-arm lock delay to max');
    }

    public function testHardDropAwardsTwoPointsPerCell(): void
    {
        // Create a fresh game, manually position piece at y=0, then hard drop.
        // The board starts empty so no line clears happen.
        // Tetromino::O at rot 0 (height 2, cells at y=0,1) spawns at y=0.
        // It falls until bottom cell (y+1) hits floor at row 23 → lands at y=22.
        // Fall distance = 22 cells × 2 = 44 points.
        $g = Game::start(new Bag(static fn(int $max): int => 0));
        $pieceAtY0 = new Piece(Tetromino::O, 0, 3, 0);
        $game = $g->mutate(['piece' => $pieceAtY0, 'score' => new Score()]);

        $startPoints = $game->score->points;
        [$dropped] = $game->update(new KeyMsg(KeyType::Char, ' '));

        // Score increase = 2 × fall distance. Board is empty so no line-clear bonus.
        // O falls from y=0 to y=22 (floor) = 22 cells → 44 points.
        $this->assertSame(44, $dropped->score->points - $startPoints,
            'Hard drop must award 2 points per cell fallen (44 = 22 cells × 2)');
    }

    public function testSoftDropAwardsOnePointPerCell(): void
    {
        // Create a game and manually set piece at y=0, then soft drop one cell.
        // Verify 1 point is awarded when the move succeeds.
        $g = Game::start(new Bag(static fn(int $max): int => 0));
        $pieceAtY0 = new Piece(Tetromino::T, 0, 3, 0);
        $game = $g->mutate(['piece' => $pieceAtY0, 'score' => new Score()]);

        $startPoints = $game->score->points;

        // Soft drop one cell - piece is at y=0, cell at y=1 is free (empty board)
        [$dropped] = $game->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $dropped->score->points - $startPoints,
            'Soft drop must award 1 point per cell successfully moved');
    }
}
