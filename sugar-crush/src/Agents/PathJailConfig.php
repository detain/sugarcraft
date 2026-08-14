<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Configuration for {@see PathJail} path isolation.
 *
 * Deliberately EMPTY today, and deliberately not removed. It is the seam for
 * the per-agent policy that has to sit on the bound jail rather than in the
 * stateless containment algorithm ({@see \SugarCraft\Crush\Tools\PathJail}):
 * a read-only worktree, allow/deny globs layered on top of the root, a write
 * size ceiling — knobs that differ per sub-agent, where the algorithm's answer
 * ("is this path inside that root?") is the same for everyone.
 *
 * Keeping the type means adding the first such knob is one field plus one
 * `with*()`, instead of a signature change rippling through every construction
 * site of the jail. Being a required constructor argument is what keeps it
 * threaded and therefore reachable; {@see PathJail::config()} reads it back.
 */
final class PathJailConfig
{
    public function __construct()
    {
    }
}
