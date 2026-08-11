<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\App\App;

/**
 * Manages skill loading, selection, and application.
 */
final class SkillManager
{
    public function __construct(
        private SkillLoader $loader,
        private SkillRegistry $registry,
    ) {}

    /**
     * Load all skills from standard locations.
     *
     * Registers Stage-1 manifests only (name/description/flags) via
     * SkillLoader::loadAllManifests() -- not the previous
     * SkillLoader::loadAll(), which eagerly read every skill's full
     * SKILL.md body off disk regardless of whether it was ever used that
     * session. Fixes crush_feat.md section 7.E3.
     *
     * The body is *designed* to backfill lazily, on-demand, via
     * Tools\BuiltIn\SkillTool::execute() (which already calls
     * loadSkillBody() correctly) -- but as of this step that tool is not
     * yet registered into Bootstrap::tools()/EngineBackend, so that half of
     * the backfill path is implemented and tested, not yet production
     * reachable from bin/sugarcrush. Wiring it in is tracked separately
     * (crush_feat.md section 7, item 2 / W3.S8 in crush_feat_plan.md).
     */
    public function loadAll(string $projectRoot = '.'): void
    {
        foreach ($this->loader->loadAllManifests($projectRoot) as $manifest) {
            $this->registry->registerFromManifest($manifest);
        }
    }

    /**
     * Get skills for a specific task.
     *
     * @return array<Skill>
     */
    public function getSkillsForTask(string $task): array
    {
        return $this->registry->findForPrompt($task);
    }

    /**
     * Get skills matching file paths.
     *
     * @param array<string> $paths
     * @return array<Skill>
     */
    public function getSkillsForPaths(array $paths): array
    {
        return $this->registry->getForPaths($paths);
    }

    /**
     * Apply skills to an app.
     *
     * @param array<string> $skillNames
     */
    public function applyToApp(App $app, array $skillNames): App
    {
        $skills = [];

        foreach ($skillNames as $name) {
            $skill = $this->registry->get($name);
            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return $app->withEnabledSkills($skills);
    }

    /**
     * Enable a skill by name.
     */
    public function enable(App $app, string $skillName): App
    {
        $current = $app->enabledSkills;
        $skill = $this->registry->get($skillName);

        if ($skill === null) {
            return $app;
        }

        foreach ($current as $s) {
            if ($s->name === $skillName) {
                return $app;  // Already enabled
            }
        }

        return $app->withEnabledSkills([...$current, $skill]);
    }

    /**
     * Disable a skill by name.
     */
    public function disable(App $app, string $skillName): App
    {
        $current = $app->enabledSkills;

        return $app->withEnabledSkills(
            array_filter($current, fn($s) => $s->name !== $skillName)
        );
    }

    /**
     * Get all user-invocable skills.
     *
     * @return array<Skill>
     */
    public function getUserInvocable(): array
    {
        return $this->registry->getUserInvocable();
    }

    /**
     * Disable skills listed in config.
     *
     * @param array<string> $disabled
     */
    public function disableFromConfig(array $disabled): void
    {
        $this->registry->disableMultiple($disabled);
    }
}
