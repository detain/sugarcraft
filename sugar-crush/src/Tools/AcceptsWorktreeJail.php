<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;

/**
 * A {@see Tool} that resolves model-supplied paths and can therefore be
 * re-confined to a sub-agent's git worktree after it was built (audit F-J5).
 *
 * WHY A WITHER AND NOT ONLY THE CONSTRUCTOR ARGUMENT. Every implementer
 * already takes an optional trailing `worktreeJail` at construction, but the
 * tool set a backend runs is assembled ONCE, on the main checkout's root
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::tools()}), long before anyone knows
 * which worktree a sub-agent will be given. The one place that does know is
 * {@see \SugarCraft\Crush\Backend\EngineBackend::withWorktreeRoot()}, and it
 * holds built instances, not their constructor arguments. Before this seam it
 * could only add the Bash escape hook, so a worktree sub-agent's Glob, Grep,
 * Lsp, Read, Edit and Write all still answered from — and wrote to — the MAIN
 * checkout.
 *
 * The returned instance is a copy: the receiver keeps its own confinement,
 * because the parent backend's tool list is the same objects.
 */
interface AcceptsWorktreeJail
{
    /**
     * This tool, confined to `$jail`'s root in place of the workspace root it
     * was built on. Every other collaborator — the announce-once trackers a
     * session shares across tools included — is carried over by reference.
     */
    public function withWorktreeJail(AgentPathJail $jail): static;
}
