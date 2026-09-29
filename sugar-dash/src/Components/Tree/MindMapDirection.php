<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * Layout direction for mind map branches.
 */
enum MindMapDirection: string
{
    case LeftRight = 'lr';
    case RightLeft = 'rl';
    case TopBottom = 'tb';
    case BottomTop = 'bt';
}
