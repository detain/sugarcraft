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
        /**
         * Identity tag of the live VS tick chain (candy-tetris-003: the
         * solo game's epoch defence, mirrored). Restarts bump it so a
         * tick still in flight from the dead chain lands inert on the
         * fresh pair instead of stepping it and re-arming a second
         * chain. The inner Games keep their OWN chain domain — the
         * forward at {@see self::update()} re-stamps for them.
         */
        public readonly int $gravityChain = 0,
        /**
         * Identity tag of the AI's own tick chain (candy-tetris-085): a
         * second generation space beside the human epoch, bumped together
         * with it at restarts. The computer advances one planned input
         * per ComputerTickMsg at ITS board's gravity interval — the human
         * clock no longer throttles the AI in either direction.
         */
        public readonly int $computerChain = 0,
        /**
         * The match's root seed (candy-tetris-018): the human board's
         * pieces and garbage holes derive from it directly, the computer
         * board's from it mixed with {@see self::COMPUTER_STREAM_MIX}, so
         * the two boards run independent streams that a single `--seed=N`
         * still pins end-to-end.
         */
        public readonly ?int $seed = null,
        /** True when the seed came from `--seed=N` (banner stamp law, {@see Game::$reproducible}). */
        public readonly bool $reproducible = false,
    ) {}

    /**
     * Stream-split multiplier: seed ^ MIX gives board 2 its own LCG
     * lineage from the same run seed (any odd 31-bit constant works;
     * this one is documented rather than magic-decorated).
     */
    private const COMPUTER_STREAM_MIX = 0x5BD1E995;

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
     * Bindings::actuates itself — including the ctrl-chord discipline of candy-tetris-034). Null bindings resolve to the
     * shipped default table byte-for-byte.
     */
    private function keys(): KeyBindings
    {
        return $this->bindings ?? Support\Bindings::table();
    }

    /**
     * Start a new VS Computer game.
     *
     * candy-tetris-017: the human side starts on the same 500 ms lock-delay
     * window the solo game gives (slide-on-ground, cap-bounded). The AI side
     * stays plain-started: stepComputer drives it one planned input per
     * tick through applyAiMove and never through update(), so a window on
     * that board could never be interacted with — instant lock IS the AI's
     * difficulty, and the asymmetry is a rule, not an oversight.
     */
    public static function start(?KeyBindings $bindings = null, ?Scores $scores = null, ?string $scoresPath = null, ?int $seed = null, bool $reproducible = false): self
    {
        // Seed law as in Game::start() (candy-tetris-018): production
        // always carries a seed; --seed=N pins and stamps it, a plain
        // launch captures fresh entropy once. Board 2 runs the mixed
        // stream so the two boards never punch matching holes.
        $seed ??= random_int(0, Game::SEED_MASK);

        return new self(
            Game::startWithLockDelay(bindings: $bindings, seed: $seed, reproducible: $reproducible),
            Game::start(bindings: $bindings, seed: $seed ^ self::COMPUTER_STREAM_MIX, reproducible: $reproducible),
            bindings: $bindings,
            scores: $scores,
            scoresPath: $scoresPath,
            seed: $seed,
            reproducible: $reproducible,
        );
    }

    public function init(): ?\Closure
    {
        // Two clocks start together (candy-tetris-085): the human gravity
        // chain and the AI's own tick chain, each a generation-tagged
        // singleton from here on.
        return Cmd::batch(
            self::scheduleTick($this->player, $this->gravityChain),
            self::scheduleComputerTick($this->computer, $this->computerChain),
        );
    }

    /**
     * Schedule a tick for the player game, stamped with the live chain
     * epoch (candy-tetris-003 — the player controls overall speed; the
     * tag decides which chain the arrival belongs to).
     */
    private static function scheduleTick(Game $player, int $chain): \Closure
    {
        $interval = $player->score->gravityIntervalUs() / 1_000_000;
        return Cmd::tick($interval, static fn(): Msg => new GravityMsg($chain));
    }

    /**
     * Arm the AI's next planned input at the COMPUTER board's own gravity
     * interval (candy-tetris-007/-085). Same singleton law as the human
     * chain: only the arms above and the ComputerTickMsg answer below ever
     * re-arm it, and the live epoch tag decides which chain owns an arrival.
     */
    private static function scheduleComputerTick(Game $computer, int $chain): \Closure
    {
        $interval = $computer->score->gravityIntervalUs() / 1_000_000;
        return Cmd::tick($interval, static fn(): Msg => new ComputerTickMsg($chain));
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

        // The tick chain is generation-tagged (candy-tetris-003, mirroring
        // the solo defence): an arrival from a superseded chain is inert
        // BEFORE any branch may treat it as a heartbeat — a stale tick
        // must not step the fresh pair, drive the computer, or re-arm the
        // dead chain into a second concurrent timer.
        if ($msg instanceof GravityMsg && $msg->chain !== $this->gravityChain) {
            return [$this, null];
        }
        if ($msg instanceof ComputerTickMsg && $msg->chain !== $this->computerChain) {
            return [$this, null];
        }

        // Below the frame floor the VS pair freezes the way pause freezes
        // it: q still quits, the tick chain keeps its heartbeat, and a
        // resize back into fit resumes with nothing double-armed (C-2).
        if ($this->tooSmall() === true) {
            if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if ($msg instanceof GravityMsg) {
                return [$this, self::scheduleTick($this->player, $this->gravityChain)];
            }
            if ($msg instanceof ComputerTickMsg) {
                return [$this, self::scheduleComputerTick($this->computer, $this->computerChain)];
            }
            if ($msg instanceof LockDelayMsg) {
                // A resize never parks a live window (solo timers keep
                // running across WindowSizeMsg too): the lock lands behind
                // the freeze frame and the restored board shows an honest
                // stack (candy-tetris-017 composition rule).
                [$grown, $locked] = $this->player->update($msg);

                return [$this->mutate(['player' => $grown]), $locked];
            }
            return [$this, null];
        }

        // If game is over, accept only quit or restart (the 'r' arm below
        // mirrors the solo game's post-over restart parity).
        if ($this->over === true) {
            if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'restart')) {
                // Restart the pair and arm one fresh tick chain — parity
                // with the solo game's 'r'. The chain epoch bumps so the
                // superseded chain's in-flight tick lands inert on the
                // fresh pair (candy-tetris-003). The fresh player's own
                // chain counter starts on the same epoch (candy-tetris-017
                // composition rule): forwarded player re-arms carry the
                // player's counter, and the guard above must read them as
                // the live chain they are — two number spaces, one clock.
                $bumped = $this->gravityChain + 1;
                $freshPair = self::start($this->bindings, $this->scores, $this->scoresPath, seed: $this->seed, reproducible: $this->reproducible);
                $fresh = $freshPair->mutate([
                    'gravityChain' => $bumped,
                    'computerChain' => $this->computerChain + 1,
                    'player' => $freshPair->player->mutate(['gravityChain' => $bumped]),
                    // The live terminal size survives the restart: the
                    // Program only re-reports on the next SIGWINCH.
                    'frameCols' => $this->frameCols,
                    'frameRows' => $this->frameRows,
                    'colorProfile' => $this->colorProfile,
                ]);
                return [$fresh, Cmd::batch(
                    self::scheduleTick($fresh->player, $fresh->gravityChain),
                    self::scheduleComputerTick($fresh->computer, $fresh->computerChain),
                )];
            }
            return [$this, null];
        }

        // Handle quit key for player
        if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
            return [$this, Cmd::quit()];
        }

        // Handle pause — toggle pause; the in-flight tick stays in flight,
        // so no new tick is armed here (arming on key messages was the
        // chain-duplication defect fixed alongside Game's gravity guard).
        // The toggle is owned here rather than delegated: a release or a
        // stray repeat must not flip the flag a second time on the way
        // (m11 — this gate is load-bearing, not a pass-through).
        if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'pause')) {
            $toggled = $this->mutate(['player' => $this->player->mutate(['paused' => !$this->player->paused])]);
            if ($this->player->paused === false) {
                // This press PAUSES: the in-flight AI tick lands on the
                // paused branch below and dies there on purpose, so nothing
                // is armed here; the resume leg revives the chain once.
                return [$toggled, null];
            }

            // This press RESUMES: the AI chain was dropped while frozen —
            // re-arm it exactly once, here.
            return [$toggled, self::scheduleComputerTick($toggled->computer, $toggled->computerChain)];
        }

        // While paused, only the tick chain keeps beating: gravity re-arms
        // itself, every other message is ignored (player input dropped,
        // computer frozen).
        if ($this->player->paused === true) {
            if ($msg instanceof GravityMsg) {
                return [$this, self::scheduleTick($this->player, $this->gravityChain)];
            }
            if ($msg instanceof ComputerTickMsg) {
                // Dropped WITHOUT re-arm on purpose: the AI is frozen and
                // its chain revives exactly once from the resume leg above.
                return [$this, null];
            }
            if ($msg instanceof LockDelayMsg) {
                // Solo's survival law mirrored (candy-tetris-017): a window
                // that comes due while paused EXTENDS — the player's own
                // paused arm re-arms the timer and the frozen piece never
                // locks out from under the player.
                [$pausedPlayer, $again] = $this->player->update($msg);

                return [$this->mutate(['player' => $pausedPlayer]), $again];
            }
            return [$this, null];
        }

        // Process player game (KeyMsg only reaches here when not paused/quit).
        // A tick that passed the VS-level epoch gate is re-stamped into the
        // INNER game's own chain domain: the VS epoch guards the outer loop,
        // the inner Game enforces its tag against its own counter, and a
        // fresh pair after restart carries 0 while the outer epoch has moved
        // on (candy-tetris-003; the inner chain only ever bumps via paths
        // VS never drives — see the same law in {@see GravityMsg}'s docblock).
        $isComputerTick = $msg instanceof ComputerTickMsg;
        [$newPlayer, $playerCmd] = $isComputerTick
            ? [$this->player, null]
            : $this->player->update(
                $msg instanceof GravityMsg ? new GravityMsg($this->player->gravityChain) : $msg
            );

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
        if ($isComputerTick) {
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
            // addGarbage (not addGarbageRows): the holes ride the recipient
            // board's seeded stream — the production path of -026.
            $processedComputer = $newComputer->addGarbage($linesCleared);
        }

        // If computer cleared lines, add garbage to player
        $computerBeforeLines = $this->computer->score->lines;
        $computerLinesCleared = $processedComputer->score->lines - $computerBeforeLines;
        $finalPlayer = $newPlayer;
        if ($computerLinesCleared > 0) {
            $finalPlayer = $newPlayer->addGarbage($computerLinesCleared);
        }

        // Check for game over after garbage
        if ($finalPlayer->over === true) {
            return $this->withComputerWinner($finalPlayer);
        }
        if ($processedComputer->over === true) {
            return $this->withPlayerWinner($finalPlayer, $processedComputer);
        }

        // candy-tetris-017 composition rule: a garbage lift clears the
        // recipient's armed lock window (Game's reset arms do it), so any
        // window timer already in flight for the player arrives STALE. If
        // this arrival lifted the player and left the chain unanchored —
        // no pending gravity, no window — re-anchor it here, or the piece
        // would strand forever behind a heartbeat that only ever gets
        // dropped at the player's own pending gate.
        if ($computerLinesCleared > 0
            && $finalPlayer->gravityPending === false
            && $finalPlayer->lockWindowGeneration === null
        ) {
            $finalPlayer = $finalPlayer->mutate(['gravityPending' => true]);
            $playerCmd = self::scheduleTick($finalPlayer, $this->gravityChain);
        }

        return [
            $this->mutate([
                'player' => $finalPlayer,
                'computer' => $processedComputer,
                'hasComputerPlan' => $hasPlan,
                'computerRotationsLeft' => $rotationsLeft,
                'computerShiftLeft' => $shiftLeft,
            ]),
            // The tick chain stays singleton: on a gravity tick the PLAYER's
            // own answer is the truth — either its re-arm or the lock-delay
            // timer that replaced it (candy-tetris-017) — and only when the
            // player parked without arming anything does the VS heartbeat
            // take over. On key messages only the window timer survives.
            $msg instanceof GravityMsg
                ? ($playerCmd ?? self::scheduleTick($finalPlayer, $this->gravityChain))
                : ($isComputerTick
                    ? ($playerCmd === null
                        ? self::scheduleComputerTick($processedComputer, $this->computerChain)
                        : Cmd::batch(
                            self::scheduleComputerTick($processedComputer, $this->computerChain),
                            $playerCmd,
                        ))
                    : $playerCmd),
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
                // Blocked rotation — the recorded plan is no longer
                // executable, so RE-EVALUATE: the piece stays untouched and
                // the next tick records a fresh bestMove for the real board
                // (candy-tetris-085; forcing the old legs through would be
                // the wasted-drop the old abandon-then-continue produced).
                return [$computer, false, 0, 0];
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
            // Blocked sideways — same law: re-evaluate from the next tick.
            return [$computer, false, 0, 0];
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
            fwrite(STDERR, Lang::t('score.save.failed', ['problem' => Support\CliVisible::clamp($problem->getMessage())]) . "\n");
        }

        return $this->mutate($changes);
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
            $changes['gravityChain'] ?? $this->gravityChain,
            $changes['computerChain'] ?? $this->computerChain,
            $changes['seed'] ?? $this->seed,
            $changes['reproducible'] ?? $this->reproducible,
        );
    }
}
