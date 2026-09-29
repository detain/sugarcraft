<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Graph;

/**
 * Graph types.
 */
enum GraphType: string
{
    case Line = 'line';
    case Bar = 'bar';
    case Area = 'area';
    case Scatter = 'scatter';
}
