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
 * @mixin Game
 *
 * Architecture:
 *   - Two independent Game states (player and computer)
 *   - Garbage row passing when one player clears lines
 *   - Win/lose detection when one player's game ends
 *   - Independent update loops until game over
 */
final class VsGame implements Model
{
    public function __construct(
        public readonly Game  $player,
        public readonly Game  $computer,
        public readonly bool  $over = false,
        public readonly ?string $winner = null,
    ) {}

    /**
     * Start a new VS Computer game.
     */
    public static function start(): self
    {
        return new self(Game::start(), Game::vsComputer());
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

        // Handle pause
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'p') {
            $newPlayer = $this->player->update($msg)[0] ?? $this->player;
            return [new self($newPlayer, $this->computer, over: false, winner: null), null];
        }

        // Process player game
        [$newPlayer, ] = $this->player->update($msg);

        // Check if player's game is over (computer wins)
        if ($newPlayer->over) {
            return $this->withComputerWinner($newPlayer);
        }

        // Clone computer and tick it forward (AI moves every update)
        $computerBeforeLines = $this->computer->score->lines;
        [$newComputer, ] = $this->computer->update($msg);
        $computerAfterLines = $newComputer->score->lines;

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
            new self($finalPlayer, $processedComputer, over: false, winner: null),
            self::scheduleTick($finalPlayer),
        ];
    }

    /**
     * @return array{0:VsGame,1:?\Closure}
     */
    private function withComputerWinner(Game $player): array
    {
        $overPlayer = new Game(
            $player->board, $player->piece, $player->bag, $player->score,
            over: true, hold: $player->hold, canHold: false,
        );
        return [
            new self($overPlayer, $this->computer, over: true, winner: 'COMPUTER'),
            null,
        ];
    }

    /**
     * @return array{0:VsGame,1:?\Closure}
     */
    private function withPlayerWinner(Game $player, Game $computer): array
    {
        $overComputer = new Game(
            $computer->board, $computer->piece, $computer->bag, $computer->score,
            over: true, hold: $computer->hold, canHold: false,
        );
        return [
            new self($player, $overComputer, over: true, winner: 'PLAYER'),
            null,
        ];
    }

    public function view(): string
    {
        return VsRenderer::render($this);
    }
}
