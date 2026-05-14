<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

/**
 * Flex direction enum for FlexLayout.
 */
enum FlexDirection: string
{
    case Row = 'row';
    case Column = 'column';
    case RowReverse = 'row-reverse';
    case ColumnReverse = 'column-reverse';
}
