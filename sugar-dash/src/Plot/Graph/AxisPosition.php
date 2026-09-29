<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Graph;

/**
 * Graph axis labels position.
 */
enum AxisPosition: string
{
    case Left = 'left';
    case Right = 'right';
    case Both = 'both';
}
