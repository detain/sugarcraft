<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * Permission mode for hook execution, controlling when edits are
 * accepted, plans are shown, or permissions are bypassed.
 */
enum PermissionMode: string
{
    case Default = 'default';
    case AcceptEdits = 'accept-edits';
    case Plan = 'plan';
    case Auto = 'auto';
    case DontAsk = 'dont-ask';
    case BypassPermissions = 'bypass-permissions';
}
