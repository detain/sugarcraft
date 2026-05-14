<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Border regions of the table.
 */
enum BorderRegion
{
    case Outer;
    case Header;
    case Inner;
    case Footer;
}
