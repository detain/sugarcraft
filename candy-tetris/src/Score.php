<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * Score / level / lines tracker. Standard NES Tetris scoring:
 *
 *   1 line  ×  40 × (level + 1)
 *   2 lines × 100 × (level + 1)
 *   3 lines × 300 × (level + 1)
 *   4 lines × 1200 × (level + 1)   ← "Tetris"
 *
 * Level rises every 10 lines. The {@see Game} converts level into
 * a per-tick gravity delay (faster falls at higher levels).
 *
 * Immutable: `withLines()` returns a new Score. `Game::update()`
 * threads the new instance through the result tuple.
 */
final class Score
{
    public function __construct(
        public readonly int $points = 0,
        public readonly int $lines = 0,
        public readonly int $level = 0,
    ) {}

    public function withLines(int $cleared): self
    {
        if ($cleared <= 0) {
            return $this;
        }
        $multiplier = match ($cleared) {
            1 => 40,
            2 => 100,
            3 => 300,
            default => 1200, // 4+ all credit as Tetris
        };
        $points = $this->points + $multiplier * ($this->level + 1);
        $lines  = $this->lines + $cleared;
        $level  = intdiv($lines, 10);
        return new self($points, $lines, $level);
    }

    /**
     * Frames-per-row gravity at the current level. Cribbed from
     * NES Tetris: starts at 48 frames/row at level 0, ramps down
     * to 1 frame/row past level 29. We're frame-rate-agnostic
     * (SugarCraft uses a wall-clock tick), so consumers convert
     * this to a microsecond delay via `framesPerRow() * 16667`
     * (i.e. 60 fps).
     */
    public function framesPerRow(): int
    {
        return match (true) {
            $this->level <= 8  => 48 - 5 * $this->level,
            $this->level === 9 => 6,
            $this->level <= 12 => 5,
            $this->level <= 15 => 4,
            $this->level <= 18 => 3,
            $this->level <= 28 => 2,
            default            => 1,
        };
    }

    /** Microseconds to wait between gravity steps. */
    public function gravityIntervalUs(): int
    {
        return $this->framesPerRow() * 16_667;
    }

    /**
     * Add points from a hard-drop or soft-drop. Lines and level are
     * unchanged (unlike {@see withLines()} which may level up).
     */
    public function withDropPoints(int $points): self
    {
        return new self(
            $this->points + $points,
            $this->lines,
            $this->level,
        );
    }
}
