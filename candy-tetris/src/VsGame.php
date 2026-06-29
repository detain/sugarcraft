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
 *   - The computer AI makes a bounded decision: once per new piece,
 *     it calls bestMove() and remembers the rotation/shift, then
 *     applies that move over subsequent gravity ticks until the piece locks.
 *   - Garbage row passing when one player clears lines
 *   - Win/lose detection when one player's game ends
 *   - Independent update loops until game over
 */
final class VsGame implements Model
{
    /** Last piece kind the computer committed a move for (null = no decision yet). */
    private ?Tetromino $computerDecisionFor = null;

    /** The computer's committed rotation delta (from bestMove). */
    private int $computerRotDelta = 0;

    /** The computer's committed horizontal shift (from bestMove). */
    private int $computerDx = 0;

    public function __construct(
        public readonly Game          $player,
        public readonly Game          $computer,
        public readonly bool          $over = false,
        public readonly ?string       $winner = null,
        private readonly Computer     $computerAI = new Computer(),
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
        if ($this->over) {
            if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
                return [$this, Cmd::quit()];
            }
            return [$this, null];
        }

        // Handle quit key for player
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }

        // Handle pause — toggle pause and keep ticks flowing
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'p') {
            $newPlayer = $this->player->update($msg)[0] ?? $this->player;
            // Return scheduleTick so the game doesn't freeze (fixes VS pause bug)
            return [$this->mutate(['player' => $newPlayer]), self::scheduleTick($newPlayer)];
        }

        // When paused, only schedule a tick (player input is ignored, computer pauses)
        if ($this->player->paused) {
            return [$this, self::scheduleTick($this->player)];
        }

        // Process player game (KeyMsg only reaches here when not paused/quit)
        [$newPlayer, ] = $this->player->update($msg);

        // Check if player's game is over (computer wins)
        if ($newPlayer->over) {
            return $this->withComputerWinner($newPlayer);
        }

        // Handle computer turn
        $newComputer = $this->computer;
        if ($msg instanceof GravityMsg) {
            $newComputer = $this->advanceComputer();
        }
        // Note: player KeyMsg is NOT forwarded to computer (computer is AI-controlled)

        // Check if computer's game is over (player wins)
        if ($newComputer->over) {
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
        if ($finalPlayer->over) {
            return $this->withComputerWinner($finalPlayer);
        }
        if ($processedComputer->over) {
            return $this->withPlayerWinner($finalPlayer, $processedComputer);
        }

        return [
            $this->mutate(['player' => $finalPlayer, 'computer' => $processedComputer]),
            self::scheduleTick($finalPlayer),
        ];
    }

    /**
     * Advance the computer's game by one gravity tick, applying AI move if needed.
     */
    private function advanceComputer(): Game
    {
        $computerGame = $this->computer;

        // Detect if a new piece just spawned (piece kind changed)
        $currentKind = $computerGame->piece->kind;
        if ($currentKind !== $this->computerDecisionFor) {
            // New piece — compute fresh AI move
            [$dx, $rotDelta] = $this->computerAI->bestMove(
                $computerGame->board,
                $computerGame->piece,
            );
            $this->computerDecisionFor = $currentKind;
            $this->computerRotDelta = $rotDelta;
            $this->computerDx = $dx;
        }

        // Apply the committed AI move: rotate, shift, hard-drop, lock+spawn
        return $computerGame->applyAiMove($this->computerRotDelta, $this->computerDx);
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
            $changes['player']   ?? $this->player,
            $changes['computer']  ?? $this->computer,
            $changes['over']     ?? $this->over,
            $changes['winner']   ?? $this->winner,
            $this->computerAI,
        );
    }
}
