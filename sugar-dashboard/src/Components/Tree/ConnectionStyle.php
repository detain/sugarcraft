<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

/**
 * Connection line styles for mind map branches.
 */
enum ConnectionStyle: string
{
    case Straight = 'straight';
    case Curved = 'curved';
    case Rounded = 'rounded';
}
