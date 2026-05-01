<?php

declare(strict_types=1);

namespace CandyCore\Gloss;

/**
 * The 8 corner/edge runes that make up a rectangular box border. All runes
 * must occupy a single terminal cell.
 *
 * Mirrors lipgloss `Border`. Table-specific separator runes (middle row,
 * cross intersections) will land in `CandyCore\Gloss\Table` later.
 */
final class Border
{
    public function __construct(
        public readonly string $top,
        public readonly string $bottom,
        public readonly string $left,
        public readonly string $right,
        public readonly string $topLeft,
        public readonly string $topRight,
        public readonly string $bottomLeft,
        public readonly string $bottomRight,
    ) {}

    public static function normal(): self
    {
        return new self('─', '─', '│', '│', '┌', '┐', '└', '┘');
    }

    public static function rounded(): self
    {
        return new self('─', '─', '│', '│', '╭', '╮', '╰', '╯');
    }

    public static function thick(): self
    {
        return new self('━', '━', '┃', '┃', '┏', '┓', '┗', '┛');
    }

    public static function double(): self
    {
        return new self('═', '═', '║', '║', '╔', '╗', '╚', '╝');
    }

    public static function block(): self
    {
        return new self('█', '█', '█', '█', '█', '█', '█', '█');
    }

    public static function ascii(): self
    {
        return new self('-', '-', '|', '|', '+', '+', '+', '+');
    }

    public static function hidden(): self
    {
        return new self(' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ');
    }
}
