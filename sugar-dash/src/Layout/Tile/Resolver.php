<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * Resolver for tile layout dimensions using a 5-phase algorithm.
 * Mirrors tealeaves/tealayout_resolve.go.
 */
final class Resolver
{
    /**
     * Resolve a single axis of layout: given available space, constraints,
     * gap between children, and optional SizeHinters (may be nil or contain
     * nil entries), it returns the assigned size for each child.
     *
     * @param Constraint[] $constraints
     * @param SizeHinter[]|null $hinters
     * @return int[] sizes
     */
    public static function resolveLinear(
        int $available,
        array $constraints,
        int $gap,
        ?array $hinters = null,
        bool $horizontal = true,
    ): array {
        $n = \count($constraints);
        if ($n === 0) {
            return [];
        }

        // Normalize hinters slice
        if ($hinters === null || \count($hinters) < $n) {
            $padded = [];
            for ($i = 0; $i < $n; $i++) {
                $padded[$i] = $hinters[$i] ?? null;
            }
            $hinters = $padded;
        }

        $sizes = array_fill(0, $n, 0);
        $active = array_fill(0, $n, true);

        [$sizes, ] = self::resolveWithOptionalRemoval($available, $constraints, $gap, $hinters, $sizes, $active, $horizontal);
        return $sizes;
    }

    /**
     * resolveWithOptionalRemoval runs the full 5-phase algorithm including
     * optional child removal and retry.
     *
     * @param Constraint[] $constraints
     * @param SizeHinter[] $hinters
     * @return array{0: int[], 1: bool[]}
     */
    private static function resolveWithOptionalRemoval(
        int $available,
        array $constraints,
        int $gap,
        array $hinters,
        array $sizes,
        array $active,
        bool $horizontal,
    ): array {
        $n = \count($constraints);

        // Apply minSizeFit: query hinters for effective minimums.
        $work = [];
        for ($i = 0; $i < $n; $i++) {
            $work[$i] = $constraints[$i];
        }
        for ($i = 0; $i < $n; $i++) {
            $c = $work[$i];
            if ($c->minSizeFit && $hinters[$i] !== null) {
                $hint = $hinters[$i]->sizeHint($available, $available);
                $minVal = $horizontal ? $hint->min->width : $hint->min->height;
                if ($minVal > $work[$i]->minSize) {
                    $work[$i] = $work[$i]->withMinSize($minVal);
                }
            }
        }

        while (true) {
            // Count active children for gap calculation
            $activeCount = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($active[$i]) {
                    $activeCount++;
                }
            }

            $gapTotal = 0;
            if ($activeCount > 1) {
                $gapTotal = $gap * ($activeCount - 1);
            }

            $remaining = $available - $gapTotal;
            if ($remaining < 0) {
                $remaining = 0;
            }

            // Phase 1: Resolve Fixed and Fit children
            $remaining = self::resolveFixedAndFit($remaining, $work, $hinters, $sizes, $active, $horizontal);

            // Phase 2+3: Distribute remaining to Flex with clamping loop
            self::distributeFlexWithClamping($remaining, $work, $sizes, $active);

            // Phase 4: Check optional children — remove any below MinSize
            $removed = false;
            for ($i = 0; $i < $n; $i++) {
                if (!$active[$i]) {
                    continue;
                }
                $c = $work[$i];
                if ($c->optional && $c->minSize > 0 && $sizes[$i] < $c->minSize) {
                    $active[$i] = false;
                    $sizes[$i] = 0;
                    $removed = true;
                }
            }

            if (!$removed) {
                break;
            }

            // Reset non-removed sizes and retry from Phase 1
            for ($i = 0; $i < $n; $i++) {
                if ($active[$i]) {
                    $sizes[$i] = 0;
                }
            }
        }

        return [$sizes, $active];
    }

    /**
     * resolveFixedAndFit handles Phase 1: assign sizes to Fixed and Fit children,
     * subtract from remaining. Returns updated remaining space.
     *
     * @param Constraint[] $constraints
     * @param SizeHinter[] $hinters
     */
    private static function resolveFixedAndFit(
        int $remaining,
        array $constraints,
        array $hinters,
        array &$sizes,
        array $active,
        bool $horizontal,
    ): int {
        $n = \count($constraints);
        for ($i = 0; $i < $n; $i++) {
            if (!$active[$i]) {
                continue;
            }
            $c = $constraints[$i];
            switch ($c->kind) {
                case ConstraintKind::Fixed:
                    $size = self::clampConstraint($c->fixedSize, $c);
                    $sizes[$i] = $size;
                    $remaining -= $size;
                    break;

                case ConstraintKind::Fit:
                    $desired = 0;
                    if ($hinters[$i] !== null) {
                        $hint = $hinters[$i]->sizeHint($remaining, 0);
                        $desired = $horizontal ? $hint->desired->width : $hint->desired->height;
                    }
                    $size = self::clampConstraint($desired, $c);
                    $sizes[$i] = $size;
                    $remaining -= $size;
                    break;

                default:
                    break;
            }
        }
        if ($remaining < 0) {
            $remaining = 0;
        }
        return $remaining;
    }

    /**
     * distributeFlexWithClamping handles Phases 2+3: distribute remaining space
     * to Flex children using cumulative rounding, then clamp and redistribute
     * until stable.
     *
     * @param Constraint[] $constraints
     */
    private static function distributeFlexWithClamping(
        int $remaining,
        array $constraints,
        array &$sizes,
        array $active,
    ): void {
        $n = \count($constraints);

        // Track which flex children are frozen (clamped at min/max)
        $frozen = array_fill(0, $n, false);

        // Clamping loop — max N iterations (one child freezes per iteration worst case)
        for ($iter = 0; $iter < $n; $iter++) {
            // Collect unfrozen flex children
            $totalWeight = 0.0;
            $flexAvailable = $remaining;
            for ($i = 0; $i < $n; $i++) {
                if (!$active[$i] || $constraints[$i]->kind !== ConstraintKind::Flex) {
                    continue;
                }
                if ($frozen[$i]) {
                    $flexAvailable -= $sizes[$i];
                    continue;
                }
                $totalWeight += $constraints[$i]->flexWeight;
            }

            if ($totalWeight == 0 || $flexAvailable <= 0) {
                break;
            }

            // Cumulative rounding distribution
            $cumulative = 0.0;
            $prevPos = 0;
            $anyClamped = false;

            for ($i = 0; $i < $n; $i++) {
                if (!$active[$i] || $constraints[$i]->kind !== ConstraintKind::Flex || $frozen[$i]) {
                    continue;
                }
                $c = $constraints[$i];
                $fraction = $c->flexWeight / $totalWeight;
                $cumulative += $fraction * (float) $flexAvailable;
                $pos = (int) \round($cumulative);
                $rawSize = $pos - $prevPos;
                $prevPos = $pos;

                // For optional children, skip minSize clamping during distribution.
                // Phase 4 will check the raw size and remove them if too small.
                $clamped = self::clampConstraintForFlex($rawSize, $c);
                $sizes[$i] = $clamped;
                if ($clamped !== $rawSize) {
                    $frozen[$i] = true;
                    $anyClamped = true;
                }
            }

            if (!$anyClamped) {
                break;
            }
        }
    }

    /**
     * clampConstraint applies min/max bounds from a constraint.
     */
    private static function clampConstraint(int $val, Constraint $c): int
    {
        if ($val < $c->minSize) {
            $val = $c->minSize;
        }
        if ($c->maxSize >= 0 && $val > $c->maxSize) {
            $val = $c->maxSize;
        }
        return $val;
    }

    /**
     * clampConstraintForFlex applies bounds during flex distribution. For optional
     * children, minSize is not enforced — Phase 4 handles removal instead.
     */
    private static function clampConstraintForFlex(int $val, Constraint $c): int
    {
        if (!$c->optional && $val < $c->minSize) {
            $val = $c->minSize;
        }
        if ($c->maxSize >= 0 && $val > $c->maxSize) {
            $val = $c->maxSize;
        }
        return $val;
    }

    /**
     * Calculate tile dimensions based on layout direction and available space.
     */
    public static function resolveTile(
        Tile $tile,
        Direction $direction,
        int $availableWidth,
        int $availableHeight,
        int $totalFixedWidth = 0,
        int $totalFixedHeight = 0,
    ): array {
        $size = $tile->getSize();

        if ($direction === Direction::Horizontal) {
            $tileWidth = self::decideWidth($size, $availableWidth, $totalFixedWidth);
            $tileHeight = self::decideHeight($size, $availableHeight);
            return [$tileWidth, $tileHeight];
        }

        $tileWidth = self::decideWidth($size, $availableWidth);
        $tileHeight = self::decideHeight($size, $availableHeight, $totalFixedHeight);
        return [$tileWidth, $tileHeight];
    }

    private static function decideWidth(Size $size, int $available, int $fixedSubtract = 0): int
    {
        $avail = $available - $fixedSubtract;

        if ($size->fixedWidth !== null) {
            return min($avail, $size->fixedWidth);
        }

        $w = $avail;
        if ($size->maxWidth !== null && $w > $size->maxWidth) {
            $w = $size->maxWidth;
        }
        if ($size->minWidth !== null && $w < $size->minWidth) {
            $w = $size->minWidth;
        }

        return max(0, $w);
    }

    private static function decideHeight(Size $size, int $available, int $fixedSubtract = 0): int
    {
        $avail = $available - $fixedSubtract;

        if ($size->fixedHeight !== null) {
            return min($avail, $size->fixedHeight);
        }

        $h = $avail;
        if ($size->maxHeight !== null && $h > $size->maxHeight) {
            $h = $size->maxHeight;
        }
        if ($size->minHeight !== null && $h < $size->minHeight) {
            $h = $size->minHeight;
        }

        return max(0, $h);
    }
}
