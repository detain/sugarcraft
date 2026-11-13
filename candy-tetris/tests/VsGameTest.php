<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\ComputerTickMsg;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\LockDelayMsg;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Score;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;
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

    private function aiTick(): ComputerTickMsg
    {
        return new ComputerTickMsg();
    }

    private function deterministicBag(): Bag
    {
        return new Bag(static fn(int $_max): int => 0);
    }

    /**
     * -066 cure: the old body was two `assertInstanceOf(Game::class, ...)`
     * lines — readonly typed props, cannot fail. The fresh versus pair now
     * gets the contract that actually matters at kickoff: boards empty,
     * scores zeroed, the AI holding no plan yet, the frame natural.
     */
    public function testStartCreatesAFreshEmptyVersusPair(): void
    {
        $vs = VsGame::start();

        foreach ([$vs->player, $vs->computer] as $game) {
            $occupied = 0;
            foreach ($game->board->rows() as $row) {
                foreach ($row as $cell) {
                    $occupied += $cell === null ? 0 : 1;
                }
            }
            $this->assertSame(0, $occupied, 'a fresh versus board must start empty');
            $this->assertSame(0, $game->score->points);
            $this->assertFalse($game->over);
        }

        $this->assertFalse($vs->over);
        $this->assertNull($vs->winner);
        $this->assertFalse($vs->hasComputerPlan, 'the AI starts without a recorded plan');
        $this->assertSame(0, $vs->computerRotationsLeft);
        $this->assertSame(0, $vs->computerShiftLeft);
        $this->assertSame(VsRenderer::NATURAL_COLS, $vs->frameCols);
        $this->assertSame(VsRenderer::NATURAL_ROWS, $vs->frameRows);
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

    /**
     * candy-tetris-047 (matrix leg 2 of 4): the mirror transfer. The old
     * suite only ever showed player→computer garbage; here the COMPUTER
     * clears a real line inside the VS loop and the PLAYER's board must
     * visibly receive the garbage row.
     */
    public function testGarbageRowPassingWhenComputerClearsLines(): void
    {
        $vs = VsGame::start();
        $computer = $this->createComputerPoisedToClear($vs->computer);
        $vs = new VsGame($vs->player, $computer, hasComputerPlan: true);

        $playerBoardBefore = $vs->player->board;
        [$updated] = $vs->update($this->aiTick());

        $this->assertSame($computer->score->lines + 1, $updated->computer->score->lines,
            'the computer must really have cleared the line');
        $this->assertNotSame($playerBoardBefore, $updated->player->board,
            'the player board must change when garbage arrives');

        $bottomRow = $updated->player->board->rows()[Board::ROWS - 1];
        $nulls = count(array_filter($bottomRow, static fn($c) => $c === null));
        $this->assertSame(1, $nulls, 'garbage row carries exactly one hole');

        $this->assertNull($updated->winner, 'a surviving arrival must not decide the match');
    }

    /**
     * candy-tetris-047 (matrix leg 3 of 4): death by arrival, player kills.
     * The defender's stack already touches the ceiling, so the arriving
     * garbage row itself is the kill — the match must end with the winner
     * recorded and the banner naming the reason.
     */
    public function testGarbageArrivalTopOutEndsTheComputer(): void
    {
        $vs = VsGame::start();
        $player = $this->createGameWithOneLine($vs->player);
        $computer = $this->seedCeilingCell($vs->computer);
        $vs = new VsGame($player, $computer);

        [$updated] = $vs->update($this->key(' ')); // hard drop → clear → lethal arrival

        $this->assertTrue($updated->over, 'a lethal garbage arrival must end the match');
        $this->assertSame('PLAYER', $updated->winner);
        $this->assertSame(Game::OVER_TOP_OUT, $updated->computer->overReason,
            'the computer must die by the arriving garbage, not by its own lock');

        $view = $updated->view();
        $this->assertStringContainsString('YOU WIN!', $view);
        $this->assertStringContainsString('stack reached the ceiling', $view,
            'the death banner must name why the loser died');
    }

    /**
     * candy-tetris-047 (matrix leg 4 of 4): death by arrival, computer kills
     * — the mirror direction with a lethal arrival, which the old suite
     * never exercised at all (its win asserts were gravity-driven only).
     */
    public function testGarbageArrivalTopOutEndsThePlayer(): void
    {
        $vs = VsGame::start();
        $computer = $this->createComputerPoisedToClear($vs->computer);
        $player = $this->seedCeilingCell($vs->player);
        $vs = new VsGame($player, $computer, hasComputerPlan: true);

        [$updated] = $vs->update($this->aiTick());

        $this->assertTrue($updated->over, 'a lethal garbage arrival must end the match');
        $this->assertSame('COMPUTER', $updated->winner);
        $this->assertSame(Game::OVER_TOP_OUT, $updated->player->overReason,
            'the player must die by the arriving garbage, not by their own lock');

        $view = $updated->view();
        $this->assertStringContainsString('COMPUTER WINS!', $view);
        $this->assertStringContainsString('stack reached the ceiling', $view,
            'the death banner must name why the loser died');
    }

    /**
     * candy-tetris-017: the human side of a VS match now starts on the solo
     * game's 500 ms lock-delay window. A grounded VS piece must survive the
     * tick-driven landing (the window owns the timing, gravity self-parks),
     * slide inputs must re-arm it through the forwarded Cmd, and the live
     * window generation must be the thing that finally locks the piece.
     */
    public function testVsGroundedPieceSlidesOnTheLockWindow(): void
    {
        $vs = $this->createVsPlayerPerched();

        [$state, $cmd] = $vs->update($this->gravity());
        $request = $cmd === null ? null : $cmd();
        $this->assertInstanceOf(TickRequest::class, $request, 'grounding must arm the window timer');
        $this->assertSame(0.5, $request->seconds, 'the VS window is the solo 500 ms one');
        $this->assertNotNull($state->player->lockWindowGeneration);

        [$parked] = $state->update($this->gravity());
        $this->assertSame($state->player->piece, $parked->player->piece, 'a tick must not bypass the window');
        $this->assertSame($state->player->board, $parked->player->board, 'no lock, no stack growth');

        [$afterSlide, $slideCmd] = $parked->update($this->key('left'));
        $this->assertNotNull($slideCmd, 'a grounded VS move forwards its re-armed window timer');
        $this->assertNotSame($parked->player->lockWindowGeneration, $afterSlide->player->lockWindowGeneration,
            'the slide must re-arm a fresh generation');

        [$locked, $lockCmd] = $afterSlide->update(new LockDelayMsg($afterSlide->player->lockWindowGeneration));
        $this->assertNotSame($afterSlide->player->piece, $locked->player->piece, 'the live window generation locks the piece');
        $this->assertNotSame($afterSlide->player->board, $locked->player->board);
        $this->assertNotNull($lockCmd, 'the lock re-anchors the gravity chain');
    }

    /**
     * candy-tetris-017 (pause composition): solo's survival law mirrored —
     * a window that comes due while the VS board is paused EXTENDS, it
     * never locks a frozen piece out from under the player.
     */
    public function testVsPauseExtendsTheWindowInsteadOfLocking(): void
    {
        $vs = $this->createVsPlayerPerched();
        [$grounded] = $vs->update($this->gravity());
        [$paused] = $grounded->update($this->key('p'));
        $gen = $paused->player->lockWindowGeneration;
        $this->assertNotNull($gen, 'the window armed before the pause');

        [$held, $again] = $paused->update(new LockDelayMsg($gen));

        $this->assertNotNull($again, 'a paused VS window re-arms, it never locks');
        $this->assertSame($paused->player->piece, $held->player->piece, 'the frozen board keeps its piece');
        $this->assertSame($gen, $held->player->lockWindowGeneration, 'the same generation stays live');
    }

    /**
     * candy-tetris-017 (garbage composition): a lift that clears the
     * player's armed window strands the chain behind a stale timer unless
     * the re-anchor law fires — pending must be re-seated and a heartbeat
     * must be in flight on the same arrival.
     */
    public function testGarbageLiftReAnchorsAWindowedPlayerChain(): void
    {
        $vs = VsGame::start();
        $computer = $this->createComputerPoisedToClear($vs->computer);
        // Player grounded with a live window, chain parked (gravity consumed
        // when the window opened): exactly the shape a slide leaves behind.
        $player = $vs->player->mutate([
            'piece' => new Piece(Tetromino::T, 0, 3, 22),
            'gravityPending' => false,
            'lockGeneration' => 1,
            'lockWindowGeneration' => 1,
        ]);
        $vs = new VsGame($player, $computer, hasComputerPlan: true);

        [$after, $cmd] = $vs->update($this->aiTick()); // the computer's lock leg sends garbage

        $this->assertNull($after->player->lockWindowGeneration, 'the lift cleared the stale window');
        $this->assertTrue($after->player->gravityPending, 'the strand rule must re-anchor the chain');
        $this->assertNotNull($cmd, 'both chains must be in flight so the game resumes');
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch, 'a re-anchored arrival carries both clocks');
        $classes = [];
        foreach ($batch->cmds as $inner) {
            $request = $inner();
            $this->assertInstanceOf(TickRequest::class, $request);
            $classes[] = get_class(($request->produce)());
        }
        $this->assertContains(GravityMsg::class, $classes, 'the strand rule arms a gravity tick, not a stale lock');
        $this->assertContains(ComputerTickMsg::class, $classes, 'and the AI chain keeps its own clock');
    }

    /**
     * candy-tetris-085 pause composition: the AI chain dies on purpose at
     * the paused branch (a frozen board must not tick), and every resume
     * revives it with EXACTLY one anchor — never stacked revives.
     */
    public function testVsPauseFreezesTheAiAndResumeRevivesItsChain(): void
    {
        $vs = $this->createVsPlayerPerched();

        [$paused] = $vs->update($this->key('p'));
        [$held, $dropCmd] = $paused->update($this->aiTick());
        $this->assertNull($dropCmd, 'an AI tick landing while paused dies without re-arming');
        $this->assertSame($paused->computer, $held->computer, 'the frozen AI does not step');

        [$resumed, $reviveCmd] = $held->update($this->key('p'));
        $this->assertFalse($resumed->player->paused);
        $this->assertNotNull($reviveCmd, 'resume revives the AI chain exactly once');
        $msg = ($reviveCmd()) instanceof BatchMsg
            ? null
            : (($reviveCmd())->produce)();
        $this->assertInstanceOf(ComputerTickMsg::class, $msg, 'the revive is the AI clock, not a stray gravity tick');
        $this->assertSame($resumed->computerChain, $msg->chain, 'armed under the live AI epoch');

        [$pausedAgain] = $resumed->update($this->key('p'));
        [, $second] = $pausedAgain->update($this->key('p'));
        $this->assertNotNull($second, 'every resume carries one fresh AI anchor');
    }

    /**
     * candy-tetris-085 freeze composition: below the frame floor BOTH
     * clocks keep beating and NEITHER board steps, so a resize back into
     * fit resumes with live anchors instead of dead chains.
     */
    public function testTooSmallFrameKeepsBothClocksBeating(): void
    {
        $vs = VsGame::start();
        $tiny = new VsGame($vs->player, $vs->computer, frameCols: 10, frameRows: 5);
        $this->assertTrue($tiny->tooSmall());

        [, $cmd] = $tiny->update($this->aiTick());
        $request = $cmd();
        $this->assertInstanceOf(ComputerTickMsg::class, ($request->produce)(), 'the AI heartbeat survives the freeze');

        [, $gcmd] = $tiny->update($this->gravity());
        $this->assertInstanceOf(GravityMsg::class, (($gcmd())->produce)(), 'and so does the human chain');
    }

    /** Fresh VS pair whose player piece sits grounded on the floor, ready to window. */
    private function createVsPlayerPerched(): VsGame
    {
        $vs = VsGame::start();
        $player = $vs->player->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 22)]);

        return new VsGame($player, $vs->computer);
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

    public function testComputerTakesExactlyOneActionPerAiTick(): void
    {
        // MAJ(4): the old loop memo-stored the AI decision, dropped the memo
        // on every mutate, and applied the whole placement (rotate+shift+drop
        // +lock) in a single tick. The plan now lives in the model and each
        // AI-clock tick spends exactly one input (candy-tetris-085 moved the
        // step off the human's gravity chain — it no longer waits on 'p'
        // presses or a slow human level).
        $vs = VsGame::start();
        $initialPiece = $vs->computer->piece;

        [$state] = $vs->update($this->aiTick());
        $this->assertTrue($state->hasComputerPlan, 'the first tick records the plan');
        $this->assertSame($initialPiece, $state->computer->piece,
            'the decision tick itself moves nothing');
        $this->assertEmptyBoard($state->computer, 'nothing may lock before the plan is spent');

        $rot = $initialPiece->rotation;
        $x = $initialPiece->x;
        $y = $initialPiece->y;
        for ($i = 0; $i < 8; $i++) {
            [$next] = $state->update($this->aiTick());
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
            [$a] = $a->update($this->aiTick());
            [$b] = $b->update($this->aiTick());
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

    /**
     * candy-tetris-047 helper: a computer mid-set that clears a line the
     * moment its plan's lock leg runs — the bottom row filled except one
     * column, and a vertical I resting in that gap (rot1 puts its cells in
     * column x+2, so x=0 fills column 2 down to the floor).
     */
    private function createComputerPoisedToClear(Game $game): Game
    {
        $rows = $game->board->rows();
        $bottomRow = Board::ROWS - 1;
        for ($col = 0; $col < Board::COLS; $col++) {
            if ($col !== 2) {
                $rows[$bottomRow][$col] = Tetromino::I;
            }
        }

        return $game->mutate([
            'board' => new Board($rows),
            'piece' => new Piece(Tetromino::I, 1, 0, Board::ROWS - 4),
        ]);
    }

    /**
     * candy-tetris-047 helper: one locked cell in the very top row, so the
     * next garbage arrival has nowhere to push the stack and the arrival
     * itself becomes the kill (Game::OVER_TOP_OUT).
     */
    private function seedCeilingCell(Game $game): Game
    {
        $rows = $game->board->rows();
        $rows[0][0] = Tetromino::I;

        return $game->mutate(['board' => new Board($rows)]);
    }

    /**
     * MA-4-vs: a plan rotation that does not fit is never forced. The
     * computer's T sits at spawn with one rot1 owed; cell (4,2) is the
     * rot1-exclusive cell (rot0 does not touch it), so the naive rotation
     * is illegal. Pre-guard the piece was rotated blind into the blocker —
     * an overlapping, illegal state that then dropPiece'd and locked over
     * occupied cells. Post-guard the rotation is skipped, the piece stays
     * legal, and (candy-tetris-085) the unexecutable plan is RE-EVALUATED
     * from the next tick rather than run out leg by leg into a wasted
     * drop at the old target.
     */
    public function testComputerRotationPlanSkipsIllegalRotation(): void
    {
        $computer = Game::start($this->deterministicBag())
            ->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 0)]);
        $rows = $computer->board->rows();
        $rows[2][4] = Tetromino::I; // blocks ONLY the rot1 target; rot0 fits fine
        $computer = $computer->mutate(['board' => new Board($rows)]);

        $vs = new VsGame(
            player: Game::start($this->deterministicBag()),
            computer: $computer,
            hasComputerPlan: true,
            computerRotationsLeft: 1,
            computerShiftLeft: 0,
        );

        [$after] = $vs->update($this->aiTick());

        $this->assertSame(
            0,
            $after->computer->piece->rotation,
            'an impossible plan rotation must be skipped, not teleported into legality',
        );
        $this->assertTrue(
            $after->computer->board->fits($after->computer->piece),
            'the computer piece must always be in a legal state',
        );
        $this->assertFalse(
            $after->hasComputerPlan,
            'a blocked plan is re-evaluated from the next tick, not continued toward a stale target',
        );

        [$retargeted, $retargetCmd] = $after->update($this->aiTick());
        $this->assertTrue($retargeted->hasComputerPlan, 'the next AI tick records a fresh bestMove');
        $this->assertNotNull($retargetCmd);
    }

    /**
     * candy-tetris-007/-085: the AI runs on its own clock. The ComputerTick
     * anchor carries the COMPUTER board's gravity interval, so a sprinting
     * human can no longer drag the opponent through 20 plies a second, and
     * a crawling human level cannot starve it either.
     */
    public function testComputerClockRunsAtTheComputersOwnInterval(): void
    {
        $vs = VsGame::start();
        $sprinting = $vs->player->mutate(['score' => new Score(0, 0, 20)]);
        $vs = new VsGame($sprinting, $vs->computer);

        $playerInterval = $vs->player->score->gravityIntervalUs() / 1_000_000;
        $computerInterval = $vs->computer->score->gravityIntervalUs() / 1_000_000;
        $this->assertLessThan($computerInterval, $playerInterval, 'the boards must actually disagree on pace');

        [, $cmd] = $vs->update($this->aiTick());
        $request = $cmd();
        $this->assertInstanceOf(TickRequest::class, $request);
        $this->assertSame($computerInterval, $request->seconds, 'the AI re-arms at ITS interval');
        $this->assertGreaterThan($playerInterval, $request->seconds, 'never throttled to the human clock');

        [, $humanCmd] = $vs->update($this->gravity());
        $humanRequest = $humanCmd();
        $this->assertSame($playerInterval, $humanRequest->seconds, 'the human chain keeps the human pace');
    }

    /**
     * candy-tetris-007/-085 eventuality: with ZERO human input the match
     * still finishes — the AI keeps placing on its own clock until a board
     * dies — and it visibly progresses (the stack grows), it does not
     * stall behind the parked human chain.
     */
    public function testMatchFinishesWithZeroHumanInput(): void
    {
        $vs = new VsGame(
            player: Game::start($this->deterministicBag()),
            computer: Game::start($this->deterministicBag()),
        );

        $boardBefore = $vs->computer->board;
        $ticks = 0;
        while ($vs->over === false && $ticks < 4000) { // a full stack is ~60 pieces at ~25 ticks each
            [$vs] = $vs->update(new ComputerTickMsg(0)); // one clock beats; the other idles
            $ticks++;
        }

        $this->assertNotSame($boardBefore, $vs->computer->board, 'the AI must visibly progress with no human input at all');
        $this->assertTrue($vs->over, "the match must finish (took {$ticks} AI ticks)");
        $this->assertContains($vs->winner, ['PLAYER', 'COMPUTER'], 'a finished match carries its verdict');
    }

    /**
     * MA-11: parity restart. The versus board honours 'r' once the match is
     * over — a fresh pair, cleared plan state, and exactly one re-armed
     * tick chain (mirroring the solo game).
     */
    public function testRestartInVersusFreshensPairAndClearsPlan(): void
    {
        $base = VsGame::start();
        $over = new VsGame(
            player: $base->player->mutate(['over' => true]),
            computer: $base->computer,
            over: true,
            winner: 'PLAYER',
            hasComputerPlan: true,
            computerRotationsLeft: 2,
            computerShiftLeft: 3,
        );

        [$fresh, $cmd] = $over->update($this->key('r'));

        $this->assertFalse($fresh->over, 'r must clear the over state');
        $this->assertNull($fresh->winner, 'r must clear the winner');
        $this->assertFalse($fresh->hasComputerPlan, 'r must drop the stale computer plan');
        $this->assertSame(0, $fresh->computerRotationsLeft);
        $this->assertInstanceOf(\Closure::class, $cmd, 'the fresh pair arms exactly one tick chain');

        [$next, $reArm] = $fresh->update(new GravityMsg($fresh->gravityChain));
        $this->assertFalse($next->over, 'the restarted match keeps playing');
        $this->assertInstanceOf(\Closure::class, $reArm, 'the live chain answers with its next tick');
    }

    /**
     * candy-tetris-003: the VS tick chain is generation-tagged like the
     * solo one. The epoch bumps exactly once per restart, the armed tick
     * carries it, and a tick still in flight from the superseded chain
     * goes inert — no step, no computer input, no second re-arm.
     */
    public function testVsChainEpochBumpsOnRestartAndSupersededTicksGoInert(): void
    {
        $base = VsGame::start();
        $this->assertSame(0, $base->gravityChain, 'a fresh match owns chain 0');

        $over = new VsGame(
            player: $base->player->mutate(['over' => true]),
            computer: $base->computer,
            over: true,
            gravityChain: 4,
        );
        [$fresh, $cmd] = $over->update($this->key('r'));
        $this->assertSame(5, $fresh->gravityChain, 'the restart bumps the epoch once');
        $this->assertSame(1, $fresh->computerChain, 'and the AI epoch with it, once');
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch, 'restart arms both clocks exactly once each');
        $gravity = $ai = [];
        foreach ($batch->cmds as $inner) {
            $request = $inner();
            $this->assertInstanceOf(TickRequest::class, $request);
            $msg = ($request->produce)();
            if ($msg instanceof GravityMsg) {
                $gravity[] = $msg;
            } else {
                $ai[] = $msg;
            }
        }
        $this->assertCount(1, $gravity, 'one gravity tick armed');
        $this->assertSame(5, $gravity[0]->chain, 'armed under the live epoch, never a bare 0');
        $this->assertCount(1, $ai, 'one AI tick armed');
        $this->assertInstanceOf(ComputerTickMsg::class, $ai[0]);
        $this->assertSame(1, $ai[0]->chain, 'the AI chain is armed under its own live epoch');

        [$staleAi, $staleAiArm] = $fresh->update(new ComputerTickMsg(0)); // dead chain's in-flight tick
        $this->assertSame($fresh, $staleAi, 'a superseded AI tick may not step the fresh pair');
        $this->assertNull($staleAiArm, 'nor leak a second AI timer');

        [$stale, $staleArm] = $fresh->update(new GravityMsg(4)); // dead chain's in-flight tick
        $this->assertSame($fresh, $stale, 'a superseded tick may not step the fresh pair');
        $this->assertNull($staleArm, 'nor leak a second timer');

        [$live, $liveArm] = $fresh->update(new GravityMsg(5));
        $this->assertNotSame($fresh, $live, 'the live chain still drives the pair');
        $this->assertInstanceOf(\Closure::class, $liveArm, 'and re-arms exactly once');
    }

    /**
     * Discrete-event simulation of the loop VsGame would drive (same
     * shape as {@see GameGravityChainTest}): each Cmd is invoked to a
     * TickRequest, queued at now + seconds, and the produced Msg fed
     * back. The return value is the number of tick timers STILL in
     * flight when the window closes — the singleton chain must show
     * exactly one, candy-tetris-003's leak showed one MORE per restart,
     * with no real sleeping involved.
     *
     * @param list<float> $restartAt times at which the match is over and 'r' is pressed
     */
    private function simulateVsChain(array $restartAt, float $window = 10.0): array
    {
        $vs = VsGame::start();
        $now = 0.0;
        /** @var list<array{0: float, 1: \Closure}> $queue */
        $queue = [];
        $arm = null;
        $arm = function (?\Closure $cmd) use (&$arm, &$queue, &$now): void {
            if ($cmd === null) {
                return;
            }
            $request = $cmd();
            if ($request instanceof BatchMsg) {
                foreach ($request->cmds as $inner) {
                    $arm($inner);
                }

                return;
            }
            if ($request instanceof \SugarCraft\Core\TickRequest) {
                $queue[] = [$now + $request->seconds, $request->produce, get_class(($request->produce)())];
            }
        };
        $arm($vs->init());

        $pendingRestarts = array_values(array_filter($restartAt, static fn(float $t): bool => $t <= $window));

        while ($queue !== [] || $pendingRestarts !== []) {
            $nextEvent = $queue === [] ? INF : min(array_column($queue, 0));
            $nextRestart = $pendingRestarts === [] ? INF : $pendingRestarts[0];

            if ($nextRestart <= $nextEvent) {
                $restartTime = array_shift($pendingRestarts);
                $now = max($now, $restartTime);
                // The bug's exact shape: a LOSS (forced over), then the
                // very next press is 'r'. The dead chain's tick stays
                // queued in $queue to prove the guard must catch it on
                // ARRIVAL, not at arming.
                $vs = new VsGame(
                    player: $vs->player->mutate(['over' => true]),
                    computer: $vs->computer,
                    over: true,
                    gravityChain: $vs->gravityChain,
                    computerChain: $vs->computerChain,
                );
                [$vs, $cmd] = $vs->update($this->key('r'));
                $arm($cmd);
                continue;
            }

            if ($nextEvent > $window) {
                break;
            }
            $index = array_search($nextEvent, array_column($queue, 0), true);
            [, $produce] = $queue[$index];
            array_splice($queue, $index, 1);
            $now = $nextEvent;
            [$vs, $cmd] = $vs->update($produce());
            $arm($cmd);
        }

        $g = $c = 0;
        foreach ($queue as [, , $cls]) {
            if ($cls === GravityMsg::class) {
                $g++;
            } else {
                $c++;
            }
        }

        return [$g, $c];
    }

    /**
     * candy-tetris-003 pin: restart must never stack gravity timers.
     * Two losses-and-restarts inside a 10 s window leave the same
     * singleton chain the keyless baseline shows — before the epoch
     * guard each restart leaked one more concurrent timer and the tick
     * speed doubled permanently.
     */
    public function testVsRestartTwiceKeepsExactlyOneTickChain(): void
    {
        $this->assertSame([1, 1], $this->simulateVsChain([]), 'baseline: each clock is a singleton');

        $this->assertSame(
            [1, 1],
            $this->simulateVsChain([3.0, 6.0]),
            'after two restarts exactly one tick chain per clock may still be in flight',
        );
    }
}
