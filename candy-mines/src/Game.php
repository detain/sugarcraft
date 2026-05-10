<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Minesweeper as a SugarCraft Model. Keys:
 *   - arrows / hjkl  → move cursor
 *   - space          → reveal
 *   - f              → toggle flag
 *   - r              → restart
 *   - q / esc        → quit
 *
 * The PRNG is injected as a `Closure(int $maxInclusive): int` so
 * fixture tests can pin the layout. Default is `random_int(0, $max)`.
 */
final class Game implements Model
{
    /** @var \Closure(int):int */
    private \Closure $rand;

    public function __construct(
        public readonly Board $board,
        public readonly int $cursorX = 0,
        public readonly int $cursorY = 0,
        ?\Closure $rand = null,
        public readonly ?int $startedAt = null,
        public readonly ?int $elapsedSeconds = null,
        public readonly ?Stats $stats = null,
    ) {
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
    }

    public static function start(int $width = 10, int $height = 10, int $mines = 12, ?\Closure $rand = null): self
    {
        return new self(
            board: Board::blank($width, $height, $mines),
            rand:  $rand,
        );
    }

    public static function withDifficulty(Difficulty $d, ?\Closure $rand = null): self
    {
        return self::start($d->width(), $d->height(), $d->mines(), $rand);
    }

    public static function withCustom(int $width, int $height, int $mines, ?\Closure $rand = null): self
    {
        return self::start($width, $height, $mines, $rand);
    }

    public function difficulty(): ?Difficulty
    {
        return Difficulty::fromDimensions(
            $this->board->width,
            $this->board->height,
            $this->board->mineCount,
        );
    }

    public function rand(): \Closure
    {
        return $this->rand;
    }

    public function stats(): Stats
    {
        return $this->stats ?? new Stats();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [self::withCustom(
                $this->board->width, $this->board->height, $this->board->mineCount, $this->rand,
            ), null];
        }
        if ($this->board->exploded || $this->board->isWon()) {
            // Only `r` and `q` work after the game ends.
            return [$this, null];
        }
        $next = match (true) {
            $msg->type === KeyType::Up    || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => $this->moveCursor(0, -1),
            $msg->type === KeyType::Down  || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => $this->moveCursor(0, +1),
            $msg->type === KeyType::Left  || ($msg->type === KeyType::Char && $msg->rune === 'h')
                => $this->moveCursor(-1, 0),
            $msg->type === KeyType::Right || ($msg->type === KeyType::Char && $msg->rune === 'l')
                => $this->moveCursor(+1, 0),
            $msg->type === KeyType::Space || $msg->type === KeyType::Enter
                => $this->reveal(),
            $msg->type === KeyType::Char && $msg->rune === 'f'
                => $this->flag(),
            default => $this,
        };
        return [$next, null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    private function moveCursor(int $dx, int $dy): self
    {
        return new self(
            board: $this->board,
            cursorX: max(0, min($this->board->width  - 1, $this->cursorX + $dx)),
            cursorY: max(0, min($this->board->height - 1, $this->cursorY + $dy)),
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats,
        );
    }

    private function reveal(): self
    {
        $now = $this->startedAt ?? time();
        return new self(
            board: $this->board->reveal($this->cursorX, $this->cursorY, $this->rand),
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $now,
            elapsedSeconds: null,
            stats: $this->stats,
        );
    }

    private function flag(): self
    {
        return new self(
            board: $this->board->toggleFlag($this->cursorX, $this->cursorY),
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats,
        );
    }

    /**
     * Returns the elapsed time in seconds if the game is in progress.
     */
    public function elapsed(): ?int
    {
        if ($this->startedAt === null) {
            return null;
        }
        if ($this->board->exploded || $this->board->isWon()) {
            return $this->elapsedSeconds;
        }
        return time() - $this->startedAt;
    }

    /**
     * Record the result of this game and return a new Game with updated stats.
     * The returned Game has the same board state but updated stats.
     */
    public function recordResult(?int $elapsed): self
    {
        $difficulty = $this->difficulty();
        if ($difficulty === null) {
            return $this;
        }
        $won = $this->board->isWon();
        return new self(
            board: $this->board,
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats()->withGame($difficulty, $won, $elapsed),
        );
    }
}
