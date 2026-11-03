<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Flappy\PipeGenerator;

/**
 * Flappy-Bird as a SugarCraft Model. The world is `width × height`
 * cells; the bird sits at column 8 and the world scrolls past it
 * at a fixed cadence. Pipes spawn on the right edge at intervals
 * controlled by `pipeSpacing` and slide left one column per tick.
 *
 * Keys: space / w / ↑ — flap.  q / esc — quit.  r — restart.
 *
 * The PRNG is injected as a `Closure(int): int` so tests pin a
 * specific pipe layout without touching the runtime.
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
     * Read ceiling (m8): a save larger than this is not a leaderboard, it is
     * garbage or an attack — it is quarantined without being parsed.
     */
    public const MAX_SCORE_FILE_BYTES = 65536;

    /**
     * Legality ceiling (m7), exclusive: scores at or above this cannot have
     * been earned at 30 ticks/s (one point per pipe, pipes spawn every
     * PIPE_EVERY ticks), so a save claiming them is forged and the entry is
     * dropped on read.
     */
    public const SCORE_CEILING = 100000;

    /** @var \Closure(int): int */
    private \Closure $rand;

    private string $highScoreFilePath;

    /** Raw config dir as supplied to the constructor — threaded through
     *  {@see advance()} / {@see withBird()} so the persist target survives
     *  every derived instance instead of silently reverting to the default. */
    private ?string $configDir;

    /**
     * @param list<Pipe> $pipes
     * @param list<int>  $highScores
     */
    public function __construct(
        public readonly Bird $bird,
        public readonly array $pipes,
        public readonly int $score = 0,
        public readonly bool $crashed = false,
        public readonly int $tickIndex = 0,
        public readonly array $highScores = [],
        public readonly bool $newRecord = false,
        ?\Closure $rand = null,
        ?string $configDir = null,
    ) {
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
        $this->configDir = $configDir;
        $this->highScoreFilePath = self::scoreFilePath($configDir);
    }

    /** Single path resolution shared by the constructor and start()
     *  (m32: start() no longer mints a throwaway Game just to learn it). */
    private static function scoreFilePath(?string $configDir): string
    {
        return ($configDir ?? self::getDefaultConfigDir()) . '/' . self::HIGH_SCORE_FILE;
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
            throw new \RuntimeException('Cannot resolve a config directory: no $HOME or $XDG_CONFIG_HOME set');
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
     * @return list<int>
     */
    private static function readScores(string $path): array
    {
        if (is_file($path) === false) {
            return [];
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_SCORE_FILE_BYTES) {
            self::quarantine($path);
            return [];
        }
        try {
            $decoded = AtomicJsonFile::new($path)->read();
        } catch (\JsonException | \RuntimeException) {
            self::quarantine($path);
            return [];
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
        return $scores;
    }

    /** Best-effort rename of a poisoned save aside; if even that fails the
     *  file is simply left in place and ignored (launch still must not
     *  break — every later read goes through the same soft gate). */
    private static function quarantine(string $path): void
    {
        @rename($path, $path . '.corrupt-' . date('Ymd-His'));
    }

    public static function start(?\Closure $rand = null, ?string $configDir = null): self
    {
        $scores = self::readScores(self::scoreFilePath($configDir));

        return new self(
            bird:  Bird::spawn(self::BIRD_COL, self::HEIGHT / 2.0),
            pipes: [],
            highScores: $scores,
            rand:  $rand,
            configDir: $configDir,
        );
    }

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

    /**
     * Return a NEW Game with $score merged into the high-scores list
     * IF it qualifies as a new record. Does NOT write to disk.
     */
    public function withHighScore(int $score): self
    {
        if ($score <= $this->highScore() || $score <= 0) {
            return $this;
        }
        $scores = $this->highScores;
        $scores[] = $score;
        sort($scores, SORT_NUMERIC);
        // Cap the leaderboard: the save file is re-read and re-appended
        // across sessions, so an unbounded list would grow forever. Keep
        // the top MAX_HIGH_SCORES (the tail after an ascending sort).
        if (count($scores) > self::MAX_HIGH_SCORES) {
            $scores = array_values(array_slice($scores, -self::MAX_HIGH_SCORES));
        }
        return new self(
            bird: $this->bird,
            pipes: $this->pipes,
            score: $this->score,
            crashed: $this->crashed,
            tickIndex: $this->tickIndex,
            highScores: $scores,
            newRecord: true,
            rand: $this->rand,
            configDir: $this->configDir,
        );
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
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape
                || ($msg->type === KeyType::Char && $msg->rune === 'q')
                || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this, Cmd::quit()];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'r' && $msg->ctrl === false) {
                // Emit no timer: the stable-id subscription carries over.
                return [self::start($this->rand, $this->configDir), null];
            }
            if ($this->crashed === true) {
                return [$this, null];
            }
            $isFlap = $msg->type === KeyType::Space
                || $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'w');
            if ($isFlap === true) {
                return [$this->withBird($this->bird->flap()), null];
            }
            return [$this, null];
        }
        if ($msg instanceof TickMsg) {
            if ($this->crashed) {
                return [$this, null];
            }
            $next = $this->advance();
            // Persist high score when game ends — off the synchronous update()
            // path. The write itself lives in persistHighScores() (single
            // source of truth); the Cmd wrapper only defers it off the render
            // loop and swallows I/O errors so a full disk can't crash the game.
            if ($next->crashed === true) {
                $updated = $next->withHighScore($next->score);
                if ($updated->newRecord === true) {
                    $persistCmd = static function () use ($updated): ?Msg {
                        try {
                            $updated->persistHighScores();
                        } catch (\Throwable) {
                            // Swallow: full disk / unwritable file must not crash the loop.
                        }
                        return null;
                    };
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
            $pipes[] = PipeGenerator::makePipe($this->score, $this->rand);
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
        $crashed = $bird->row() < 0 || $bird->row() >= self::HEIGHT;
        if ($crashed === false) {
            foreach ($pipes as $p) {
                if ($p->collides($bird->x, $bird->row()) === true) {
                    $crashed = true;
                    break;
                }
            }
        }

        return new self(
            bird: $bird,
            pipes: $pipes,
            score: $score,
            crashed: $crashed,
            tickIndex: $tick,
            highScores: $this->highScores,
            rand: $this->rand,
            configDir: $this->configDir,
        );
    }

    private function withBird(Bird $b): self
    {
        return new self(
            bird: $b,
            pipes: $this->pipes,
            score: $this->score,
            crashed: $this->crashed,
            tickIndex: $this->tickIndex,
            highScores: $this->highScores,
            rand: $this->rand,
            configDir: $this->configDir,
        );
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

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        // The single source of the game cadence (C1/M11). A stable id means
        // Program's per-cycle reconcile keeps exactly one chain alive across
        // restarts, and an empty set on the crashed board cancels it (m31).
        if ($this->crashed === true) {
            return new \SugarCraft\Core\Subscriptions();
        }
        return (new \SugarCraft\Core\Subscriptions())->withTick(
            self::TICK_SUBSCRIPTION,
            self::TICK_SECONDS,
            static fn(): Msg => new TickMsg(),
        );
    }
}
