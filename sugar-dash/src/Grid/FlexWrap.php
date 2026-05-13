<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Flex wrap enum for FlexLayout.
 */
enum FlexWrap: string
{
    case NoWrap = 'nowrap';
    case Wrap = 'wrap';
    case WrapReverse = 'wrap-reverse';
}
