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
 * Internal solver: maps a Rect + list of Constraints to a list of Rects.
 *
 * Algorithm (ratatui-inspired, simplified — no cassowary):
 *  1. Compute Percentage and Ratio against total area → absolute sizes.
 *  2. Sum fixed Length + computed Percentage/Ratio as reserved space.
 *  3. Min is a floor; Max is a ceiling (greedy — takes remaining space).
 *     If slack < sum-of-mins, all mins clamped proportionally.
 *  4. Remaining slack distributed across Fill() and Max() constraints
 *     proportionally (Max is greedy here; clamp pass reduces it).
 *  5. If no Fill/Max, slack goes to Min constraints proportionally.
 *  6. Apply Max clamp pass; reclaimed space redistributed to Fill > Min > others.
 *  7. If total reserved > area, truncate proportionally and warn.
 *
 * ── boxer-compat mode ──────────────────────────────────────────────────────
 * The default behaviour (floor split, rounding remainder handed to the FIRST
 * region, proportional overflow truncation) diverges on three axes from
 * sugar-boxer's hand-rolled distribute()/distributeFlex(). {@see compat()}
 * (or the granular {@see withRoundSplit()}/{@see withRemainderToLast()}/
 * {@see withoutOverflowTruncation()} toggles) flips exactly those three so a
 * consumer can reproduce sugar-boxer's distribution byte-for-byte:
 *   1. round() instead of floor() for Percentage/Ratio proportional sizes;
 *   2. the rounding remainder is handed to the LAST region (or LAST Fill/Max)
 *      instead of the first;
 *   3. on overflow, each region keeps its full base size (layout runs
 *      off-grid) instead of being truncated proportionally.
 * These flags are immutable+fluent and default OFF, so the default solver is
 * unchanged. This exists only to let sugar-boxer retire its duplicated solver
 * (plan_missing.md W12) with golden parity.
 *
 * ── min-share floor ─────────────────────────────────────────────────────────
 * sugar-boxer's NON-flex distribute() carries a FOURTH quirk the three flags
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
 * so the default solver is unchanged. A 11 124-case sweep against distribute()
 * found 0 divergences (plan_missing.md W12).
 */
final class GreedySolver implements LayoutSolver
{
    /**
     * @param bool $roundSplit        round() (not floor()) Percentage/Ratio sizes — sugar-boxer distribute().
     * @param bool $remainderToLast   hand the rounding remainder to the LAST region/Fill/Max, not the first.
     * @param bool $truncateOverflow  proportionally shrink regions when demand exceeds the area (default);
     *                                sugar-boxer keeps each region at full base size (pass false).
     * @param int  $minShare          0 = OFF (default). >0 engages the sequential min-share floor over Fill
     *                                weights, reserving this many cell(s) for every not-yet-placed region.
     * @param int  $reserveGap        extra cells reserved per ALREADY-placed region — models an inter-region
     *                                gap that lives outside the solved content span (sugar-boxer spacing).
     * @param int  $reserveLead       fixed cells reserved before the first region — models a leading margin
     *                                outside the content span (sugar-boxer border pad).
     *
     * Public (not private) because candy-sprinkles' SolverFactory and
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
     * @return CassowarySolver
     */
    public static function cassowary(): CassowarySolver
    {
        return new CassowarySolver();
    }

    /**
     * sugar-boxer parity mode: round-split + remainder-to-last + non-truncating
     * overflow. Opt-in seam for retiring sugar-boxer's duplicated distribute()
     * /distributeFlex() (plan_missing.md W12) without regressing its goldens.
     */
    public static function compat(): self
    {
        return new self(roundSplit: true, remainderToLast: true, truncateOverflow: false);
    }

    /**
     * Round Percentage/Ratio proportional sizes with round() instead of floor().
     */
    public function withRoundSplit(bool $on = true): self
    {
        return new self($on, $this->remainderToLast, $this->truncateOverflow, $this->minShare, $this->reserveGap, $this->reserveLead);
    }

    /**
     * Hand the rounding remainder to the LAST region (or LAST Fill/Max) instead
     * of the first.
     */
    public function withRemainderToLast(bool $on = true): self
    {
        return new self($this->roundSplit, $on, $this->truncateOverflow, $this->minShare, $this->reserveGap, $this->reserveLead);
    }

    /**
     * Keep each region at its full base size on overflow (layout runs off-grid)
     * instead of truncating proportionally.
     */
    public function withoutOverflowTruncation(): self
    {
        return new self($this->roundSplit, $this->remainderToLast, false, $this->minShare, $this->reserveGap, $this->reserveLead);
    }

    /**
     * Re-enable (or disable) proportional overflow truncation.
     */
    public function withOverflowTruncation(bool $on = true): self
    {
        return new self($this->roundSplit, $this->remainderToLast, $on, $this->minShare, $this->reserveGap, $this->reserveLead);
    }

    /**
     * Engage the sequential min-share floor: split the region proportionally
     * across its Fill weights while reserving at least `$cells` cell(s) for every
     * region NOT yet placed, so the trailing region never collapses to 0 in a
     * tight viewport. This is the opt-in seam that lets sugar-boxer retire its
     * hand-rolled NON-flex distribute() (plan_missing.md W12) with byte parity —
     * the fourth quirk compat()'s three flags cannot express.
     *
     * The floor path is inherently round-split + remainder-to-last, so it does
     * not need (and ignores) the compat() flags. Pass `$reserveGap`/`$reserveLead`
     * to re-create distribute()'s absolute-position reservation frame: sugar-boxer
     * seeds its running offset with the border pad ($reserveLead) and adds one
     * inter-child spacing per placed child ($reserveGap). With both 0 the floor
     * reserves purely in the content frame.
     *
     * `$cells = 0` disables the floor (restores the default solver path).
     */
    public function withMinShare(int $cells = 1, int $reserveGap = 0, int $reserveLead = 0): self
    {
        return new self($this->roundSplit, $this->remainderToLast, $this->truncateOverflow, $cells, $reserveGap, $reserveLead);
    }

    /**
     * Instance solve — satisfies {@see LayoutSolver}.
     */
    public function solve(Region $region, Direction $dir, array $constraints): array
    {
        if ($constraints === []) {
            return [];
        }

        if ($dir === Direction::Horizontal) {
            return $this->solveHorizontal($region, $constraints);
        }
        return $this->solveVertical($region, $constraints);
    }

    /**
     * Static solver — kept for golden-test parity with candy-sprinkles. Always
     * uses the DEFAULT (floor / remainder-first / truncating) behaviour; the
     * boxer-compat toggles are reachable only via an instance.
     *
     * @param Constraint[] $constraints
     * @return Region[]
     */
    public static function solveStatic(Region $area, array $constraints, Direction $dir): array
    {
        return (new self())->solve($area, $dir, $constraints);
    }

    /**
     * @param Constraint[] $constraints
     * @return Region[]
     */
    private function solveHorizontal(Region $area, array $constraints): array
    {
        // Min-share floor short-circuits the whole greedy pipeline: it is a
        // self-contained sequential proportional split (see solveMinShare) that
        // reproduces sugar-boxer's distribute(). Only engaged when opted in
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

        foreach ($constraints as $c) {
            if ($c instanceof Length) {
                $rawSizes[] = $c->n;
                $reservedFixed += $c->n;
            } elseif ($c instanceof Percentage) {
                // boxer-compat rounds (distribute() uses round()); default floors.
                $size = $this->roundSplit
                    ? (int) round($totalWidth * $c->n / 100)
                    : (int) floor($totalWidth * $c->n / 100);
                $rawSizes[] = $size;
                $reservedFixed += $size;
            } elseif ($c instanceof Ratio) {
                $size = $this->roundSplit
                    ? (int) round($totalWidth * $c->numerator / $c->denominator)
                    : (int) floor($totalWidth * $c->numerator / $c->denominator);
                $rawSizes[] = $size;
                $reservedFixed += $size;
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
                throw new \InvalidArgumentException('Unsupported constraint type');
            }
        }

        $totalCount = count($constraints);
        $totalReserved = $reservedFixed + $reservedMinSum;

        // Step 2: handle overflow — total exceeds area
        if ($totalReserved > $totalWidth) {
            if ($this->truncateOverflow) {
                // Truncate proportionally
                $scale = $totalWidth / $totalReserved;
                foreach ($rawSizes as $i => $size) {
                    $rawSizes[$i] = (int) floor($size * $scale);
                }
            }
            // boxer-compat (truncateOverflow=false): leave every region at its
            // full base size and let the layout run off-grid — sugar-boxer's
            // distribute()/distributeFlex() never shrink fixed children, and
            // truncating them here is what regressed sugar-boxer's
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
                        foreach ($constraints as $i => $c) {
                            if ($c instanceof Min) {
                                $rawSizes[$i] = (int) floor(($c->n / $reservedMinSum) * $slack) + $c->n;
                            }
                        }
                    }

                    $used = array_sum($rawSizes);
                    $diff = $totalWidth - $used;
                    if ($this->remainderToLast) {
                        // boxer-compat: the LAST region absorbs the entire
                        // remainder, mirroring sugar-boxer distribute() where the
                        // final child = span - sum(others). The remainder can be
                        // negative (round() over-allocated); clamp the resulting
                        // width to >= 0 so Region stays non-negative.
                        if ($diff !== 0 && $totalCount > 0) {
                            $lastIdx = $totalCount - 1;
                            $rawSizes[$lastIdx] = max(0, $rawSizes[$lastIdx] + $diff);
                        }
                    } elseif ($diff > 0 && $diff <= 2) {
                        // Rounding reclamation: pure Percentage/Ratio layouts lose
                        // pixels to floor(). Distribute leftover round-robin to
                        // Percentage/Ratio entries only (ratatui "give remainder to
                        // earlier segments first"). Min/Length are exact values and
                        // must not be inflated. Guard: only correct genuine floor
                        // rounding (small diff <= 2), not large shortfalls that
                        // represent intentional slack.
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
                // boxer-compat scans from the LAST Fill/Max instead of the first
                // (sugar-boxer distributeFlex() lets the final flex child absorb
                // the remainder).
                if ($totalDistWeight > 0) {
                    $usedWidth = 0;
                    foreach ($rawSizes as $s) {
                        $usedWidth += $s;
                    }
                    $diff = $totalWidth - $usedWidth;
                    if ($diff !== 0) {
                        $order = range(0, $totalCount - 1);
                        if ($this->remainderToLast) {
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
     * Sequential min-share floor — reproduces sugar-boxer's NON-flex
     * distribute() byte-for-byte (plan_missing.md W12).
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
     * @param Constraint[] $constraints
     * @return Region[]
     */
    private function solveMinShare(Region $area, array $constraints): array
    {
        $span = $area->width;
        $n = count($constraints);

        $weights = [];
        foreach ($constraints as $c) {
            if (!$c instanceof Fill) {
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
     * @param Constraint[] $constraints
     * @param int[] $rawSizes
     * @return int[]
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
        if (!$hasMax) {
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

        if ($hasMin) {
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
                    $recipientWeights[] = $c instanceof Fill ? $c->weight : 1;
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
     * @param Constraint[] $constraints
     * @return Region[]
     */
    private function solveVertical(Region $area, array $constraints): array
    {
        $totalHeight = $area->height;
        $width = $area->width;

        // Flip area to use horizontal solver on the "other" dimension.
        // Origin must be 0,0 — the flip-back below re-adds $area->x/$area->y.
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
