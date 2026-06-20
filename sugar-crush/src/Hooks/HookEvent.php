<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

enum HookEvent: string
{
    case PreToolUse = 'PreToolUse';
    case PostToolUse = 'PostToolUse';
}
