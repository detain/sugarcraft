<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Flappy-Bird as a SugarCraft Model. The world is `width × height`
 * cells; the bird sits at column 8 and the world scrolls past it
 * at a fixed cadence. Pipes spawn on the right edge every
 * `Game::PIPE_EVERY` ticks and slide left one column per tick.
 *
 * Keys: space / ⏎ / w / ↑ — flap, which also starts the run.  q / esc /
 * Ctrl+C — quit.  r — restart at any moment, after a crash or mid-flight,
 * landing on the ready card (M19).  Esc is gated to the plain key (n4,
 * step 13): Ctrl+C is the only chord the model reads. Legacy terminals
 * send Ctrl+[ as a bare 0x1B byte — byte-identical to Esc, so the terminal
 * itself cannot separate the two and no gate can either.
 *
 * Playability (M10/M18, lib-reviews-1102): the board opens in a READY phase —
 * the bird hovers at spawn and the world does not scroll until the first
 * flap, so a launch never drops you straight into the floor. Quitting
 * mid-flight with a qualifying score settles it to the leaderboard before
 * the process exits (M18: previously only a crash could ever persist).
 *
 * Terminal posture (M6-M8, lib-reviews-1102): the frame needs a 62x21
 * viewport; a smaller one gets a plain notice screen and the world freezes
 * (no ticks). On a no-TTY / TERM=dumb profile (ColorProfile::NoTty) the app
 * refuses to play instead of emitting escape codes nobody can render; the
 * ASCII tier draws plain glyphs.
 *
 * Assistive technology (flappy-26): the view is a full-canvas repaint on
 * the alternate screen — no stable text stream exists for a screen reader
 * to follow; the notice/refusal copy is the only AT-tractable output, and
 * there is deliberately no high-contrast posture and no pause key. Mirrors
 * the README "Assistive technology" subsection.
 *
 * The PRNG is injected as a `Closure(int $maxInclusive): int` so tests
 * pin a specific pipe layout without touching the runtime — the parameter
 * name carries the inclusive-endpoint law the closure must honour.
 *
 * The top edge is a wall — touching row < 0 crashes immediately,
 * same as the floor (row >= HEIGHT).
 */
final class Game implements Model
{
    public const WIDTH       = 60;
    public const HEIGHT      = 18;
    public const BIRD_COL    = 8;
    public const PIPE_EVERY  = 18;         // cells between successive pipes

    /**
     * Smallest terminal the framed world plus its status line fits without
     * wrapping (M26): the rounded border costs 2 columns, and the board plus
     * one chrome row costs 3 — a narrower viewport gets the notice screen
     * instead of a corrupted wrap.
     */
    public const MIN_COLS = self::WIDTH + 2;   // 62
    public const MIN_ROWS = self::HEIGHT + 3;  // 21

    /**
     * Wall-clock tick period. Derived from {@see Bird::TICKS_PER_SEC} so the
     * timer and the projectile's dt can never drift apart (M11): the model
     * integrates at 1/30 s per tick, so the timer fires at 1/30 s. (The old
     * magic `0.033` was a hand-rounded duplicate of the same number — the
     * cadence moves ~1% slower now, which is the DRIFT being removed, not a
     * tuning change: model time was always 1/30.)
     */
    public const TICK_SECONDS = 1 / Bird::TICKS_PER_SEC;

    /** Stable subscription id — Program's reconcile keys on it, so a restart
     *  mid-flight reuses the one running chain instead of arming a second
     *  (C1: the old per-update `Cmd::tick` re-arm multiplied tick chains). */
    public const TICK_SUBSCRIPTION = 'game-tick';

    private const HIGH_SCORE_FILE = '.candy-flappy/scores.json';

    /** Upper bound on the retained high-score leaderboard. */
    public const MAX_HIGH_SCORES = 10;

    /**
     * flappy-78 (finding -068): every death used to print the same 'splat'.
     * advance() now records WHY the run ended so the hint can name the
     * cause; the values double as the ui.why_* lang-key suffixes the
     * renderer selects (literal keys at every call site — no dynamic
     * Lang::t composition).
     */
    public const CRASH_CEILING = 'ceiling';

    public const CRASH_GROUND = 'ground';

    public const CRASH_PIPE = 'pipe';

    /**
     * Read ceiling (m8): a save larger than this is not a leaderboard, it is
     * garbage or an attack — it is quarantined without being parsed.
     */
    public const MAX_SCORE_FILE_BYTES = 65536;

    /**
     * Legality ceiling (m7), exclusive — and enforced SYMMETRICALLY: the
     * write path ({@see withHighScore()}) refuses scores at or above it, so
     * the read filter below can never silently drop a score this program
     * banked; it only rejects tampered files and cross-build strays.
     *
     * WHY the premise is a stated bound, not "cannot have been earned": at
     * 30 ticks/s with one point per pipe, 100 000 points is roughly 16.7
     * hours of flawless non-stop play — and nothing in this build ends or
     * caps a session, so an over-ceiling value is not physically impossible,
     * merely outside the declared score space of the board. A player who
     * somehow crosses the line still sees their score on the crash card;
     * the leaderboard, whose file contract needs a bound to stay parseable,
     * declines the entry. SugarCraft addition; no upstream parity.
     */
    public const SCORE_CEILING = 100000;

    /** @var \Closure(int $maxInclusive): int */
    private readonly \Closure $rand;

    /** Where the leaderboard persists (derived from configDir, write-once). */
    private readonly string $highScoreFilePath;

    /** Raw config dir as supplied to the constructor — threaded through
     *  every derived instance via {@see mutate()} so the persist target
     *  survives instead of silently reverting to the default. */
    private readonly ?string $configDir;

    /**
     * @param list<Pipe> $pipes
     * @param list<int>  $highScores
     */
    public function __construct(
        public readonly Bird $bird,
        public readonly array $pipes,
        public readonly int $score = 0,
        public readonly bool $crashed = false,
        /**
         * flappy-78: one of Game::CRASH_* when crashed, null while alive or
         * on boards that assert only the boolean (the hint then falls back
         * to the generic splat).
         */
        public readonly ?string $crashCause = null,
        public readonly int $tickIndex = 0,
        public readonly array $highScores = [],
        public readonly bool $newRecord = false,
        /** False until the first flap (M10 ready phase). */
        public readonly bool $started = false,
        /**
         * flappy-20 (finding -022): the terminal window lost focus (DECSET
         * 1004 BlurMsg). A 30 fps gravity game dies in about a second of
         * alt-tab, so a blurred board freezes: subscriptions stand down and
         * ticks arriving in the race window are ignored. FocusGainedMsg
         * unfreezes with no tick debt — the world resumes exactly where it
         * stopped, it does not replay the time away.
         */
        public readonly bool $blurred = false,
        ?\Closure $rand = null,
        ?string $configDir = null,
        /** Live viewport (M6): seeded at MIN, replaced by WindowSizeMsg. */
        public readonly int $frameCols = self::MIN_COLS,
        public readonly int $frameRows = self::MIN_ROWS,
        /** Null until the Program reports the detected profile (M7/M8). */
        public readonly ?ColorProfile $colorProfile = null,
        /**
         * flappy-18 (finding -018): basename of the forensic copy made when
         * launch found the leaderboard unreadable, or null when the save was
         * fine. The READY board says it out loud once; the first flap ends
         * its shift (the renderer only asks while unstarted), and a restart
         * does not carry it — the announcement belongs to the launch, not
         * to every board after it.
         */
        public readonly ?string $quarantinedSave = null,
        /**
         * flappy-38: the run this model already settled into the list (a q
         * or r merge), so the process-boundary settle cannot merge the same
         * score a second time. True top-N made the old best-only gate's
         * accidental double-settle protection vanish — this replaces it on
         * purpose.
         */
        public readonly ?int $settledScore = null,
    ) {
        $this->rand = $rand ?? static fn(int $maxInclusive): int => random_int(0, $maxInclusive);
        $this->configDir = $configDir;
        $this->highScoreFilePath = self::scoreFilePath($configDir);
    }

    /** Single path resolution shared by the constructor and start()
     *  (m32: start() no longer mints a throwaway Game just to learn it). */
    private static function scoreFilePath(?string $configDir): string
    {
        // rtrim: a caller-supplied trailing slash must not mint '//' segments (L6).
        return rtrim($configDir ?? self::getDefaultConfigDir(), '/') . '/' . self::HIGH_SCORE_FILE;
    }

    private static function getDefaultConfigDir(): string
    {
        // Prefer XDG_CONFIG_HOME, then HOME, then fail closed.
        $xdg = getenv('XDG_CONFIG_HOME');
        if ($xdg !== false && $xdg !== '') {
            return $xdg;
        }
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        if ($home === '') {
            // flappy-71 (finding -061): remedy copy, not just diagnosis —
            // and Lang-routed (bin sets the locale before the first Game is
            // built, so a config-fatal already speaks the user's language;
            // the lookup's own default is en, so this is throw-safe).
            throw new \RuntimeException(Lang::t('config.no_dir'));
        }
        return $home . '/.config';
    }

    /**
     * Load the leaderboard, failing SOFT (M3, lib-reviews-1102).
     *
     * A corrupt, unreadable, or oversized save must never brick launch —
     * the old path threw out of start() and bin/candy-flappy died with 255.
     * Any such file is QUARANTINED aside (`scores.json.corrupt-<ts>`, kept
     * for forensics, never re-read) and the board starts empty; the decode
     * itself rides sugar-core's AtomicJsonFile::read() (Json::decodeArray
     * SSOT underneath). Entries are legality-clamped on the way in (m7) and
     * the raw size is capped pre-parse (m8).
     *
     * @return array{0: list<int>, 1: ?string} the board plus, when a poisoned
     *         save was moved aside, the basename of the forensic artifact
     */
    private static function readScores(string $path): array
    {
        if (is_file($path) === false) {
            return [[], null];
        }
        // n7 (accepted, deliberate): the filesize-then-read pair is a TOCTOU
        // window — a local writer could grow the file past the cap between
        // the probe and the read. It is reachable only with write access to
        // the user's OWN config dir (0700/0600 territory), and the parse
        // stays bounded anyway: AtomicJsonFile::read() decodes through
        // Json::decodeArray fail-loud, and the envelope cap below is the
        // ordinary-size guard, not a security boundary. Accepted as-is.
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_SCORE_FILE_BYTES) {
            return [[], self::quarantine($path)];
        }
        try {
            $decoded = AtomicJsonFile::new($path)->read();
        } catch (\JsonException | \RuntimeException) {
            return [[], self::quarantine($path)];
        }
        $scores = array_values(array_filter(
            $decoded,
            static fn(mixed $s): bool => is_int($s) && $s > 0 && $s < self::SCORE_CEILING,
        ));
        sort($scores, SORT_NUMERIC);
        if (count($scores) > self::MAX_HIGH_SCORES) {
            $scores = array_values(array_slice($scores, -self::MAX_HIGH_SCORES));
        }
        /** @var list<int> */
        return [$scores, null];
    }

    /**
     * Best-effort move of a poisoned save aside; if that fails the file is
     * simply left in place and ignored (launch still must not break — every
     * later read goes through the same soft gate).
     *
     * n6 (step 14): a bare rename() silently REPLACES a same-second
     * `.corrupt-<ts>` artifact, so a save that keeps getting re-poisoned
     * between launches could destroy the older forensic copies the readScores
     * docblock promises to keep. The cure refuses to overwrite: link() is an
     * atomic create-new-name (it fails with EEXIST if the target already
     * exists), then unlink moves the original out. A collision walks a
     * bounded `-%06d` counter so every quarantine generation survives under
     * its own name; any non-collision failure (filesystem without hard links,
     * vanished source) gets the plain rename fallback — same fail-soft
     * posture as before, never an exception.
     *
     * flappy-18 (finding -018): the move now reports what it archived, so
     * the first ready frame can say it out loud instead of letting the
     * forensic copy sit invisible on disk.
     *
     * @return ?string basename of the archived artifact, null when nothing
     *                was actually moved aside
     */
    private static function quarantine(string $path): ?string
    {
        $base = $path . '.corrupt-' . date('Ymd-His');
        $target = $base;
        for ($attempt = 1; $attempt <= 100; $attempt++) {
            if (@link($path, $target) === true) {
                @unlink($path);
                return \basename($target);
            }
            if (is_file($target) === false) {
                return @rename($path, $target) === true ? \basename($target) : null;
            }
            $target = sprintf('%s-%06d', $base, $attempt);
        }
        // 100 artifacts sharing one timestamp second is pathological; leave
        // the file in place (ignored by every read) rather than loop harder.
        return null;
    }

    public static function start(?\Closure $rand = null, ?string $configDir = null): self
    {
        [$scores, $archived] = self::readScores(self::scoreFilePath($configDir));

        return new self(
            bird:  Bird::spawn(self::BIRD_COL, self::HEIGHT / 2.0),
            pipes: [],
            highScores: $scores,
            rand:  $rand,
            configDir: $configDir,
            quarantinedSave: $archived,
        );
    }

    /**
     * @internal Test seam only (flappy-45, finding -045): lets the suite pin
     * PRNG identity through start()/mutate()/resize carry-through (GameTest).
     * Publishing the game's RNG as API would advertise "re-draw with the
     * game's PRNG" — it is not: the injected closure is a private detail of
     * deterministic pipe placement.
     */
    public function rand(): \Closure
    {
        return $this->rand;
    }

    /**
     * @return list<int>
     */
    public function highScores(): array
    {
        return $this->highScores;
    }

    public function highScore(): int
    {
        if ($this->highScores === []) {
            return 0;
        }
        return max($this->highScores);
    }

    /** True when the live viewport cannot hold the 62x21 frame (M6). */
    public function tooSmall(): bool
    {
        return $this->frameCols < self::MIN_COLS || $this->frameRows < self::MIN_ROWS;
    }

    /**
     * flappy-06 (finding -006): the field list lives HERE once. The eight
     * hand-copied `new self(...)` sites could silently drop a field added
     * later — the m30 frame-reset bug (restart tripping through start() and
     * losing the live viewport) is exactly that class of defect. Every
     * transition builder now routes through mutate() (canonical
     * `candy-tetris` Game::mutate pattern): the `??` arms inherit, and the
     * nullable fields use `array_key_exists` so an explicit null can still be
     * SET. The AGENTS.md sentinel-pair variant is deliberately not adopted:
     * adding `bool $XSet` params would widen the public constructor beyond a
     * pure structural change, and this file's nulls are load-bearing values
     * (colorProfile null = not yet reported, configDir null = derive), so the
     * explicit-null arms carry the same law without the signature churn.
     *
     * `start()` and the r-arm's fresh board keep an explicit constructor on
     * purpose: they RESET state, they do not carry it.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(
            bird: $changes['bird'] ?? $this->bird,
            pipes: $changes['pipes'] ?? $this->pipes,
            score: $changes['score'] ?? $this->score,
            crashed: $changes['crashed'] ?? $this->crashed,
            crashCause: $changes['crashCause'] ?? $this->crashCause,
            tickIndex: $changes['tickIndex'] ?? $this->tickIndex,
            highScores: $changes['highScores'] ?? $this->highScores,
            newRecord: $changes['newRecord'] ?? $this->newRecord,
            started: $changes['started'] ?? $this->started,
            blurred: $changes['blurred'] ?? $this->blurred,
            // The live PRNG cell rides the lineage (tetris re-attaches its
            // stream the same way): a mutation must never re-roll the dice.
            rand: $this->rand,
            configDir: array_key_exists('configDir', $changes) ? $changes['configDir'] : $this->configDir,
            frameCols: $changes['frameCols'] ?? $this->frameCols,
            frameRows: $changes['frameRows'] ?? $this->frameRows,
            colorProfile: array_key_exists('colorProfile', $changes) ? $changes['colorProfile'] : $this->colorProfile,
            // A resize on the ready board must not swallow the launch
            // announcement (flappy-18); the flap that clears the ready
            // phase ends its shift in the renderer, not here.
            quarantinedSave: $changes['quarantinedSave'] ?? $this->quarantinedSave,
            settledScore: $changes['settledScore'] ?? $this->settledScore,
        );
    }

    /** A new viewport from a WindowSizeMsg — the model never re-frames itself. */
    private function withFrameSize(int $cols, int $rows): self
    {
        return $this->mutate(['frameCols' => $cols, 'frameRows' => $rows]);
    }

    /** The Program's detected colour capability (M7/M8). */
    private function withColorProfile(ColorProfile $profile): self
    {
        return $this->mutate(['colorProfile' => $profile]);
    }

    /**
     * Return a NEW Game with $score merged into the high-scores list
     * IF it qualifies as a new record. Does NOT write to disk.
     */
    /**
     * Merge a finished run into the leaderboard.
     *
     * flappy-38 (finding -012): the true top-N law — a run earns a slot
     * when the ladder still has room OR it beats the bottom entry. The old
     * best-only gate (score must beat the MAX) kept the list a pure
     * best-progression log: one inherited or forged 99 999 (a legal value
     * under SCORE_CEILING) permanently write-locked every future score out
     * of the file, and the player's only recovery was deleting it. The
     * ladder now holds ten slots and fills them, self-healing upward
     * around inflated entries.
     *
     * `newRecord` keeps its narrow, loud meaning: the 🏆 shout is for a run
     * that beats the BEST, not merely the bottom. The persist trigger is
     * IDENTITY — a returned `$this` means the list did not change and there
     * is nothing to write; a new instance means it did, shout or no shout.
     */
    public function withHighScore(int $score): self
    {
        if ($score <= 0) {
            return $this;
        }
        // flappy-77 (finding -067): write-side ceiling — see SCORE_CEILING.
        if ($score >= self::SCORE_CEILING) {
            return $this;
        }
        $scores = $this->highScores;
        // count >= MAX implies a non-empty list, so min() is guard-safe by
        // short-circuit order.
        if (count($scores) >= self::MAX_HIGH_SCORES && $score <= min($scores)) {
            return $this;
        }
        $scores[] = $score;
        sort($scores, SORT_NUMERIC);
        // Cap the leaderboard: the save file is re-read and re-appended
        // across sessions, so an unbounded list would grow forever. Keep
        // the top MAX_HIGH_SCORES (the tail after an ascending sort).
        if (count($scores) > self::MAX_HIGH_SCORES) {
            $scores = array_values(array_slice($scores, -self::MAX_HIGH_SCORES));
        }
        return $this->mutate(['highScores' => $scores, 'newRecord' => $score > $this->highScore()]);
    }

    /**
     * Publish the leaderboard through sugar-core's AtomicJsonFile (M4):
     * flock-serialised temp+rename (no torn writes on ENOSPC/kill), an
     * explicit 0600 publish mode (m9: the bare write landed 0664/0644 under
     * common umasks), and the 0700 parent dir creation the old hand-rolled
     * version did. A short write used to slip past the old `=== false`
     * guard entirely (file_put_contents returns bytes, not false).
     */
    private function persistHighScores(): void
    {
        AtomicJsonFile::new($this->highScoreFilePath)
            ->withPermissions(0600)
            ->write($this->highScores);
    }

    public function init(): ?\Closure
    {
        // C1: no timer is armed here. The whole cadence lives in
        // subscriptions(), reconciled by the Program on every update cycle,
        // so exactly one 'game-tick' chain can ever be running.
        return null;
    }

    public function update(Msg $msg): array
    {
        // M6: the viewport is data, not a re-render side effect. Program
        // sends WindowSizeMsg at startup and on every SIGWINCH.
        if ($msg instanceof WindowSizeMsg) {
            if ($msg->cols === $this->frameCols && $msg->rows === $this->frameRows) {
                return [$this, null];
            }
            return [$this->withFrameSize($msg->cols, $msg->rows), null];
        }
        // M7/M8: the detected colour capability arrives once at startup.
        if ($msg instanceof ColorProfileMsg) {
            if ($msg->profile === $this->colorProfile) {
                return [$this, null];
            }
            // flappy-02 (finding -002): a NoTty frame is unplayable by
            // definition — the refusal is the final frame. Quit through the
            // Model so Program's full teardown runs (framerate timer closed,
            // terminal restored) instead of baking the player into a game
            // that only Ctrl+C ends.
            if ($msg->profile === ColorProfile::NoTty) {
                return [$this->withColorProfile($msg->profile), Cmd::quit()];
            }

            return [$this->withColorProfile($msg->profile), null];
        }
        // flappy-09 (finding -008): click/tap flaps. MouseClickMsg is the
        // press-only type (sugar-core splits press from move/release), so
        // this is a tap, never drag noise — minesweeper precedent. Same
        // posture law as the keyboard flap: a dead board or a notice screen
        // swallows the press, anywhere else the first click starts the run.
        // flappy-20 (finding -022): the focus pair, plain state — blur
        // freezes (subscriptions stand down, in-flight ticks are dropped),
        // focus resumes with no catch-up and no debt.
        if ($msg instanceof BlurMsg) {
            if ($this->blurred === true) {
                return [$this, null];
            }
            return [$this->mutate(['blurred' => true]), null];
        }
        if ($msg instanceof FocusGainedMsg) {
            if ($this->blurred === false) {
                return [$this, null];
            }
            return [$this->mutate(['blurred' => false]), null];
        }
        if ($msg instanceof MouseClickMsg) {
            if ($this->crashed === true || $this->tooSmall() === true) {
                return [$this, null];
            }
            return [$this->withBird($this->bird->flap())->withStarted(), null];
        }
        if ($msg instanceof KeyMsg) {
            // m4: a Shift-ed letter arrives as the uppercase rune; the game must not
            // silently ignore it.
            $rune = strtolower($msg->rune);
            // m1 (r2): Ctrl+C is the ONLY chord action; plain keys behind
            // chords (Ctrl+Q) must not fire them. Ctrl+W/Ctrl+Enter are text
            // editor idioms — a flap on a chord would be a surprise.
            // n4 (step 13): the Escape leg lacked the ctrl gate its q/r
            // siblings carry; under kitty extended reporting Ctrl+Esc
            // arrives as Escape+ctrl and must not quit. Legacy Ctrl+[ is
            // byte-identical to Esc (bare 0x1B) and still quits — the
            // decoder cannot separate the two.
            if (($msg->type === KeyType::Escape && $msg->ctrl === false)
                || ($msg->type === KeyType::Char && $rune === 'q' && $msg->ctrl === false)
                || ($msg->ctrl && $msg->rune === 'c')) {
                // M18: quitting mid-flight used to discard a qualifying
                // score entirely — only a crash could persist. Settle first,
                // then quit, in one batched Cmd.
                if ($this->crashed === false && $this->started === true && $this->score > 0) {
                    $merged = $this->withHighScore($this->score);
                    // flappy-38: identity is the persist trigger — any
                    // top-N slot earned writes, not just best-beating runs.
                    // The settledScore stamp marks this run as banked so
                    // the bin-level settle will not merge it a second time.
                    if ($merged !== $this) {
                        $settled = $merged->mutate(['settledScore' => $this->score]);
                        return [$settled, Cmd::batch($settled->persistCmd(), Cmd::quit())];
                    }
                }
                return [$this, Cmd::quit()];
            }
            if ($msg->type === KeyType::Char && $rune === 'r' && $msg->ctrl === false) {
                // Emit no timer: the stable-id subscription carries over.
                // m30: restart is a fresh board IN THE SAME viewport — the
                // old trip through start() silently reset the frame to the
                // default 62x21, so a resized terminal would snap back.
                // flappy-04 (finding -004): a mid-flight restart with a
                // qualifying score settles it like the settling quit does —
                // "you do not have to die to keep your best" was only half
                // true (crash and q persisted, r discarded a live record in
                // silence). Same merge check as the q arm above; the fresh
                // board carries the MERGED list and the persist rides a Cmd
                // off the update path — but no quit, the game continues.
                if ($this->crashed === false && $this->started === true && $this->score > 0) {
                    $merged = $this->withHighScore($this->score);
                    if ($merged !== $this) {  // flappy-38: identity trigger
                        return [new self(
                            bird: Bird::spawn(self::BIRD_COL, self::HEIGHT / 2.0),
                            pipes: [],
                            highScores: $merged->highScores,
                            rand: $this->rand,
                            configDir: $this->configDir,
                            frameCols: $this->frameCols,
                            frameRows: $this->frameRows,
                            colorProfile: $this->colorProfile,
                        ), $merged->persistCmd()];
                    }
                }
                // flappy-40 (finding -056): the plain restart reuses the
                // in-memory leaderboard — a synchronous readScores() inside
                // the key handler put disk latency (NFS $HOME stutter) on
                // the input path, and between-board external edits are
                // indistinguishable in-game anyway. The persist leg was
                // already deferred through a Cmd; the read leg follows.
                return [new self(
                    bird: Bird::spawn(self::BIRD_COL, self::HEIGHT / 2.0),
                    pipes: [],
                    highScores: $this->highScores,
                    rand: $this->rand,
                    configDir: $this->configDir,
                    frameCols: $this->frameCols,
                    frameRows: $this->frameRows,
                    colorProfile: $this->colorProfile,
                ), null];
            }
            if ($this->crashed === true) {
                return [$this, null];
            }
            // flappy-69 (finding -069): the notice screen hides the world —
            // a stray space behind it must neither start the run nor bank a
            // flap the bird will spend the instant the viewport recovers.
            // Flaps are inert while too-small (q/r stay live on purpose:
            // quitting and restarting are correct on any posture).
            if ($this->tooSmall() === true) {
                return [$this, null];
            }
            $isFlap = $msg->ctrl === false      // m1 (r2): chords are inert (see quit arm)
                && ($msg->type === KeyType::Space
                || $msg->type === KeyType::Enter   // M20: Enter flaps too
                || $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $rune === 'w'));
            if ($isFlap === true) {
                return [$this->withBird($this->bird->flap())->withStarted(), null];
            }
            return [$this, null];
        }
        if ($msg instanceof TickMsg) {
            if ($this->crashed) {
                return [$this, null];
            }
            // flappy-20: a tick that raced in before Program's reconcile
            // cancelled the chain must not step a frozen (blurred) board.
            if ($this->blurred === true) {
                return [$this, null];
            }
            if ($this->started === false) {
                // M10: the ready board is frozen — no gravity, no scroll,
                // no pipe spawn until the first flap.
                return [$this, null];
            }
            $next = $this->advance();
            // Persist high score when game ends — off the synchronous update()
            // path. The write itself lives in persistHighScores() (single
            // source of truth); the Cmd wrapper only defers it off the render
            // loop and swallows I/O errors so a full disk can't crash the game.
            if ($next->crashed === true) {
                $updated = $next->withHighScore($next->score);
                if ($updated !== $next) {  // flappy-38: identity trigger
                    $persistCmd = $updated->persistCmd();
                    // C1: persist only — the crashed model's subscriptions()
                    // is empty, so Program's reconcile cancels the tick chain
                    // right here (m31: no more idle re-arming after death).
                    return [$updated, $persistCmd];
                }
            }
            // C1: no re-arm here either — the subscription owns the cadence.
            return [$next, null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    /**
     * One tick of the world: gravity, pipe slide + spawn cadence,
     * collision, scoring. Mirrors kbrgl/flapioca `Model.Frame`
     * (internal/model.go) — upstream ran everything in that one
     * Bubble-Tea update leg.
     */
    private function advance(): self
    {
        $bird = $this->bird->tick();

        // Slide every pipe one column left, drop those off-screen.
        $pipes = [];
        foreach ($this->pipes as $p) {
            $next = $p->tick();
            if ($next->isOffScreen() === false) {
                $pipes[] = $next;
            }
        }
        // Spawn a new pipe every PIPE_EVERY ticks.
        $tick = $this->tickIndex + 1;
        if ($tick % self::PIPE_EVERY === 0) {
            $pipes[] = PipeGenerator::pipe($this->score, $this->rand);
        }

        // Score = number of pipes the bird passed this tick. Each pipe slid
        // one column left (x+1 → x), so it just cleared the bird exactly when
        // it lands on the column immediately left of the bird — x == BIRD_COL-1.
        // (The earlier guard compared x+1 against BIRD_COL instead of BIRD_COL-1,
        // an off-by-one that made the condition unsatisfiable, so the score was
        // pinned at 0 for the entire game — and no high score could ever persist.)
        $score = $this->score;
        foreach ($pipes as $p) {
            if ($p->x === self::BIRD_COL - 1) {
                $score++;
            }
        }

        // Collision: bird hits a pipe, hits the floor, or hits the top wall.
        // M27 (comment truth only — behaviour is the game spec): PHP's round()
        // rounds half AWAY from zero, so y = -0.5 rounds to -1 and CRASHES;
        // safe is y > -0.5 strictly (row() >= 0).
        // flappy-78 (finding -068): the cause is stored, not just the fact —
        // a ceiling death deserves different coaching than a floor splat,
        // and most first deaths are the ceiling at ~1 s (mash-starters).
        $birdRow = $bird->row();
        $crashCause = null;
        if ($birdRow >= self::HEIGHT) {
            $crashCause = self::CRASH_GROUND;
        } elseif ($birdRow < 0) {
            $crashCause = self::CRASH_CEILING;
        } else {
            foreach ($pipes as $p) {
                if ($p->collides($bird->x, $birdRow) === true) {
                    $crashCause = self::CRASH_PIPE;
                    break;
                }
            }
        }

        return $this->mutate([
            'bird' => $bird,
            'pipes' => $pipes,
            'score' => $score,
            'crashed' => $crashCause !== null,
            'crashCause' => $crashCause,
            'tickIndex' => $tick,
        ]);
    }

    private function withBird(Bird $b): self
    {
        return $this->mutate(['bird' => $b]);
    }

    /** First flap arms the run (M10): the ready board becomes live. */
    private function withStarted(): self
    {
        if ($this->started === true) {
            return $this;
        }
        return $this->mutate(['started' => true]);
    }

    /**
     * Process-boundary settle used by bin/candy-flappy after Program::run()
     * returns. `q`/Esc already settle inside update() via a batched Cmd; the
     * gap this closes is a signal death (Ctrl+C), where no KeyMsg ever ran
     * and a live qualifying score would be lost. Idempotent: the settledScore
     * stamp means a repeat call after a banking one is a no-op. A failed
     * final save is reported there and then — see the catch — this method
     * never throws and never touches the exit status. Returns the settled
     * model: the banked run with its new-record flag when this call landed a
     * fresh record on disk (flappy-70's ack line reads it), otherwise the
     * unchanged instance.
     */
    public function settleOnExit(): Game
    {
        if ($this->crashed === true || $this->started === false || $this->score <= 0) {
            return $this;
        }
        // flappy-38: the run was already banked inside update() (settling
        // q/r) — merging the same score again would double-list it.
        if ($this->settledScore === $this->score) {
            return $this;
        }
        $merged = $this->withHighScore($this->score);
        if ($merged === $this) {
            // flappy-38: identity is the trigger — the list unchanged means
            // nothing earned a slot this run.
            return $this;
        }
        $banked = false;
        try {
            $merged->persistHighScores();
            $banked = true;
        } catch (\Throwable) {
            // flappy-17 (finding -017): PHP-03's split. The in-loop swallow
            // in persistCmd() stays right — a full disk must not kill a live
            // game. Here the loop is over and nothing is left to protect, so
            // silence would be a lie: say the score did not land. One fixed
            // line, no exception text (the message carries paths), no trace;
            // stderr is not the game canvas so it stays raw English like the
            // pre-flight refusal — deliberately not Lang-routed.
            \fwrite(\STDERR, "candy-flappy: could not save the leaderboard\n");
        }
        // flappy-70 (finding -070): hand back the settled model so the exit
        // site can acknowledge a BANKED new best — but only when the write
        // actually landed. The settledScore stamp keeps the call idempotent:
        // a repeat settle sees the run as already banked. A failed save
        // returns $this, so a record that never reached disk never earns
        // the ack line.
        return $banked ? $merged->mutate(['settledScore' => $this->score]) : $this;
    }

    /** Deferred leaderboard write (M18): the crash path and the settling
     *  quit path share one Cmd shape — run the persist, swallow I/O so a
     *  full disk cannot kill the loop, hand back no Msg. */
    private function persistCmd(): \Closure
    {
        $g = $this;
        return static function () use ($g): ?Msg {
            try {
                $g->persistHighScores();
            } catch (\Throwable) {
                // Swallow: full disk / unwritable file must not crash the loop.
            }
            return null;
        };
    }

    /** Test helper — apply N ticks deterministically. */
    public function tickN(int $n): self
    {
        $g = $this;
        for ($i = 0; $i < $n; $i++) {
            $g = $g->advance();
        }
        return $g;
    }

    /**
     * flappy-87 (finding -079): the live set is a constant — stable id,
     * constant period, constant Msg factory — so it is built ONCE per
     * process and every reconcile compares memo identity instead of
     * minting a throwaway object per frame. Sharing it across Game
     * instances is safe for the same reason the id law makes restarts
     * safe: nothing downstream mutates a Subscriptions object, Program's
     * reconcile reads it (M6/M7 re-arm law: the memo still answers freshly
     * on every cycle, and stand-downs keep returning null).
     */
    private static ?Subscriptions $liveSubscriptions = null;

    public function subscriptions(): ?Subscriptions
    {
        // The single source of the game cadence (C1/M11). A stable id means
        // Program's per-cycle reconcile keeps exactly one chain alive across
        // restarts, and a stand-down cancels it (m31: no idle chains after
        // death). The world also stands down when it cannot be seen or drawn
        // (M6/M7) — a too-small viewport gets a notice, a NoTty profile gets
        // the refusal — and flappy-19 (finding -019) extends the file's own
        // "nothing to see, nothing to simulate" law to the READY card: a
        // frozen-by-design board that drops every TickMsg on the floor must
        // not burn a 30 Hz timer and its redraws just to hover. All four
        // stand-downs return null — the allocation-free shape (minesweeper
        // Game::subscriptions precedent): Program reconciles "no
        // subscriptions" exactly like the old empty set, so the cancel
        // behaviour is unchanged and the null doubles as the dispatch-count
        // pin — with no subscription there is no source a TickMsg could ever
        // arrive from pre-start.
        if ($this->crashed === true || $this->tooSmall() === true
            || $this->colorProfile === ColorProfile::NoTty || $this->started === false
            || $this->blurred === true) {
            return null;
        }
        return self::$liveSubscriptions ??= (new Subscriptions())->withTick(
            self::TICK_SUBSCRIPTION,
            self::TICK_SECONDS,
            static fn(): Msg => new TickMsg(),
        );
    }
}
