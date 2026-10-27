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
 *    `~/.candy-code/commands` and `<root>/.candy-code/commands`. The per-origin
 *    opt-in covers only the two third-party arms, and it lands with the
 *    bundle and MCP row-identity stamps later plan steps mint — without an
 *    origin name on the row there is nothing for the two allow-lists to match.
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
}
