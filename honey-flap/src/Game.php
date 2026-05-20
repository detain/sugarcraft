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
 */
final class Game implements Model
{
    public const WIDTH       = 60;
    public const HEIGHT      = 18;
    public const BIRD_COL    = 8;
    public const PIPE_GAP    = 6;          // open cells in each pipe pair
    public const PIPE_EVERY  = 18;         // cells between successive pipes

    private const HIGH_SCORE_FILE = '.honey-flap/scores.json';

    /** @var \Closure(int): int */
    private \Closure $rand;

    /** @var list<int> */
    private array $highScores = [];

    private string $highScoreFilePath;

    /**
     * @param list<Pipe> $pipes
     */
    public function __construct(
        public readonly Bird $bird,
        public readonly array $pipes,
        public readonly int $score = 0,
        public readonly bool $crashed = false,
        public readonly int $tickIndex = 0,
        ?\Closure $rand = null,
        ?string $configDir = null,
    ) {
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
        $this->highScoreFilePath = ($configDir ?? $this->getDefaultConfigDir()) . '/' . self::HIGH_SCORE_FILE;
        $this->loadHighScores();
    }

    private function getDefaultConfigDir(): string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
        return $home . '/.config';
    }

    public static function start(?\Closure $rand = null): self
    {
        return new self(
            bird:  Bird::spawn(self::BIRD_COL, self::HEIGHT / 2.0),
            pipes: [],
            rand:  $rand,
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
     * Load high scores from JSON file. Fail fast if file exists but is unreadable.
     */
    private function loadHighScores(): void
    {
        $path = $this->highScoreFilePath;
        if (!file_exists($path)) {
            return;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read high score file: {$path}");
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid high score file format: {$path}");
        }
        $this->highScores = array_values(array_filter($decoded, 'is_int'));
        sort($this->highScores, SORT_NUMERIC);
    }

    /**
     * Save a new high score if it qualifies. Returns true if saved.
     */
    public function saveHighScore(int $score): bool
    {
        if ($score <= $this->highScore()) {
            return false;
        }
        $this->highScores[] = $score;
        sort($this->highScores, SORT_NUMERIC);
        $this->persistHighScores();
        return true;
    }

    private function persistHighScores(): void
    {
        $dir = dirname($this->highScoreFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $json = json_encode($this->highScores, JSON_PRETTY_PRINT);
        if (file_put_contents($this->highScoreFilePath, $json) === false) {
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
            // Persist high score when game ends.
            if ($next->crashed) {
                $next->saveHighScore($next->score);
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
        $score = $this->score;
        foreach ($pipes as $p) {
            // A pipe just passed the bird if its previous x was BIRD_COL
            // and after tick is BIRD_COL - 1.
            if ($p->x === self::BIRD_COL - 1) {
                $score++;
            }
        }

        // Collision: bird hits a pipe, hits the floor, or floats off the top.
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
}
