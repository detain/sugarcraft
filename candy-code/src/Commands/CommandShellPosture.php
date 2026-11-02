<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Skills\SkillSource;

/**
 * The (tier, source) -> {@see ShellProvenance} table answering "whose shell is
 * this?" for one command row (C-SEC-3, plan_comparison §6). One lookup feeds
 * both {@see \SugarCraft\Code\Host\TurnController::refuseCommandShell()} and
 * {@see \SugarCraft\Code\Host\TurnController::customCommandMustFork()}, so the
 * refusal and the fork decision can never disagree about a row's provenance.
 *
 * THE ORDER IS THE TABLE:
 *
 * 1. The `mcp` tier is a stamp no disk walk applies, so it answers first.
 * 2. The bundle badge OUTRANKS the tier: `ForeignCommandDiscovery::bundleDirs()`
 *    stamps each row with its bundle's own tier — `user` for a bundle under
 *    `~/.candy-code/bundles`, `project` for one anchored in a checkout — and
 *    the tier alone would read either as native text this machine chose.
 *    A bundle is a package somebody else packed; its shell forms are refused
 *    whichever directory it landed in.
 * 3. Then the tier decides, and the C-SEC-3.b ruling settles that as the
 *    foreign rows' permanent answer: a `~/.claude` row is user tier and
 *    judged as the operator's own, a `<root>/.claude` row is project tier and
 *    judged as the repository's, exactly as it does for
 *    `~/.candy-code/commands` and `<root>/.candy-code/commands`.
 *
 * The per-origin opt-in ({@see self::optedIn()}) covers only the two
 * third-party arms: the row-identity stamps ({@see CommandSpec::$originName})
 * the bundle loader and the MCP prompt bridge mint give the two user-tier
 * allow-lists something to match, and an opted-in row falls through to the
 * permission gate like any other. Both lists default empty, so the answers
 * above are the shipped posture until the operator names an origin.
 */
final class CommandShellPosture
{
    private function __construct() {}

    /** Whose shell the row's `` !`…` `` forms would run, if any. */
    public static function of(CommandSpec $spec): ShellProvenance
    {
        if ($spec->tier === McpPromptCommands::TIER) {
            return ShellProvenance::Mcp;
        }

        if ($spec->source === SkillSource::Bundle) {
            return ShellProvenance::Bundle;
        }

        if ($spec->tier === 'project') {
            return ShellProvenance::Repo;
        }

        return ShellProvenance::Operator;
    }

    /**
     * C-SEC-3.b per-origin opt-in: has the operator named this row's origin in
     * the matching user-tier allow-list, so its shell forms may reach the
     * permission gate instead of being refused by provenance?
     *
     * Matching is a strict `===` against the RAW stamped name
     * ({@see CommandSpec::$originName}): the bundle manifest's own name for a
     * `Bundle` posture, the server key as the user config names it for an `Mcp`
     * posture. Never the folded row-name segment, never the sanitized wire
     * prefix — those spellings are machine-derived aliases, and an allow-list
     * entry should read like the config that declares the origin. An unstamped
     * row (native operator/project file, or any row predating the stamp) has a
     * null origin and is never opted in; only the two third-party postures
     * consult their lists, exactly as the ruling narrowed this opt-in.
     */
    public static function optedIn(CommandSpec $spec, array $trustedBundleCommands = [], array $mcpPromptShell = []): bool
    {
        return match (self::of($spec)) {
            ShellProvenance::Bundle => in_array($spec->originName, $trustedBundleCommands, true),
            ShellProvenance::Mcp => in_array($spec->originName, $mcpPromptShell, true),
            default => false,
        };
    }
}
