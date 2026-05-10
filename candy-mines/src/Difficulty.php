<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

/**
 * Difficulty levels for minesweeper.
 *
 * Mirrors common minesweeper presets:
 *   - EASY   (9×9, 10 mines)
 *   - MEDIUM (16×16, 40 mines)
 *   - EXPERT (30×16, 99 mines)
 */
enum Difficulty
{
    case EASY;
    case MEDIUM;
    case EXPERT;

    public function width(): int
    {
        return match ($this) {
            self::EASY   => 9,
            self::MEDIUM => 16,
            self::EXPERT => 30,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::EASY   => 9,
            self::MEDIUM => 16,
            self::EXPERT => 16,
        };
    }

    public function mines(): int
    {
        return match ($this) {
            self::EASY   => 10,
            self::MEDIUM => 40,
            self::EXPERT => 99,
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
