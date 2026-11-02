<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

/**
 * WHOSE shell a command row's `` !`…` `` forms are — the one answer
 * {@see CommandShellPosture::of()} reads off (tier, source) and
 * {@see \SugarCraft\Code\Host\TurnController::refuseCommandShell()} judges by
 * (C-SEC-3, plan_comparison §6 "Third-party `!cmd`").
 *
 * WHY A CASE PER PROVENANCE instead of a yes/no flag: the cases differ in WHO
 * can undo a refusal. The operator edits their own file; a repository's author
 * is reached by the operator listing the root under `trustedProjectCommands`;
 * the author of a fetched MCP prompt body and the author of a bundle's files
 * are reached by NEITHER machine-local root grant — their shell forms are
 * refused outright unless the operator names that exact origin in the
 * C-SEC-3.b per-origin opt-ins ({@see CommandShellPosture::optedIn()}, the
 * `trustedBundleCommands` and `mcpPromptShell` user-tier lists), and that
 * distinction is what the refusal sentences tell each author.
 *
 * There is no `Foreign` case on purpose: `.claude` and `.opencode` rows keep
 * today's answer in this step — the tier decides, exactly as for the native
 * row the same tier would stamp — and the table reaches that answer through
 * {@see ShellProvenance::Operator} and {@see ShellProvenance::Repo}.
 */
enum ShellProvenance
{
    /** The operator's own file (user tier, or an un-tiered spec an in-process caller built): the permission gate is the only check. */
    case Operator;

    /** A project-tier file that arrived with the repository: `trustedProjectCommands` first, then the gate. */
    case Repo;

    /** A `SkillSource::Bundle` row, whatever tier it installed under: shell forms are refused unless the operator opted its bundle in (`trustedBundleCommands`). */
    case Bundle;

    /** An MCP prompt body fetched over the wire: shell forms are refused unless the operator opted its server in (`mcpPromptShell`). */
    case Mcp;

    /**
     * Whether this provenance's shell forms are refused before the permission
     * gate is even evaluated — the two third-party cases AT THEIR DEFAULT,
     * with the C-SEC-3.b opt-ins unset. A caller holding the operator's
     * allow-lists consults {@see CommandShellPosture::optedIn()} first; an
     * opted-in origin skips this refusal and goes straight to the gate. A
     * refusal that never reached the gate must not move Auto mode's
     * circuit-breaker counters, which is why the callers consult this before
     * evaluating.
     */
    public function refusesShell(): bool
    {
        return $this === self::Bundle || $this === self::Mcp;
    }
}
