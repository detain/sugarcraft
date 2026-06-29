<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Scoring\TSpin;

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
 *   - T-Spin detection (3-corner rule) + T-Spin / T-Spin Mini scoring
 *   - Back-to-Back (B2B) bonus for consecutive Tetris / T-Spin clears
 *   - Combo counter for consecutive line clears
 *   - Perfect clear detection (+5000 bonus)
 */
final class Game implements Model
{
    public const PERFECT_CLEAR_BONUS = 5_000;
    public const B2B_MULTIPLIER      = 1.5;

    public function __construct(
        public readonly Board        $board,
        public readonly Piece        $piece,
        public readonly Bag           $bag,
        public readonly Score         $score,
        public readonly bool          $over = false,
        public readonly bool          $paused = false,
        public readonly ?Tetromino    $hold = null,
        public readonly bool          $canHold = true,
        public readonly int            $lockDelayTicks = 0,
        public readonly int           $lockDelayMax = 0,
        public readonly int           $combo = 0,
        public readonly bool          $backToBack = false,
        public readonly int           $preLockRotation = 0,
    ) {}

    /**
     * Construct a new Game from the current state with optional field overrides.
     * Mirrors the canonical SugarCraft immutable/fluent pattern (see
     * candy-sprinkles/src/Style.php, candy-core/src/Concerns/Mutable.php).
     *
     * @param array<string,mixed> $changes Field overrides
     */
    public function mutate(array $changes): self
    {
        return new self(
            $changes['board']           ?? $this->board,
            $changes['piece']           ?? $this->piece,
            $changes['bag']             ?? $this->bag,
            $changes['score']           ?? $this->score,
            $changes['over']            ?? $this->over,
            $changes['paused']          ?? $this->paused,
            $changes['hold']            ?? $this->hold,
            $changes['canHold']         ?? $this->canHold,
            $changes['lockDelayTicks']  ?? $this->lockDelayTicks,
            $changes['lockDelayMax']    ?? $this->lockDelayMax,
            $changes['combo']           ?? $this->combo,
            $changes['backToBack']      ?? $this->backToBack,
            $changes['preLockRotation'] ?? $this->preLockRotation,
        );
    }

    public static function start(?Bag $bag = null): self
    {
        $bag ??= new Bag();
        $first = $bag->next();
        $piece = self::spawn($first);
        return new self(new Board(), $piece, $bag, new Score(), preLockRotation: $piece->rotation);
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
        $piece = self::spawn($first);
        return new self(
            new Board(),
            $piece,
            $bag,
            new Score(),
            lockDelayTicks: $lockDelayTicks,
            lockDelayMax: $lockDelayTicks,
            preLockRotation: $piece->rotation,
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
            // Piece can move down
            $game = $this->mutate(['piece' => $next]);
        } else {
            // Piece can't move down - handle lock delay
            if ($this->lockDelayTicks > 0) {
                $game = $this->mutate(['lockDelayTicks' => $this->lockDelayTicks - 1]);
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
            // SRS-style: successful move re-arms lock delay when grounded.
            // Only re-arm when lock delay is active (max > 0) and the
            // piece is already touching the ground (can't move further down).
            $changes = ['piece' => $next];
            if ($this->lockDelayMax > 0 && !$this->board->fits($this->piece->moved(0, 1))) {
                $changes['lockDelayTicks'] = $this->lockDelayMax;
            }
            return $this->mutate($changes);
        }
        return $this;
    }

    private function tryRotate(int $delta): self
    {
        // Full SRS: try all rotation candidates (naive + wall kicks).
        // The first candidate is always the naive rotation, so the
        // "no kick needed" case is preserved automatically.
        foreach ($this->piece->rotationsWithKicks($delta) as $candidate) {
            if ($this->board->fits($candidate)) {
                // Successful rotation re-arms lock delay when grounded.
                $changes = [
                    'piece' => $candidate,
                    'preLockRotation' => $candidate->rotation,
                ];
                if ($this->lockDelayMax > 0 && !$this->board->fits($this->piece->moved(0, 1))) {
                    $changes['lockDelayTicks'] = $this->lockDelayMax;
                }
                return $this->mutate($changes);
            }
        }
        return $this;
    }

    private function softDrop(): self
    {
        $next = $this->piece->moved(0, 1);
        if ($this->board->fits($next)) {
            // Soft drop awards 1 point per cell. Re-arm lock delay when
            // grounded (same as horizontal movement per SRS).
            $changes = [
                'piece' => $next,
                'score' => $this->score->withDropPoints(1),
            ];
            if ($this->lockDelayMax > 0 && !$this->board->fits($this->piece->moved(0, 1))) {
                $changes['lockDelayTicks'] = $this->lockDelayMax;
            }
            return $this->mutate($changes);
        }
        return $this;
    }

    /**
     * @return array{0:Game,1:?\Closure}
     */
    private function hardDrop(): array
    {
        $resting = $this->board->dropPiece($this->piece);
        // Hard drop awards 2 points per cell fallen
        $dropDistance = $resting->y - $this->piece->y;
        $game = $this->mutate([
            'piece' => $resting,
            'score' => $this->score->withDropPoints(2 * $dropDistance),
        ]);
        $game = $game->lockAndSpawn();
        if ($game->over) {
            return [$game, null];
        }
        return [$game, self::scheduleGravity($game->score)];
    }

    /**
     * Apply an AI move: rotate the current piece by $rotDelta times,
     * shift it by $dx horizontally, lock it, and spawn the next piece.
     *
     * Mirrors the AI move path in charmbracelet/bubbletea Tetris:
     * the AI computes bestMove() once per new piece, then applies
     * that rotation+shift on each gravity tick until the piece locks.
     *
     * @param int $rotDelta Number of clockwise rotations (0-3)
     * @param int $dx       Horizontal shift in cells
     */
    public function applyAiMove(int $rotDelta, int $dx): self
    {
        // Rotate the current piece
        $piece = $this->piece;
        for ($i = 0; $i < $rotDelta; $i++) {
            $piece = $piece->rotated(1);
        }
        // Apply horizontal shift if it fits
        $shifted = $piece->moved($dx, 0);
        if ($this->board->fits($shifted)) {
            $piece = $shifted;
        }
        // Hard-drop: find resting position and lock+spawn
        $resting = $this->board->dropPiece($piece);
        $afterDrop = $this->mutate(['piece' => $resting]);
        return $afterDrop->lockAndSpawn();
    }

    private function lockAndSpawn(): self
    {
        $boardWithPiece = $this->board->place($this->piece);
        [$cleared, $count] = $boardWithPiece->clearLines();

        // T-Spin detection: check corners on the board before the piece
        // was placed. Pass the original rotation state so TSpin knows
        // whether the piece actually spun.
        $tspin = TSpin::detect($this->board, $this->piece, $this->preLockRotation);

        // B2B-eligible clear: Tetris or full T-Spin (not mini)
        $b2bEligible = $count >= 4 || ($tspin->active && !$tspin->mini);
        $b2bActive = $this->backToBack && $b2bEligible;

        // B2B bonus: 1.5× multiplier when B2B is active
        $b2bMultiplier = $b2bActive ? self::B2B_MULTIPLIER : 1.0;

        // T-Spin scoring: mini gets 100, full T-Spin gets 400 (pre-multiplier)
        $tspinPoints = 0;
        if ($tspin->active) {
            $tspinPoints = $tspin->mini
                ? TSpin::T_SPIN_MINI_POINTS
                : TSpin::T_SPIN_POINTS;
        }

        // Combo bonus: consecutive line clears multiply; resets on a miss
        $newCombo = $count > 0 ? $this->combo + 1 : 0;
        $comboBonus = $newCombo > 0 ? $newCombo * 10 : 0;

        // Base score from lines cleared (also updates level in $score object)
        $score = $this->score->withLines($count);

        // Use the level AT WHICH THE CLEAR WAS PERFORMED for all bonus calculations.
        // Per CALIBER learning b2b-combo-multiplier-stacking, the multiplier is
        // the level when the lines were cleared (the old level), not the
        // post-clear level that $score->level would return.
        $levelForBonus = $this->score->level + 1;

        // Apply B2B multiplier and add T-Spin + combo points
        $b2bBonus = (int) (($score->points - $this->score->points) * ($b2bMultiplier - 1.0));
        $bonus = $b2bBonus + (int) (($tspinPoints + $comboBonus) * $levelForBonus);

        // Perfect clear bonus
        if ($cleared->isPerfectClear()) {
            $bonus += self::PERFECT_CLEAR_BONUS * $levelForBonus;
        }

        $score = new Score(
            $this->score->points + $bonus,
            $score->lines,
            $score->level,
        );

        $newPiece = self::spawn($this->bag->next());
        if (!$cleared->fits($newPiece)) {
            return $this->mutate([
                'board' => $cleared,
                'piece' => $newPiece,
                'score' => $score,
                'over' => true,
                'canHold' => true,
                'combo' => 0,
                'backToBack' => false,
                'preLockRotation' => $newPiece->rotation,
            ]);
        }

        // After locking, canHold is re-enabled and lock delay resets to max.
        // B2B state persists only if the current clear was B2B-eligible.
        return $this->mutate([
            'board' => $cleared,
            'piece' => $newPiece,
            'bag' => $this->bag,
            'score' => $score,
            'hold' => $this->hold,
            'canHold' => true,
            'lockDelayTicks' => $this->lockDelayMax,
            'combo' => $newCombo,
            'backToBack' => $b2bEligible,
            'preLockRotation' => $newPiece->rotation,
        ]);
    }

    private function withPaused(bool $paused): self
    {
        return $this->mutate(['paused' => $paused]);
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
            $game = $this->mutate([
                'piece' => $newPiece,
                'hold' => $currentKind,
                'canHold' => false,
                'preLockRotation' => $newPiece->rotation,
            ]);
        } else {
            // Swap current piece with held piece
            $swappedPiece = new Piece($this->hold, 0, $this->piece->x, Board::HIDDEN_ROWS - 4);
            if (!$this->board->fits($swappedPiece)) {
                // Can't place held piece - don't hold
                return [$this, null];
            }
            $game = $this->mutate([
                'piece' => $swappedPiece,
                'hold' => $currentKind,
                'canHold' => false,
                'preLockRotation' => $swappedPiece->rotation,
            ]);
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

        // Overflow check: before shifting, verify that the top $count rows
        // (which will be displaced upward) contain no locked cells.
        // If they do, the garbage would erase placed content → top-out.
        for ($r = 0; $r < $count; $r++) {
            foreach ($rows[$r] as $cell) {
                if ($cell !== null) {
                    // Locked content would be displaced — game over
                    $newBoard = new Board($rows);
                    return $this->mutate([
                        'board' => $newBoard,
                        'over' => true,
                        'canHold' => true,
                        'lockDelayTicks' => 0,
                    ]);
                }
            }
        }

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
            return $this->mutate([
                'board' => $newBoard,
                'over' => true,
                'canHold' => true,
                'lockDelayTicks' => 0,
            ]);
        }

        return $this->mutate(['board' => $newBoard]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
