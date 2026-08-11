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
                    $skillName = $this->skillKeyFor($dir, $file->getPathname(), $skill->name);
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
        return $this->loadFromDirectory($this->userSkillsDir());
    }

    /**
     * Load project skills from .sugar-crush/skills/.
     *
     * @return array<string, Skill>
     */
    public function loadProjectSkills(string $projectRoot): array
    {
        return $this->loadFromDirectory($this->projectSkillsDir($projectRoot));
    }

    /**
     * Load built-in skills.
     *
     * @return array<string, Skill>
     */
    public function loadBuiltInSkills(): array
    {
        return $this->loadFromDirectory($this->builtInSkillsDir());
    }

    /** ~/.sugar-crush/skills — shared by the eager and manifest-only loaders. */
    private function userSkillsDir(): string
    {
        $dir = $_SERVER['HOME'] ?? '/root';

        return $dir . '/.sugar-crush/skills';
    }

    /** <projectRoot>/.sugar-crush/skills — shared by the eager and manifest-only loaders. */
    private function projectSkillsDir(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/.sugar-crush/skills';
    }

    /** src/Skills/BuiltIn — shared by the eager and manifest-only loaders. */
    private function builtInSkillsDir(): string
    {
        $reflection = new \ReflectionClass($this);

        return dirname($reflection->getFileName()) . '/BuiltIn';
    }

    /**
     * Compute a discovered skill's registry key the same way for both the
     * eager (loadFromDirectory) and manifest-only (loadManifestsFromDirectory)
     * walkers: a skill nested more than one level under $baseDir is keyed by
     * its path relative to $baseDir (so sibling skills sharing a leaf dirname
     * don't collide); a top-level skill keeps its own name.
     */
    private function skillKeyFor(string $baseDir, string $skillFilePath, string $fallbackName): string
    {
        $relativePath = substr($skillFilePath, strlen($baseDir) + 1);
        $relativeSkillDir = dirname($relativePath);

        return $relativeSkillDir === '.' ? $fallbackName : $relativeSkillDir;
    }

    /**
     * Load skills from multiple sources.
     *
     * Priority order: built-in < user < project (later sources override
     * earlier). Foreign (.claude/.opencode) source merging is W1.D2a's
     * concern, not this step's -- see ForeignSkillDiscovery's own doc-block.
     *
     * @return array<string, Skill>
     */
    public function loadAll(string $projectRoot = '.'): array
    {
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
     *   - paths: glob patterns for path-based auto-scoping (SkillRegistry::getForPaths())
     *   - sourcePath: absolute path to SKILL.md
     *
     * paths comes from frontmatter (already parsed above), so surfacing it
     * here doesn't cost the body-read this stage exists to avoid.
     *
     * @return array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}
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
            'paths' => $frontmatter['paths'] ?? [],
            'sourcePath' => realpath($skillPath) ?: $skillPath,
        ];
    }

    /**
     * Stage-1 equivalent of loadFromDirectory(): walks the same directory
     * tree for SKILL.md files, but loads only each one's manifest
     * (loadSkillManifest()) instead of the full Skill (Skill::fromFile()),
     * so no body content is read from disk at this stage.
     *
     * @return array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}>
     */
    public function loadManifestsFromDirectory(string $dir): array
    {
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return [];
        }

        $manifests = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getBasename() === 'SKILL.md' && $file->isFile()) {
                try {
                    $skillFilePath = $file->getPathname();
                    $manifest = $this->loadSkillManifest(dirname($skillFilePath));
                    $manifest['name'] = $this->skillKeyFor($dir, $skillFilePath, $manifest['name']);
                    $manifests[$manifest['name']] = $manifest;
                } catch (\Throwable $e) {
                    // Log and skip invalid skills -- same contract as loadFromDirectory().
                    error_log("Failed to load skill manifest from {$file->getPathname()}: {$e->getMessage()}");
                }
            }
        }

        return $manifests;
    }

    /**
     * Stage-1 equivalent of loadAll(): discovers every skill across the same
     * sources and priority order (built-in < user < project, later
     * overrides earlier) but loads only each one's manifest, not its body.
     *
     * Fixes the defect described in crush_feat.md section 7.E3: every
     * ReactPHP-loop session used to pay the full I/O + YAML-parse cost of
     * every built-in/user/project skill's body at startup even when zero
     * skills were invoked that session, defeating the point of the
     * already-designed three-stage progressive disclosure. The body is
     * designed to backfill just-in-time via loadSkillBody(), called from
     * Tools\BuiltIn\SkillTool::execute() -- but that tool is not yet
     * registered into Bootstrap::tools()/EngineBackend, so the backfill
     * half is implemented and tested, not yet reachable from
     * bin/sugarcrush (tracked separately: crush_feat.md section 7 item 2 /
     * W3.S8).
     *
     * Foreign-imported skills (.claude/skills, .opencode/skills) are
     * W1.D2a's concern (ForeignSkillDiscovery) and are deliberately not
     * merged in here -- that step lands its own merge-order change on top
     * once it's independently reviewed, keeping this step's diff scoped to
     * SkillLoader.php/SkillManager.php per crush_feat_plan.md's Wave 1
     * file-disjoint-steps design.
     *
     * @return array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}>
     */
    public function loadAllManifests(string $projectRoot = '.'): array
    {
        // Built-in overrides nothing (lowest priority)
        $manifests = $this->loadManifestsFromDirectory($this->builtInSkillsDir());

        // User skills override builtins
        $manifests = array_merge($manifests, $this->loadManifestsFromDirectory($this->userSkillsDir()));

        // Project skills override everything else
        $manifests = array_merge($manifests, $this->loadManifestsFromDirectory($this->projectSkillsDir($projectRoot)));

        return $manifests;
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
