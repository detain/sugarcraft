<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

enum EdgeStyle: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Double = 'double';
}
