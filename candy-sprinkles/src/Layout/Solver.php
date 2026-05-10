<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

/**
 * Internal solver: maps a Rect + list of Constraints to a list of Rects.
 *
 * Algorithm (ratatui-inspired, simplified — no cassowary):
 *  1. Sum fixed Length() values as reserved.
 *  2. Each Min() is a floor — if slack < sum-of-mins, all mins must be
 *     clamped down proportionally (warn in this case, per ratatui silent
 *     truncation).
 *  3. Remaining slack distributed across Fill() constraints proportionally
 *     by weight.
 *  4. If total reserved > area, truncate everything proportionally and warn.
 */
final class Solver
{
    /**
     * @param Constraint[] $constraints
     * @return Rect[]
     */
    public static function solve(Rect $area, array $constraints, Direction $dir): array
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
     * @return Rect[]
     */
    private static function solveHorizontal(Rect $area, array $constraints): array
    {
        $totalWidth = $area->width;
        $height = $area->height;

        // Step 1: gather constraint sizes and metadata
        $rawSizes = [];
        $reservedFixed = 0;
        $reservedMinSum = 0;
        $fillWeightSum = 0;

        foreach ($constraints as $c) {
            if ($c instanceof Length) {
                $rawSizes[] = $c->n;
                $reservedFixed += $c->n;
            } elseif ($c instanceof Min) {
                $rawSizes[] = $c->n;
                $reservedMinSum += $c->n;
            } elseif ($c instanceof Fill) {
                $rawSizes[] = 0;
                $fillWeightSum += $c->weight;
            } else {
                throw new \InvalidArgumentException('Unsupported constraint type');
            }
        }

        $totalCount = count($constraints);

        // Step 2: handle overflow — total exceeds area
        $totalReserved = $reservedFixed + $reservedMinSum;
        if ($totalReserved > $totalWidth) {
            // Truncate proportionally
            $scale = $totalWidth / $totalReserved;
            foreach ($rawSizes as $i => $size) {
                $rawSizes[$i] = (int) floor($size * $scale);
            }
        } else {
            // Step 3: distribute slack
            $slack = $totalWidth - $reservedFixed - $reservedMinSum;
            if ($slack < 0) {
                // Not enough room for all mins — distribute shortage proportionally
                $scale = $totalWidth / $reservedMinSum;
                foreach ($rawSizes as $i => $size) {
                    $rawSizes[$i] = (int) floor($size * $scale);
                }
            } elseif ($slack > 0) {
                if ($fillWeightSum > 0) {
                    // Give slack to fills proportionally by weight
                    foreach ($constraints as $i => $c) {
                        if ($c instanceof Fill) {
                            $rawSizes[$i] = (int) floor(($c->weight / $fillWeightSum) * $slack);
                        }
                    }
                } else {
                    // No fills — distribute slack to mins proportionally by their floor
                    foreach ($constraints as $i => $c) {
                        if ($c instanceof Min) {
                            $rawSizes[$i] = (int) floor(($c->n / $reservedMinSum) * $slack) + $c->n;
                        }
                    }
                }
            }

            // Rounding error: distribute leftover cell(s) to first Fill or Min
            if ($fillWeightSum > 0) {
                $usedWidth = array_sum($rawSizes);
                $diff = $totalWidth - $usedWidth;
                if ($diff !== 0) {
                    for ($i = 0; $i < $totalCount && $diff !== 0; $i++) {
                        if ($constraints[$i] instanceof Fill) {
                            $rawSizes[$i] += $diff > 0 ? 1 : -1;
                            $diff = 0;
                            break;
                        }
                    }
                }
            }
        }

        // Step 4: build output Rects
        $x = $area->x;
        $rects = [];
        foreach ($rawSizes as $width) {
            $rects[] = new Rect($x, $area->y, $width, $height);
            $x += $width;
        }
        return $rects;
    }

    /**
     * @param Constraint[] $constraints
     * @return Rect[]
     */
    private static function solveVertical(Rect $area, array $constraints): array
    {
        $totalHeight = $area->height;
        $width = $area->width;

        // Flip area to use horizontal solver on the "other" dimension
        $fakeArea = new Rect($area->x, $area->y, $totalHeight, $width);
        $hRects = self::solveHorizontal($fakeArea, $constraints);

        // Flip x/y and width/height back to original orientation
        $rects = [];
        foreach ($hRects as $r) {
            $rects[] = new Rect($area->x + $r->y, $area->y + $r->x, $r->height, $r->width);
        }
        return $rects;
    }
}
