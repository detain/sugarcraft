<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

/**
 * ConstraintKind identifies which constraint type is active.
 * Mirrors tealeaves tealayout_constraint.go constraintKind.
 */
enum ConstraintKind: int
{
    case Fixed = 0;
    case Flex = 1;
    case Fit = 2;
}
