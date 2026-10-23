<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\Constraint as ConstraintsConstraint;
use SugarCraft\Constraints\Direction as ConstraintsDirection;
use SugarCraft\Constraints\LayoutSolver;
use SugarCraft\Constraints\Region;

/**
 * Facade that delegates constraint solving to a {@see LayoutSolver}.
 *
 * Converts {@see Rect} to {@see Region} and
 * {@see SugarCraft\Style\Layout\Constraint*} to
 * {@see SugarCraft\Constraints\Constraint*} before delegation, then converts
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

        $solver = SolverFactory::fromEnvironment();
        $region = new Region($area->x, $area->y, $area->width, $area->height);
        $layoutConstraints = self::toConstraintsConstraints($constraints);
        $layoutDir = $dir === Direction::Horizontal
            ? ConstraintsDirection::Horizontal
            : ConstraintsDirection::Vertical;

        $layoutRegions = $solver->solve($region, $layoutDir, $layoutConstraints);

        return self::fromConstraintsRegions($layoutRegions);
    }

    /**
     * Convert Style constraint wrappers to Constraints-library constraints.
     *
     * @param Constraint[] $constraints
     * @return list<ConstraintsConstraint\Constraint>
     */
    private static function toConstraintsConstraints(array $constraints): array
    {
        $result = [];
        foreach ($constraints as $c) {
            $result[] = match (true) {
                $c instanceof Length => new ConstraintsConstraint\Length($c->n),
                $c instanceof Min => new ConstraintsConstraint\Min($c->n),
                $c instanceof Fill => new ConstraintsConstraint\Fill($c->weight),
                $c instanceof Percentage => new ConstraintsConstraint\Percentage($c->n),
                $c instanceof Ratio => new ConstraintsConstraint\Ratio($c->numerator, $c->denominator),
                $c instanceof Max => new ConstraintsConstraint\Max($c->n),
                default => throw new \InvalidArgumentException('Unsupported constraint type'),
            };
        }
        return $result;
    }

    /**
     * Convert Constraints-library regions back to Style Rect values.
     *
     * @param Region[] $regions
     * @return Rect[]
     */
    private static function fromConstraintsRegions(array $regions): array
    {
        $rects = [];
        foreach ($regions as $r) {
            $rects[] = new Rect($r->x, $r->y, $r->width, $r->height);
        }
        return $rects;
    }
}
