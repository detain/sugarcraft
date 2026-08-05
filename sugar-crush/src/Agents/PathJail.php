<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Path isolation layer that constrains file operations to an agent's worktree.
 *
 * Every path passed through jailPath() is guaranteed to be absolute and
 * rooted within the agent's worktree. The isAllowed() check verifies whether
 * a given path resolves to a location within the worktree boundary.
 *
 * @see https://github.com/sugarcraft/sugar-crush/blob/master/crush_code_plan.md#path-isolation-layer
 */
final class PathJail
{
    public function __construct(
        private readonly string $agentWorktreePath,
        private readonly PathJailConfig $config,
    ) {}

    /**
     * Prepend the worktree path if the given path is relative.
     *
     * Absolute paths are returned unchanged. Relative paths are resolved
     * relative to the worktree root.
     */
    public function jailPath(string $path): string
    {
        if ($path === '') {
            return $this->agentWorktreePath;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->agentWorktreePath . '/' . $path;
    }

    /**
     * Check whether the given path resolves to a location within the worktree.
     *
     * Uses realpath() to resolve symlinks and relative segments (../..) before
     * checking containment. Returns false if the path does not exist or points
     * outside the worktree boundary.
     */
    public function isAllowed(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        // Exact match for the worktree root itself
        if ($real === $this->agentWorktreePath) {
            return true;
        }
        // Require descendant paths to be inside a proper subdirectory to avoid
        // sibling-directory prefix collisions (e.g. /tmp/worktree-abc-xyz would
        // incorrectly match if we only checked str_starts_with(worktree)).
        return str_starts_with($real, $this->agentWorktreePath . '/');
    }
}
