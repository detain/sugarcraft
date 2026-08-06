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
 */
final class InstructionFileLoader
{
    /**
     * @var array<string, true> Tracks which nested instruction files have been injected this session
     */
    private array $injectedPaths = [];

    /**
     * @param string $repoRoot Absolute path to the repository root
     * @param string[] $forcedInstructions Glob patterns from config, force-loaded every session
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly array $forcedInstructions = [],
    ) {}

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
                $contents[] = file_get_contents($path);
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
                    return file_get_contents($fullPath);
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
}
