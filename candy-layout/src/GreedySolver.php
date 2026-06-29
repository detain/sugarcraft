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
 */
final class GreedySolver implements LayoutSolver
{
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
     * Instance solve — satisfies {@see LayoutSolver}.
     */
    public function solve(Region $region, Direction $dir, array $constraints): array
    {
        return self::solveStatic($region, $constraints, $dir);
    }

    /**
     * Static solver — kept for golden-test parity with candy-sprinkles.
     *
     * @param Constraint[] $constraints
     * @return Region[]
     */
    public static function solveStatic(Region $area, array $constraints, Direction $dir): array
    {
        if ($constraints === []) {
            return [];
        }

        if ($dir === Direction::Horizontal) {
            return self::solveHorizontal($area, $constraints);
        }
        return self::solveVertical($area, $constraints);
    }

    /**
     * @param Constraint[] $constraints
     * @return Region[]
     */
    private static function solveHorizontal(Region $area, array $constraints): array
    {
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
                $size = (int) floor($totalWidth * $c->n / 100);
                $rawSizes[] = $size;
                $reservedFixed += $size;
            } elseif ($c instanceof Ratio) {
                $size = (int) floor($totalWidth * $c->numerator / $c->denominator);
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
            // Truncate proportionally
            $scale = $totalWidth / $totalReserved;
            foreach ($rawSizes as $i => $size) {
                $rawSizes[$i] = (int) floor($size * $scale);
            }
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
                    foreach ($constraints as $i => $c) {
                        if ($c instanceof Min) {
                            $rawSizes[$i] = (int) floor(($c->n / $reservedMinSum) * $slack) + $c->n;
                        }
                    }

                    // Rounding reclamation: pure Percentage/Ratio layouts lose pixels
                    // to floor(). Distribute leftover round-robin to Percentage/Ratio
                    // entries only (ratatui "give remainder to earlier segments first").
                    // Min/Length are exact values and must not be inflated.
                    // Guard: only correct genuine floor rounding (small diff <= 2),
                    // not large shortfalls that represent intentional slack.
                    $used = array_sum($rawSizes);
                    $diff = $totalWidth - $used;
                    if ($diff > 0 && $diff <= 2) {
                        for ($i = 0; $i < $totalCount && $diff > 0; $i++) {
                            $c = $constraints[$i];
                            if ($c instanceof Percentage || $c instanceof Ratio) {
                                $rawSizes[$i] += 1;
                                $diff--;
                            }
                        }
                    }
                }

                // Rounding error: distribute to first Fill or Max
                if ($totalDistWeight > 0) {
                    $usedWidth = 0;
                    foreach ($rawSizes as $s) {
                        $usedWidth += $s;
                    }
                    $diff = $totalWidth - $usedWidth;
                    if ($diff !== 0) {
                        for ($i = 0; $i < $totalCount && $diff !== 0; $i++) {
                            if ($constraints[$i] instanceof Fill) {
                                $rawSizes[$i] += $diff > 0 ? 1 : -1;
                                $diff = 0;
                                break;
                            }
                        }
                        // If no Fill, try Max
                        if ($diff !== 0) {
                            for ($i = 0; $i < $totalCount && $diff !== 0; $i++) {
                                if ($constraints[$i] instanceof Max) {
                                    $rawSizes[$i] += $diff > 0 ? 1 : -1;
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
        $rawSizes = self::applyMaxClamp($constraints, $rawSizes);

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
     * Clamp sizes that exceed their Max constraint, then redistribute reclaimed space.
     *
     * @param Constraint[] $constraints
     * @param int[] $rawSizes
     * @return int[]
     */
    private static function applyMaxClamp(array $constraints, array $rawSizes): array
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
        foreach ($recipients as $idx => $i) {
            $share = (int) floor(($recipientWeights[$idx] / $totalWeight) * $reclaimed);
            $clamped[$i] += $share;
            $remainder -= $share;
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
    private static function solveVertical(Region $area, array $constraints): array
    {
        $totalHeight = $area->height;
        $width = $area->width;

        // Flip area to use horizontal solver on the "other" dimension.
        // Origin must be 0,0 — the flip-back at line 326 re-adds $area->x/$area->y.
        $fakeArea = new Region(0, 0, $totalHeight, $width);
        $hRects = self::solveHorizontal($fakeArea, $constraints);

        // Flip x/y and width/height back to original orientation
        $rects = [];
        foreach ($hRects as $r) {
            $rects[] = new Region($area->x + $r->y, $area->y + $r->x, $r->height, $r->width);
        }
        return $rects;
    }
}
