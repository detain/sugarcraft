<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

/**
 * Difficulty levels for minesweeper.
 *
 * Cases are PascalCase to match the fleet enum convention (sugar-core
 * MouseButton/Kind, candy-vcr EventKind, sugar-widgets Paginator\Type —
 * msw-33, finding -071). The enum is unbacked and the stats wire keys are
 * its own flat lowercase literals (easyGames…), so the rename touches no
 * on-disk bytes.
 *
 * Easy, Medium and Expert carry the classic Windows Minesweeper
 * shapes — no transcribed go-sweep counterpart for the set (the repo_map
 * parity record, docs/old/repo_map/sugarcraft_candy-minesweeper.md,
 * transcribes none). Standard is this port's own plain-boot shape:
 *   - Easy     (9×9, 10 mines)
 *   - Standard (10×10, 12 mines) — the shape `Game::start()` ships with
 *   - Medium   (16×16, 40 mines)
 *   - Expert   (30×16, 99 mines)
 */
enum Difficulty
{
    case Easy;
    case Standard;
    case Medium;
    case Expert;

    public function width(): int
    {
        return match ($this) {
            self::Easy     => 9,
            self::Standard => 10,
            self::Medium   => 16,
            self::Expert   => 30,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Easy     => 9,
            self::Standard => 10,
            self::Medium   => 16,
            self::Expert   => 16,
        };
    }

    public function mines(): int
    {
        return match ($this) {
            self::Easy     => 10,
            self::Standard => 12,
            self::Medium   => 40,
            self::Expert   => 99,
        };
    }

    /**
     * Derive a Difficulty from board dimensions and mine count.
     *
     * Returns null if the dimensions don't match any preset.
     */
    public static function fromDimensions(int $width, int $height, int $mines): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->width() === $width && $case->height() === $height && $case->mines() === $mines) {
                return $case;
            }
        }
        return null;
    }
}
