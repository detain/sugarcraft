<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

final class SkillLoader
{
    /**
     * Load all skills from a directory.
     *
     * @return array<string, Skill>
     */
    public function loadFromDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $skills = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getBasename() === 'SKILL.md' && $file->isFile()) {
                try {
                    $skill = Skill::fromFile($file->getPathname());
                    // Compute skill name as relative path from base directory
                    $filePath = $file->getPathname();
                    $relativePath = substr($filePath, strlen($dir) + 1);
                    $skillDir = dirname($relativePath);
                    $skillName = $skillDir === '.' ? $skill->name : $skillDir;
                    $skill = $skill->withName($skillName);
                    $skills[$skill->name] = $skill;
                } catch (\Throwable $e) {
                    // Log and skip invalid skills
                    error_log("Failed to load skill from {$file->getPathname()}: {$e->getMessage()}");
                }
            }
        }

        return $skills;
    }

    /**
     * Load user skills from ~/.sugar-crush/skills/.
     *
     * @return array<string, Skill>
     */
    public function loadUserSkills(): array
    {
        $dir = $_SERVER['HOME'] ?? '/root';
        $dir .= '/.sugar-crush/skills';

        return $this->loadFromDirectory($dir);
    }

    /**
     * Load project skills from .sugar-crush/skills/.
     *
     * @return array<string, Skill>
     */
    public function loadProjectSkills(string $projectRoot): array
    {
        $dir = rtrim($projectRoot, '/') . '/.sugar-crush/skills';

        return $this->loadFromDirectory($dir);
    }

    /**
     * Load built-in skills.
     *
     * @return array<string, Skill>
     */
    public function loadBuiltInSkills(): array
    {
        $reflection = new \ReflectionClass($this);
        $dir = dirname($reflection->getFileName()) . '/BuiltIn';

        return $this->loadFromDirectory($dir);
    }

    /**
     * Load skills from multiple sources.
     *
     * Priority order: built-in < user < project (later sources override earlier)
     *
     * @return array<string, Skill>
     */
    public function loadAll(string $projectRoot = '.'): array
    {
        $skills = [];

        // Built-in first (lowest priority)
        $builtin = $this->loadBuiltInSkills();

        // User skills override builtins
        $user = $this->loadUserSkills();
        $skills = array_merge($builtin, $user);

        // Project skills override both
        $project = $this->loadProjectSkills($projectRoot);
        $skills = array_merge($skills, $project);

        return $skills;
    }
}
