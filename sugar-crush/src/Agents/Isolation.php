<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the isolation level for agent workspace operations.
 *
 * Mirrors upstream isolation classification used in workspace management.
 */
enum Isolation: string
{
    case None = 'none';
    case Worktree = 'worktree';
}
