<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

enum NetworkShape: string
{
    case Circle = 'circle';
    case Square = 'square';
    case Diamond = 'diamond';
    case Hexagon = 'hexagon';
    case Star = 'star';
}
