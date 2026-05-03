<?php

declare(strict_types=1);

namespace CandyCore\Mines;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Minesweeper as a CandyCore Model. Keys:
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

    public function rand(): \Closure
    {
        return $this->rand;
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
            return [self::start(
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
            $this->board,
            max(0, min($this->board->width  - 1, $this->cursorX + $dx)),
            max(0, min($this->board->height - 1, $this->cursorY + $dy)),
            $this->rand,
        );
    }

    private function reveal(): self
    {
        return new self(
            $this->board->reveal($this->cursorX, $this->cursorY, $this->rand),
            $this->cursorX, $this->cursorY, $this->rand,
        );
    }

    private function flag(): self
    {
        return new self(
            $this->board->toggleFlag($this->cursorX, $this->cursorY),
            $this->cursorX, $this->cursorY, $this->rand,
        );
    }
}
