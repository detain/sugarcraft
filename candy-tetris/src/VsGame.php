<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Tetris\Support\Scores;

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
        /**
         * Live terminal size (C-2), owned by the top model — the inner
         * Games keep their seeds; the VS frame is what the arms reflow.
         */
        public readonly int       $frameCols = VsRenderer::NATURAL_COLS,
        public readonly int       $frameRows = VsRenderer::NATURAL_ROWS,
        public readonly ?ColorProfile $colorProfile = null,
        /**
         * m27 live binding registry shared by BOTH inner games and the
         * strip's derived key row (null = shipped defaults, same law as
         * {@see Game::$bindings}).
         */
        public readonly ?KeyBindings $bindings = null,
        /**
         * MA-23 versus tally (wins/losses only — no best makes sense across
         * the two-board race). Null = no record wired. The inner games are
         * deliberately UNwired: a versus death must never pollute the solo
         * best, so the match result is recorded here, at the winner
         * chokepoints, exactly once per match.
         */
        public readonly ?Scores $scores = null,
        /** Where {@see self::$scores} persists to at the settle. */
        public readonly ?string $scoresPath = null,
    ) {}

    /**
     * True when the live terminal cannot host the VS frame
     * ({@see VsRenderer::MIN_COLS} × {@see VsRenderer::MIN_ROWS}).
     */
    public function tooSmall(): bool
    {
        return $this->frameCols < VsRenderer::MIN_COLS || $this->frameRows < VsRenderer::MIN_ROWS;
    }

    /**
     * m27: the live action table for the q/r/p gates this class owns
     * (the pause toggle stays owned here, not delegated — the gate is
     * the release/repeat double-fire guard, m11, a duty now carried by
     * KeyBindings::matches itself). Null bindings resolve to the
     * shipped default table byte-for-byte.
     */
    private function keys(): KeyBindings
    {
        return $this->bindings ?? Support\Bindings::table();
    }

    /**
     * Start a new VS Computer game.
     */
    public static function start(?KeyBindings $bindings = null, ?Scores $scores = null, ?string $scoresPath = null): self
    {
        return new self(
            Game::start(bindings: $bindings),
            Game::start(bindings: $bindings),
            bindings: $bindings,
            scores: $scores,
            scoresPath: $scoresPath,
        );
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
        // Terminal posture first (C-2): a resize or capability report is
        // never gameplay — it lands even on the game-over screen, and the
        // identity short-circuit keeps state from churning per frame.
        if ($msg instanceof WindowSizeMsg) {
            if ($msg->cols === $this->frameCols && $msg->rows === $this->frameRows) {
                return [$this, null];
            }
            return [$this->mutate(['frameCols' => $msg->cols, 'frameRows' => $msg->rows]), null];
        }
        if ($msg instanceof ColorProfileMsg) {
            if ($msg->profile === $this->colorProfile) {
                return [$this, null];
            }
            return [$this->mutate(['colorProfile' => $msg->profile]), null];
        }

        // Below the frame floor the VS pair freezes the way pause freezes
        // it: q still quits, the tick chain keeps its heartbeat, and a
        // resize back into fit resumes with nothing double-armed (C-2).
        if ($this->tooSmall() === true) {
            if ($msg instanceof KeyMsg && $this->keys()->matches($msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if ($msg instanceof GravityMsg) {
                return [$this, self::scheduleTick($this->player)];
            }
            return [$this, null];
        }

        // If game is over, accept only quit or restart (the 'r' arm below
        // mirrors the solo game's post-over restart parity).
        if ($this->over === true) {
            if ($msg instanceof KeyMsg && $this->keys()->matches($msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if ($msg instanceof KeyMsg && $this->keys()->matches($msg, 'restart')) {
                // Restart the pair and arm one fresh tick chain — parity
                // with the solo game's 'r'.
                $fresh = self::start($this->bindings, $this->scores, $this->scoresPath)->mutate([
                    // The live terminal size survives the restart: the
                    // Program only re-reports on the next SIGWINCH.
                    'frameCols' => $this->frameCols,
                    'frameRows' => $this->frameRows,
                    'colorProfile' => $this->colorProfile,
                ]);
                return [$fresh, self::scheduleTick($fresh->player)];
            }
            return [$this, null];
        }

        // Handle quit key for player
        if ($msg instanceof KeyMsg && $this->keys()->matches($msg, 'quit')) {
            return [$this, Cmd::quit()];
        }

        // Handle pause — toggle pause; the in-flight tick stays in flight,
        // so no new tick is armed here (arming on key messages was the
        // chain-duplication defect fixed alongside Game's gravity guard).
        // The toggle is owned here rather than delegated: a release or a
        // stray repeat must not flip the flag a second time on the way
        // (m11 — this gate is load-bearing, not a pass-through).
        if ($msg instanceof KeyMsg && $this->keys()->matches($msg, 'pause')) {
            return [$this->mutate(['player' => $this->player->mutate(['paused' => !$this->player->paused])]), null];
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
            $rotated = $computer->piece->rotated(1);
            if ($computer->board->fits($rotated) === false) {
                // Blocked rotation — abandon the rest of the plan's
                // rotations rather than force the piece into an illegal
                // state (mirrors the blocked-shift arm below).
                return [$computer, true, 0, $shiftLeft];
            }
            $stepped = $computer->mutate([
                'piece' => $rotated,
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
        // MA-23 settle point: this match is decided; fold it into the
        // versus record before the banner frame exists.
        $overPlayer = $player->mutate(['over' => true, 'canHold' => false]);

        return [$this->settleRecord(['player' => $overPlayer, 'over' => true, 'winner' => 'COMPUTER'], false), null];
    }

    /**
     * MA-23: fold the finished match into the versus tally and persist.
     * Same disclosure law as the solo path — a refused save is announced
     * on stderr, the on-disk record survives untouched, and the frame
     * still reports what this match actually did.
     *
     * @param array<string,mixed> $changes
     */
    private function settleRecord(array $changes, bool $playerWon): self
    {
        if ($this->scoresPath === null) {
            return $this->mutate($changes);
        }
        $next = ($this->scores ?? new Scores())->withVersusResult($playerWon);
        $changes['scores'] = $next;
        try {
            $next->save($this->scoresPath);
        } catch (\Throwable $problem) {
            fwrite(STDERR, Lang::t('score.save.failed', ['problem' => self::cliVisible($problem->getMessage())]) . "\n");
        }

        return $this->mutate($changes);
    }

    /** Trim a failure message to one visible, control-character-free line. */
    private static function cliVisible(string $raw): string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]/', '?', $raw);

        return strlen($clean) > 40 ? substr($clean, 0, 39) . '…' : $clean;
    }

    /**
     * @return array{0:VsGame,1:?\Closure}
     */
    private function withPlayerWinner(Game $player, Game $computer): array
    {
        $overComputer = $computer->mutate(['over' => true, 'canHold' => false]);

        return [$this->settleRecord(['player' => $player, 'computer' => $overComputer, 'over' => true, 'winner' => 'PLAYER'], true), null];
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
            // Nullable: presence, not truthiness, decides — a future
            // ['winner' => null] must be able to CLEAR the recorded winner;
            // ?? would silently keep the old one (same law as the
            // colorProfile leg below).
            array_key_exists('winner', $changes) ? $changes['winner'] : $this->winner,
            $this->computerAI,
            $changes['hasComputerPlan'] ?? $this->hasComputerPlan,
            $changes['computerRotationsLeft'] ?? $this->computerRotationsLeft,
            $changes['computerShiftLeft'] ?? $this->computerShiftLeft,
            $changes['frameCols'] ?? $this->frameCols,
            $changes['frameRows'] ?? $this->frameRows,
            // Nullable: presence, not truthiness, decides (same law as
            // Game::mutate's lockWindowGeneration/colorProfile legs).
            array_key_exists('colorProfile', $changes) ? $changes['colorProfile'] : $this->colorProfile,
            $changes['bindings'] ?? $this->bindings,
            $changes['scores'] ?? $this->scores,
            $changes['scoresPath'] ?? $this->scoresPath,
        );
    }
}
