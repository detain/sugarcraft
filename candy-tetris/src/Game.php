<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Tetris\Scoring\TSpin;
use SugarCraft\Tetris\Support\Bindings;
use SugarCraft\Tetris\Support\Scores;

/**
 * Tetris as a SugarCraft {@see Model}.
 *
 * Architecture:
 *
 *   - State machine. Every transition (`update()`) returns
 *     `[nextGame, ?Cmd]` and emits no Cmd of its own beyond
 *     `Cmd::quit()`, a gravity `tick()`, or a lock-delay `tick()`.
 *     The one exception is deliberate (MA-23): when a wired solo game
 *     crosses alive→over, the record save happens right there, in-model,
 *     because the death is only knowable at that transition — a failure
 *     is announced on stderr, never swallowed into a silent zero.
 *   - Gravity is a single chain: exactly one gravity tick is ever
 *     in flight. `init()` arms it; each gravity tick consumes the
 *     pending slot and re-arms it 1:1. Key paths (hard drop, hold)
 *     never append a second tick — {@see self::armGravity()} skips
 *     re-arming while one is pending, so N actions cannot buy N
 *     extra timers.
 *   - The descent chain is paced on an ABSOLUTE deadline
 *     (candy-tetris-023): each re-arm fires interval - lateness,
 *     where lateness is the render/update cost that ran between the
 *     armed deadline and this handler. A slow terminal therefore
 *     drops ticks (never pieces) instead of stretching every gap by
 *     its own frame cost — fall speed is game state, not machine
 *     throughput. A gap already in the past when it is armed is
 *     dropped outright (no catch-up burst).
 *   - The lock-delay window is real-time (milliseconds), independent
 *     of gravity speed, and generation-tagged via
 *     {@see LockDelayMsg}: superseded windows go stale by tag
 *     mismatch rather than by cancellation (sugar-core ticks have
 *     no cancel API).
 *   - Game-over is detected when a freshly-spawned piece doesn't
 *     {@see Board::fits()}. After that, `q` quits and `r` restarts.
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
        /**
         * Identity tag of the live gravity chain. Restarts bump it so a
         * stale in-flight tick from the dead chain can never step the
         * fresh board (the single-chain invariant enforced on ARRIVAL,
         * not only at arming).
         */
        public readonly int          $gravityChain = 0,
        /**
         * Live terminal size as last reported by WindowSizeMsg (seeded at
         * the natural frame, {@see Renderer::NATURAL_COLS}). The renderer
         * reflows against this and refuses below its floor instead of
         * silently clipping (C-2).
         */
        public readonly int          $frameCols = Renderer::NATURAL_COLS,
        public readonly int          $frameRows = Renderer::NATURAL_ROWS,
        /**
         * Terminal capability reported by ColorProfileMsg (null until the
         * Program answers — null renders the full-colour path, which is
         * what every pre-feature frame and golden was built on).
         */
        public readonly ?ColorProfile $colorProfile = null,
        /**
         * Why the game ended (MA-10): one of Game::OVER_* — the fatal piece
         * spawns in the hidden rows, so without a named reason the player
         * only ever sees a banner. Null while alive and on the legacy
         * golden paths that never set it.
         */
        public readonly ?string      $overReason = null,
        /**
         * What the most recent lock earned (MA-13): 'T-SPIN', 'TETRIS',
         * 'PERFECT CLEAR' & compound names, or null for an ordinary
         * placement. The scoring math always paid these moments silently —
         * the sidebar badges the token while it is the fresh achievement.
         */
        public readonly ?string      $lastAward = null,
        /**
         * m27 live binding registry (null = the shipped default table,
         * resolved per access through {@see self::keys()}). The launcher
         * injects the user-configured table when the keys file carries
         * overrides; tests may inject any registry directly.
         */
        public readonly ?KeyBindings $bindings = null,
        /**
         * MA-23 cross-session record (null = no record wired; a test or a
         * HOME-less launch runs exactly the pre-MA-23 game). Carries the
         * best as of the last settle, so the game-over banner can show it.
         */
        public readonly ?Scores $scores = null,
        /** Where {@see self::$scores} persists to on settle (null = nowhere). */
        public readonly ?string $scoresPath = null,
        /**
         * True only on the frame the death produced it: this run's score
         * beat every solo game settled before it. Resets with the fresh
         * board (a restart never inherits the shout).
         */
        public readonly bool $newBest = false,
        /**
         * MA-24: the first-run on-ramp card is up. True only when the
         * launcher seeds it (solo mode, record never onboarded); the
         * default false keeps every existing construction byte-identical.
         */
        public readonly bool $introPending = false,
        /**
         * The run's root seed (candy-tetris-018): every RNG stream this
         * game owns derives from it, so `--seed=N` replays piece-for-piece
         * across launches. Always set on the factory paths (an unseeded
         * launch still captures fresh entropy HERE, once) — null only for
         * hand-constructed test models.
         */
        public readonly ?int $seed = null,
        /**
         * True only when the seed came from `--seed=N` (the launcher
         * passes it explicitly): the banner stamps the run as
         * reproducible. A hidden fresh seed from a normal launch stamps
         * nothing, so every pre-feature frame and golden stays
         * byte-identical.
         */
        public readonly bool $reproducible = false,
        /**
         * Monotonic-microsecond clock used to pace the gravity chain
         * (candy-tetris-023). Null answers for the real hrtime; a test
         * reads the simulator's own timeline so arm and fire happen on the
         * SAME time base — without one, deadline chaining is untestable
         * without sleeping. Signature: `(): float`, microseconds.
         */
        public readonly ?\Closure $clockUs = null,
        /**
         * candy-tetris-006: the on-screen key reference. The live card
         * ('help' id, default h) is the reachable key education on any
         * posture — the sidebar help card is the first budget casualty
         * below natural height, and on an 80x24 terminal nothing else
         * listed the keys at all. Session-local view state: a restart
         * (a fresh Game from the factories) closes it, mutate carries it.
         */
        public readonly bool $helpOpen = false,
        /**
         * candy-tetris-025/-092: the pending-frame slot. The game loop
         * owns the terminal — a mid-loop fwrite(STDERR) scribbled raw
         * lines across the live alt-screen (the score-save failure did,
         * twice) and corrupted the very frame the next paint had to
         * recover from. Failures, line clears, level-ups and incoming
         * garbage now land HERE and ride the banner machinery as normal
         * frame lines. Lifetime law: the slot survives ticks (a death-
         * screen save failure must still be readable) and clears on the
         * next keypress — the player's own input means their attention
         * has moved on. Newest kept; at most two lines paint.
         *
         * @var list<string>
         */
        public readonly array $notices = [],
    ) {
        // One stateful stream feeds this game's garbage holes. Built from
        // the seed so the sequence is run-determined; Game::mutate()
        // re-attaches THIS cell instead of re-deriving, so the stream
        // advances across the whole lineage and never restarts mid-run
        // (a re-derived stream would punch the same hole every arrival).
        $this->garbageStream = Bag::stream($seed ?? random_int(0, self::SEED_MASK));
    }

    /** 31-bit seed ring shared with {@see Bag::stream()}. */
    public const SEED_MASK = 0x7FFFFFFF;

    /**
     * Live garbage-hole stream (stateful closure, see the ctor note).
     *
     * @var \Closure(int): int
     */
    private \Closure $garbageStream;

    /** Death by spawn-block: the freshly spawned piece does not fit. */
    public const OVER_SPAWN_BLOCKED = 'spawn-blocked';
    /** Death by top-out: incoming garbage would push locked content off the ceiling. */
    public const OVER_TOP_OUT = 'top-out';
    /** Death by crush: the active piece fits neither raised nor in place after garbage. */
    public const OVER_CRUSHED = 'crushed';

    /**
     * Construct a new Game from the current state with optional field overrides.
     * Mirrors the canonical SugarCraft immutable/fluent pattern (see
     * sugar-style/src/Style.php, sugar-core/src/Concerns/Mutable.php).
     *
     * `lockWindowGeneration` is nullable and closes via explicit null, so
     * it uses array_key_exists rather than the ?? carry — otherwise
     * `mutate(['lockWindowGeneration' => null])` could never clear it.
     *
     * @param array<string,mixed> $changes Field overrides
     */
    public function mutate(array $changes): self
    {
        $fresh = new self(
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
            $changes['gravityChain']  ?? $this->gravityChain,
            $changes['frameCols']     ?? $this->frameCols,
            $changes['frameRows']     ?? $this->frameRows,
            array_key_exists('colorProfile', $changes)
                ? $changes['colorProfile']
                : $this->colorProfile,
            array_key_exists('overReason', $changes)
                ? $changes['overReason']
                : $this->overReason,
            array_key_exists('lastAward', $changes)
                ? $changes['lastAward']
                : $this->lastAward,
            $changes['bindings']      ?? $this->bindings,
            $changes['scores']         ?? $this->scores,
            $changes['scoresPath']     ?? $this->scoresPath,
            $changes['newBest']        ?? $this->newBest,
            $changes['introPending']  ?? $this->introPending,
            $changes['seed']          ?? $this->seed,
            $changes['reproducible']  ?? $this->reproducible,
            $changes['clockUs']       ?? $this->clockUs,
            $changes['helpOpen']      ?? $this->helpOpen,
            $changes['notices']       ?? $this->notices,
        );
        // The live garbage stream rides the update (see ctor note): a
        // re-derived stream would restart the hole sequence on every
        // mutation, punching the same column each arrival.
        $fresh->garbageStream = $this->garbageStream;

        return $fresh;
    }

    /**
     * True when the live terminal cannot host the smallest honest frame
     * ({@see Renderer::MIN_COLS} × {@see Renderer::MIN_ROWS}). Gameplay
     * keeps its clock (the chain re-arms) but nothing steps: the renderer
     * answers with a resize notice instead of a silently clipped board.
     */
    public function tooSmall(): bool
    {
        return $this->frameCols < Renderer::MIN_COLS || $this->frameRows < Renderer::MIN_ROWS;
    }

    public static function start(?Bag $bag = null, ?KeyBindings $bindings = null, ?Scores $scores = null, ?string $scoresPath = null, bool $introPending = false, ?int $seed = null, bool $reproducible = false, ?\Closure $clockUs = null): self
    {
        // candy-tetris-018: the production path always carries a seed.
        // `--seed=N` pins it (and stamps the banner); a plain launch
        // captures fresh entropy once, here, so no hot path ever falls
        // back to an untracked global default mid-run.
        $seed ??= random_int(0, self::SEED_MASK);
        $bag ??= Bag::seeded($seed);
        [$first, $bag] = $bag->next();
        $piece = self::spawn($first);
        // init() will arm the gravity chain; the pending slot starts taken
        // so no key path can double-book it before then.
        return new self(new Board(), $piece, $bag, new Score(), gravityPending: true, bindings: $bindings, scores: $scores, scoresPath: $scoresPath, introPending: $introPending, seed: $seed, reproducible: $reproducible, clockUs: $clockUs);
    }

    /**
     * Start a new game with a real-time lock-delay window enabled.
     *
     * @param int $lockDelayMs Grounded window length in milliseconds
     *                         (SRS-style: ~0.5 s, independent of gravity).
     */
    public static function startWithLockDelay(?Bag $bag = null, int $lockDelayMs = self::DEFAULT_LOCK_DELAY_MS, ?KeyBindings $bindings = null, ?Scores $scores = null, ?string $scoresPath = null, bool $introPending = false, ?int $seed = null, bool $reproducible = false, ?\Closure $clockUs = null): self
    {
        // Seed law as in {@see self::start()} (candy-tetris-018).
        $seed ??= random_int(0, self::SEED_MASK);
        $bag ??= Bag::seeded($seed);
        [$first, $bag] = $bag->next();
        $piece = self::spawn($first);
        return new self(
            new Board(),
            $piece,
            $bag,
            new Score(),
            lockDelayMs: $lockDelayMs,
            gravityPending: true,
            bindings: $bindings,
            scores: $scores,
            scoresPath: $scoresPath,
            introPending: $introPending,
            seed: $seed,
            reproducible: $reproducible,
            clockUs: $clockUs,
        );
    }

    public function init(): ?\Closure
    {
        return $this->scheduleGravity($this->score, $this->gravityChain);
    }

    /**
     * Arm the next gravity tick on the absolute-deadline chain
     * (candy-tetris-023). $deadlineUs is the monotonic reading the tick
     * that triggered this re-arm was SUPPOSED to fire at — null answers
     * for fresh baselines (init, restart, lock-and-spawn, input revives,
     * non-descending heartbeats). The next deadline sits exactly one
     * interval after the given one, so the cadence advances in virtual
     * time and only the wait shrinks by whatever processing overran the
     * deadline: fall speed stops being `interval + frame cost` and stops
     * depending on the machine. A deadline already in the past when
     * armed (the terminal lagged a whole interval) is rebased: the
     * overdue tick is dropped, never burst into a catch-up cascade.
     */
    private function scheduleGravity(Score $score, int $chain = 0, ?float $deadlineUs = null): \Closure
    {
        $intervalUs = (float) $score->gravityIntervalUs();
        $nowUs = $this->nowUs();
        $nextDeadlineUs = ($deadlineUs ?? $nowUs) + $intervalUs;
        if ($nextDeadlineUs <= $nowUs) {
            $nextDeadlineUs = $nowUs + $intervalUs;
        }

        return Cmd::tick(
            max(0.0, $nextDeadlineUs - $nowUs) / 1_000_000,
            static fn(): Msg => new GravityMsg($chain, $nextDeadlineUs),
        );
    }

    /**
     * Monotonic clock in microseconds. hrtime (not microtime) so the
     * chain survives wall-clock steps and NTP slewing; an injected
     * clock (see the ctor param) puts arm and fire on one time base.
     */
    private function nowUs(): float
    {
        $clock = $this->clockUs;

        return $clock === null ? hrtime(true) / 1_000.0 : (float) $clock();
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
     * $deadlineUs chains an absolute-deadline re-arm (see
     * {@see $this->scheduleGravity()}); null arms a fresh baseline.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function armGravity(self $game, ?float $deadlineUs = null): array
    {
        if ($game->gravityPending === true) {
            return [$game, null];
        }
        return [
            $game->mutate(['gravityPending' => true]),
            $this->scheduleGravity($game->score, $game->gravityChain, $deadlineUs),
        ];
    }

    /**
     * True for key events that mean "the player actuated this binding" —
     * the base KeyMsg and explicit presses count; releases and auto-repeats
     * (kitty REPORT_EVENT_TYPES wire, once negotiated) must not double-fire
     * a toggle (m11).
     */
    private static function isPress(KeyMsg $msg): bool
    {
        return !$msg instanceof KeyReleaseMsg && !$msg instanceof KeyRepeatMsg;
    }

    /**
     * m27: the live action table. An un-injected game answers with the
     * shipped default table, so dispatch without a registry behaves the
     * way the hardcoded per-arm comparisons did — the press-only rule
     * (m11), the case fold (m26) and the ctrl-chord discipline
     * (candy-tetris-034) live in Support\Bindings::actuates, and the
     * space token now fires on BOTH wire shapes: the legacy decoder
     * delivers KeyType::Space with rune ' ' (the old charKey arm could
     * only ever see the hand-built Char fixture shape, never the wire —
     * README's hard-drop promise was dead on real terminals).
     */
    private function keys(): KeyBindings
    {
        return $this->bindings ?? Support\Bindings::table();
    }

    public function update(Msg $msg): array
    {
        // candy-tetris-025/-092: the slot's lifetime law — the player's
        // own next input retires whatever the last frame was showing;
        // ticks, timers and resizes never clear a notice a death screen
        // owns (the save failure must still be readable under the banner).
        $state = $msg instanceof KeyMsg && $this->notices !== []
            ? $this->mutate(['notices' => []])
            : $this;
        $wasOver = $state->over;
        $result = $state->stepGame($msg);
        if ($wasOver === false && $result[0]->over === true) {
            $result = $state->settled($result);
        }

        return $result;
    }

    /**
     * candy-tetris-025/-092: the single push point for the pending-frame
     * slot — never a raw write while the loop owns the terminal. Oldest
     * line gives way; the two freshest ride the next banner.
     */
    public function withNotice(string $line): self
    {
        return $this->mutate(['notices' => array_slice([...$this->notices, $line], -2)]);
    }

    /**
     * MA-23: the alive→over transition is where the record learns about a
     * finished game. Exactly once per death: the wrapper only fires on the
     * crossing, so every later keystroke on the banner and the fresh board
     * after a restart are both safe. The save mutates nothing the failure
     * path needs — an unwritable file leaves the on-disk record untouched
     * (never zeroed) and the in-memory best still banners this frame.
     *
     * @param array{0:self,1:?\Closure} $pair
     *
     * @return array{0:self,1:?\Closure}
     */
    private function settled(array $pair): array
    {
        if ($this->scoresPath === null) {
            return $pair;
        }
        $game = $pair[0];
        $previous = $this->scores ?? new Scores();
        $next = $previous->withSolo($game->score->points);
        $game = $game->mutate(['scores' => $next, 'newBest' => $previous->beatsSoloBest($game->score->points)]);
        $pair[0] = $game;

        try {
            // Adopt the record as written (merged with the disk — -035) so
            // a later settlement in this session deltas from the published
            // state and never re-bills a counter another save already paid.
            $pair[0] = $game->mutate(['scores' => $next->save($this->scoresPath)]);
        } catch (\Throwable $problem) {
            // Loud, and only loud — but loud means ON-FRAME now (candy-
            // tetris-025): the session keeps its honest banner, the
            // untouched old file still holds the true record, and the
            // failure line rides the pending slot instead of scribbling
            // raw bytes across the live alt-screen.
            $pair[0] = $game->withNotice(
                Lang::t('score.save.failed', ['problem' => Support\CliVisible::clamp($problem->getMessage())]),
            );
        }

        return $pair;
    }

    /**
     * The pre-MA-23 dispatch body, unchanged: posture, notice screen,
     * banner screen, live keys, gravity, lock window.
     */
    /**
     * MA-24: the on-ramp screen. The board freezes the way pause freezes
     * it (the gravity chain keeps its heartbeat, nothing steps); 'begin'
     * starts play and marks the record onboarded, and 'quit' leaves
     * UNMARKED — closing the game unseen must not count as having seen
     * it, so the card comes back next launch. A save refusal is announced
     * on-frame exactly like the settle path's, never silent (candy-
     * tetris-025: the slot, not stderr — the card is up when it happens).
     *
     * @return array{0:self,1:?\Closure}
     */
    private function handleIntro(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if (Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if (Support\Bindings::actuates($this->keys(), $msg, 'begin')) {
                $closed = $this->mutate(['introPending' => false]);
                if ($closed->scoresPath !== null) {
                    $next = ($closed->scores ?? new Scores())->withOnboarded();
                    try {
                        // The written (merged — -035) record is the session's
                        // truth from here: later settles delta from it.
                        $closed = $closed->mutate(['scores' => $next->save($closed->scoresPath)]);
                    } catch (\Throwable $problem) {
                        // candy-tetris-025: on-frame like the settle path —
                        // the intro card is up, the terminal belongs to the
                        // game, so the failure rides the slot.
                        $closed = $closed->mutate(['scores' => $next])
                            ->withNotice(
                                Lang::t('score.save.failed', ['problem' => Support\CliVisible::clamp($problem->getMessage())]),
                            );
                    }
                }
                return [$closed, null];
            }
            return [$this, null];
        }
        if ($msg instanceof GravityMsg) {
            if ($msg->chain !== $this->gravityChain || $this->gravityPending === false) {
                return [$this, null];
            }
            return $this->armGravity($this->mutate(['gravityPending' => false]));
        }
        if ($msg instanceof LockDelayMsg) {
            if ($msg->generation !== $this->lockWindowGeneration) {
                return [$this, null];
            }
            return [$this, $this->scheduleLockDelay($msg->generation)];
        }
        return [$this, null];
    }

    /**
     * The key names the on-ramp card prints for the start action (m27: a
     * remapped begin shows remapped on the card). Candy-tetris-053: the
     * frame glyph pair 'spc ent' read as one impossible chord to a new
     * player — the card spells the keycaps ('Space or Enter') and joins
     * them with the locale's own disjunction; a custom remap token that
     * has no display name shows itself verbatim.
     */
    public function introBeginLabel(): string
    {
        return $this->keyLabel('begin');
    }

    /**
     * The live, human-readable name of an action's bindings (candy-tetris-
     * 005): every banner that tells the player to press something must
     * print the table as it stands, never a baked literal — a remapped
     * pause whose overlay still says 'p' strands the player at the screen
     * it claims to unlock. Tokens render in keycap long form and join with
     * the locale's own disjunction, so 'q' + 'ctrl+c' reads 'q or ctrl+c'.
     */
    public function keyLabel(string $id): string
    {
        $names = array_map(
            static fn(string $token): string => Bindings::KEY_NAMES[$token] ?? $token,
            $this->keys()->tokens($id),
        );

        return implode(Lang::t('key_or'), $names);
    }

    private function stepGame(Msg $msg): array
    {
        // Terminal posture first (C-2): a resize or capability report is
        // never gameplay, must land even on the game-over screen, and must
        // not churn state when nothing changed (identity short-circuit).
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

        if ($this->tooSmall() === true) {
            // The notice screen replaces the frame. The world freezes the
            // way pause freezes it: q still quits, the gravity chain keeps
            // its heartbeat (consume, re-arm), the lock window stays open
            // for a full delay — so a resize back into fit resumes here
            // with nothing lost and nothing double-armed.
            if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if ($msg instanceof GravityMsg) {
                if ($msg->chain !== $this->gravityChain || $this->gravityPending === false) {
                    return [$this, null];
                }
                return $this->armGravity($this->mutate(['gravityPending' => false]));
            }
            if ($msg instanceof LockDelayMsg) {
                if ($msg->generation !== $this->lockWindowGeneration) {
                    return [$this, null];
                }
                return [$this, $this->scheduleLockDelay($msg->generation)];
            }
            return [$this, null];
        }

        if ($this->introPending === true) {
            // MA-24: the on-ramp card sits after the too-small notice — a
            // frame that cannot show the board cannot honestly show the
            // card either — and before the over screen it can never reach.
            return $this->handleIntro($msg);
        }

        if ($this->over === true) {
            if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
                return [$this, Cmd::quit()];
            }
            if ($msg instanceof KeyMsg && Support\Bindings::actuates($this->keys(), $msg, 'restart')) {
                // 'r' starts a completely fresh game. The score does NOT
                // carry over: Score embeds the level, so keeping it would
                // spawn the new board under the old gravity. The fresh state
                // arrives with gravityPending already true and its gravity
                // chain is armed right here — init() ran once at boot and
                // will not re-run.
                $fresh = $this->lockDelayMs > 0
                    ? self::startWithLockDelay(lockDelayMs: $this->lockDelayMs, bindings: $this->bindings, scores: $this->scores, scoresPath: $this->scoresPath, seed: $this->seed, reproducible: $this->reproducible, clockUs: $this->clockUs)
                    : self::start(bindings: $this->bindings, scores: $this->scores, scoresPath: $this->scoresPath, seed: $this->seed, reproducible: $this->reproducible, clockUs: $this->clockUs);
                // Bump the chain tag so any tick still in flight from the
                // dead chain lands inert on the fresh board.
                $fresh = $fresh->mutate([
                    'gravityChain' => $this->gravityChain + 1,
                    // The lock generation space carries across restart
                    // (monotonic, never reset): a superseded window's
                    // in-flight LockDelayMsg can never collide with the
                    // tag a window on the fresh board later arms
                    // (candy-tetris-065 — gravity got this defence in
                    // -003's lineage, the lock chain never did).
                    'lockGeneration' => $this->lockGeneration,
                    // The live terminal size survives the restart: the
                    // Program only re-reports on the next SIGWINCH, and a
                    // fresh natural-size seed would re-clip a squeezed one.
                    'frameCols' => $this->frameCols,
                    'frameRows' => $this->frameRows,
                    'colorProfile' => $this->colorProfile,
                ]);
                return [$fresh, $this->scheduleGravity($fresh->score, $fresh->gravityChain)];
            }
            return [$this, null];
        }

        if ($msg instanceof KeyMsg && self::isPress($msg)) {
            return $this->handleKey($msg);
        }

        if ($msg instanceof GravityMsg) {
            // Consume the pending slot first: every exit path below either
            // re-arms through armGravity() or deliberately ends the chain
            // (over, or a lock window taking over the timing).
            // Enforce the single-chain invariant on ARRIVAL, not only at
            // arming: a tick with no slot taken is stale — a doubled arm,
            // or one in flight from before a restart whose fresh chain
            // carries a bumped tag. It goes inert without stepping.
            if ($msg->chain !== $this->gravityChain || $this->gravityPending === false) {
                return [$this, null];
            }
            $consumed = $this->mutate(['gravityPending' => false]);
            if ($this->paused || $this->helpOpen === true) {
                // Pause — and the help overlay (candy-tetris-006) — heart-
                // beats without stepping: nothing moves behind a card the
                // player is reading.
                return $this->armGravity($consumed);
            }
            return $consumed->gravityStep($msg->deadlineUs);
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
        if ($this->paused === true || $this->helpOpen === true) {
            // Pause — and the help overlay (candy-tetris-006) — extend the
            // window: re-arm the same generation for a full delay instead
            // of locking behind a card the player is reading.
            return [$this, $this->scheduleLockDelay($msg->generation)];
        }
        $game = $this->lockAndSpawn();
        if ($game->over === true) {
            return [$game, null];
        }
        return $this->armGravity($game);
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
        if (Support\Bindings::actuates($this->keys(), $msg, 'quit')) {
            return [$this, Cmd::quit()];
        }
        if (Support\Bindings::actuates($this->keys(), $msg, 'pause')) {
            return [$this->withPaused(!$this->paused), null];
        }
        if ($this->paused) {
            // Nothing mutates game state while paused — hold included —
            // but the key reference (candy-tetris-006) is the one press
            // that answers: a paused player may want to check the card.
            if (Support\Bindings::actuates($this->keys(), $msg, 'help')) {
                return [$this->mutate(['helpOpen' => !$this->helpOpen]), null];
            }

            return [$this, null];
        }
        if ($this->helpOpen === true) {
            // The world is frozen behind the card (candy-tetris-006):
            // nothing moves unseen while the player reads. Only the same
            // press closes it.
            if (Support\Bindings::actuates($this->keys(), $msg, 'help')) {
                return [$this->mutate(['helpOpen' => false]), null];
            }

            return [$this, null];
        }

        $played = match (true) {
            Support\Bindings::actuates($this->keys(), $msg, 'hold')
                => $this->tryHold(),
            Support\Bindings::actuates($this->keys(), $msg, 'move-left')
                => $this->tryMove(-1, 0),
            Support\Bindings::actuates($this->keys(), $msg, 'move-right')
                => $this->tryMove(1, 0),
            Support\Bindings::actuates($this->keys(), $msg, 'soft-drop')
                => $this->softDrop(),
            Support\Bindings::actuates($this->keys(), $msg, 'rotate-cw')
                => $this->tryRotate(1),
            Support\Bindings::actuates($this->keys(), $msg, 'rotate-ccw')
                => $this->tryRotate(-1),
            // candy-tetris-016: the flip rides the same kick path — delta
            // 2 walks the 0↔2 transitions the SRS table already carries.
            Support\Bindings::actuates($this->keys(), $msg, 'rotate-180')
                => $this->tryRotate(2),
            Support\Bindings::actuates($this->keys(), $msg, 'hard-drop')
                => $this->hardDrop(),
            // candy-tetris-006: the meta press sits BEHIND the gameplay
            // arms — an override that hands 'h' to hard-drop keeps the
            // piece's lock (a remap wins over a shipped default, exactly
            // as 'begin'/'hard-drop' already share space by screen), and
            // with an untouched table no gameplay id claims a plain 'h',
            // so the key reference still answers.
            Support\Bindings::actuates($this->keys(), $msg, 'help')
                => [$this->mutate(['helpOpen' => true]), null],
            default => [$this, null],
        };

        return $played;
    }

    /**
     * One gravity tick on an already-consumed state. The gravity chain
     * keeps running while the piece can fall; when the piece grounds with
     * a lock-delay window enabled, the window owns the timing and the
     * chain parks until the next lock/spawn.
     *
     * @return array{0:Game,1:?\Closure}
     */
    /**
     * One gravity tick of descent. $deadlineUs is the armed deadline of
     * the tick being handled: a successful move chains the next arm to
     * it (absolute-deadline cadence, candy-tetris-023), while a lock
     * ends the piece's chain — the spawned piece gets a fresh baseline
     * because it was born now, not one interval after the last tick.
     *
     * @return array{0:self,1:?\Closure}
     */
    private function gravityStep(?float $deadlineUs = null): array
    {
        $next = $this->piece->moved(0, 1);
        if ($this->board->fits($next)) {
            // Piece can move down. Gravity is not a player input, so it
            // never clears lastActionWasRotation (rotate-then-settle is a
            // valid T-Spin in the SRS guidelines).
            $game = $this->mutate(['piece' => $next, 'lockWindowGeneration' => null]);
            return $this->armGravity($game, $deadlineUs);
        }

        // Piece can't move down - handle the lock-delay window.
        if ($this->lockDelayMs > 0) {
            if ($this->lockWindowGeneration !== null) {
                // Window armed and a gravity tick slipped in (armed while
                // airborne by a key path): the window owns the lock now.
                return [$this, null];
            }
            if ($this->lockResets >= self::LOCK_DELAY_RESET_CAP) {
                // Forced lock: the stated cap is exhausted — possibly
                // spent by the counted float-close cycles of the overhang
                // jiggle (candy-tetris-021). No untimed fresh window opens
                // here.
                $locked = $this->lockAndSpawn();
                if ($locked->over === true) {
                    return [$locked, null];
                }
                return $this->armGravity($locked);
            }
            return $this->openLockWindow();
        }

        $game = $this->lockAndSpawn();
        if ($game->over === true) {
            return [$game, null];
        }
        return $this->armGravity($game);
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
                if ($moved->lockResets >= self::LOCK_DELAY_RESET_CAP) {
                    // Cap exhausted: the forced lock lands on this
                    // successful grounded input instead of opening an
                    // untimed fresh window (the float-jiggle bypass of
                    // candy-tetris-021 — counted by the float leg below —
                    // ends here).
                    $locked = $moved->lockAndSpawn();
                    if ($locked->over === true) {
                        return [$locked, null];
                    }
                    return $this->armGravity($locked);
                }
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
            // The close-and-re-arm COUNTS as a reset (candy-tetris-021):
            // an overhang nudge loop that only ever closed and re-opened
            // windows was the bypass this cap exists to stop, so budget it
            // like the grounded move-reset does.
            $changes = ['lockWindowGeneration' => null];
            if ($moved->lockResets < self::LOCK_DELAY_RESET_CAP) {
                $changes['lockResets'] = $moved->lockResets + 1;
            }
            return $this->armGravity($moved->mutate($changes));
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
        return $this->armGravity($game);
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
            // A rotation that does not fit is dropped, never teleported
            // into legality (mirrors the dx-skip semantics below).
            $rotated = $piece->rotated(1);
            if ($this->board->fits($rotated) === false) {
                break;
            }
            $piece = $rotated;
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

        // B2B-eligible clear: Tetris, or a T-Spin that actually cleared
        // lines. The guideline pays a nub-pair rotation bonus even without
        // a clear, but a difficult CLEAR is what arms back-to-back — never
        // a quiet lock. A mini shape (including the one-front kick-out of
        // candy-tetris-022) that clears two or more lines is promoted to a
        // full T-Spin (guideline: mini double = full difficulty).
        $tspinFull = $tspin->active === true && ($tspin->mini === false || $count >= 2);
        $b2bEligible = $count >= 4 || ($tspinFull && $count > 0);
        $b2bActive = $this->backToBack && $b2bEligible;

        // B2B bonus: 1.5× multiplier when B2B is active
        $b2bMultiplier = $b2bActive === true ? self::B2B_MULTIPLIER : 1.0;

        // T-Spin scoring: mini gets 100, full T-Spin gets 400 (pre-multiplier)
        $tspinPoints = 0;
        if ($tspin->active === true) {
            $tspinPoints = $tspinFull
                ? TSpin::T_SPIN_POINTS
                : TSpin::T_SPIN_MINI_POINTS;
        }

        // Combo bonus: consecutive line clears multiply; resets on a miss
        $newCombo = $count > 0 ? $this->combo + 1 : 0;
        $comboBonus = $newCombo > 0 ? $newCombo * 10 : 0;

        // Base score from lines cleared (also updates level in $score object)
        $score = $this->score->withLines($count);

        // candy-tetris-092: the game-feel moment the banner machinery was
        // two lines from delivering. A clear and a level-up now announce
        // themselves ON-frame through the pending slot (-025's machinery)
        // — the silent disappearance of rows was the whole complaint.
        $notices = $this->notices;
        if ($count > 0) {
            $notices[] = $count === 1
                ? Lang::t('moment.lines.one')
                : Lang::t('moment.lines.many', ['count' => $count]);
        }
        if ($score->level > $this->score->level) {
            $notices[] = Lang::t('moment.level', ['level' => $score->level]);
        }
        if ($notices !== $this->notices) {
            $notices = array_slice($notices, -2);
        }

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

        // NAME the earned moment the math above just paid. T-spins
        // lead (the difficult input is the story), perfect clears compound
        // with what earned them, and the rarest composites are spelled out
        // rather than flattened into their parts.
        $perfect = $cleared->isPerfectClear();
        $award = match (true) {
            $tspin->active === true && $perfect === true => 'T-SPIN+PC',
            $tspinFull === true                          => 'T-SPIN',
            $tspin->active === true                      => 'T-SPIN MINI',
            $count >= 4 && $perfect === true             => 'TETRIS+PC',
            $count >= 4                                  => 'TETRIS',
            $perfect === true                            => 'PERFECT CLEAR',
            default                                      => null,
        };

        [$newKind, $nextBag] = $this->bag->next();
        $newPiece = self::spawn($newKind);
        if ($cleared->fits($newPiece) === false) {
            return $this->mutate([
                'board' => $cleared,
                'piece' => $newPiece,
                'bag' => $nextBag,
                'score' => $score,
                'over' => true,
                'overReason' => self::OVER_SPAWN_BLOCKED,
                'canHold' => true,
                'combo' => 0,
                'backToBack' => false,
                'lastActionWasRotation' => false,
                'lockWindowGeneration' => null,
                'lockResets' => 0,
                'lastAward' => $award,
                'notices' => $notices,
            ]);
        }

        // After locking, canHold is re-enabled and the lock window state
        // resets for the fresh piece. Back-to-back follows the Tetris
        // Guideline: a placement that clears no lines leaves the chain
        // untouched, and only a clear that is not itself difficult (neither
        // a Tetris nor a full T-Spin) breaks it. Resetting on quiet locks
        // silently dropped the armed state players had earned.
        return $this->mutate([
            'board' => $cleared,
            'piece' => $newPiece,
            'bag' => $nextBag,
            'score' => $score,
            'hold' => $this->hold,
            'canHold' => true,
            'combo' => $newCombo,
            'backToBack' => $b2bEligible === true ? true : ($count > 0 ? false : $this->backToBack),
            'lastActionWasRotation' => false,
            'lockWindowGeneration' => null,
            'lockResets' => 0,
            'lastAward' => $award,
            'notices' => $notices,
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
            [$heldKind, $nextBag] = $this->bag->next();
            $newPiece = self::spawn($heldKind);
            if ($this->board->fits($newPiece) === false) {
                return [$this->mutate([
                    'piece' => $newPiece,
                    'bag' => $nextBag,
                    'hold' => $currentKind,
                    'canHold' => false,
                    'over' => true,
                    'overReason' => self::OVER_SPAWN_BLOCKED,
                    'lastActionWasRotation' => false,
                    'lockWindowGeneration' => null,
                    'lockResets' => 0,
                ]), null];
            }
            $game = $this->mutate([
                'piece' => $newPiece,
                'bag' => $nextBag,
                'hold' => $currentKind,
                'canHold' => false,
                'lastActionWasRotation' => false,
                'lockWindowGeneration' => null,
                'lockResets' => 0,
            ]);
        } else {
            // Swap current piece with held piece. The held piece re-enters
            // through the same centred spawn a fresh piece gets: carrying the
            // replaced piece's column would drop a returned piece wherever the
            // piece it replaces had walked to, so a hold swap would land in a
            // different place depending on input history.
            $swappedPiece = self::spawn($this->hold);
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
        return $this->armGravity($game);
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
     * Receive garbage the way production does (candy-tetris-026): the VS
     * hot path's entry point. With no explicit `$rand` the holes ride THIS
     * game's seeded stream — the run's root seed (candy-tetris-018) thus
     * controls the whole match: pieces, holes, everything. An unseeded
     * global default is no longer reachable from any production path;
     * fresh entropy is captured once at launch instead.
     *
     * @param int $count Number of garbage rows to add
     * @param (\Closure(int): int)|null $rand Override stream (direct test injection)
     */
    public function addGarbage(int $count, ?\Closure $rand = null): self
    {
        return $this->addGarbageRows($count, $rand ?? $this->garbageStream);
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
     * This is the shared mechanics behind {@see self::addGarbage()}; the
     * VS hot path arrived HERE with no `$rand` until -026 — that docblock
     * lied about the seam being test-only, and this line is the honesty
     * fix: production callers use addGarbage, tests may inject either.
     *
     * Garbage rows have exactly one random hole to keep them challenging.
     *
     * @param int $count Number of garbage rows to add
     * @param (\Closure(int): int)|null $rand Hole stream; null = fresh entropy per row
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

        // candy-tetris-092: an incoming garbage attack announces itself —
        // the VS hit was completely silent (GC-006). It rides the pending
        // slot (frame lines, never raw stderr, -025) and survives until
        // the next press; a lethal row still gets its word under the
        // death banner.
        $received = array_slice([...$this->notices, Lang::t('moment.garbage')], -2);

        // Overflow check: the lift pushes rows 0..count-1 off the ceiling.
        // If they carry locked cells the stack has nowhere to go → top-out.
        for ($r = 0; $r < $count; $r++) {
            foreach ($rows[$r] as $cell) {
                if ($cell !== null) {
                    return $this->mutate([
                        'over' => true,
                        'overReason' => self::OVER_TOP_OUT,
                        'canHold' => true,
                        'lockWindowGeneration' => null,
                        'lockResets' => 0,
                        'notices' => $received,
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
                    'overReason' => self::OVER_CRUSHED,
                    'canHold' => true,
                    'lockWindowGeneration' => null,
                    'lockResets' => 0,
                    'notices' => $received,
                ]);
            }
        }

        return $this->mutate([
            'board' => $newBoard,
            'piece' => $piece,
            'lockWindowGeneration' => null,
            'lockResets' => 0,
            'notices' => $received,
        ]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
