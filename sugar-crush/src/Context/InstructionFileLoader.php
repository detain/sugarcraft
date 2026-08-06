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
 *
 * Nested per-path loading (P6.S15) is handled separately via loadForPath().
 */
final class InstructionFileLoader
{
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
}
