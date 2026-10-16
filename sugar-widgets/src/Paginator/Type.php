<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Paginator;

/**
 * How the {@see Paginator} renders itself:
 *
 * - {@see Dots}   — bullets where the active page is filled (●) and the
 *                   rest are hollow (○). Best for small page counts.
 * - {@see Arabic} — `1/4`, `2/4`, etc. Compact and unbounded.
 */
enum Type: string
{
    case Dots   = 'dots';
    case Arabic = 'arabic';
}
