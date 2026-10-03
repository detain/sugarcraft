<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * How the settings editor offers a key.
 *
 *  - Easy      a single field: bool, enum, number or short string.
 *  - List      a multi-select over discovered names (plus free globs).
 *  - Complex   a nested value that needs a row editor; read-only until one exists.
 *  - ReadOnly  shown, never form-edited (`layout` is written by the dock itself).
 *  - Hidden    not shown at all.
 */
enum UiEditability: string
{
    case Easy = 'easy';
    case List = 'list';
    case Complex = 'complex';
    case ReadOnly = 'read-only';
    case Hidden = 'hidden';
}
