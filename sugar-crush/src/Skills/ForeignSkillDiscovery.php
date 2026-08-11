<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

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
     * Precedence: user (home) < project -- the project checkout wins a
     * name collision, matching the "built-in < user < project (later
     * sources override earlier)" convention {@see SkillLoader::loadAll()}
     * documents and implements for native skills. The search order below is
     * therefore lowest-priority-first, because the merge loop is
     * last-write-wins.
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::Claude
     */
    public function discoverClaude(string $projectRoot): array
    {
        return $this->discover(
            [($_SERVER['HOME'] ?? '/root') . '/.claude/skills', $projectRoot . '/.claude/skills'],
            SkillSource::Claude,
        );
    }

    /**
     * Discover opencode skills from ~/.config/opencode/skills and
     * {projectRoot}/.opencode/skills, tagging every result SkillSource::Opencode.
     *
     * Same user < project precedence as {@see discoverClaude()}; see that
     * method's doc-block for why the search order is lowest-priority-first.
     *
     * @return array<string, Skill> keyed by skill name, tagged SkillSource::Opencode
     */
    public function discoverOpencode(string $projectRoot): array
    {
        return $this->discover(
            [
                ($_SERVER['HOME'] ?? '/root') . '/.config/opencode/skills',
                $projectRoot . '/.opencode/skills',
            ],
            SkillSource::Opencode,
        );
    }

    /**
     * Merge every directory's skills into one registry, tagging each with
     * $source. $dirs MUST be ordered lowest-priority-first: the loop is
     * last-write-wins, so a later directory's skill overrides an earlier
     * one sharing its name.
     *
     * @param list<string> $dirs
     * @return array<string, Skill>
     */
    private function discover(array $dirs, SkillSource $source): array
    {
        $skills = [];
        foreach ($dirs as $dir) {
            foreach ($this->loader->loadFromDirectory($dir) as $name => $skill) {
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
