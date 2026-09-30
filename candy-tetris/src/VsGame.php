<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * VS Computer mode model - combines two independent Game instances.
 *
 * Architecture:
 *   - Two independent Game states (player and computer)
 *   - update() is pure per the TEA contract: all state (including the
 *     computer's in-progress move plan) flows through the returned model,
 *     never through writes on $this.
 *   - One GravityMsg tick drives both sides. On the tick where a fresh
 *     piece is observed, bestMove() records a plan (rotations + shift);
 *     each later tick consumes exactly ONE input — one rotation, one
 *     horizontal step, one fall — and the piece locks only after gravity
 *     carries it down, mirroring how a human would play it.
 *   - Garbage row passing when one player clears lines
 *   - Win/lose detection when one player's game ends
 */
final class VsGame implements Model
{
    public function __construct(
        public readonly Game      $player,
        public readonly Game      $computer,
        public readonly bool      $over = false,
        public readonly ?string   $winner = null,
        private readonly Computer $computerAI = new Computer(),
        /** True while the computer is executing a recorded move plan. */
        public readonly bool      $hasComputerPlan = false,
        /** Rotations still owed by the current plan. */
        public readonly int       $computerRotationsLeft = 0,
        /** Horizontal cells still owed by the current plan (signed). */
        public readonly int       $computerShiftLeft = 0,
    ) {}

    /**
     * Start a new VS Computer game.
     */
    public static function start(): self
    {
        return new self(Game::start(), Game::start());
    }

    public function init(): ?\Closure
    {
        return self::scheduleTick($this->player);
    }

    /**
     * Schedule a tick for the player game.
     * The player controls overall speed.
     */
    private static function scheduleTick(Game $player): \Closure
    {
        $interval = $player->score->gravityIntervalUs() / 1_000_000;
        return Cmd::tick($interval, static fn(): Msg => new GravityMsg());
    }

    public function update(Msg $msg): array
    {
        // If game is over, only accept quit
        if ($this->over === true) {
            if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
                return [$this, Cmd::quit()];
            }
            return [$this, null];
        }

        // Handle quit key for player
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }

        // Handle pause — toggle pause; the in-flight tick stays in flight,
        // so no new tick is armed here (arming on key messages was the
        // chain-duplication defect fixed alongside Game's gravity guard).
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'p') {
            [$newPlayer] = $this->player->update($msg);
            return [$this->mutate(['player' => $newPlayer]), null];
        }

        // While paused, only the tick chain keeps beating: gravity re-arms
        // itself, every other message is ignored (player input dropped,
        // computer frozen).
        if ($this->player->paused === true) {
            if ($msg instanceof GravityMsg) {
                return [$this, self::scheduleTick($this->player)];
            }
            return [$this, null];
        }

        // Process player game (KeyMsg only reaches here when not paused/quit)
        [$newPlayer, ] = $this->player->update($msg);

        // Check if player's game is over (computer wins)
        if ($newPlayer->over === true) {
            return $this->withComputerWinner($newPlayer);
        }

        // Advance the computer exactly one input per gravity tick; key
        // messages never touch the computer (it is AI-controlled).
        $newComputer = $this->computer;
        $hasPlan = $this->hasComputerPlan;
        $rotationsLeft = $this->computerRotationsLeft;
        $shiftLeft = $this->computerShiftLeft;
        if ($msg instanceof GravityMsg) {
            [$newComputer, $hasPlan, $rotationsLeft, $shiftLeft] = self::stepComputer(
                $this->computer,
                $this->hasComputerPlan,
                $this->computerRotationsLeft,
                $this->computerShiftLeft,
                $this->computerAI,
            );
        }

        // Check if computer's game is over (player wins)
        if ($newComputer->over === true) {
            return $this->withPlayerWinner($newPlayer, $newComputer);
        }

        // If player cleared lines, add garbage to computer
        $linesCleared = $newPlayer->score->lines - $this->player->score->lines;
        $processedComputer = $newComputer;
        if ($linesCleared > 0) {
            $processedComputer = $newComputer->addGarbageRows($linesCleared);
        }

        // If computer cleared lines, add garbage to player
        $computerBeforeLines = $this->computer->score->lines;
        $computerLinesCleared = $processedComputer->score->lines - $computerBeforeLines;
        $finalPlayer = $newPlayer;
        if ($computerLinesCleared > 0) {
            $finalPlayer = $newPlayer->addGarbageRows($computerLinesCleared);
        }

        // Check for game over after garbage
        if ($finalPlayer->over === true) {
            return $this->withComputerWinner($finalPlayer);
        }
        if ($processedComputer->over === true) {
            return $this->withPlayerWinner($finalPlayer, $processedComputer);
        }

        return [
            $this->mutate([
                'player' => $finalPlayer,
                'computer' => $processedComputer,
                'hasComputerPlan' => $hasPlan,
                'computerRotationsLeft' => $rotationsLeft,
                'computerShiftLeft' => $shiftLeft,
            ]),
            // The tick chain is singleton: re-arm only when this message
            // consumed the in-flight tick, never on key messages.
            $msg instanceof GravityMsg ? self::scheduleTick($finalPlayer) : null,
        ];
    }

    /**
     * Perform one computer input for one gravity tick, purely.
     *
     * Tick without a plan: record bestMove()'s result (the piece itself is
     * untouched this tick). Later ticks spend the plan one input at a time
     * — rotations first, then horizontal shifts, then falls — and lock the
     * piece (drop + lock + spawn) once the plan is spent and the piece is
     * resting.
     *
     * @return array{0:Game,1:bool,2:int,3:int} [computer state, hasPlan, rotationsLeft, shiftLeft]
     */
    private static function stepComputer(
        Game $computer,
        bool $hasPlan,
        int $rotationsLeft,
        int $shiftLeft,
        Computer $ai,
    ): array {
        if ($hasPlan === false) {
            [$dx, $rotDelta] = $ai->bestMove($computer->board, $computer->piece);
            return [$computer, true, $rotDelta, $dx];
        }

        if ($rotationsLeft > 0) {
            $stepped = $computer->mutate([
                'piece' => $computer->piece->rotated(1),
                'lastActionWasRotation' => true,
            ]);
            return [$stepped, true, $rotationsLeft - 1, $shiftLeft];
        }

        if ($shiftLeft !== 0) {
            $direction = $shiftLeft > 0 ? 1 : -1;
            $candidate = $computer->piece->moved($direction, 0);
            if ($computer->board->fits($candidate)) {
                $stepped = $computer->mutate([
                    'piece' => $candidate,
                    'lastActionWasRotation' => false,
                ]);
                return [$stepped, true, 0, $shiftLeft - $direction];
            }
            // Blocked sideways — abandon the rest of the shift this piece.
            return [$computer, true, 0, 0];
        }

        if ($computer->board->fits($computer->piece->moved(0, 1))) {
            $stepped = $computer->mutate([
                'piece' => $computer->piece->moved(0, 1),
                'lastActionWasRotation' => false,
            ]);
            return [$stepped, true, 0, 0];
        }

        // Plan spent and resting: lock the piece and take delivery of the
        // next one; the following tick will record a fresh plan.
        $locked = $computer->applyAiMove(0, 0);
        return [$locked, false, 0, 0];
    }

    /**
     * @return array{0:VsGame,1:?\Closure}
     */
    private function withComputerWinner(Game $player): array
    {
        $overPlayer = $player->mutate(['over' => true, 'canHold' => false]);
        return [
            $this->mutate(['player' => $overPlayer, 'over' => true, 'winner' => 'COMPUTER']),
            null,
        ];
    }

    /**
     * @return array{0:VsGame,1:?\Closure}
     */
    private function withPlayerWinner(Game $player, Game $computer): array
    {
        $overComputer = $computer->mutate(['over' => true, 'canHold' => false]);
        return [
            $this->mutate(['player' => $player, 'computer' => $overComputer, 'over' => true, 'winner' => 'PLAYER']),
            null,
        ];
    }

    public function view(): string
    {
        return VsRenderer::render($this);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * Construct a new VsGame from the current state with optional field overrides.
     */
    private function mutate(array $changes): self
    {
        return new self(
            $changes['player'] ?? $this->player,
            $changes['computer'] ?? $this->computer,
            $changes['over'] ?? $this->over,
            $changes['winner'] ?? $this->winner,
            $this->computerAI,
            $changes['hasComputerPlan'] ?? $this->hasComputerPlan,
            $changes['computerRotationsLeft'] ?? $this->computerRotationsLeft,
            $changes['computerShiftLeft'] ?? $this->computerShiftLeft,
        );
    }
}
