<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * Result of evaluating a tool call against the permission gate.
 */
enum PermissionDecision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Ask = 'ask';
}
