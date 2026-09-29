<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * Branch line patterns.
 */
enum BranchPattern: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Double = 'double';
}
