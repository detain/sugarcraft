<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

/**
 * Justify content enum for FlexLayout.
 */
enum JustifyContent: string
{
    case Start = 'start';
    case End = 'end';
    case FlexStart = 'flex-start';
    case FlexEnd = 'flex-end';
    case Center = 'center';
    case SpaceBetween = 'space-between';
    case SpaceAround = 'space-around';
    case SpaceEvenly = 'space-evenly';
}
