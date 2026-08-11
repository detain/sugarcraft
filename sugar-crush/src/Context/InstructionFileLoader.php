<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Loads instruction files (CLAUDE.md, AGENTS.md) from the repo root and
 * resolves forced-instruction glob patterns from config.
 *
 * This class handles the nested instruction file discovery mechanism:
 * - Root-level files (CLAUDE.md, AGENTS.md) are always loaded at session start
 * - Forced instruction patterns from config are glob-resolved and loaded every session
 * - Per-path nested instruction files (CLAUDE.md, AGENTS.md in subdirectories)
 *   are loaded via loadForPath() and tracked internally to inject each file at most once
 *
 * `loadRoot()`/`loadForPath()` also expand `@path` import references via
 * ImportResolver, mirroring Claude Code's CLAUDE.md/AGENTS.md import syntax
 * (this repo's own root CLAUDE.md already uses `@./AGENTS.md`).
 */
final class InstructionFileLoader
{
    /**
     * @var array<string, true> Tracks which nested instruction files have been injected this session
     */
    private array $injectedPaths = [];

    private readonly ImportResolver $importResolver;

    /**
     * @param string $repoRoot Absolute path to the repository root
     * @param string[] $forcedInstructions Glob patterns from config, force-loaded every session
     * @param ImportResolver|null $importResolver Expander for `@path` references; defaults to ImportResolver::new()
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly array $forcedInstructions = [],
        ?ImportResolver $importResolver = null,
    ) {
        $this->importResolver = $importResolver ?? ImportResolver::new();
    }

    /**
     * Load CLAUDE.md and AGENTS.md from the repo root.
     *
     * These root-level instruction files are always loaded at session start,
     * providing cross-cutting conventions that apply everywhere.
     *
     * @return string[] Array of file contents (empty strings for missing files)
     */
    public function loadRoot(): array
    {
        $rootFiles = [
            $this->repoRoot . '/CLAUDE.md',
            $this->repoRoot . '/AGENTS.md',
        ];

        $contents = [];
        foreach ($rootFiles as $path) {
            if (is_file($path)) {
                $raw = file_get_contents($path);
                $contents[] = $raw === false ? '' : $this->expandImports($raw, dirname($path));
            }
        }

        return $contents;
    }

    /**
     * Resolve glob patterns from config and load matching file contents.
     *
     * These patterns (e.g. "candy-shine/CALIBER_LEARNINGS.md") force-load
     * regardless of what the agent has touched, providing cross-cutting
     * guidance that shouldn't depend on an agent opening the right file.
     *
     * @return string[] Array of file contents (skips patterns with no matches)
     */
    public function loadForced(): array
    {
        if ($this->forcedInstructions === []) {
            return [];
        }

        $contents = [];
        foreach ($this->forcedInstructions as $pattern) {
            // Reject absolute paths — they bypass repoRoot and are a security risk.
            if (str_starts_with($pattern, '/')) {
                continue;
            }

            $fullPattern = $this->repoRoot . '/' . $pattern;
            $matches = glob($fullPattern);

            if ($matches === false || $matches === []) {
                continue;
            }

            foreach ($matches as $path) {
                if (is_file($path)) {
                    $contents[] = file_get_contents($path);
                }
            }
        }

        return $contents;
    }

    /**
     * Load nested instruction file for a touched path.
     *
     * Walks up from the touched file's directory toward repoRoot, checking
     * each level for CLAUDE.md or AGENTS.md. CLAUDE.md is preferred over
     * AGENTS.md at the same level. Each nested file is injected at most once
     * per session — subsequent calls for the same path return null if already
     * injected.
     *
     * @param string $touchedPath Absolute path to the file that was touched
     * @return string|null The nested instruction file content, or null if none found
     */
    public function loadForPath(string $touchedPath): ?string
    {
        // Get the directory containing the touched file
        $dir = dirname($touchedPath);

        // Normalize repoRoot to avoid infinite loops on edge cases
        $repoRoot = realpath($this->repoRoot) ?: $this->repoRoot;
        if ($repoRoot === '') {
            return null;
        }

        // Walk up the directory tree toward repoRoot
        while ($dir !== $repoRoot && $dir !== '.' && $dir !== false) {
            // Check for CLAUDE.md first (preferred), then AGENTS.md
            foreach (['CLAUDE.md', 'AGENTS.md'] as $filename) {
                $fullPath = $dir . '/' . $filename;

                if (is_file($fullPath) && !isset($this->injectedPaths[$fullPath])) {
                    $this->injectedPaths[$fullPath] = true;
                    $raw = file_get_contents($fullPath);
                    return $raw === false ? null : $this->expandImports($raw, dirname($fullPath));
                }
            }

            // Move to parent directory
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Expand `@path` import references in freshly-read instruction content.
     *
     * A boundary-check closure is handed straight into
     * ImportResolver::expand(), which threads it through EVERY recursive
     * expansion call (not just the references present in the outermost
     * $content) -- so a reference that resolves outside $repoRoot is
     * blocked and replaced with an inline warning note no matter how many
     * @import hops deep it is found, mirroring Claude Code's approval-dialog
     * concept for imports that leave the project (at minimum, sugar-crush
     * has no interactive approval flow yet, so this is the "at minimum a
     * warning-tagged note" fallback). In-repo references are left for
     * ImportResolver to resolve and recurse into as normal.
     */
    private function expandImports(string $content, string $baseDir): string
    {
        $repoRoot = realpath($this->repoRoot) ?: rtrim($this->repoRoot, '/');

        $boundaryCheck = static function (string $realPath, string $pathFragment) use ($repoRoot): ?string {
            if ($realPath === $repoRoot || str_starts_with($realPath, $repoRoot . '/')) {
                return null; // in-repo -- let ImportResolver expand it normally
            }

            return "<import-blocked reason=\"outside-repo-root\">Import '{$pathFragment}' resolves to"
                . " '{$realPath}', outside the repository root, and was not followed.</import-blocked>";
        };

        return $this->importResolver->expand($content, $baseDir, 0, $boundaryCheck);
    }
}
