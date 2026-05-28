<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;
use SugarCraft\Layout\Direction as LayoutDirection;
use SugarCraft\Layout\LayoutSolver;
use SugarCraft\Layout\Region;

/**
 * Facade that delegates constraint solving to a {@see LayoutSolver}.
 *
 * Converts {@see Rect} to {@see Region} and
 * {@see SugarCraft\Sprinkles\Layout\Constraint*} to
 * {@see SugarCraft\Layout\Constraint*} before delegation, then converts
 * results back to {@see Rect[]}.
 *
 * Mirrors ratatui's layout solver (charmbracelet/bubbletea).
 */
final class Solver
{
    /**
     * Solve constraints against an area in the given direction.
     *
     * @param Constraint[] $constraints
     * @return Rect[]
     */
    public static function solve(Rect $area, array $constraints, Direction $dir): array
    {
        if ($constraints === []) {
            return [];
        }

        $solver = SolverFactory::default();
        $region = new Region($area->x, $area->y, $area->width, $area->height);
        $layoutConstraints = self::toLayoutConstraints($constraints);
        $layoutDir = $dir === Direction::Horizontal
            ? LayoutDirection::Horizontal
            : LayoutDirection::Vertical;

        $layoutRegions = $solver->solve($region, $layoutDir, $layoutConstraints);

        return self::fromLayoutRegions($layoutRegions);
    }

    /**
     * Convert Sprinkles constraints to Layout constraints.
     *
     * @param Constraint[] $constraints
     * @return list<LayoutConstraint\Constraint>
     */
    private static function toLayoutConstraints(array $constraints): array
    {
        $result = [];
        foreach ($constraints as $c) {
            $result[] = match (true) {
                $c instanceof Length => new LayoutConstraint\Length($c->n),
                $c instanceof Min => new LayoutConstraint\Min($c->n),
                $c instanceof Fill => new LayoutConstraint\Fill($c->weight),
                $c instanceof Percentage => new LayoutConstraint\Percentage($c->n),
                $c instanceof Ratio => new LayoutConstraint\Ratio($c->numerator, $c->denominator),
                $c instanceof Max => new LayoutConstraint\Max($c->n),
                default => throw new \InvalidArgumentException('Unsupported constraint type'),
            };
        }
        return $result;
    }

    /**
     * Convert Layout regions back to Sprinkles rects.
     *
     * @param Region[] $regions
     * @return Rect[]
     */
    private static function fromLayoutRegions(array $regions): array
    {
        $rects = [];
        foreach ($regions as $r) {
            $rects[] = new Rect($r->x, $r->y, $r->width, $r->height);
        }
        return $rects;
    }
}
