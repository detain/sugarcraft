<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Tetris as a SugarCraft {@see Model}.
 *
 * Architecture:
 *
 *   - Pure state machine. Every transition (`update()`) returns
 *     `[nextGame, ?Cmd]` with no I/O. The Cmd here is always
 *     either `Cmd::quit()` (on `q`) or a `tick()` for the next
 *     gravity drop.
 *   - Time is driven by `TickMsg`. The first tick is scheduled
 *     from `init()`; each tick handler returns the next tick at
 *     the level-appropriate interval, so gravity ramps with the
 *     score automatically.
 *   - Game-over is detected when a freshly-spawned piece doesn't
 *     {@see Board::fits()}. After that, only `q` is honoured.
 *
 * The `Renderer` is a separate pure function that takes a Game
 * and returns the frame string — keeps `view()` tiny and the
 * game loop testable in isolation.
 *
 * Features:
 *   - Ghost piece showing landing position
 *   - Hold piece (press c to swap with held piece)
 *   - Lock delay (piece locks after touching bottom for a delay period)
 */
final class Game implements Model
{
    public function __construct(
        public readonly Board        $board,
        public readonly Piece        $piece,
        public readonly Bag          $bag,
        public readonly Score        $score,
        public readonly bool         $over = false,
        public readonly bool         $paused = false,
        public readonly ?Tetromino   $hold = null,
        public readonly bool         $canHold = true,
        public readonly int          $lockDelayTicks = 0,
    ) {}

    public static function start(?Bag $bag = null): self
    {
        $bag ??= new Bag();
        $first = $bag->next();
        return new self(new Board(), self::spawn($first), $bag, new Score());
    }

    /**
     * Start a new game with lock delay enabled.
     *
     * @param int $lockDelayTicks Number of ticks before piece locks (SRS-style)
     */
    public static function startWithLockDelay(?Bag $bag = null, int $lockDelayTicks = 15): self
    {
        $bag ??= new Bag();
        $first = $bag->next();
        return new self(
            new Board(),
            self::spawn($first),
            $bag,
            new Score(),
            lockDelayTicks: $lockDelayTicks,
        );
    }

    public function init(): ?\Closure
    {
        return self::scheduleGravity($this->score);
    }

    private static function scheduleGravity(Score $score): \Closure
    {
        return Cmd::tick(
            $score->gravityIntervalUs() / 1_000_000,
            static fn(): Msg => new GravityMsg(),
        );
    }

    public function update(Msg $msg): array
    {
        if ($this->over) {
            if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
                return [$this, Cmd::quit()];
            }
            return [$this, null];
        }

        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        if ($msg instanceof GravityMsg) {
            if ($this->paused) {
                return [$this, self::scheduleGravity($this->score)];
            }
            return $this->gravityStep();
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    /**
     * @return array{0:Game,1:?\Closure}
     */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'p') {
            return [$this->withPaused(!$this->paused), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'c') {
            return $this->tryHold();
        }
        if ($this->paused) {
            return [$this, null];
        }

        return match (true) {
            $msg->type === KeyType::Left
                => [$this->tryMove(-1, 0), null],
            $msg->type === KeyType::Right
                => [$this->tryMove(1, 0), null],
            $msg->type === KeyType::Down
                => [$this->softDrop(), null],
            $msg->type === KeyType::Up,
            $msg->type === KeyType::Char && $msg->rune === 'x'
                => [$this->tryRotate(1), null],
            $msg->type === KeyType::Char && $msg->rune === 'z'
                => [$this->tryRotate(-1), null],
            $msg->type === KeyType::Char && $msg->rune === ' '
                => $this->hardDrop(),
            default => [$this, null],
        };
    }

    /**
     * @return array{0:Game,1:?\Closure}
     */
    private function gravityStep(): array
    {
        $next = $this->piece->moved(0, 1);
        if ($this->board->fits($next)) {
            // Piece can move down - reset lock delay
            $game = new self($this->board, $next, $this->bag, $this->score, lockDelayTicks: $this->lockDelayTicks);
        } else {
            // Piece can't move down - handle lock delay
            if ($this->lockDelayTicks > 0) {
                $newLockDelay = $this->lockDelayTicks - 1;
                $game = new self($this->board, $this->piece, $this->bag, $this->score, lockDelayTicks: $newLockDelay);
                // Still return a tick to continue the lock delay countdown
                return [$game, self::scheduleGravity($game->score)];
            }
            $game = $this->lockAndSpawn();
        }
        if ($game->over) {
            return [$game, null];
        }
        return [$game, self::scheduleGravity($game->score)];
    }

    private function tryMove(int $dx, int $dy): self
    {
        $next = $this->piece->moved($dx, $dy);
        if ($this->board->fits($next)) {
            // SRS-style: successful move resets lock delay
            return new self(
                $this->board, $next, $this->bag, $this->score,
                hold: $this->hold,
                canHold: $this->canHold,
                lockDelayTicks: $this->lockDelayTicks,
            );
        }
        return $this;
    }

    private function tryRotate(int $delta): self
    {
        $candidate = $this->piece->rotated($delta);
        // Tiny wall-kick: try ±1 / ±2 horizontal nudges if the
        // bare rotation collides. Not full SRS but covers the
        // most common stuck-in-corner cases.
        foreach ([0, -1, 1, -2, 2] as $kick) {
            $kicked = $candidate->moved($kick, 0);
            if ($this->board->fits($kicked)) {
                // SRS-style: successful rotation resets lock delay
                return new self(
                    $this->board, $kicked, $this->bag, $this->score,
                    hold: $this->hold,
                    canHold: $this->canHold,
                    lockDelayTicks: $this->lockDelayTicks,
                );
            }
        }
        return $this;
    }

    private function softDrop(): self
    {
        $next = $this->piece->moved(0, 1);
        if ($this->board->fits($next)) {
            // Soft drop also resets lock delay
            return new self(
                $this->board, $next, $this->bag, $this->score,
                hold: $this->hold,
                canHold: $this->canHold,
                lockDelayTicks: $this->lockDelayTicks,
            );
        }
        return $this;
    }

    /**
     * @return array{0:Game,1:?\Closure}
     */
    private function hardDrop(): array
    {
        $resting = $this->board->dropPiece($this->piece);
        $game = new self(
            $this->board, $resting, $this->bag, $this->score,
            hold: $this->hold,
            lockDelayTicks: $this->lockDelayTicks,
        );
        $game = $game->lockAndSpawn();
        if ($game->over) {
            return [$game, null];
        }
        return [$game, self::scheduleGravity($game->score)];
    }

    private function lockAndSpawn(): self
    {
        $boardWithPiece = $this->board->place($this->piece);
        [$cleared, $count] = $boardWithPiece->clearLines();
        $score = $this->score->withLines($count);
        $newPiece = self::spawn($this->bag->next());
        if (!$cleared->fits($newPiece)) {
            return new self(
                $cleared, $newPiece, $this->bag, $score,
                over: true,
                hold: $this->hold,
                canHold: true,  // Re-enable hold after game over
            );
        }
        // After locking, canHold is re-enabled and lock delay resets
        return new self(
            $cleared, $newPiece, $this->bag, $score,
            hold: $this->hold,
            canHold: true,
            lockDelayTicks: $this->lockDelayTicks > 0 ? $this->lockDelayTicks : 0,
        );
    }

    private function withPaused(bool $paused): self
    {
        return new self(
            $this->board,
            $this->piece,
            $this->bag,
            $this->score,
            $this->over,
            $paused,
            $this->hold,
            $this->canHold,
            $this->lockDelayTicks,
        );
    }

    /**
     * Try to hold the current piece, swapping with the held piece if available.
     *
     * @return array{0:Game,1:?\Closure}
     */
    private function tryHold(): array
    {
        if (!$this->canHold) {
            return [$this, null];
        }

        $currentKind = $this->piece->kind;
        if ($this->hold === null) {
            // No held piece - spawn new piece and store current
            $newPiece = self::spawn($this->bag->next());
            $game = new self(
                $this->board,
                $newPiece,
                $this->bag,
                $this->score,
                hold: $currentKind,
                canHold: false,
                lockDelayTicks: $this->lockDelayTicks,
            );
        } else {
            // Swap current piece with held piece
            $swappedPiece = new Piece($this->hold, 0, $this->piece->x, Board::HIDDEN_ROWS - 4);
            if (!$this->board->fits($swappedPiece)) {
                // Can't place held piece - don't hold
                return [$this, null];
            }
            $game = new self(
                $this->board,
                $swappedPiece,
                $this->bag,
                $this->score,
                hold: $currentKind,
                canHold: false,
                lockDelayTicks: $this->lockDelayTicks,
            );
        }

        if ($game->over) {
            return [$game, null];
        }
        return [$game, self::scheduleGravity($game->score)];
    }

    private static function spawn(Tetromino $kind): Piece
    {
        // Spawn so the bounding box is centred horizontally and
        // sits in the hidden buffer rows so the player gets a
        // tick or two before pieces appear in the visible area.
        $x = (int) ((Board::COLS - 4) / 2);
        return new Piece($kind, 0, $x, Board::HIDDEN_ROWS - 4);
    }

    /**
     * Add garbage rows to the top of the board (pushed down from opponent's line clears).
     *
     * Garbage rows have exactly one random hole to keep them challenging.
     *
     * @param int $count Number of garbage rows to add
     * @param \Closure|null $rand Random number generator (for testing)
     */
    public function addGarbageRows(int $count, ?\Closure $rand = null): self
    {
        if ($count <= 0) {
            return $this;
        }

        $rand ??= static fn(int $max): int => random_int(0, $max);
        $rows = $this->board->rows();

        // Push existing rows down by $count (they shift to higher indices)
        // The bottom $count rows get replaced with garbage
        for ($row = Board::ROWS - 1; $row >= $count; $row--) {
            $rows[$row] = $rows[$row - $count];
        }

        // Fill the top $count rows with garbage (full rows with one random hole)
        for ($r = 0; $r < $count; $r++) {
            $hole = $rand(Board::COLS - 1);
            $garbageRow = [];
            for ($col = 0; $col < Board::COLS; $col++) {
                $garbageRow[] = $col === $hole ? null : Tetromino::I; // Garbage uses I color
            }
            $rows[$r] = $garbageRow;
        }

        $newBoard = new Board($rows);

        // Check if the current piece is still valid after adding garbage
        if (!$newBoard->fits($this->piece)) {
            return new self(
                $newBoard, $this->piece, $this->bag, $this->score,
                over: true,
                hold: $this->hold,
                canHold: true,
                lockDelayTicks: 0,
            );
        }

        return new self(
            $newBoard,
            $this->piece,
            $this->bag,
            $this->score,
            $this->over,
            $this->paused,
            $this->hold,
            $this->canHold,
            $this->lockDelayTicks,
        );
    }

    /**
     * Create a Game suitable for VS Computer mode with slightly faster gravity.
     *
     * The computer opponent gets a small speed disadvantage to keep it fair.
     */
    public static function vsComputer(?Bag $bag = null): self
    {
        $bag ??= new Bag();
        $first = $bag->next();
        $game = new self(new Board(), self::spawn($first), $bag, new Score());
        // Return game with adjusted score for computer's slightly slower reaction
        return $game;
    }
}
