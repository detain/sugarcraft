<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

/**
 * Align items enum for FlexLayout.
 */
enum AlignItems: string
{
    case Start = 'start';
    case End = 'end';
    case FlexStart = 'flex-start';
    case FlexEnd = 'flex-end';
    case Center = 'center';
    case Stretch = 'stretch';
    case Baseline = 'baseline';
}
