<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

use SugarCraft\Layout\Constraint\Fill;
use SugarCraft\Layout\Constraint\Length;
use SugarCraft\Layout\Constraint\Max;
use SugarCraft\Layout\Constraint\Min;
use SugarCraft\Layout\Constraint\Percentage;
use SugarCraft\Layout\Constraint\Ratio;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\Region;

/**
 * Cassowary simplex solver for 1D terminal layout constraints.
 *
 * Based on Badros & Borning 2001 "The Cassowary Linear Arithmetic
 * Constraint Solving Algorithm" — simplified for 1D, integer outputs.
 *
 * Key differences from full Cassowary:
 * - Single direction only (x or y, not both)
 * - Integer outputs via intval()
 * - Simplified pivot rule (minimum ratio test)
 * - No constraint editing/change tracking (static once added)
 *
 * Mirrors kiwisolver / rhea approach for constraint solving.
 */
final class CassowarySolver implements LayoutSolver
{
    /** @var Tableau */
    private Tableau $tableau;

    /** @var array<string, Variable> */
    private array $variables = [];

    /** @var array<string, ConstraintDef> */
    private array $constraints = [];

    /** @var array<string, EditInfo> edit variables */
    private array $editVars = [];

    /** @var bool */
    private bool $optimize = false;

    /** @var array<string, float> Objective function row: var -> coefficient */
    private array $objectiveRow = [];

    /** @var float Objective constant term */
    private float $objectiveConstant = 0.0;

    /** @var float Big-M penalty value for artificial variables */
    private const BIG_M = 1000000.0;

    /**
     * Strength constants — symbolic weights for constraint priority.
     * Required (1e+9) >> Strong (1.0) >> Medium (0.001) >> Weak (0.0001)
     */
    private const STRENGTH_REQUIRED = 1e9;
    private const STRENGTH_STRONG = 1.0;
    private const STRENGTH_MEDIUM = 0.001;
    private const STRENGTH_WEAK = 0.0001;

    public function __construct()
    {
        $this->tableau = new Tableau();
    }

    /**
     * Default factory — matches LayoutSolver convention.
     */
    public static function new(): self
    {
        return new self();
    }

    /** @return GreedySolver */
    public static function greedy(): GreedySolver
    {
        return new GreedySolver();
    }

    public static function cassowary(): self
    {
        return new self();
    }

    /**
     * Solve constraints against a region in the given direction.
     */
    public function solve(Region $region, Direction $dir, array $constraints): array
    {
        if ($constraints === []) {
            return [];
        }

        // Build variable map — one variable per constraint
        $vars = [];
        foreach ($constraints as $i => $c) {
            $var = new Variable("x{$i}");
            $vars[$i] = $var;
            $this->variables[$var->name] = $var;
            $this->tableau->externalVars[$var->name] = true;
        }

        // Add constraints for each type
        $totalSize = $dir === Direction::Horizontal ? $region->width : $region->height;
        $prefixSum = 0;

        foreach ($constraints as $i => $c) {
            $var = $vars[$i];

            if ($c instanceof Min) {
                // x_i >= n  →  x_i - s_i = n
                $this->addConstraint(
                    Expression::variable($var->name)->minus(
                        Expression::constant($c->n)
                    ),
                    Relation::GreaterThanOrEqual,
                    0.0,
                    self::STRENGTH_REQUIRED
                );
            } elseif ($c instanceof Max) {
                // x_i <= n  →  x_i + s_i = n
                $this->addConstraint(
                    Expression::variable($var->name)->plus(
                        Expression::constant($c->n)
                    ),
                    Relation::LessThanOrEqual,
                    0.0,
                    self::STRENGTH_REQUIRED
                );
            } elseif ($c instanceof Length) {
                // x_i = n
                $this->addConstraint(
                    Expression::variable($var->name),
                    Relation::Equal,
                    (float) $c->n,
                    self::STRENGTH_REQUIRED
                );
            } elseif ($c instanceof Fill) {
                // Fill is greedy — handled in distribution phase
            } elseif ($c instanceof Percentage) {
                // x_i = total * p / 100  →  x_i - total * p/100 = 0
                $pct = $c->n / 100.0;
                $expr = Expression::variable($var->name)->minus(
                    Expression::constant($totalSize * $pct)
                );
                $this->addConstraint(
                    $expr,
                    Relation::Equal,
                    0.0,
                    self::STRENGTH_STRONG
                );
            } elseif ($c instanceof Ratio) {
                // x_i = total * num/den  →  x_i - total * num/den = 0
                $ratio = $c->numerator / $c->denominator;
                $expr = Expression::variable($var->name)->minus(
                    Expression::constant($totalSize * $ratio)
                );
                $this->addConstraint(
                    $expr,
                    Relation::Equal,
                    0.0,
                    self::STRENGTH_STRONG
                );
            }

            // Add edit variable for this constraint's size
            $this->addEditVariable($var->name, self::STRENGTH_STRONG);
        }

        // Stay constraint: all variables should keep their current values (weak)
        // Actually, for 1D layout, we want to distribute totalSize among variables
        // Add: sum(x_i) = totalSize  (equality with required strength)
        $sumExpr = Expression::zero();
        foreach ($vars as $v) {
            $sumExpr = $sumExpr->plus(Expression::variable($v->name));
        }
        $this->addConstraint(
            $sumExpr,
            Relation::Equal,
            (float) $totalSize,
            self::STRENGTH_REQUIRED
        );

        // Solve
        $this->solveCore();

        // Build output regions
        $results = [];
        $x = $region->x;
        foreach ($constraints as $i => $c) {
            $val = $this->getVariableValue($vars[$i]->name);
            $intVal = max(0, (int) round($val));

            if ($c instanceof Fill) {
                // Fill gets whatever is left (greedy distribution)
                // For now, compute Fill sizes proportionally after other constraints
            }

            $width = $intVal;
            $height = $region->height;
            if ($dir === Direction::Vertical) {
                $results[] = new Region($region->x, $x, $region->width, $width);
            } else {
                $results[] = new Region($x, $region->y, $width, $height);
            }
            $x += $width;
        }

        // Handle Fill constraints: redistribute slack to Fill constraints
        $results = $this->distributeFill($constraints, $results, $totalSize, $dir);

        return $results;
    }

    /**
     * Distribute remaining space to Fill constraints.
     */
    private function distributeFill(array $constraints, array $results, int $total, Direction $dir): array
    {
        $fillIndices = [];
        $fillWeights = [];
        $nonFillTotal = 0;

        foreach ($constraints as $i => $c) {
            if ($c instanceof Fill) {
                $fillIndices[] = $i;
                $fillWeights[] = $c->weight;
            } else {
                $nonFillTotal += $results[$i]->width ?? $results[$i]->height ?? 0;
            }
        }

        if ($fillIndices === []) {
            return $results;
        }

        $fillTotal = $total - $nonFillTotal;
        if ($fillTotal <= 0) {
            return $results;
        }

        $weightSum = array_sum($fillWeights);
        $x = 0;
        $newResults = $results;
        foreach ($fillIndices as $idx => $i) {
            $w = $weightSum > 0
                ? (int) floor(($fillWeights[$idx] / $weightSum) * $fillTotal)
                : (int) floor($fillTotal / count($fillIndices));
            $newResults[$i] = $this->updateRegionSize($results[$i], $w, $constraints[$i], $dir);
            $x += $w;
        }

        return $newResults;
    }

    private function updateRegionSize(Region $r, int $size, Constraint $c, Direction $dir): Region
    {
        // For horizontal, update width; for vertical, update height
        if ($dir === Direction::Horizontal) {
            return new Region($r->x, $r->y, $size, $r->height);
        }
        return new Region($r->x, $r->y, $r->width, $size);
    }

    // ─── Core Cassowary ───────────────────────────────────────────────────────

    private function solveCore(): void
    {
        // Set up Phase 1 objective: minimize sum of artificial variables
        // This drives artificial vars out of the basis to find feasible solution
        $this->setupPhaseOneObjective();

        $iterations = 0;
        $maxIterations = 1000;

        while ($iterations++ < $maxIterations) {
            $changed = $this->optimizeOneStep();
            if (!$changed) {
                break;
            }
        }
    }

    /**
     * Set up Phase 1 objective: minimize sum of artificial variables.
     * This uses the Big-M method where we add M * (sum of artificial vars) to objective.
     * The negative coefficients will drive the simplex to eliminate artificial vars.
     */
    private function setupPhaseOneObjective(): void
    {
        // For each artificial variable, add to objective with coefficient -1
        // (negative because we're minimizing and want to drive them to zero)
        // Actually for Big-M: add +M to objective for each artificial var
        // The simplex will express objective in non-basic vars, creating negative coefficients

        // Initialize objective row with stay objective for external vars
        // (each var should stay at 0, i.e., minimize movement from initial position)
        foreach ($this->tableau->externalVars as $var => $_) {
            if (!isset($this->objectiveRow[$var])) {
                $this->objectiveRow[$var] = 0.0;
            }
        }

        // If no artificial variables, set up a stay objective to drive simplex
        // Each external variable gets coefficient -1 (minimize sum of variables)
        if ($this->tableau->artificialVars === []) {
            foreach ($this->tableau->externalVars as $var => $_) {
                $this->objectiveRow[$var] = -1.0;
            }
        } else {
            // Big-M method: minimize sum of artificial variables
            // Each artificial var a_i gets coefficient +M in objective
            // When expressed in non-basic vars, this creates negative coefficients
            foreach ($this->tableau->artificialVars as $artVar => $_) {
                $this->objectiveRow[$artVar] = ($this->objectiveRow[$artVar] ?? 0.0) + self::BIG_M;
            }
        }
    }

    private function optimizeOneStep(): bool
    {
        // Find entering variable (most negative reduced cost in objective row)
        $entering = $this->findEnteringVariable();
        if ($entering === null) {
            return false;
        }

        // Find leaving variable (minimum ratio test)
        $leaving = $this->findLeavingVariable($entering);
        if ($leaving === null) {
            return false; // Unbounded
        }

        // Pivot
        $this->pivot($entering, $leaving);
        return true;
    }

    private function findEnteringVariable(): ?string
    {
        // Search objective row for most negative coefficient
        // (we're minimizing, so negative = can improve objective)
        $minCoeff = -0.000001; // tolerance to avoid FP issues
        $entering = null;

        // First check objective row
        foreach ($this->objectiveRow as $colVar => $coeff) {
            // For external variables, check if coefficient is negative
            if ($coeff < $minCoeff && isset($this->tableau->externalVars[$colVar])) {
                $minCoeff = $coeff;
                $entering = $colVar;
            }
        }

        // Also check constraint rows for external variables with negative coefficients
        // This is needed for Big-M method where objective expr in non-basic vars has negatives
        if ($entering === null) {
            $minCoeff = -0.000001;
            foreach ($this->tableau->rows as $rowVar => $row) {
                foreach ($row as $colVar => $coeff) {
                    // For Big-M: look for external vars with ANY negative coefficient
                    // They are candidates to replace artificial vars in the basis
                    if ($coeff < $minCoeff && isset($this->tableau->externalVars[$colVar])) {
                        $minCoeff = $coeff;
                        $entering = $colVar;
                    }
                }
            }
        }

        return $entering;
    }

    private function findLeavingVariable(string $entering): ?string
    {
        $minRatio = PHP_FLOAT_MAX;
        $leaving = null;

        foreach ($this->tableau->rows as $rowVar => $row) {
            $coeff = $row[$entering] ?? 0.0;
            if ($coeff <= 0) {
                continue;
            }

            $b = $this->tableau->b[$rowVar] ?? 0.0;
            $ratio = $b / $coeff;

            if ($ratio < $minRatio) {
                $minRatio = $ratio;
                $leaving = $rowVar;
            }
        }

        return $leaving;
    }

    private function pivot(string $entering, string $leaving): void
    {
        $t = $this->tableau;
        $row = &$t->rows[$leaving];
        $pivotVal = $row[$entering] ?? 0.0;

        // Scale pivot row
        foreach ($row as $col => &$val) {
            if ($val !== 0.0) {
                $val /= $pivotVal;
            }
        }
        unset($val);
        $t->b[$leaving] /= $pivotVal;

        // Eliminate entering var from other rows
        foreach ($t->rows as $rVar => &$rRow) {
            if ($rVar === $leaving) {
                continue;
            }
            $coef = $rRow[$entering] ?? 0.0;
            if ($coef === 0.0) {
                continue;
            }

            foreach ($rRow as $col => &$val) {
                $val -= $coef * ($row[$col] ?? 0.0);
            }
            unset($val);
            $t->b[$rVar] -= $coef * $t->b[$leaving];
        }

        // Update column index
        unset($t->colIndex[$entering]);
    }

    /**
     * Add a constraint: expression relation value at given strength.
     */
    private function addConstraint(
        Expression $expr,
        Relation $relation,
        float $value,
        float $strength
    ): void {
        $cn = $this->newConstraintName();
        $this->constraints[$cn] = new ConstraintDef($expr, $relation, $value, $strength, $cn);

        // Add to tableau
        // For now, use simplified approach: direct row addition
        $this->addDirectRow($cn, $expr, $relation, $value);
    }

    private function addDirectRow(string $cn, Expression $expr, Relation $relation, float $value): void
    {
        $t = $this->tableau;

        // Build row: expression + [slack/artificial] = value
        $row = [];
        foreach ($expr->terms as $var => $coef) {
            $row[$var] = $coef;
        }

        $slackOrArtificialAdded = false;

        if ($relation === Relation::LessThanOrEqual) {
            // expr + slack = value
            $sName = 's' . $t->nextSlackVar;
            $t->nextSlackVar++;
            $t->slackVars[$sName] = true;
            $row[$sName] = 1.0;
            $slackOrArtificialAdded = true;
        } elseif ($relation === Relation::GreaterThanOrEqual) {
            // expr - slack = value
            $sName = 's' . $t->nextSlackVar;
            $t->nextSlackVar++;
            $t->slackVars[$sName] = true;
            $row[$sName] = -1.0;
            $slackOrArtificialAdded = true;
        } elseif ($relation === Relation::Equal) {
            // Equal constraint: requires artificial variable for Big-M method
            // Add artificial variable a_i with coefficient 1
            $aName = 'a' . $t->nextArtificialVar;
            $t->nextArtificialVar++;
            $t->artificialVars[$aName] = true;
            $row[$aName] = 1.0;

            // Big-M method: add M * a_i to objective
            // Also add -M * (coeff of each original var) to objective
            // This is because when we express objective in non-basic vars:
            //   z = M*a_i = M*(b - sum(ci*xi)) = M*b - M*sum(ci*xi)
            // So original vars get -M*ci coefficient
            $this->objectiveRow[$aName] = ($this->objectiveRow[$aName] ?? 0.0) + self::BIG_M;
            foreach ($expr->terms as $var => $coef) {
                $this->objectiveRow[$var] = ($this->objectiveRow[$var] ?? 0.0) - self::BIG_M * $coef;
            }
            $this->objectiveConstant += self::BIG_M * ($value + $expr->constant);

            $slackOrArtificialAdded = true;
        }

        if ($slackOrArtificialAdded) {
            $t->rows[$cn] = $row;
            $t->b[$cn] = $value + $expr->constant;

            // Index column
            foreach (array_keys($row) as $colVar) {
                $t->colIndex[$colVar][$cn] = true;
            }
        }
    }

    /**
     * Add an edit variable for interactive constraint solving.
     */
    private function addEditVariable(string $varName, float $strength): void
    {
        $this->editVars[$varName] = new EditInfo($varName, $strength);
    }

    /**
     * Get current value of a variable.
     */
    private function getVariableValue(string $varName): float
    {
        // Find which row this variable is basic in
        foreach ($this->tableau->rows as $rowVar => $row) {
            if (isset($row[$varName]) && $row[$varName] !== 0.0) {
                return $this->tableau->b[$rowVar] / $row[$varName];
            }
        }
        return 0.0;
    }

    private function newConstraintName(): string
    {
        return 'c' . (count($this->constraints) + 1);
    }
}

// ─── Supporting classes ───────────────────────────────────────────────────────

/**
 * A Cassowary variable — represents an unknown in the constraint system.
 */
final class Variable
{
    public function __construct(
        public string $name,
        public float $value = 0.0,
    ) {}
}

/**
 * Linear expression: sum(a_i * x_i) + c
 */
final class Expression
{
    /** @var array<string, float> Variable coefficients */
    public array $terms = [];

    public float $constant = 0.0;

    public function __construct(array $terms = [], float $constant = 0.0)
    {
        $this->terms = $terms;
        $this->constant = $constant;
    }

    public static function zero(): self
    {
        return new self();
    }

    public static function constant(float $c): self
    {
        return new self([], $c);
    }

    public static function variable(string $name, float $coef = 1.0): self
    {
        return new self([$name => $coef], 0.0);
    }

    public function plus(Expression $other): self
    {
        $result = new self($this->terms, $this->constant);
        foreach ($other->terms as $name => $coef) {
            $result->terms[$name] = ($result->terms[$name] ?? 0.0) + $coef;
        }
        $result->constant += $other->constant;
        return $result;
    }

    public function minus(Expression $other): self
    {
        return $this->plus(new self(
            array_map(fn($v) => -$v, $other->terms),
            -$other->constant
        ));
    }

    public function times(float $scalar): self
    {
        $result = new self();
        foreach ($this->terms as $name => $coef) {
            $result->terms[$name] = $coef * $scalar;
        }
        $result->constant = $this->constant * $scalar;
        return $result;
    }
}

/**
 * Relational operator for constraints.
 */
enum Relation
{
    case LessThanOrEqual;
    case GreaterThanOrEqual;
    case Equal;
}

/**
 * Internal constraint definition.
 */
final class ConstraintDef
{
    public function __construct(
        public Expression $expr,
        public Relation $relation,
        public float $value,
        public float $strength,
        public string $name,
    ) {}
}

/**
 * Edit variable info for interactive solving.
 */
final class EditInfo
{
    public function __construct(
        public string $variable,
        public float $strength,
    ) {}
}
