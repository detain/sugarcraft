<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Skills;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads skills in three stages:
 * 1. name/description only at startup (loadSkillManifest)
 * 2. full SKILL.md body when task matches (loadSkillBody)
 * 3. scripts/references/assets subdirectories only when actually needed (loadSkillAsset)
 *
 * Supports context-fork mode (spawn sub-agent with no access to calling conversation).
 */
final class SkillLoader
{
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';
    private const ASSET_SUBDIRS = ['scripts', 'references', 'assets'];
    /**
     * Load all skills from a directory.
     *
     * @return array<string, Skill>
     */
    public function loadFromDirectory(string $dir): array
    {
        // Resolve real path and validate the directory exists
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
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

    // -------------------------------------------------------------------------
    // Staged Loading Methods
    // -------------------------------------------------------------------------

    /**
     * Stage 1: Load only name + description frontmatter from SKILL.md.
     *
     * Returns a manifest array with:
     *   - name: skill directory name
     *   - description: from frontmatter or "Skill: $name"
     *   - disableModelInvocation: bool
     *   - userInvocable: bool
     *   - context: 'thread' or 'fork' (context-fork mode)
     *   - sourcePath: absolute path to SKILL.md
     *
     * @return array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, sourcePath: string}
     */
    public function loadSkillManifest(string $skillDir): array
    {
        $skillPath = rtrim($skillDir, '/') . '/SKILL.md';

        if (!is_file($skillPath)) {
            throw new \RuntimeException("SKILL.md not found in: $skillDir");
        }

        $content = file_get_contents($skillPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read skill manifest: $skillPath");
        }

        // Parse frontmatter only (stage 1 - don't load body)
        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches)) {
            $frontmatter = Yaml::parse($matches[1]);
        } else {
            $frontmatter = [];
        }

        $name = basename($skillDir);

        return [
            'name' => $name,
            'description' => $frontmatter['description'] ?? "Skill: $name",
            'disableModelInvocation' => (bool)($frontmatter['disable-model-invocation'] ?? false),
            'userInvocable' => (bool)($frontmatter['user-invocable'] ?? true),
            'context' => $frontmatter['context'] ?? 'thread',
            'sourcePath' => realpath($skillPath) ?: $skillPath,
        ];
    }

    /**
     * Stage 2: Load full SKILL.md body content.
     *
     * Returns the content after frontmatter, trimmed.
     */
    public function loadSkillBody(string $skillPath): string
    {
        if (!is_file($skillPath)) {
            throw new \RuntimeException("Skill file not found: $skillPath");
        }

        $content = file_get_contents($skillPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read skill body: $skillPath");
        }

        // Strip frontmatter to get body
        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches)) {
            $body = substr($content, strlen($matches[0]));
        } else {
            $body = $content;
        }

        return trim($body);
    }

    /**
     * Stage 3: Load a file from scripts/references/assets subdirectories.
     *
     * @param string $skillPath Absolute path to the skill's SKILL.md
     * @param string $relativePath Relative path within the skill's subdirectories (must be within scripts/references/assets)
     * @return string The file contents
     */
    public function loadSkillAsset(string $skillPath, string $relativePath): string
    {
        $skillDir = dirname($skillPath);
        $assetPath = $skillDir . '/' . $relativePath;

        // Validate relativePath is within allowed subdirectories
        $firstComponent = explode('/', ltrim($relativePath, '/'))[0];
        if (!in_array($firstComponent, self::ASSET_SUBDIRS, true)) {
            throw new \RuntimeException(
                "Asset path must be within " . implode('/', self::ASSET_SUBDIRS) . " subdirectory: $relativePath"
            );
        }

        // Security: ensure the path is within the skill directory
        $realSkillDir = realpath($skillDir);
        $realAssetPath = realpath($assetPath);

        if ($realSkillDir === false) {
            throw new \RuntimeException("Invalid skill path: $skillPath");
        }

        if ($realAssetPath === false) {
            throw new \RuntimeException("Asset path does not exist: $relativePath");
        }

        // Must be within skill directory (no path traversal)
        if (!str_starts_with($realAssetPath . '/', $realSkillDir . '/')) {
            throw new \RuntimeException("Asset path escapes skill directory: $relativePath");
        }

        if (!is_file($assetPath)) {
            throw new \RuntimeException("Asset not found: $assetPath");
        }

        $content = file_get_contents($assetPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read asset: $assetPath");
        }

        return $content;
    }
}
