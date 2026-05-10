<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

/**
 * Constraint-based layout split facade.
 *
 * Mirrors ratatui's `Layout` — takes a Direction + array of Constraints
 * and produces a list of Rects covering the given area.
 *
 * Usage:
 * ```php
 * $rows = Layout::vertical([
 *     Constraint::length(3),   // header
 *     Constraint::min(10),     // body — at least 10 rows, takes remaining
 *     Constraint::length(1),   // status line
 * ])->split(new Rect(0, 0, 100, 30));
 * ```
 */
final class Layout
{
    /**
     * @param Constraint[] $constraints
     */
    private function __construct(
        private readonly Direction $direction,
        private readonly array $constraints,
    ) {}

    /**
     * Create a horizontal (left-to-right) layout.
     *
     * @param Constraint[] $constraints
     */
    public static function horizontal(array $constraints): self
    {
        return new self(Direction::Horizontal, $constraints);
    }

    /**
     * Create a vertical (top-to-bottom) layout.
     *
     * @param Constraint[] $constraints
     */
    public static function vertical(array $constraints): self
    {
        return new self(Direction::Vertical, $constraints);
    }

    /**
     * Split the given area into Rects according to the constraints.
     *
     * @return Rect[]
     */
    public function split(Rect $area): array
    {
        return Solver::solve($area, $this->constraints, $this->direction);
    }
}
