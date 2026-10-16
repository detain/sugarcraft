<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Foundation;

enum EdgeStyle: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Bold = 'bold';
}
