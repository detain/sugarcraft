<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * Gantt chart time scale.
 */
enum TimeScale: string
{
    case Hours = 'hours';
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
}
