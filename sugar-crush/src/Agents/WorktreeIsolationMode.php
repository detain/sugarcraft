<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Specifies the isolation strategy for worktree-based agent workspaces.
 *
 * Worktree provides the strongest isolation — each agent gets a full git
 * worktree on its own branch. Branch isolation uses a dedicated branch
 * per agent but shares the filesystem. Path isolation routes file
 * operations through PathJail without a separate worktree.
 */
enum WorktreeIsolationMode: string
{
    case Worktree = 'worktree';
    case Branch = 'branch';
    case Path = 'path';
}
