<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

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
