<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * Organizational chart layout style.
 */
enum OrgChartStyle: string
{
    case TopDown = 'topdown';
    case LeftRight = 'leftright';
    case Tree = 'tree';
}
