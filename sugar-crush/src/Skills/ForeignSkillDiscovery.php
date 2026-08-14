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
     * That is DELIBERATELY the opposite of the "built-in < user < project"
     * order {@see SkillLoader::loadAll()} uses for native
     * `.sugar-crush/skills`, and the difference is who wrote the file. A
     * native project skill is something you put in your own repo. A foreign
     * one arrives with any repository you clone, and project-last meant that
     * cloning a repo carrying `.claude/skills/db-query` silently re-pointed
     * the `db-query` the user had been relying on — with a different body,
     * different `allowedTools`, possibly `context: fork`. That is the same
     * "cloned content silently redefines the user's setup" the project hook
     * file is gated for ({@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()}),
     * one notch weaker: prompt text rather than shell. A project's foreign
     * skill is still IMPORTED and still offered — it just cannot displace a
     * name the user already has.
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::Claude
     */
    public function discoverClaude(string $projectRoot): array
    {
        return $this->discover(
            [
                // The project tree is confined to itself; the user's own tree
                // may follow links into the rest of the user's home. See
                // {@see SkillLoader::skillFilesIn()}'s $ownedBy — the same
                // "who wrote this file" line this method's PRECEDENCE rule
                // below is drawn on.
                $projectRoot . '/.claude/skills' => null,
                self::homeDir() . '/.claude/skills' => self::homeDir(),
            ],
            SkillSource::Claude,
        );
    }

    /**
     * Discover opencode skills from ~/.config/opencode/skills and
     * {projectRoot}/.opencode/skills, tagging every result SkillSource::Opencode.
     *
     * Same project < user precedence as {@see discoverClaude()}; see that
     * method's doc-block for why the user's own copy is the one that wins.
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::Opencode
     */
    public function discoverOpencode(string $projectRoot): array
    {
        return $this->discover(
            [
                // See {@see discoverClaude()} for the per-tree containment.
                $projectRoot . '/.opencode/skills' => null,
                self::homeDir() . '/.config/opencode/skills' => self::homeDir(),
            ],
            SkillSource::Opencode,
        );
    }

    /**
     * This user's home directory — see {@see HomeDirectory}, the one
     * resolution every `~`-rooted path in this package goes through.
     */
    private static function homeDir(): string
    {
        return HomeDirectory::path();
    }

    /**
     * Merge every directory's skills into one registry, tagging each with
     * $source. $dirs MUST be ordered lowest-priority-first: the loop is
     * last-write-wins, so a later directory's skill overrides an earlier
     * one sharing its name.
     *
     * @param array<string, string|null> $dirs directory => the extra
     *        containment root its symlinks may resolve into, or null to
     *        confine them to the directory itself
     * @return array<string, Skill>
     */
    private function discover(array $dirs, SkillSource $source): array
    {
        $skills = [];
        foreach ($dirs as $dir => $ownedBy) {
            foreach ($this->loader->loadFromDirectory($dir, $ownedBy) as $name => $skill) {
                $skills[$name] = $this->tag($skill, $source);
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
        );
    }
}
