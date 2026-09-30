<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use PHPUnit\Framework\TestCase;

final class VsGameTest extends TestCase
{
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

    private function deterministicBag(): Bag
    {
        return new Bag(static fn(int $_max): int => 0);
    }

    public function testStartCreatesTwoGames(): void
    {
        $vs = VsGame::start();

        $this->assertInstanceOf(Game::class, $vs->player);
        $this->assertInstanceOf(Game::class, $vs->computer);
        $this->assertFalse($vs->over);
        $this->assertNull($vs->winner);
    }

    public function testInitReturnsTickClosure(): void
    {
        $vs = VsGame::start();
        $cmd = $vs->init();

        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testQuitKeyDispatchesQuit(): void
    {
        $vs = VsGame::start();
        [, $cmd] = $vs->update($this->key('q'));

        $this->assertInstanceOf(\Closure::class, $cmd, 'q must dispatch a quit Cmd');
    }

    public function testPauseTogglesPlayerPause(): void
    {
        $vs = VsGame::start();

        [$paused] = $vs->update($this->key('p'));

        $this->assertTrue($paused->player->paused);
    }

    public function testMovementAffectsPlayerOnly(): void
    {
        $vs = VsGame::start();
        $playerStartX = $vs->player->piece->x;

        [$next] = $vs->update($this->key('left'));

        $this->assertSame($playerStartX - 1, $next->player->piece->x);
    }

    public function testGarbageRowPassingWhenPlayerClearsLines(): void
    {
        // MED(9) de-vacuumed: the old test asserted assertNotNull only —
        // it passed even when zero garbage moved. Now the player genuinely
        // clears a row through the VS loop and the computer's board is
        // inspected for the arriving garbage line.
        $vs = VsGame::start();

        // A full bottom row on the player side: the next lock clears it,
        // which the VS loop converts into one garbage row for the computer.
        $playerWithLine = $this->createGameWithOneLine($vs->player);
        $vs = new VsGame($playerWithLine, $vs->computer);

        $computerBoardBefore = $vs->computer->board;
        [$updated] = $vs->update($this->key(' ')); // hard drop → clear → transfer

        $this->assertSame($playerWithLine->score->lines + 1, $updated->player->score->lines,
            'the player must really have cleared the line');
        $this->assertNotSame($computerBoardBefore, $updated->computer->board,
            'the computer board must change when garbage arrives');

        $bottomRow = $updated->computer->board->rows()[Board::ROWS - 1];
        $nulls = count(array_filter($bottomRow, static fn($c) => $c === null));
        $filled = count(array_filter($bottomRow, static fn($c) => $c !== null));
        $this->assertSame(1, $nulls, 'garbage row carries exactly one hole');
        $this->assertSame(Board::COLS - 1, $filled, 'the rest of the garbage row is filled');
    }

    public function testOverStateWhenPlayerGameOver(): void
    {
        $vs = VsGame::start();

        // Set player's game to over
        $overPlayer = $vs->player->mutate(['over' => true]);

        $vs = new VsGame($overPlayer, $vs->computer);

        // Process a gravity tick to trigger win detection
        [$result] = $vs->update($this->gravity());

        $this->assertTrue($result->over);
        $this->assertSame('COMPUTER', $result->winner);
    }

    public function testOverStateWhenComputerGameOver(): void
    {
        $vs = VsGame::start();

        // Set computer's game to over
        $overComputer = $vs->computer->mutate(['over' => true]);

        $vs = new VsGame($vs->player, $overComputer);

        // Process a gravity tick to trigger win detection
        [$result] = $vs->update($this->gravity());

        $this->assertTrue($result->over);
        $this->assertSame('PLAYER', $result->winner);
    }

    public function testQuitOnlyAcceptedWhenOver(): void
    {
        $vs = VsGame::start();
        $playerX = $vs->player->piece->x;

        // Quit should exit, not just move
        [$next, $cmd] = $vs->update($this->key('q'));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertSame($playerX, $next->player->piece->x, 'quit should not move piece');
    }

    public function testViewReturnsString(): void
    {
        $vs = VsGame::start();
        $view = $vs->view();

        $this->assertIsString($view);
        $this->assertNotEmpty($view);
    }

    public function testBothGamesIndependentUntilOver(): void
    {
        // MED(9) de-vacuumed: the old pin compared a value to itself. Player
        // input must move the player piece (wall-clamped at x=0) and leave the
        // computer Game object literally untouched — keys never rebuild it.
        $vs = new VsGame(Game::start($this->deterministicBag()), Game::start($this->deterministicBag()));
        $start = $vs;

        for ($i = 0; $i < 5; $i++) {
            [$vs] = $vs->update($this->key('left'));
        }

        $this->assertSame(-1, $vs->player->piece->x,
            'the O spawn piece (cols x+1,x+2) clamps at x=-1 against the left wall');
        $this->assertNotSame($start->player, $vs->player, 'the player side advanced');
        $this->assertSame($start->computer, $vs->computer,
            'key-only updates must not rebuild the computer side at all');
    }

    public function testComputerTakesExactlyOneActionPerGravityTick(): void
    {
        // MAJ(4): the old loop memo-stored the AI decision, dropped the memo
        // on every mutate, and applied the whole placement (rotate+shift+drop
        // +lock) in a single tick. The plan now lives in the model and each
        // gravity tick spends exactly one input.
        $vs = VsGame::start();
        $initialPiece = $vs->computer->piece;

        [$state] = $vs->update($this->gravity());
        $this->assertTrue($state->hasComputerPlan, 'the first tick records the plan');
        $this->assertSame($initialPiece, $state->computer->piece,
            'the decision tick itself moves nothing');
        $this->assertEmptyBoard($state->computer, 'nothing may lock before the plan is spent');

        $rot = $initialPiece->rotation;
        $x = $initialPiece->x;
        $y = $initialPiece->y;
        for ($i = 0; $i < 8; $i++) {
            [$next] = $state->update($this->gravity());
            $p = $next->computer->piece;
            $dRot = abs($p->rotation - $rot);
            $dX = abs($p->x - $x);
            $dY = abs($p->y - $y);
            $this->assertLessThanOrEqual(1, $dRot, "tick $i may rotate at most once");
            $this->assertLessThanOrEqual(1, $dX, "tick $i may shift at most one column");
            $this->assertLessThanOrEqual(1, $dY, "tick $i may drop at most one row");
            $this->assertFalse(
                ($dRot > 0) && ($dX > 0 || $dY > 0),
                "tick $i mixes rotation and translation — one input per tick",
            );
            $state = $next;
            $rot = $p->rotation;
            $x = $p->x;
            $y = $p->y;
            $this->assertEmptyBoard($state->computer, 'the eight move ticks must still not lock');
        }
    }

    public function testUpdateIsPureAcrossIdenticalChains(): void
    {
        // Determinism is the observable face of the purity fix: two chains
        // fed the same messages must land on the same state. The old code
        // wrote memos onto $this inside update() and reset-via-mutate, so
        // the sequence of states depended on hidden object history.
        $a = new VsGame(Game::start($this->deterministicBag()), Game::start($this->deterministicBag()));
        $b = new VsGame(Game::start($this->deterministicBag()), Game::start($this->deterministicBag()));

        for ($i = 0; $i < 6; $i++) {
            [$a] = $a->update($this->gravity());
            [$b] = $b->update($this->gravity());
        }

        $this->assertEquals($a->computer->piece, $b->computer->piece);
        $this->assertEquals($a->computer->board->rows(), $b->computer->board->rows());
        $this->assertSame($a->hasComputerPlan, $b->hasComputerPlan);
        $this->assertSame($a->computerRotationsLeft, $b->computerRotationsLeft);
        $this->assertSame($a->computerShiftLeft, $b->computerShiftLeft);
    }

    public function testKeyMsgNeverReArmsTheGravityTick(): void
    {
        // The chain is gravity-driven: keys must not stack timers on top of
        // the in-flight tick (same singleton discipline as Game).
        $vs = new VsGame(Game::start($this->deterministicBag()), Game::start($this->deterministicBag()));
        [$state, $cmd] = $vs->update($this->key('left'));
        $this->assertNull($cmd, 'key input schedules nothing');
        $this->assertTrue($state->player->gravityPending, 'the original tick is still the only one');
    }

    public function testPlayerWinnerDetectedWhenComputerOver(): void
    {
        $vs = VsGame::start();

        // Set computer game to over manually
        $overComputer = $vs->computer->mutate(['over' => true]);

        $vs = new VsGame($vs->player, $overComputer);

        // Process gravity tick which should detect computer is over and set player as winner
        [$result] = $vs->update($this->gravity());

        $this->assertTrue($result->over);
        $this->assertSame('PLAYER', $result->winner);
        $this->assertTrue($result->computer->over);
    }

    public function testComputerWinnerDetectedWhenPlayerOver(): void
    {
        $vs = VsGame::start();

        // Set player game to over
        $overPlayer = $vs->player->mutate(['over' => true]);

        $vs = new VsGame($overPlayer, $vs->computer);

        // Send a message to trigger the detection
        [$result] = $vs->update($this->gravity());

        $this->assertTrue($result->over);
        $this->assertSame('COMPUTER', $result->winner);
        $this->assertTrue($result->player->over);
    }

    public function testQuitWhenOverReturnsQuitCommand(): void
    {
        $overVs = new VsGame(
            Game::start(), Game::start(),
            over: true, winner: 'PLAYER'
        );

        [, $cmd] = $overVs->update($this->key('q'));

        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testPauseWhenNotOverSetsPlayerPaused(): void
    {
        $vs = VsGame::start();
        $this->assertFalse($vs->player->paused);

        [$paused] = $vs->update($this->key('p'));
        $this->assertTrue($paused->player->paused);
    }

    public function testPauseWhenAlreadyPausedKeepsPaused(): void
    {
        $vs = VsGame::start();
        [$paused] = $vs->update($this->key('p'));
        $this->assertTrue($paused->player->paused);

        [$stillPaused] = $paused->update($this->key('p'));
        $this->assertFalse($stillPaused->player->paused);
    }

    public function testPausedVsKeepsGravityAliveAndFrozen(): void
    {
        $vs = VsGame::start();
        [$paused] = $vs->update($this->key('p'));
        $pieceBefore = $paused->player->piece;

        [$held, $cmd] = $paused->update($this->gravity());
        $this->assertSame($pieceBefore, $held->player->piece, 'gravity does not step a paused player');
        $this->assertInstanceOf(\Closure::class, $cmd, 'but the chain keeps re-arming while paused');
        $this->assertSame($paused->computer, $held->computer, 'the computer side stands down while paused');
    }

    private function assertEmptyBoard(Game $game, string $message): void
    {
        foreach ($game->board->rows() as $row) {
            foreach ($row as $cell) {
                $this->assertNull($cell, $message);
            }
        }
    }

    /**
     * Helper: Create a game with one complete line at the bottom.
     */
    private function createGameWithOneLine(Game $game): Game
    {
        $rows = $game->board->rows();

        // Make bottom visible row complete
        $bottomRow = Board::ROWS - 1;
        for ($col = 0; $col < Board::COLS; $col++) {
            $rows[$bottomRow][$col] = Tetromino::I;
        }

        return $game->mutate(['board' => new Board($rows)]);
    }
}
