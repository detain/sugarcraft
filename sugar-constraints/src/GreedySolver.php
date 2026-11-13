<?php

declare(strict_types=1);

namespace SugarCraft\Constraints;

use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Constraint\Fill;
use SugarCraft\Constraints\Constraint\Length;
use SugarCraft\Constraints\Constraint\Max;
use SugarCraft\Constraints\Constraint\Min;
use SugarCraft\Constraints\Constraint\Percentage;
use SugarCraft\Constraints\Constraint\Ratio;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\Region;

/**
 * The package's solver: maps a Region plus a list of constraints to a list of
 * sub-regions, ratatui-inspired but greedy rather than linear-programming
 * (the retired simplex prototype never converged — see {@see CassowarySolver}).
 *
 * Algorithm:
 *  1. Compute Percentage and Ratio against total area → absolute sizes.
 *  2. Sum fixed Length + computed Percentage/Ratio as reserved space.
 *  3. Min is a floor; Max is a ceiling (greedy — takes remaining space).
 *     If slack < sum-of-mins, all mins clamped proportionally.
 *  4. Remaining slack distributed across Fill() and Max() constraints
 *     proportionally (Max is greedy here; clamp pass reduces it).
 *  5. If no Fill/Max, slack goes to Min constraints proportionally.
 *  6. Apply Max clamp pass; reclaimed space redistributed to Min > Fill >
 *     others — and, when the set has neither, to Length/Percentage/Ratio,
 *     which inflates "fixed" sizes (the documented reclaim ladder, see
 *     {@see applyMaxClamp()}).
 *  7. If total reserved > area, truncate proportionally; the scale pass's
 *     floor loss is handed back one cell per truncated region (earliest
 *     first, latest first under {@see withRemainderToLast()}) so the sizes
 *     still tile the area.
 *     No PHP warning is raised: an over-constrained split is the ordinary
 *     state of a TUI resized below its fixed panes, it recurs every frame,
 *     and a warning on the default handler would print into the terminal the
 *     layout is drawing. Hosts that need to know ask
 *     {@see GreedySolver::demandOf()} — demand above the area says something
 *     gave; {@see withoutOverflowTruncation()} keeps every region at its full
 *     base size instead.
 *
 * The produced sub-regions tile the region exactly for every well-constrained
 * set, but two shapes deliberately fall off-grid ({@see LayoutSolver} carries
 * the carve-out): a set of nothing-but-fixed constraints under-constrains the
 * area and leaves an untiled gap, and compatibility mode's non-truncating
 * overflow lets demand exceed the area on purpose.
 *
 * ── sugar-panels compatibility mode ────────────────────────────────────────
 * The default behaviour (floor split, rounding remainder handed to the FIRST
 * region, proportional overflow truncation) diverges on three axes from
 * sugar-panels's hand-rolled distribute()/distributeFlex(). {@see compat()}
 * (or the granular {@see withRoundSplit()}/{@see withRemainderToLast()}/
 * {@see withoutOverflowTruncation()} toggles) flips exactly those three so a
 * consumer can reproduce sugar-panels's distribution byte-for-byte:
 *   1. round() instead of floor() for Percentage/Ratio proportional sizes;
 *   2. the rounding remainder is handed to the LAST region (or LAST Fill/Max)
 *      instead of the first;
 *   3. on overflow, each region keeps its full base size (layout runs
 *      off-grid) instead of being truncated proportionally.
 * These flags are immutable+fluent and default OFF, so the default solver is
 * unchanged. The mode exists so sugar-panels can retire its duplicated
 * distribution helpers while keeping its golden frames byte-identical.
 *
 * ── min-share floor ─────────────────────────────────────────────────────────
 * sugar-panels's NON-flex distribute() carries a FOURTH quirk the three flags
 * above do not cover: a bespoke "reserve ≥1 column per not-yet-placed child"
 * SEQUENTIAL clamp that guarantees the trailing region never collapses to 0 in
 * a tight viewport. {@see withMinShare()} reproduces it exactly. When the floor
 * is on (minShare > 0) the solver takes a dedicated proportional path over Fill
 * weights: each region gets `round(weight/total * span)` cells, clamped so at
 * least `minShare` cell(s) remain for every region still unplaced, with the
 * last region absorbing the remainder — so this mode is inherently round-split +
 * remainder-to-last and the compat() flags are moot while it is engaged. The
 * `reserveGap`/`reserveLead` args re-create distribute()'s absolute-position
 * reservation frame (its `$used` counter is seeded with the border pad and
 * accumulates one inter-child gap per placed child), which is the only way to
 * stay byte-identical when spacing/border make the clamp bind. Default 0 → OFF,
 * so the default solver is unchanged. Parity is pinned against
 * sugar-panels's own golden tests; a full case sweep against distribute()
 * found zero divergences.
 */
final class GreedySolver implements LayoutSolver
{
    /**
     * @param bool $roundSplit        round() (not floor()) Percentage/Ratio sizes — sugar-panels distribute().
     *                                Float→int determinism contract in {@see self::withRoundSplit()}.
     * @param bool $remainderToLast   hand the rounding remainder to the LAST region/Fill/Max, not the first.
     * @param bool $truncateOverflow  proportionally shrink regions when demand exceeds the area (default);
     *                                sugar-panels keeps each region at full base size (pass false).
     * @param int  $minShare          0 = OFF (default). >0 engages the sequential min-share floor over Fill
     *                                weights, reserving this many cell(s) for every not-yet-placed region.
     * @param int  $reserveGap        extra cells reserved per ALREADY-placed region — models an inter-region
     *                                gap that lives outside the solved content span (sugar-panels spacing).
     * @param int  $reserveLead       fixed cells reserved before the first region — models a leading margin
     *                                outside the content span (sugar-panels border pad).
     *
     * Public (not private) because sugar-style's SolverFactory and
     * CassowarySolver already construct this via `new GreedySolver()`; the
     * defaults preserve that legacy zero-arg call byte-for-byte. Prefer the
     * ::new()/::greedy()/::compat() factories for new code.
     */
    public function __construct(
        public readonly bool $roundSplit = false,
        public readonly bool $remainderToLast = false,
        public readonly bool $truncateOverflow = true,
        public readonly int $minShare = 0,
        public readonly int $reserveGap = 0,
        public readonly int $reserveLead = 0,
    ) {
        if ($minShare < 0 || $reserveGap < 0 || $reserveLead < 0) {
            throw new \InvalidArgumentException(
                'min-share floor parameters must be non-negative; '
                . "got minShare={$minShare}, reserveGap={$reserveGap}, reserveLead={$reserveLead}"
            );
        }
    }

    /**
     * Default factory — matches LayoutSolver convention.
     */
    public static function new(): self
    {
        return new self();
    }

    public static function greedy(): self
    {
        return new self();
    }

    /**
     * sugar-panels parity mode: round-split + remainder-to-last + non-truncating
     * overflow. Opt-in seam for retiring sugar-panels's duplicated distribute()
     * /distributeFlex() without regressing its goldens.
     */
    public static function compat(): self
    {
        return new self(roundSplit: true, remainderToLast: true, truncateOverflow: false);
    }

    /**
     * Round Percentage/Ratio proportional sizes with round() instead of floor().
     *
     * Fractional-width determinism contract: both branches
     * cast an IEEE-754 double to int with a single PHP-core function —
     * `(int) round(...)` (half away from zero) when ON, `(int) floor(...)`
     * (toward negative infinity) when OFF. The operands are integer arithmetic
     * (`$totalWidth * $c->n / 100`, `... * $c->numerator / $c->denominator`), so
     * the same inputs always yield the same cell widths on every build; the
     * floor-vs-round POLICY is the only divergence, and it is chosen solely by
     * this flag (opt-in — candy-code never enables it, its path is floor-only).
     * Pinned by WidthSumInvariantSweepTest::testRoundSplitRoundsHalfAwayFromZero.
     */
    public function withRoundSplit(bool $on = true): self
    {
        return $this->mutate(['roundSplit' => $on]);
    }

    /**
     * Hand the rounding remainder to the LAST region (or LAST Fill/Max) instead
     * of the first.
     */
    public function withRemainderToLast(bool $on = true): self
    {
        return $this->mutate(['remainderToLast' => $on]);
    }

    /**
     * Keep each region at its full base size on overflow (layout runs off-grid)
     * instead of truncating proportionally.
     */
    public function withoutOverflowTruncation(): self
    {
        return $this->mutate(['truncateOverflow' => false]);
    }

    /**
     * Re-enable (or disable) proportional overflow truncation.
     */
    public function withOverflowTruncation(bool $on = true): self
    {
        return $this->mutate(['truncateOverflow' => $on]);
    }

    /**
     * Engage the sequential min-share floor: split the region proportionally
     * across its Fill weights while reserving at least `$cells` cell(s) for every
     * region NOT yet placed, so the trailing region never collapses to 0 in a
     * tight viewport. This is the opt-in seam that lets sugar-panels retire its
     * hand-rolled NON-flex distribute() with byte parity —
     * the fourth quirk compat()'s three flags cannot express.
     *
     * The floor path is inherently round-split + remainder-to-last, so it does
     * not need (and ignores) the compat() flags. Pass `$reserveGap`/`$reserveLead`
     * to re-create distribute()'s absolute-position reservation frame: sugar-panels
     * seeds its running offset with the border pad ($reserveLead) and adds one
     * inter-child spacing per placed child ($reserveGap). With both 0 the floor
     * reserves purely in the content frame.
     *
     * `$cells = 0` disables the floor (restores the default solver path).
     */
    public function withMinShare(int $cells = 1, int $reserveGap = 0, int $reserveLead = 0): self
    {
        // The floor path IS a round split (solveMinShare rounds each share)
        // with the last region absorbing the remainder —
        // it never consults roundSplit or remainderToLast. Carrying those
        // flags forward would leave the instance CLAIMING a policy the
        // engaged path ignores, so the read-back always reports the mode's
        // truth. truncateOverflow stays: the min-share path itself never
        // truncates, so its only effect is on a later withMinShare(0)
        // return-to-default walk — a compat()/non-truncating instance keeps
        // the knob it was built with.
        return $this->mutate([
            'minShare' => $cells,
            'reserveGap' => $reserveGap,
            'reserveLead' => $reserveLead,
            'roundSplit' => false,
            'remainderToLast' => true,
        ]);
    }

    /**
     * Dedicated construction door for the sequential min-share floor: the
     * compat knobs arrive at the mode's truth (floor split, remainder-to-last;
     * truncateOverflow neutral-true) instead of inheriting a policy the floor
     * path ignores — the loud alternative to withMinShare()'s reset when the
     * instance is being built, not retuned.
     */
    public static function minShareFloor(int $cells = 1, int $reserveGap = 0, int $reserveLead = 0): self
    {
        return new self(
            roundSplit: false,
            remainderToLast: true,
            truncateOverflow: true,
            minShare: $cells,
            reserveGap: $reserveGap,
            reserveLead: $reserveLead,
        );
    }

    /**
     * Copy-on-write door for the flag mutators. Every field is a ctor-promoted
     * readonly property, so `get_object_vars($this)` returns exactly the ctor
     * argument set — a seventh field then costs one line here, not five site
     * touches. Mirrors {@see DockLayout::mutate()} in the same package.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * Instance solve — satisfies {@see LayoutSolver}.
     *
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    public function solve(Region $region, Direction $dir, array $constraints): array
    {
        if ($constraints === []) {
            return [];
        }

        self::guardConstraintList($constraints);

        if ($dir === Direction::Horizontal) {
            return $this->solveHorizontal($region, $constraints);
        }
        return $this->solveVertical($region, $constraints);
    }

    /**
     * Static convenience for the default (floor / remainder-first / truncating)
     * behaviour — reachable without holding an instance.
     *
     * NOTE the 2026-11 signature reorder: the legacy entry used to take
     * (area, constraints, direction), transposed against the instance method.
     * A repo-wide grep found no positional callers outside this lib's own
     * tests, so the order now matches {@see solve()} exactly. The compat()
     * toggles remain reachable only via an instance.
     *
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    public static function solveStatic(Region $area, Direction $dir, array $constraints): array
    {
        return (new self())->solve($area, $dir, $constraints);
    }

    /**
     * The integer APPETITE of a constraint set against the frame's solved
     * axis — the README's documented host-side degradation comparison,
     * mechanized: Length at its exact size, Percentage/Ratio resolved against
     * the span under this instance's rounding policy, Min at its floor, Max
     * at its cap, and Fill at 0 (it claims only slack, so it is never left
     * "unmet"). Demand above the span means the set cannot be satisfied
     * whole: either the proportional truncation fires (fixed + Min floors
     * already exceed the axis) or a slack consumer is served below its
     * appetite. Which pane lost what stays a per-frame question one integer
     * cannot split — see the README Degradation section, which still
     * recommends {@see withoutOverflowTruncation()} when collapse must be
     * visible per region. Same boundary refusals as {@see solve()}; the walk
     * is pure integer arithmetic (carve-out 4 applies to its products too).
     *
     * @param list<Constraint> $constraints
     */
    public function demandOf(Region $area, Direction $dir, array $constraints): int
    {
        self::guardConstraintList($constraints);
        $span = $dir === Direction::Horizontal ? $area->width : $area->height;

        $demand = 0;
        foreach ($constraints as $index => $c) {
            $demand += match (true) {
                $c instanceof Length => $c->n,
                $c instanceof Percentage => $this->resolveProportional($span, $c->n, 100),
                $c instanceof Ratio => $this->resolveProportional($span, $c->numerator, $c->denominator),
                $c instanceof Min => $c->n,
                $c instanceof Max => $c->n,
                $c instanceof Fill => 0,
                default => throw new \InvalidArgumentException(
                    'Unsupported constraint type at index ' . $index . ': ' . get_debug_type($c)
                ),
            };
        }
        return $demand;
    }

    /**
     * Boundary precondition shared by solve() and demandOf(): the size
     * bookkeeping indexes constraints positionally and the remainder passes
     * scan by integer offset, so a keyed or sparse array would silently
     * mis-align constraints against their sizes (and PHP would raise
     * "undefined key" notices per frame). The typed contract is a list —
     * refuse anything else loudly at the boundary instead.
     *
     * @param array<array-key, mixed> $constraints
     */
    private static function guardConstraintList(array $constraints): void
    {
        if (array_is_list($constraints) === false) {
            throw new \InvalidArgumentException(
                'Constraints must be a list (sequential array of Constraint); got a keyed or sparse array'
            );
        }
    }

    /**
     * Resolve a proportional constraint against a span under this
     * instance's rounding policy — the single arithmetic shared by the
     * allocation walk (solveHorizontal) and the demand walk (demandOf), so
     * the two can never drift on what a Percentage/Ratio "wants".
     */
    private function resolveProportional(int $span, int $numerator, int $denominator): int
    {
        // Compat mode rounds (sugar-panels distribute() uses round()); default floors.
        return $this->roundSplit === true
            ? (int) round($span * $numerator / $denominator)
            : (int) floor($span * $numerator / $denominator);
    }

    /**
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    private function solveHorizontal(Region $area, array $constraints): array
    {
        // Min-share floor short-circuits the whole greedy pipeline: it is a
        // self-contained sequential proportional split (see solveMinShare) that
        // reproduces sugar-panels's distribute(). Only engaged when opted in
        // (minShare > 0), so the default solver below is untouched.
        if ($this->minShare > 0) {
            return $this->solveMinShare($area, $constraints);
        }

        $totalWidth = $area->width;
        $height = $area->height;

        // Step 1: gather constraint sizes and metadata
        $rawSizes = [];
        $reservedFixed = 0;
        $reservedMinSum = 0;
        $fillWeightSum = 0;
        $maxWeightSum = 0;
        $proportionalCount = 0;

        foreach ($constraints as $index => $c) {
            if ($c instanceof Length) {
                $rawSizes[] = $c->n;
                $reservedFixed += $c->n;
            } elseif ($c instanceof Percentage) {
                $size = $this->resolveProportional($totalWidth, $c->n, 100);
                $rawSizes[] = $size;
                $reservedFixed += $size;
                $proportionalCount++;
            } elseif ($c instanceof Ratio) {
                $size = $this->resolveProportional($totalWidth, $c->numerator, $c->denominator);
                $rawSizes[] = $size;
                $reservedFixed += $size;
                $proportionalCount++;
            } elseif ($c instanceof Min) {
                $rawSizes[] = $c->n;
                $reservedMinSum += $c->n;
            } elseif ($c instanceof Fill) {
                $rawSizes[] = 0;
                $fillWeightSum += $c->weight;
            } elseif ($c instanceof Max) {
                $rawSizes[] = 0;
                $maxWeightSum += $c->n;
            } else {
                // Name the offender and where it sits — an anonymous refusal
                // sends the host hunting through its own constraint list.
                throw new \InvalidArgumentException(
                    'Unsupported constraint type at index ' . $index . ': ' . get_debug_type($c)
                );
            }
        }

        $totalCount = count($constraints);
        $totalReserved = $reservedFixed + $reservedMinSum;

        // Step 2: handle overflow — total exceeds area
        if ($totalReserved > $totalWidth) {
            if ($this->truncateOverflow === true) {
                // Truncate proportionally (exact integer floor where the
                // demand and product fit an int; float scale only past
                // PHP_INT_MAX, where the demand sum itself has become a float).
                $scale = $totalWidth / $totalReserved;
                $exact = is_int($totalReserved);
                $truncated = [];
                foreach ($rawSizes as $i => $size) {
                    $rawSizes[$i] = $exact && ($totalWidth === 0 || $size <= intdiv(PHP_INT_MAX, $totalWidth))
                        ? intdiv($size * $totalWidth, $totalReserved)
                        : (int) floor($size * $scale);
                    if ($size > 0) {
                        $truncated[] = $i;
                    }
                }
                // The per-region floor() loses under one cell each, so the
                // residual is < count($truncated): hand it back one cell per
                // truncated region, earliest first (latest first under
                // remainderToLast), so the sizes tile the area exactly.
                $residual = $totalWidth - array_sum($rawSizes);
                if ($truncated !== []) {
                    if ($this->remainderToLast === true) {
                        $truncated = array_reverse($truncated);
                    }
                    for ($k = 0; $residual > 0; $k = ($k + 1) % count($truncated)) {
                        $rawSizes[$truncated[$k]]++;
                        $residual--;
                    }
                    // Only the float fallback (products past PHP_INT_MAX) can
                    // over-allocate; take the excess back from the far end.
                    for ($k = count($truncated) - 1; $residual < 0; $k = ($k + count($truncated) - 1) % count($truncated)) {
                        if ($rawSizes[$truncated[$k]] > 0) {
                            $rawSizes[$truncated[$k]]--;
                            $residual++;
                        }
                    }
                }
            }
            // Compat mode (truncateOverflow=false): leave every region at its
            // full base size and let the layout run off-grid — sugar-panels's
            // distribute()/distributeFlex() never shrink fixed children, and
            // truncating them here is what regressed sugar-panels's
            // testFixedPanelsOverflowDegradeGracefully. No slack distribution
            // runs in the overflow branch either way.
        } else {
            // Step 3: distribute slack
            $slack = $totalWidth - $reservedFixed - $reservedMinSum;
            if ($slack > 0) {
                $totalDistWeight = $fillWeightSum + $maxWeightSum;

                if ($totalDistWeight > 0) {
                    // Fill and Max consume slack proportionally; Max is greedy here
                    foreach ($constraints as $i => $c) {
                        if ($c instanceof Fill) {
                            $rawSizes[$i] = (int) floor(($c->weight / $totalDistWeight) * $slack);
                        } elseif ($c instanceof Max) {
                            $rawSizes[$i] = (int) floor(($c->n / $totalDistWeight) * $slack);
                        }
                    }
                } else {
                    // No fills or maxes — distribute slack to mins proportionally
                    if ($reservedMinSum <= 0) {
                        // Every Min is min(0), so the proportional weight-sum is
                        // zero and $c->n / $reservedMinSum would divide by zero.
                        // Mirror applyMaxClamp()'s guard: fall back to EQUAL
                        // shares of the slack across the Min recipients, handing
                        // any rounding remainder to the first recipient so the
                        // produced sizes still sum to totalWidth.
                        $minRecipients = [];
                        foreach ($constraints as $i => $c) {
                            if ($c instanceof Min) {
                                $minRecipients[] = $i;
                            }
                        }
                        $count = count($minRecipients);
                        if ($count > 0) {
                            $remainder = $slack;
                            foreach ($minRecipients as $i) {
                                $share = intdiv($slack, $count);
                                $rawSizes[$i] = $share + $constraints[$i]->n;
                                $remainder -= $share;
                            }
                            if ($remainder > 0) {
                                $rawSizes[$minRecipients[0]] += $remainder;
                            }
                        }
                    } else {
                        $firstMin = null;
                        $remainder = $slack;
                        foreach ($constraints as $i => $c) {
                            if ($c instanceof Min) {
                                $share = $c->n <= intdiv(PHP_INT_MAX, $slack)
                                    ? intdiv($c->n * $slack, $reservedMinSum)
                                    : (int) floor(($c->n / $reservedMinSum) * $slack);
                                $rawSizes[$i] = $share + $c->n;
                                $remainder -= $share;
                                $firstMin ??= $i;
                            }
                        }
                        // The proportional floor() loses up to one cell per Min;
                        // like the equal-shares branch above, the first Min
                        // recipient absorbs it so the sizes tile the area.
                        // Compat mode leaves it for the remainder-to-last pass
                        // below, which already hands any diff to the last region.
                        if ($remainder > 0 && $firstMin !== null && $this->remainderToLast === false) {
                            $rawSizes[$firstMin] += $remainder;
                        }
                    }

                    $used = array_sum($rawSizes);
                    $diff = $totalWidth - $used;
                    if ($this->remainderToLast === true) {
                        // Compat mode: the LAST region absorbs the entire
                        // remainder, mirroring sugar-panels distribute() where the
                        // final child = span - sum(others). The remainder can be
                        // negative (round() over-allocated); clamp the resulting
                        // width to >= 0 so Region stays non-negative.
                        if ($diff !== 0 && $totalCount > 0) {
                            $lastIdx = $totalCount - 1;
                            $rawSizes[$lastIdx] = max(0, $rawSizes[$lastIdx] + $diff);
                        }
                    } elseif ($diff > 0 && $diff <= max(0, $proportionalCount - 1)) {
                        // Rounding reclamation: pure Percentage/Ratio layouts lose
                        // cells to floor(). Distribute leftover round-robin to
                        // Percentage/Ratio entries only (ratatui "give remainder to
                        // earlier segments first"). Min/Length are exact values and
                        // must not be inflated. Guard: N floored shares whose exact
                        // values tile the area fall short by at most N-1 cells, so
                        // only a diff within that bound is rounding noise; anything
                        // larger (or any diff beside a lone Percentage/Ratio, which
                        // cannot lose a whole cell) is intentional slack.
                        for ($i = 0; $i < $totalCount && $diff > 0; $i++) {
                            $c = $constraints[$i];
                            if ($c instanceof Percentage || $c instanceof Ratio) {
                                $rawSizes[$i] += 1;
                                $diff--;
                            }
                        }
                    }
                }

                // Rounding error: give the WHOLE remainder to the first Fill
                // (or Max if no Fill). floor() distribution can lose more than
                // one cell when several Fills compete (e.g. 3 fills lose up to
                // 2 cells), so adding a fixed ±1 under-corrects and the sizes
                // fail to tile the region — assign the full $diff instead.
                // Compat mode scans from the LAST Fill/Max instead of the first
                // (sugar-panels distributeFlex() lets the final flex child absorb
                // the remainder).
                if ($totalDistWeight > 0) {
                    $usedWidth = 0;
                    foreach ($rawSizes as $s) {
                        $usedWidth += $s;
                    }
                    $diff = $totalWidth - $usedWidth;
                    if ($diff !== 0) {
                        $order = range(0, $totalCount - 1);
                        if ($this->remainderToLast === true) {
                            $order = array_reverse($order);
                        }
                        foreach ($order as $i) {
                            if ($constraints[$i] instanceof Fill) {
                                $rawSizes[$i] += $diff;
                                $diff = 0;
                                break;
                            }
                        }
                        // If no Fill, try Max
                        if ($diff !== 0) {
                            foreach ($order as $i) {
                                if ($constraints[$i] instanceof Max) {
                                    $rawSizes[$i] += $diff;
                                    $diff = 0;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Step 4: apply Max clamp pass — clamp overages, redistribute reclaimed space
        $rawSizes = $this->applyMaxClamp($constraints, $rawSizes);

        // Step 5: build output Rects
        $x = $area->x;
        $rects = [];
        foreach ($rawSizes as $width) {
            $rects[] = new Region($x, $area->y, $width, $height);
            $x += $width;
        }
        return $rects;
    }

    /**
     * Sequential min-share floor — reproduces sugar-panels's NON-flex
     * distribute() byte-for-byte.
     *
     * Walks the constraints left→right, giving each region its rounded
     * proportional share of the span (`round(weight/total * span)`) but clamping
     * that share so at least `minShare` cell(s) survive for EVERY region still to
     * be placed — the reservation that keeps the trailing region from collapsing
     * to 0 in a tight viewport. The final region absorbs the remainder. The
     * clamp is measured against a running offset seeded with `reserveLead` and
     * bumped by `reserveGap` per placed region, which re-creates the
     * absolute-position frame distribute()'s `$used` counter uses (border pad +
     * one inter-child gap per placed child) so spacing/border tight cases stay
     * byte-identical.
     *
     * Only Fill constraints participate: distribute() is a purely proportional
     * split (children weighted by minWidth, defaulting to 1 → Fill(weight)); any
     * other constraint type is unsupported here and rejected loudly rather than
     * silently mis-sized.
     *
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    private function solveMinShare(Region $area, array $constraints): array
    {
        $span = $area->width;
        $n = count($constraints);

        $weights = [];
        foreach ($constraints as $c) {
            if ($c instanceof Fill === false) {
                throw new \InvalidArgumentException(
                    'Min-share floor supports only Fill constraints (proportional split); got ' . $c::class
                );
            }
            $weights[] = $c->weight;
        }

        $totalWeight = array_sum($weights);
        if ($totalWeight === 0) {
            // Every Fill weight is 0 → equal split (mirrors distribute()'s
            // "all weights 0 → distribute equally" division-by-zero guard).
            $totalWeight = $n;
            $weights = array_fill(0, $n, 1);
        }

        $sizes = [];
        $used = $this->reserveLead;
        for ($i = 0; $i < $n - 1; $i++) {
            $share = (int) round($weights[$i] / $totalWeight * $span);
            $remaining = $n - 1 - $i; // regions still to place, incl. the last
            $cap = $span - $used - $remaining * $this->minShare;
            $share = max(0, min($share, $cap));
            $sizes[] = $share;
            $used += $share + $this->reserveGap;
        }
        // Last region takes the remainder; clamp ≥ 0 for Region's invariant
        // (distribute() never emits a negative trailing size, so this only
        // guards a degenerate span).
        $sizes[] = max(0, $span - array_sum($sizes));

        $x = $area->x;
        $rects = [];
        foreach ($sizes as $width) {
            $rects[] = new Region($x, $area->y, $width, $area->height);
            $x += $width;
        }
        return $rects;
    }

    /**
     * Clamp sizes that exceed their Max constraint, then redistribute reclaimed space.
     *
     * Reclaim ladder (priority order): Min first (weighted by their clamped
     * sizes), else Fill (weighted by Fill weight), else — when the set carries
     * neither — Length/Percentage/Ratio, weighted by their clamped sizes.
     * The last rung deliberately INFLATES "fixed" sizes: Length(10) + Max(5)
     * in a 100-cell region yields [95, 5], not [10, 90→5 + gap]. That is the
     * pinned intent — tiling the area exactly beats honouring the nominal
     * fixed size once the only alternative is an untiled gap (a set with no
     * Min/Fill has nothing else to grow). WidthSumInvariantSweepTest carries
     * this shape as a named exception.
     *
     * @param list<Constraint> $constraints
     * @param list<int> $rawSizes
     * @return list<int>
     */
    private function applyMaxClamp(array $constraints, array $rawSizes): array
    {
        $hasMax = false;
        foreach ($constraints as $c) {
            if ($c instanceof Max) {
                $hasMax = true;
                break;
            }
        }
        if ($hasMax === false) {
            return $rawSizes;
        }

        // First pass: clamp any size exceeding its Max, reclaim the excess
        $clamped = [];
        $reclaimed = 0;
        foreach ($constraints as $i => $c) {
            if ($c instanceof Max && $rawSizes[$i] > $c->n) {
                $reclaimed += $rawSizes[$i] - $c->n;
                $clamped[$i] = $c->n;
            } else {
                $clamped[$i] = $rawSizes[$i];
            }
        }

        if ($reclaimed === 0) {
            return $rawSizes;
        }

        // Second pass: redistribute reclaimed space.
        // Priority: Min > Fill > Length/Percentage/Ratio (if no Min/Fill).
        // Min takes reclaimed space first. Fill takes it if no Min.
        // If neither Min nor Fill, Length/Percentage/Ratio absorb it.
        $minRecipients = [];
        $minWeights = [];
        $hasMin = false;

        foreach ($constraints as $i => $c) {
            if ($c instanceof Min) {
                $minRecipients[] = $i;
                $minWeights[] = $clamped[$i] > 0 ? $clamped[$i] : 1;
                $hasMin = true;
            }
        }

        $recipients = [];
        $recipientWeights = [];

        if ($hasMin === true) {
            // Give reclaimed space to Min constraints
            $recipients = $minRecipients;
            $recipientWeights = $minWeights;
        } else {
            // Check for Fill
            $fillRecipients = [];
            foreach ($constraints as $i => $c) {
                if ($c instanceof Fill) {
                    $fillRecipients[] = $i;
                }
            }

            if ($fillRecipients !== []) {
                // Give reclaimed space to Fill constraints
                foreach ($fillRecipients as $i) {
                    $c = $constraints[$i];
                    $recipients[] = $i;
                    $recipientWeights[] = $c->weight; // $fillRecipients indexes only Fill entries
                }
            } else {
                // No Min, no Fill — give to Length/Percentage/Ratio
                foreach ($constraints as $i => $c) {
                    if ($c instanceof Length || $c instanceof Percentage || $c instanceof Ratio) {
                        $recipients[] = $i;
                        $recipientWeights[] = $clamped[$i] > 0 ? $clamped[$i] : 1;
                    }
                }
                // If still no recipients, reclaimed space stays unused
                if ($recipients === []) {
                    return $clamped;
                }
            }
        }

        $totalWeight = array_sum($recipientWeights);
        $remainder = $reclaimed;

        if ($totalWeight <= 0) {
            // Every recipient carries zero weight — e.g. the recipients are all
            // Fill(0). Proportional distribution would divide by zero, so fall
            // back to EQUAL shares. Keeps the sum invariant intact.
            $count = count($recipients);
            foreach ($recipients as $i) {
                $share = intdiv($reclaimed, $count);
                $clamped[$i] += $share;
                $remainder -= $share;
            }
        } else {
            foreach ($recipients as $idx => $i) {
                $share = (int) floor(($recipientWeights[$idx] / $totalWeight) * $reclaimed);
                $clamped[$i] += $share;
                $remainder -= $share;
            }
        }

        // Distribute rounding remainder to first recipient
        if ($remainder > 0 && $recipients !== []) {
            $clamped[$recipients[0]] += $remainder;
        }

        return $clamped;
    }

    /**
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    private function solveVertical(Region $area, array $constraints): array
    {
        $totalHeight = $area->height;
        $width = $area->width;

        // Flip area to use horizontal solver on the "other" dimension.
        // Origin must be 0,0 — the flip-back below re-adds $area->x/$area->y.
        // Cost note (PERF, finding 027): the transpose allocates 2n Regions
        // per vertical solve (n fake + n remapped) on a per-frame path —
        // rated "nuisance not a wound" at terminal sizes; the anchor for a
        // future hot-path report lives here.
        $fakeArea = new Region(0, 0, $totalHeight, $width);
        $hRects = $this->solveHorizontal($fakeArea, $constraints);

        // Flip x/y and width/height back to original orientation
        $rects = [];
        foreach ($hRects as $r) {
            $rects[] = new Region($area->x + $r->y, $area->y + $r->x, $r->height, $r->width);
        }
        return $rects;
    }
}
