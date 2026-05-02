<?php

declare(strict_types=1);

namespace CandyCore\Gloss;

/**
 * Vertical alignment of content within a fixed-height Style box.
 * Used by {@see Style::verticalAlign()} together with {@see Style::height()}.
 */
enum VAlign: string
{
    case Top    = 'top';
    case Middle = 'middle';
    case Bottom = 'bottom';
}
