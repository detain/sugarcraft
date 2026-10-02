<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;

/**
 * The one implementation of {@see \SugarCraft\Crush\Tools\AcceptsWorktreeJail}
 * every path-resolving built-in shares (audit F-J5).
 *
 * The tools using it are `final readonly` and every one of their properties is
 * constructor-promoted, with a promoted `$worktreeJail` among them. So
 * {@see get_object_vars()} IS the constructor's argument list, by name: a
 * rebuild through it carries every field — a field added later included —
 * and replaces the jail alone. A user that ever grew a non-promoted property
 * would fail loudly here ("Unknown named parameter"), not drop it silently.
 */
trait RebindsWorktreeJail
{
    public function withWorktreeJail(AgentPathJail $jail): static
    {
        return new self(...array_replace(get_object_vars($this), ['worktreeJail' => $jail]));
    }
}
