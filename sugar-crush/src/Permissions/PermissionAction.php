<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * Permission action taken when a rule matches a tool call.
 * Determines whether to allow, deny, or prompt for the action.
 */
enum PermissionAction: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Ask = 'ask';
}
