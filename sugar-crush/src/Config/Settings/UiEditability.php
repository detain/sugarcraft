<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * How the settings editor offers a key.
 *
 *  - Easy      a single field: bool, enum, number or short string.
 *  - List      a multi-select over discovered names (plus free globs).
 *  - Complex   a nested value: a map-typed one is edited in the TUI as one JSON
 *              object, and `permissionRules` as one JSON list that the
 *              launch's own strict parser validates before it is written
 *              ({@see \SugarCraft\Crush\Tui\Settings\SettingsFieldFactory}).
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
