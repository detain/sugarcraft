<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

enum EdgeStyle: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Bold = 'bold';
}
