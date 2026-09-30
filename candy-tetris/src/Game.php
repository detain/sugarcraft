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
 *     either `Cmd::quit()` (on `q`), a gravity `tick()`, or a
 *     lock-delay `tick()`.
 *   - Gravity is a single chain: exactly one gravity tick is ever
 *     in flight. `init()` arms it; each gravity tick consumes the
 *     pending slot and re-arms it 1:1. Key paths (hard drop, hold)
 *     never append a second tick — {@see self::armGravity()} skips
 *     re-arming while one is pending, so N actions cannot buy N
 *     extra timers.
 *   - The lock-delay window is real-time (milliseconds), independent
 *     of gravity speed, and generation-tagged via
 *     {@see LockDelayMsg}: superseded windows go stale by tag
 *     mismatch rather than by cancellation (candy-core ticks have
 *     no cancel API).
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
 *   - Lock delay (real-time grounded window with a capped move-reset count)
 *   - T-Spin detection (last-action-was-rotation + corner rule)
 *   - Back-to-Back (B2B) bonus for consecutive Tetris / T-Spin clears
 *   - Combo counter for consecutive line clears
 *   - Perfect clear detection (+5000 bonus)
 */
final class Game implements Model
{
    public const PERFECT_CLEAR_BONUS = 5_000;
    public const B2B_MULTIPLIER      = 1.5;

    /** Grounded lock-delay window length used by `startWithLockDelay()`. */
    public const DEFAULT_LOCK_DELAY_MS = 500;

    /**
     * SRS-spirit cap: a grounded piece may reset its lock window with
     * successful inputs this many times before it is forced to lock.
     * Prevents infinite spin/stall.
     */
    public const LOCK_DELAY_RESET_CAP = 15;

    public function __construct(
        public readonly Board        $board,
        public readonly Piece        $piece,
        public readonly Bag          $bag,
        public readonly Score        $score,
        public readonly bool         $over = false,
        public readonly bool         $paused = false,
        public readonly ?Tetromino   $hold = null,
        public readonly bool         $canHold = true,
        /** Lock-delay window length in milliseconds; 0 disables the window. */
        public readonly int          $lockDelayMs = 0,
        /** True while exactly one gravity tick is in flight. */
        public readonly bool         $gravityPending = false,
        /** T-Spin rule: was the last successful player INPUT a rotation? */
        public readonly bool         $lastActionWasRotation = false,
        /** Monotonic counter feeding lock-window generation tags. */
        public readonly int          $lockGeneration = 0,
        /** Generation of the currently armed lock window (null = closed). */
        public readonly ?int         $lockWindowGeneration = null,
        /** Move-resets spent on the current grounding (capped). */
        public readonly int          $lockResets = 0,
        public readonly int          $combo = 0,
        public readonly bool         $backToBack = false,
    ) {}

    /**
     * Construct a new Game from the current state with optional field overrides.
     * Mirrors the canonical SugarCraft immutable/fluent pattern (see
     * candy-sprinkles/src/Style.php, candy-core/src/Concerns/Mutable.php).
     *
     * `lockWindowGeneration` is nullable and closes via explicit null, so
     * it uses array_key_exists rather than the ?? carry — otherwise
     * `mutate(['lockWindowGeneration' => null])` could never clear it.
     *
     * @param array<string,mixed> $changes Field overrides
     */
    public function mutate(array $changes): self
    {
        return new self(
            $changes['board']         ?? $this->board,
            $changes['piece']         ?? $this->piece,
            $changes['bag']           ?? $this->bag,
            $changes['score']         ?? $this->score,
            $changes['over']          ?? $this->over,
            $changes['paused']        ?? $this->paused,
            $changes['hold']          ?? $this->hold,
            $changes['canHold']       ?? $this->canHold,
            $changes['lockDelayMs']   ?? $this->lockDelayMs,
            $changes['gravityPending'] ?? $this->gravityPending,
            $changes['lastActionWasRotation'] ?? $this->lastActionWasRotation,
            $changes['lockGeneration'] ?? $this->lockGeneration,
            array_key_exists('lockWindowGeneration', $changes)
                ? $changes['lockWindowGeneration']
                : $this->lockWindowGeneration,
            $changes['lockResets']    ?? $this->lockResets,
            $changes['combo']         ?? $this->combo,
            $changes['backToBack']    ?? $this->backToBack,
        );
    }

    public static function start(?Bag $bag = null): self
    {
        $bag ??= new Bag();
        $first = $bag->next();
        $piece = self::spawn($first);
        // init() will arm the gravity chain; the pending slot starts taken
        // so no key path can double-book it before then.
        return new self(new Board(), $piece, $bag, new Score(), gravityPending: true);
    }

    /**
     * Start a new game with a real-time lock-delay window enabled.
     *
     * @param int $lockDelayMs Grounded window length in milliseconds
     *                         (SRS-style: ~0.5 s, independent of gravity).
     */
    public static function startWithLockDelay(?Bag $bag = null, int $lockDelayMs = self::DEFAULT_LOCK_DELAY_MS): self
    {
        $bag ??= new Bag();
        $first = $bag->next();
        $piece = self::spawn($first);
        return new self(
            new Board(),
            $piece,
            $bag,
            new Score(),
            lockDelayMs: $lockDelayMs,
            gravityPending: true,
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

    private function scheduleLockDelay(int $generation): \Closure
    {
        return Cmd::tick(
            $this->lockDelayMs / 1_000,
            static fn(): Msg => new LockDelayMsg($generation),
        );
    }

    /**
     * Idempotent gravity arming: while a gravity tick is already pending,
     * re-arming is skipped (the single-chain invariant). Returns the game
     * plus the arm Cmd only when the chain was actually stopped.
     *
     * @return array{0:self,1:?\Closure}
     */
    private static function armGravity(self $game): array
    {
        if ($game->gravityPending === true) {
            return [$game, null];
        }
        return [$game->mutate(['gravityPending' => true]), self::scheduleGravity($game->score)];
    }

    public function update(Msg $msg): array
    {
        if ($this->over === true) {
            if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
                return [$this, Cmd::quit()];
            }
            return [$this, null];
        }

        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        if ($msg instanceof GravityMsg) {
            // Consume the pending slot first: every exit path below either
            // re-arms through armGravity() or deliberately ends the chain
            // (over, or a lock window taking over the timing).
            $consumed = $this->mutate(['gravityPending' => false]);
            if ($this->paused) {
                return self::armGravity($consumed);
            }
            return $consumed->gravityStep();
        }

        if ($msg instanceof LockDelayMsg) {
            return $this->handleLockDelay($msg);
        }

        return [$this, null];
    }

    /**
     * @return array{0:self,1:?\Closure}
     */
    private function handleLockDelay(LockDelayMsg $msg): array
    {
        if ($msg->generation !== $this->lockWindowGeneration) {
            // Superseded or closed window — inert.
            return [$this, null];
        }
        if ($this->paused === true) {
            // Pause extends the window: re-arm the same generation for a
            // full delay instead of locking.
            return [$this, $this->scheduleLockDelay($msg->generation)];
        }
        $game = $this->lockAndSpawn();
        if ($game->over === true) {
            return [$game, null];
        }
        return self::armGravity($game);
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
        if ($this->paused) {
            // Nothing mutates game state while paused — hold included.
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'c') {
            return $this->tryHold();
        }

        return match (true) {
            $msg->type === KeyType::Left
                => $this->tryMove(-1, 0),
            $msg->type === KeyType::Right
                => $this->tryMove(1, 0),
            $msg->type === KeyType::Down
                => $this->softDrop(),
            $msg->type === KeyType::Up,
            $msg->type === KeyType::Char && $msg->rune === 'x'
                => $this->tryRotate(1),
            $msg->type === KeyType::Char && $msg->rune === 'z'
                => $this->tryRotate(-1),
            $msg->type === KeyType::Char && $msg->rune === ' '
                => $this->hardDrop(),
            default => [$this, null],
        };
    }

    /**
     * One gravity tick on an already-consumed state. The gravity chain
     * keeps running while the piece can fall; when the piece grounds with
     * a lock-delay window enabled, the window owns the timing and the
     * chain parks until the next lock/spawn.
     *
     * @return array{0:Game,1:?\Closure}
     */
    private function gravityStep(): array
    {
        $next = $this->piece->moved(0, 1);
        if ($this->board->fits($next)) {
            // Piece can move down. Gravity is not a player input, so it
            // never clears lastActionWasRotation (rotate-then-settle is a
            // valid T-Spin in the SRS guidelines).
            $game = $this->mutate(['piece' => $next, 'lockWindowGeneration' => null]);
            return self::armGravity($game);
        }

        // Piece can't move down - handle the lock-delay window.
        if ($this->lockDelayMs > 0) {
            if ($this->lockWindowGeneration !== null) {
                // Window armed and a gravity tick slipped in (armed while
                // airborne by a key path): the window owns the lock now.
                return [$this, null];
            }
            return $this->openLockWindow();
        }

        $game = $this->lockAndSpawn();
        if ($game->over === true) {
            return [$game, null];
        }
        return self::armGravity($game);
    }

    /**
     * Open (or re-open) the grounded lock window and arm its tick.
     *
     * @return array{0:self,1:\Closure}
     */
    private function openLockWindow(): array
    {
        $generation = $this->lockGeneration + 1;
        $game = $this->mutate([
            'lockGeneration' => $generation,
            'lockWindowGeneration' => $generation,
        ]);
        return [$game, $game->scheduleLockDelay($generation)];
    }

    /**
     * Post-success bookkeeping for a player input (move/rotate/soft drop):
     * maintain the lock window against the CANDIDATE resting state and
     * return the Cmd (if any) the key handler must dispatch.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function afterSuccessfulInput(self $moved): array
    {
        if ($moved->lockDelayMs === 0) {
            return [$moved, null];
        }

        $grounded = $moved->board->fits($moved->piece->moved(0, 1)) === false;

        if ($grounded) {
            if ($moved->lockWindowGeneration === null) {
                // Landed on this input — open the window. The gravity
                // chain (if still pending) self-parks on its next tick.
                return $moved->openLockWindow();
            }
            if ($moved->lockResets < self::LOCK_DELAY_RESET_CAP) {
                // Move-reset: bump the generation, arming a fresh full
                // window; the previously armed message goes stale by tag.
                $generation = $moved->lockGeneration + 1;
                $game = $moved->mutate([
                    'lockGeneration' => $generation,
                    'lockWindowGeneration' => $generation,
                    'lockResets' => $moved->lockResets + 1,
                ]);
                return [$game, $game->scheduleLockDelay($generation)];
            }
            // Reset cap reached — the existing window expires into a lock.
            return [$moved, null];
        }

        if ($moved->lockWindowGeneration !== null) {
            // Piece floated off the surface: close the window (the armed
            // message goes stale) and make sure gravity is running again.
            return self::armGravity($moved->mutate(['lockWindowGeneration' => null]));
        }

        return [$moved, null];
    }

    /**
     * @return array{0:self,1:?\Closure}
     */
    private function tryMove(int $dx, int $dy): array
    {
        $next = $this->piece->moved($dx, $dy);
        if ($this->board->fits($next) === false) {
            return [$this, null];
        }
        // A successful horizontal input clears the rotation flag: locking
        // after a slide is not a T-Spin.
        $moved = $this->mutate(['piece' => $next, 'lastActionWasRotation' => false]);
        return $this->afterSuccessfulInput($moved);
    }

    /**
     * @return array{0:self,1:?\Closure}
     */
    private function tryRotate(int $delta): array
    {
        // Full SRS: try all rotation candidates (naive + wall kicks).
        // The first candidate is always the naive rotation, so the
        // "no kick needed" case is preserved automatically.
        foreach ($this->piece->rotationsWithKicks($delta) as $candidate) {
            if ($this->board->fits($candidate) === true) {
                // A successful rotation IS the T-Spin trigger: the flag
                // records "last input was a rotation" for lockAndSpawn().
                $moved = $this->mutate(['piece' => $candidate, 'lastActionWasRotation' => true]);
                return $this->afterSuccessfulInput($moved);
            }
        }
        return [$this, null];
    }

    /**
     * @return array{0:self,1:?\Closure}
     */
    private function softDrop(): array
    {
        $next = $this->piece->moved(0, 1);
        if ($this->board->fits($next) === false) {
            return [$this, null];
        }
        // Soft drop awards 1 point per cell and counts as a movement
        // input: it clears the rotation flag and re-arms the grounded
        // window like any other successful move (SRS behaviour).
        $moved = $this->mutate([
            'piece' => $next,
            'score' => $this->score->withDropPoints(1),
            'lastActionWasRotation' => false,
        ]);
        return $this->afterSuccessfulInput($moved);
    }

    /**
     * @return array{0:Game,1:?\Closure}
     */
    private function hardDrop(): array
    {
        $resting = $this->board->dropPiece($this->piece);
        // Hard drop awards 2 points per cell fallen and locks through any
        // open window (a hard drop is the last action, never a rotation).
        $dropDistance = $resting->y - $this->piece->y;
        $game = $this->mutate([
            'piece' => $resting,
            'score' => $this->score->withDropPoints(2 * $dropDistance),
            'lastActionWasRotation' => false,
        ]);
        $game = $game->lockAndSpawn();
        if ($game->over === true) {
            return [$game, null];
        }
        return self::armGravity($game);
    }

    /**
     * Apply an AI move in one call: rotate the current piece by
     * $rotDelta times (naive, no kicks), shift it by $dx if that fits,
     * hard-drop it, lock, and spawn the next piece.
     *
     * Mirrors the AI placement intent in Broderick-Westrope/tetrigo:
     * bestMove() computes a target once and the piece is delivered there.
     *
     * This is the single-placement convenience API. The shipped VS loop
     * ({@see VsGame}) no longer calls it per tick — it steps one input
     * per tick through the committed plan — but the naive one-shot form
     * stays public for tests and programmatic placement. Because it ends
     * in a hard drop, the lock is never a T-Spin: the rotation flag is
     * cleared before locking.
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
        if ($this->board->fits($shifted) === true) {
            $piece = $shifted;
        }
        // Hard-drop: find resting position and lock+spawn
        $resting = $this->board->dropPiece($piece);
        $afterDrop = $this->mutate(['piece' => $resting, 'lastActionWasRotation' => false]);
        return $afterDrop->lockAndSpawn();
    }

    private function lockAndSpawn(): self
    {
        $boardWithPiece = $this->board->place($this->piece);
        [$cleared, $count] = $boardWithPiece->clearLines();

        // T-Spin detection: check corners on the board before the piece
        // was placed, gated on the LAST SUCCESSFUL INPUT being a rotation
        // (the SRS guideline rule — a slide or drop into the slot is not
        // a spin, and gravity steps never clear the flag).
        $tspin = TSpin::detect($this->board, $this->piece, $this->lastActionWasRotation);

        // B2B-eligible clear: Tetris or full T-Spin (not mini)
        $b2bEligible = $count >= 4 || ($tspin->active && !$tspin->mini);
        $b2bActive = $this->backToBack && $b2bEligible;

        // B2B bonus: 1.5× multiplier when B2B is active
        $b2bMultiplier = $b2bActive === true ? self::B2B_MULTIPLIER : 1.0;

        // T-Spin scoring: mini gets 100, full T-Spin gets 400 (pre-multiplier)
        $tspinPoints = 0;
        if ($tspin->active === true) {
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

        // README scoring: line-clear base × (level+1) always lands; B2B rides
        // the line-clear base as an extra half (Tetris → 1200 × 1.5 × (lvl+1))
        // AND the T-Spin base (full T-Spin after a full T-Spin → 400 × 1.5 ×
        // (lvl+1)); combo adds combo × 10 × (level+1).
        $baseDelta = $score->points - $this->score->points;
        $b2bExtra  = (int) ($baseDelta * ($b2bMultiplier - 1.0));
        $tspinAward = (int) ($tspinPoints * $b2bMultiplier) * $levelForBonus;
        $bonus = $baseDelta + $b2bExtra + $tspinAward + $comboBonus * $levelForBonus;

        // Perfect clear bonus
        if ($cleared->isPerfectClear() === true) {
            $bonus += self::PERFECT_CLEAR_BONUS * $levelForBonus;
        }

        $score = new Score(
            $this->score->points + $bonus,
            $score->lines,
            $score->level,
        );

        $newPiece = self::spawn($this->bag->next());
        if ($cleared->fits($newPiece) === false) {
            return $this->mutate([
                'board' => $cleared,
                'piece' => $newPiece,
                'score' => $score,
                'over' => true,
                'canHold' => true,
                'combo' => 0,
                'backToBack' => false,
                'lastActionWasRotation' => false,
                'lockWindowGeneration' => null,
                'lockResets' => 0,
            ]);
        }

        // After locking, canHold is re-enabled and the lock window state
        // resets for the fresh piece. B2B state persists only if the
        // current clear was B2B-eligible.
        return $this->mutate([
            'board' => $cleared,
            'piece' => $newPiece,
            'bag' => $this->bag,
            'score' => $score,
            'hold' => $this->hold,
            'canHold' => true,
            'combo' => $newCombo,
            'backToBack' => $b2bEligible,
            'lastActionWasRotation' => false,
            'lockWindowGeneration' => null,
            'lockResets' => 0,
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
        if ($this->canHold === false) {
            return [$this, null];
        }

        $currentKind = $this->piece->kind;
        if ($this->hold === null) {
            // No held piece - spawn new piece and store current. The fresh
            // piece goes through the same fits/top-out gate a lock-spawn
            // would: a blocked spawn zone tops the game out.
            $newPiece = self::spawn($this->bag->next());
            if ($this->board->fits($newPiece) === false) {
                return [$this->mutate([
                    'piece' => $newPiece,
                    'hold' => $currentKind,
                    'canHold' => false,
                    'over' => true,
                    'lastActionWasRotation' => false,
                    'lockWindowGeneration' => null,
                    'lockResets' => 0,
                ]), null];
            }
            $game = $this->mutate([
                'piece' => $newPiece,
                'hold' => $currentKind,
                'canHold' => false,
                'lastActionWasRotation' => false,
                'lockWindowGeneration' => null,
                'lockResets' => 0,
            ]);
        } else {
            // Swap current piece with held piece
            $swappedPiece = new Piece($this->hold, 0, $this->piece->x, Board::HIDDEN_ROWS - 4);
            if ($this->board->fits($swappedPiece) === false) {
                // Can't place held piece - don't hold
                return [$this, null];
            }
            $game = $this->mutate([
                'piece' => $swappedPiece,
                'hold' => $currentKind,
                'canHold' => false,
                'lastActionWasRotation' => false,
                'lockWindowGeneration' => null,
                'lockResets' => 0,
            ]);
        }

        if ($game->over === true) {
            return [$game, null];
        }
        return self::armGravity($game);
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
     * Receive garbage rows from the opponent: the new rows enter at the
     * BOTTOM and push existing content up (classic Tetris garbage). The
     * active piece rides the lift when there is room; a piece pinned
     * against the ceiling keeps its row, and a piece with neither option
     * (its raised or original cell set collides with locked content)
     * tops the game out. Garbage never destroys locked content silently:
     * if the stack already occupies the ceiling rows the lift would need,
     * the receive is a top-out instead.
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
        if ($count >= Board::ROWS) {
            throw new \InvalidArgumentException(
                "garbage row count {$count} must stay below the board height (" . Board::ROWS . ")",
            );
        }

        $rand ??= static fn(int $max): int => random_int(0, $max);
        $rows = $this->board->rows();

        // Overflow check: the lift pushes rows 0..count-1 off the ceiling.
        // If they carry locked cells the stack has nowhere to go → top-out.
        for ($r = 0; $r < $count; $r++) {
            foreach ($rows[$r] as $cell) {
                if ($cell !== null) {
                    return $this->mutate([
                        'over' => true,
                        'canHold' => true,
                        'lockWindowGeneration' => null,
                        'lockResets' => 0,
                    ]);
                }
            }
        }

        // Push existing content UP: row r takes the content of row r+count.
        for ($r = 0; $r < Board::ROWS - $count; $r++) {
            $rows[$r] = $rows[$r + $count];
        }

        // The bottom $count rows become garbage (full rows with one hole).
        for ($b = Board::ROWS - $count; $b < Board::ROWS; $b++) {
            $hole = $rand(Board::COLS - 1);
            $garbageRow = [];
            for ($col = 0; $col < Board::COLS; $col++) {
                $garbageRow[] = $col === $hole ? null : Tetromino::I; // Garbage uses I color
            }
            $rows[$b] = $garbageRow;
        }

        $newBoard = new Board($rows);

        // The active piece rides the lift when the raised position fits;
        // near the ceiling it stays put if its own rows are still clear;
        // neither → crushed → top-out.
        $raised = $this->piece->moved(0, -$count);
        $piece = $raised;
        if ($newBoard->fits($raised) === false) {
            $piece = $this->piece;
            if ($newBoard->fits($this->piece) === false) {
                return $this->mutate([
                    'board' => $newBoard,
                    'piece' => $raised,
                    'over' => true,
                    'canHold' => true,
                    'lockWindowGeneration' => null,
                    'lockResets' => 0,
                ]);
            }
        }

        return $this->mutate([
            'board' => $newBoard,
            'piece' => $piece,
            'lockWindowGeneration' => null,
            'lockResets' => 0,
        ]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
