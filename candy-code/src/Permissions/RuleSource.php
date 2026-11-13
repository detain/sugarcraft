<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

/**
 * Where a {@see PermissionRule} came from, so a prompt or `/permissions` can
 * say which rule decided a call and which file or grant to edit to change it.
 *
 * The values are the words the report prints, not translated labels: they are
 * the same tokens a user types in `permissionRules` and `/permissions mode`,
 * so there is no second vocabulary to keep in sync with the locales.
 */
enum RuleSource: string
{
    /** A `permissionRules` entry in the user's own config or settings file. */
    case User = 'user';

    /** A deny/ask entry from the trusted project's shared settings file. */
    case Project = 'project';

    /** An "always" answer written to the user config (the `p` key). */
    case Saved = 'saved';

    /** An "always" answer remembered for this session only. */
    case Session = 'session';

    /** An `allowed-tools` / `disallowed-tools` entry of a loaded skill. */
    case Skill = 'skill';

    /** Built in code with no configured origin (embedders, sub-agent presets). */
    case Unknown = 'unknown';
}
