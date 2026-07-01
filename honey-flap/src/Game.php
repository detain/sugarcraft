<?php

declare(strict_types=1);

namespace SugarCraft\Flap;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Flap\PipeGenerator;

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

    private const HIGH_SCORE_FILE = '.honey-flap/scores.json';

    /** @var \Closure(int): int */
    private \Closure $rand;

    private string $highScoreFilePath;

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
        $this->highScoreFilePath = ($configDir ?? $this->getDefaultConfigDir()) . '/' . self::HIGH_SCORE_FILE;
    }

    private function getDefaultConfigDir(): string
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
     * @return list<int>
     */
    private static function readScores(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read high score file: {$path}");
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid high score file format: {$path}");
        }
        $scores = array_values(array_filter($decoded, 'is_int'));
        sort($scores, SORT_NUMERIC);
        /** @var list<int> */
        return $scores;
    }

    public static function start(?\Closure $rand = null, ?string $configDir = null): self
    {
        // Derive configDir the same way the constructor does (first instance, no scores yet).
        $tmp = new self(
            bird:  Bird::spawn(self::BIRD_COL, self::HEIGHT / 2.0),
            pipes: [],
            highScores: [],
            rand:  $rand,
            configDir: $configDir,
        );
        $path = $tmp->highScoreFilePath;
        $scores = self::readScores($path);

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
        return new self(
            bird: $this->bird,
            pipes: $this->pipes,
            score: $this->score,
            crashed: $this->crashed,
            tickIndex: $this->tickIndex,
            highScores: $scores,
            newRecord: true,
            rand: $this->rand,
            configDir: null,
        );
    }

    private function persistHighScores(): void
    {
        $dir = dirname($this->highScoreFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create high score directory: {$dir}");
        }
        $json = json_encode($this->highScores, JSON_PRETTY_PRINT);
        $written = @file_put_contents($this->highScoreFilePath, $json);
        if ($written === false) {
            throw new \RuntimeException("Cannot write high score file: {$this->highScoreFilePath}");
        }
    }

    public function init(): ?\Closure
    {
        return Cmd::tick(0.033, static fn() => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape
                || ($msg->type === KeyType::Char && $msg->rune === 'q')
                || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this, Cmd::quit()];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'r') {
                return [self::start($this->rand), $this->init()];
            }
            if ($this->crashed) {
                return [$this, null];
            }
            $isFlap = $msg->type === KeyType::Space
                || $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'w');
            if ($isFlap) {
                return [$this->withBird($this->bird->flap()), null];
            }
            return [$this, null];
        }
        if ($msg instanceof TickMsg) {
            if ($this->crashed) {
                return [$this, null];
            }
            $next = $this->advance();
            // Persist high score when game ends — off the synchronous update() path.
            if ($next->crashed) {
                $updated = $next->withHighScore($next->score);
                if ($updated->newRecord) {
                    $path = $next->highScoreFilePath;
                    $scores = $updated->highScores;
                    $persistCmd = static function () use ($path, $scores): ?Msg {
                        try {
                            $dir = dirname($path);
                            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                                return null;
                            }
                            $json = json_encode($scores, JSON_PRETTY_PRINT);
                            @file_put_contents($path, $json);
                        } catch (\Throwable) {
                            // Swallow: full disk / unwritable file must not crash the loop.
                        }
                        return null;
                    };
                    // Batch the tick-cmd (keeps the loop alive) with the persist side-effect.
                    return [$updated, Cmd::batch(
                        Cmd::tick(0.033, static fn() => new TickMsg()),
                        $persistCmd,
                    )];
                }
            }
            // Schedule the next tick — fires forever until quit.
            return [$next, Cmd::tick(0.033, static fn() => new TickMsg())];
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
            if (!$next->isOffScreen()) {
                $pipes[] = $next;
            }
        }
        // Spawn a new pipe every PIPE_EVERY ticks.
        $tick = $this->tickIndex + 1;
        if ($tick % self::PIPE_EVERY === 0) {
            $pipes[] = PipeGenerator::makePipe($this->score, $this->rand);
        }

        // Score = number of pipes the bird has passed in this tick.
        // A pipe crossed the bird column if its PREVIOUS x was > BIRD_COL-1
        // and its CURRENT x is <= BIRD_COL-1 (true crossing test).
        $score = $this->score;
        foreach ($pipes as $p) {
            // Pipe moved from x+1 to x this tick (tick decrements by 1).
            // It crossed BIRD_COL if (x+1) > BIRD_COL-1 && x <= BIRD_COL-1.
            // Since x == BIRD_COL-1 after the tick means it just arrived.
            if (($p->x + 1) > self::BIRD_COL && $p->x <= self::BIRD_COL - 1) {
                $score++;
            }
        }

        // Collision: bird hits a pipe, hits the floor, or hits the top wall.
        // y = -0.5 rounds to 0 = safe; y <= -0.51 rounds to -1 = crash.
        $crashed = $bird->row() < 0 || $bird->row() >= self::HEIGHT;
        if (!$crashed) {
            foreach ($pipes as $p) {
                if ($p->collides($bird->x, $bird->row())) {
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
        return null;
    }
}
