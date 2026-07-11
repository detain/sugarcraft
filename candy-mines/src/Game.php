<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseMode;
use SugarCraft\Mines\Stats\DifficultyStats;

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
        public readonly ?float $startedAt = null,
        public readonly ?int $elapsedSeconds = null,
        public readonly ?Stats $stats = null,
        /**
         * Opt-in absolute path for persisting {@see Stats} across sessions.
         * Null (the default) keeps the game entirely in-memory. When set, a
         * completed game's result is written via {@see DifficultyStats} on the
         * win/lose transition — best-effort, never fatal.
         */
        public readonly ?string $statsPath = null,
    ) {
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
    }

    public static function start(int $width = 10, int $height = 10, int $mines = 12, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null): self
    {
        return new self(
            board: Board::blank($width, $height, $mines),
            rand:  $rand,
            stats: $stats,
            statsPath: $statsPath,
        );
    }

    public static function withDifficulty(Difficulty $d, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null): self
    {
        return self::start($d->width(), $d->height(), $d->mines(), $rand, $stats, $statsPath);
    }

    public static function withCustom(int $width, int $height, int $mines, ?\Closure $rand = null, ?Stats $stats = null, ?string $statsPath = null): self
    {
        return self::start($width, $height, $mines, $rand, $stats, $statsPath);
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
        // Snapshot terminal state BEFORE the action so afterAction() can detect
        // the exact frame a game is won/lost and record the result once.
        $wasOver = $this->board->exploded || $this->board->isWon();

        // Wire mouse before the KeyMsg guard so clicks are handled first.
        if ($msg instanceof MouseMsg) {
            return [$this->afterAction($this->onMouse($msg), $wasOver), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && $msg->rune === 'q')
            || ($msg->ctrl && $msg->rune === 'c')) {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            // Restart carries the accumulated stats + persistence path forward so
            // a session's tally survives replays.
            return [self::withCustom(
                $this->board->width, $this->board->height, $this->board->mineCount,
                $this->rand, $this->stats, $this->statsPath,
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
            $msg->type === KeyType::Char && $msg->rune === 'c'
                => $this->chord(),
            default => $this,
        };
        return [$this->afterAction($next, $wasOver), null];
    }

    /**
     * Detect the win/lose transition produced by a board-mutating action and
     * record the result exactly once. This is the wiring that makes the whole
     * Stats system live from the CLI — previously {@see recordResult()} was only
     * ever called by tests, so every game's outcome was silently dropped.
     *
     * The transition is "was not over → is now over". Re-entrant key/mouse
     * events on an already-finished board keep $wasOver true and record nothing.
     * Persistence to {@see $statsPath} is opt-in and best-effort.
     */
    private function afterAction(self $next, bool $wasOver): self
    {
        if ($wasOver) {
            return $next;
        }
        $nowOver = $next->board->exploded || $next->board->isWon();
        if (!$nowOver) {
            return $next;
        }
        // Freeze the elapsed time at the transition frame — once the board is
        // over, elapsed() reads elapsedSeconds, which recordResult() stamps.
        $elapsed = $next->startedAt !== null ? microtime(true) - $next->startedAt : null;
        $recorded = $next->recordResult($elapsed);
        $recorded->persistStats();

        return $recorded;
    }

    /**
     * Best-effort write of the accumulated stats to the opt-in path. A stats
     * write failure must never crash a game in progress, so everything is
     * swallowed; a no-op when no path is configured.
     */
    private function persistStats(): void
    {
        if ($this->statsPath === null) {
            return;
        }
        try {
            DifficultyStats::fromStats($this->stats())->save($this->statsPath);
        } catch (\Throwable) {
            // Non-fatal: stats persistence is a convenience, not a game invariant.
        }
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
            statsPath: $this->statsPath,
        );
    }

    private function reveal(): self
    {
        $now = $this->startedAt ?? microtime(true);
        return new self(
            board: $this->board->reveal($this->cursorX, $this->cursorY, $this->rand),
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $now,
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
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
            statsPath: $this->statsPath,
        );
    }

    private function chord(): self
    {
        $now = $this->startedAt ?? microtime(true);
        return new self(
            board: $this->board->chord($this->cursorX, $this->cursorY),
            cursorX: $this->cursorX,
            cursorY: $this->cursorY,
            rand: $this->rand,
            startedAt: $now,
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    /**
     * Dispatch a mouse message into a board action (reveal / flag / chord).
     *
     * MouseMsg coordinates are 1-based absolute terminal positions.
     * The interior starts after the rounded border (1 col/row) and the
     * padding(0,1) layer (1 extra col each side), so the first cell glyph
     * sits at terminal (x=3, y=2). Scanner zones are ALSO 1-based (the
     * first cell resolves at scanner (col=1, row=1)), hence:
     *   scanner_col = msg.x - 2   (x=3 → col 1)
     *   scanner_row = msg.y - 1   (y=2 → row 1)
     *
     * Ignores clicks outside the board and does nothing after game ends.
     */
    private function onMouse(MouseMsg $msg): self
    {
        // Only handle press events to avoid double-firing on release.
        if (!$msg instanceof MouseClickMsg) {
            return $this;
        }
        if ($this->board->exploded || $this->board->isWon()) {
            return $this;
        }
        // Convert absolute terminal coords → 1-based scanner coords.
        $col = $msg->x - 2;
        $row = $msg->y - 1;
        $cell = Renderer::resolveClick($this, $col, $row);
        if ($cell === null) {
            return $this;
        }
        [$cx, $cy] = $cell;
        return match ($msg->button) {
            MouseButton::Left   => $this->revealAt($cx, $cy),
            MouseButton::Right  => $this->flagAt($cx, $cy),
            MouseButton::Middle => $this->chordAt($cx, $cy),
            default             => $this,
        };
    }

    private function revealAt(int $x, int $y): self
    {
        $now = $this->startedAt ?? microtime(true);
        return new self(
            board: $this->board->reveal($x, $y, $this->rand),
            cursorX: $x,
            cursorY: $y,
            rand: $this->rand,
            startedAt: $now,
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function flagAt(int $x, int $y): self
    {
        return new self(
            board: $this->board->toggleFlag($x, $y),
            cursorX: $x,
            cursorY: $y,
            rand: $this->rand,
            startedAt: $this->startedAt,
            elapsedSeconds: $this->elapsedSeconds,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    private function chordAt(int $x, int $y): self
    {
        $now = $this->startedAt ?? microtime(true);
        return new self(
            board: $this->board->chord($x, $y),
            cursorX: $x,
            cursorY: $y,
            rand: $this->rand,
            startedAt: $now,
            elapsedSeconds: null,
            stats: $this->stats,
            statsPath: $this->statsPath,
        );
    }

    /**
     * Returns the elapsed time in seconds (sub-second precision) if the game is in progress.
     */
    public function elapsed(): ?float
    {
        if ($this->startedAt === null) {
            return null;
        }
        if ($this->board->exploded || $this->board->isWon()) {
            return $this->elapsedSeconds !== null ? (float) $this->elapsedSeconds : null;
        }
        return microtime(true) - $this->startedAt;
    }

    /**
     * Record the result of this game and return a new Game with updated stats.
     * The returned Game has the same board state but updated stats.
     */
    public function recordResult(?float $elapsed): self
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
            elapsedSeconds: $elapsed !== null ? (int) $elapsed : null,
            stats: $this->stats()->withGame($difficulty, $won, $elapsed !== null ? (int) $elapsed : null),
            statsPath: $this->statsPath,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
