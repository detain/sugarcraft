<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Discovers SKILL.md-shaped directories from OTHER coding-CLI tools'
 * conventions and registers them tagged with the originating SkillSource.
 * No frontmatter translation is needed -- Claude Code's SKILL.md fields are
 * a strict superset of opencode's / the agentskills.io 6-field spec, and
 * Skill::parse() already defaults every field that's absent.
 */
final class ForeignSkillDiscovery
{
    public function __construct(private readonly SkillLoader $loader = new SkillLoader()) {}

    /**
     * Discover Claude Code skills from ~/.claude/skills and
     * {projectRoot}/.claude/skills, tagging every result SkillSource::Claude.
     *
     * PRECEDENCE: PROJECT < USER — the user's own copy wins a name collision,
     * and the search order below is therefore lowest-priority-first because
     * the merge loop is last-write-wins.
     *
     * This rule came first, and the native tiers have since been brought into
     * line with it ({@see SkillOrigin::precedence()}, audit 15d-03(b)): the
     * difference that once justified two orders — a native project skill is
     * "something you put in your own repo" — does not hold, since a native
     * `.sugar-crush/skills` arrives with a clone exactly as a `.claude/skills`
     * does. Project-last meant that cloning a repo carrying
     * `.claude/skills/db-query` silently re-pointed the `db-query` the user had
     * been relying on — with a different body, different `allowedTools`,
     * possibly `context: fork`. That is the same "cloned content silently
     * redefines the user's setup" the project hook file is gated for
     * ({@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()}), one notch weaker:
     * prompt text rather than shell. A project's foreign skill is still
     * IMPORTED and still offered — it just cannot displace a name the user
     * already has, and the loss is recorded in the loader's
     * {@see SkillLoader::skipped()} (audit 15d-03: this shadowing was the one
     * still silent after the native and cross-convention ones were reported).
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::Claude
     */
    public function discoverClaude(string $projectRoot): array
    {
        return $this->merge($this->claudeTiers($projectRoot));
    }

    /**
     * {@see discoverClaude()}'s two trees, NOT merged: each tier's skills keyed
     * by {@see SkillOrigin} value, lowest precedence first.
     *
     * For {@see SkillManager::loadAll()}, which interleaves these with the
     * native tiers — a user's `~/.claude/skills` entry must outrank a project's
     * native `.sugar-crush/skills` one, and a merged result has already lost
     * which tier each skill came from.
     *
     * @return array<string, array<string, Skill>>
     */
    public function claudeTiers(string $projectRoot): array
    {
        return $this->discover(
            self::tiers($projectRoot, '/.claude/skills', ['/.claude/skills']),
            SkillSource::Claude,
        );
    }

    /**
     * The project and user trees for one foreign convention, with the containment
     * each gets — and the user trees only when this process can establish that
     * `$HOME` is this user's. A convention may keep MORE THAN ONE user tree
     * (opencode reads `~/.opencode/skills` as well as `~/.config/opencode/skills`);
     * the list is ordered lowest-priority-first, so the LAST entry wins a same-tier
     * name inside this one convention — see {@see discover()}'s shadowing record.
     *
     * THE SENTENCE THIS METHOD EXISTS TO MAKE TRUE was written into the two call
     * sites' comments a round before it was: *"`$HOME` is the anchor that makes
     * 'the user's own tree' true rather than assumed"*. The anchor was
     * {@see HomeDirectory::path()}, which establishes NOTHING — its fallback is
     * `sys_get_temp_dir()`, mode 1777 on every stock Linux — so the anchor and the
     * anchored directory were both derived from a home nobody had vouched for, and
     * a `$HOME` pointed at a world-writable directory had another local user's
     * `.claude/skills/<name>/SKILL.md` body pass containment and reach the model's
     * prompt. The sibling changed in the same commit,
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::userDir()},
     * correctly used {@see HomeDirectory::owned()}: one sentence, true in one file
     * and false in the other. MEASURED on this host with `HOME` at a mode-0777
     * directory: `discoverClaude()` returned the skill off `path()` and returns
     * nothing off `owned()`.
     *
     * NO USER TIER AT ALL when {@see HomeDirectory::owned()} is null, rather than
     * a tier anchored to a stand-in. What that costs is a launch whose `$HOME` is
     * unresolvable, world-writable or owned by somebody else losing its foreign
     * user skills — while keeping its PROJECT ones, which are anchored to the
     * checkout and do not depend on a home at all.
     *
     * The third element of each entry is the {@see SkillOrigin} the tree's skills
     * are badged with in the system-prompt listing (audit 15d-02): the project
     * tree arrived with the checkout, the user tree is the operator's own.
     *
     * @return array<string, array{0: string|null, 1: string|null, 2: SkillOrigin}>
     */
    private static function tiers(string $projectRoot, string $projectSuffix, array $userSuffixes): array
    {
        $tiers = [
            // The project tree is confined to itself AND held inside the
            // checkout. See {@see SkillLoader::skillFilesIn()}'s $ownedBy and
            // $anchoredIn — the same "who wrote this file" line the PRECEDENCE
            // rule on {@see discoverClaude()} is drawn on, applied to the
            // directory as well as to the links inside it.
            $projectRoot . $projectSuffix => [null, $projectRoot, SkillOrigin::Project],
        ];

        $home = HomeDirectory::owned();
        if ($home !== null) {
            foreach ($userSuffixes as $userSuffix) {
                $tiers[$home . $userSuffix] = [$home, $home, SkillOrigin::User];
            }
        }

        return $tiers;
    }

    /**
     * Discover opencode skills from ~/.opencode/skills,
     * ~/.config/opencode/skills and {projectRoot}/.opencode/skills — the two
     * user trees are the ones opencode itself reads, the legacy dot tree and
     * its XDG successor — tagging every result SkillSource::Opencode.
     *
     * Same project < user precedence as {@see discoverClaude()}; see that
     * method's doc-block for why the user's own copy is the one that wins.
     * Inside the convention the XDG tree beats the legacy one (see
     * {@see opencodeTiers()}).
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::Opencode
     */
    public function discoverOpencode(string $projectRoot): array
    {
        return $this->merge($this->opencodeTiers($projectRoot));
    }

    /**
     * {@see discoverOpencode()}'s trees, NOT merged — see
     * {@see claudeTiers()}.
     *
     * @return array<string, array<string, Skill>>
     */
    public function opencodeTiers(string $projectRoot): array
    {
        return $this->discover(
            // opencode itself reads TWO user trees: the XDG one under
            // `~/.config` and a legacy one directly under `~`. Listing the
            // legacy tree FIRST makes the XDG one the intra-convention winner
            // (later entry wins, {@see discover()}); when both hold a
            // byte-identical copy — the common skillshare layout — the
            // collision collapses silently through the same-file check in
            // {@see SkillLoader::recordShadowing()}.
            self::tiers($projectRoot, '/.opencode/skills', ['/.opencode/skills', '/.config/opencode/skills']),
            SkillSource::Opencode,
        );
    }

    /**
     * Discover bare agentskills.io-convention skills from ~/.agents/skills and
     * {projectRoot}/.agents/skills, tagging every result
     * SkillSource::AgentSkillsSpec.
     *
     * This is the tool-neutral tree the agentskills.io spec recommends, so a
     * skill here carries no tool-specific frontmatter expectations beyond the
     * shared 6 fields {@see Skill::parse()} already defaults. Same project <
     * user precedence as {@see discoverClaude()}; across conventions it ranks
     * LOWEST among the foreign ones (spec < claude < opencode,
     * {@see SkillManager::loadAll()}) — the least tool-specific convention
     * loses a name to the more tool-specific ones, and native still beats all
     * three inside a tier.
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::AgentSkillsSpec
     */
    public function discoverAgents(string $projectRoot): array
    {
        return $this->merge($this->agentsTiers($projectRoot));
    }

    /**
     * {@see discoverAgents()}'s two trees, NOT merged — see
     * {@see claudeTiers()}.
     *
     * @return array<string, array<string, Skill>>
     */
    public function agentsTiers(string $projectRoot): array
    {
        return $this->discover(
            self::tiers($projectRoot, '/.agents/skills', ['/.agents/skills']),
            SkillSource::AgentSkillsSpec,
        );
    }

    /**
     * Read every directory's skills, tagged with $source and with the tier
     * the directory belongs to, grouped by tier in
     * {@see SkillOrigin::precedence()} order — lowest first, whatever order
     * $dirs lists them in.
     *
     * Within ONE tier, a later directory's skill replaces an earlier one's of
     * the same name, and the loser is recorded through
     * {@see SkillLoader::recordShadowing()} exactly as {@see merge()} records
     * cross-tier losers — byte-identical twins (one SKILL.md synced into two
     * user trees of the same convention) collapse silently there, differing
     * bytes are reported. This only became reachable when a convention grew a
     * second user tree; with one directory per tier per convention the
     * collision had no second claimant.
     *
     * @param array<string, array{0: string|null, 1: string|null, 2: SkillOrigin}> $dirs
     *        directory => [the extra containment root its symlinks may resolve
     *        into (null confines them to the directory itself), the checkout the
     *        directory itself must resolve strictly inside (null for a directory
     *        whose location no repository chose), the tier its skills belong to]
     * @return array<string, array<string, Skill>>
     */
    private function discover(array $dirs, SkillSource $source): array
    {
        $byTier = [];
        foreach ($dirs as $dir => [$ownedBy, $anchoredIn, $origin]) {
            foreach ($this->loader->loadFromDirectory($dir, $ownedBy, $anchoredIn) as $name => $skill) {
                $tagged = $this->tag($skill, $source)->withOrigin($origin);
                $incumbent = $byTier[$origin->value][$name] ?? null;
                if ($incumbent !== null) {
                    $badge = $origin->badge($source);
                    $this->loader->recordShadowing(
                        (string) $name,
                        $incumbent->sourcePath,
                        $badge,
                        $tagged->sourcePath,
                        $badge,
                    );
                }
                $byTier[$origin->value][$name] = $tagged;
            }
        }

        $tiers = [];
        foreach (SkillOrigin::precedence() as $origin) {
            if (isset($byTier[$origin->value])) {
                $tiers[$origin->value] = $byTier[$origin->value];
            }
        }

        return $tiers;
    }

    /**
     * Fold one convention's tiers into one name => Skill map, later tier
     * winning, and record every skill a later tier replaces through the
     * loader's one shadowing record ({@see SkillLoader::recordShadowing()}),
     * so it reaches {@see SkillLoader::skipped()} and the launch notice
     * alongside every other shadowing.
     *
     * @param array<string, array<string, Skill>> $tiers
     * @return array<string, Skill>
     */
    private function merge(array $tiers): array
    {
        $skills = [];
        foreach ($tiers as $tier) {
            foreach ($tier as $name => $skill) {
                if (isset($skills[$name])) {
                    $loser = $skills[$name];
                    $this->loader->recordShadowing(
                        (string) $name,
                        $loser->sourcePath,
                        $loser->origin->badge($loser->source),
                        $skill->sourcePath,
                        $skill->origin->badge($skill->source),
                    );
                }
                $skills[$name] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Re-wrap a Skill with a different SkillSource, preserving every other
     * field (Skill is immutable, so tagging means rebuilding).
     */
    private function tag(Skill $skill, SkillSource $source): Skill
    {
        return new Skill(
            name: $skill->name,
            description: $skill->description,
            userInvocable: $skill->userInvocable,
            disableModelInvocation: $skill->disableModelInvocation,
            allowedTools: $skill->allowedTools,
            disallowedTools: $skill->disallowedTools,
            model: $skill->model,
            effort: $skill->effort,
            context: $skill->context,
            paths: $skill->paths,
            content: $skill->content,
            sourcePath: $skill->sourcePath,
            source: $source,
            origin: $skill->origin,
        );
    }
}
