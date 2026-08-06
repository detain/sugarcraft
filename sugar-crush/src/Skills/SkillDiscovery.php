<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Discovers skill directories across project, user, and per-lib search paths.
 *
 * Search paths (in priority order, highest last):
 *   1. Project skills:   {projectRoot}/.sugar-crush/skills/
 *   2. User skills:      ~/.sugar-crush/skills/
 *   3. Per-lib skills:   {libPath}/.sugar-crush/skills/  (for each lib in $libPaths)
 *
 * discoverAll() merges all three sources with later paths overriding earlier
 * when the same skill name is found in multiple locations.
 */
final class SkillDiscovery
{
    private const PROJECT_SUBDIR = '.sugar-crush/skills';
    private const USER_SUBDIR = '.sugar-crush/skills';

    /**
     * Discover project-level skills under {projectRoot}/.sugar-crush/skills/.
     *
     * @return array<string> absolute skill directory paths
     */
    public function discoverProjectSkills(string $projectRoot): array
    {
        $path = $this->buildProjectPath($projectRoot);

        return $this->discoverSkillsAt($path);
    }

    /**
     * Discover user-level skills under ~/.sugar-crush/skills/.
     *
     * @return array<string> absolute skill directory paths
     */
    public function discoverUserSkills(): array
    {
        $home = $_SERVER['HOME'] ?? '/root';
        $path = $home . '/' . self::USER_SUBDIR;

        return $this->discoverSkillsAt($path);
    }

    /**
     * Discover per-lib nested skills under {libPath}/.sugar-crush/skills/.
     *
     * @return array<string> absolute skill directory paths
     */
    public function discoverLibSkills(string $libPath): array
    {
        $path = rtrim($libPath, '/') . '/.sugar-crush/skills';

        return $this->discoverSkillsAt($path);
    }

    /**
     * Discover all skills across project, user, and lib search paths.
     *
     * Priority order (later overrides earlier on name conflicts):
     *   1. Project skills
     *   2. User skills
     *   3. Per-lib skills (in the order provided by $libPaths)
     *
     * @param array<string> $libPaths   ordered list of library paths to scan for nested skills
     * @param string        $projectRoot root path for project skills (default '.')
     * @return array<string>           skill name => absolute path (deduplicated)
     */
    public function discoverAll(array $libPaths = [], string $projectRoot = '.'): array
    {
        $discovered = [];

        // 1. Project skills (lowest priority)
        foreach ($this->discoverProjectSkills($projectRoot) as $path) {
            $discovered[$this->skillNameFromPath($path)] = $path;
        }

        // 2. User skills (override project)
        foreach ($this->discoverUserSkills() as $path) {
            $discovered[$this->skillNameFromPath($path)] = $path;
        }

        // 3. Per-lib skills (highest priority, in given order)
        foreach ($libPaths as $libPath) {
            foreach ($this->discoverLibSkills($libPath) as $path) {
                $discovered[$this->skillNameFromPath($path)] = $path;
            }
        }

        return $discovered;
    }

    /**
     * Resolve the absolute path for a named skill by searching through $searchPaths.
     *
     * Returns null when the skill is not found in any of the provided search paths.
     *
     * @param string        $skillName   simple skill name (e.g. "my-skill")
     * @param array<string> $searchPaths absolute paths to scan for skill directories
     * @return string|null              absolute path to the skill directory, or null if not found
     */
    public function resolveSkillPath(string $skillName, array $searchPaths): ?string
    {
        foreach ($searchPaths as $basePath) {
            $candidate = rtrim($basePath, '/') . '/' . $skillName;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildProjectPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/' . self::PROJECT_SUBDIR;
    }

    /**
     * Scan a base skills directory and return absolute paths to each skill subdirectory.
     *
     * @return array<string>
     */
    private function discoverSkillsAt(string $path): array
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) {
            return [];
        }

        $skills = [];
        $iterator = new \DirectoryIterator($realPath);

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isDot()) {
                $skills[] = $item->getPathname();
            }
        }

        return $skills;
    }

    /**
     * Extract the skill name (directory basename) from an absolute skill path.
     */
    private function skillNameFromPath(string $path): string
    {
        return basename($path);
    }
}
