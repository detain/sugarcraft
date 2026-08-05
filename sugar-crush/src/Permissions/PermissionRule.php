<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * A rule that matches a tool call by pattern and specifies what action to take.
 * Pattern examples: Bash(composer update *), Read(./.env), mcp__git__*
 */
final class PermissionRule
{
    public function __construct(
        public readonly string $pattern,
        public readonly PermissionAction $action,
    ) {}
}
