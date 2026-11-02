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
 * third-party arms. The bundle arm additionally demands the row's own tier be
 * `user`: a bundle's manifest name is SELF-DECLARED, so a project checkout
 * that ships `.candy-code/bundles/<x>/bundle.json` naming a user-listed bundle
 * would otherwise match the allow-list through the badge and opt itself into
 * shell with zero user trust (rev-r4 C-1). Legit user installs and plugin
 * paths stamp `user` on every row they mint, so the tier test removes only
 * the squat. The MCP arm needs no tier test: the `mcp` tier is a stamp no
 * disk walk applies, the row's identity is the server key as the USER config
 * names it, and approving that server's trust pin is itself the opt-in
 * decision (TOFU-subsumed) — a project-tier row can never reach the MCP arm
 * at all, it answers Repo and the default leg refuses it.
 *
 * An opted-in row falls through to the permission gate like any other —
 * opt-in is not bypass: only a Deny rule refuses it there, and because the
 * expansion runs unattended an Ask rule cannot prompt, so it proceeds. Both
 * lists default empty, so the answers above are the shipped posture until
 * the operator names an origin.
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
     *
     * REV-R4 C-1 NARROWS THE BUNDLE ARM: the manifest name is self-declared,
     * so a `Bundle` row opts in only when its tier is exactly `user` — the
     * stamp every genuine user-directory install and plugin path lands
     * (`BundleDiscovery` + `ForeignCommandDiscovery::bundleDirs()` chain) and
     * the one a checked-out bundle can never carry. A `project`-tier bundle
     * row squatting a listed name stays refused; so does a row with a null
     * tier, fail-closed. The MCP arm takes no tier test — see the class
     * table: its posture is reachable only through the in-memory `mcp` tier
     * stamp, and the listed identity is the user's own server key.
     */
    public static function optedIn(CommandSpec $spec, array $trustedBundleCommands = [], array $mcpPromptShell = []): bool
    {
        return match (self::of($spec)) {
            ShellProvenance::Bundle => $spec->tier === 'user'
                && in_array($spec->originName, $trustedBundleCommands, true),
            ShellProvenance::Mcp => in_array($spec->originName, $mcpPromptShell, true),
            default => false,
        };
    }
}
