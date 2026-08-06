<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Loads instruction files (CLAUDE.md, AGENTS.md) for session context.
 *
 * Supports three loading strategies:
 * - loadRoot(): always-loaded root-level files
 * - loadForPath(): on-demand nested injection when tools touch files in libs
 * - loadForced(): glob-resolved paths from config, loaded every session
 *
 * Mirrors charmbracelet/treebomb or similar nested-dotfile discovery.
 */
final readonly class InstructionFileLoader
{
    /** @var array<string, string> */
    private array $forcedInstructions;

    public function __construct(
        private string $repoRoot,
        array $forcedInstructions = [],
    ) {
        $this->forcedInstructions = $forcedInstructions;
    }

    /**
     * Root-level CLAUDE.md and AGENTS.md — always loaded at session start.
     *
     * @return string[] Array of file contents
     */
    public function loadRoot(): array
    {
        $contents = [];
        foreach (['CLAUDE.md', 'AGENTS.md'] as $basename) {
            $path = rtrim($this->repoRoot, '/') . '/' . $basename;
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $contents[] = $content;
                }
            }
        }
        return $contents;
    }

    /**
     * On-demand nested instruction file injection.
     *
     * When a tool (Read/Edit/Glob) successfully touches a file inside a lib
     * directory (e.g. sugar-crush/, candy-shine/), this method discovers
     * that lib's own CLAUDE.md or AGENTS.md and returns its content.
     * The file is returned exactly once per session — subsequent calls for
     * the same lib return null even if the file content changed mid-session.
     *
     * The search walks from the touched file's directory up toward the repo
     * root, checking each intermediate directory for CLAUDE.md or AGENTS.md.
     * The repo root itself is not checked (it is handled by loadRoot()).
     *
     * @param string $touchedPath Path to the file that was touched
     * @param array<string, bool> &$sessionCache Session-scoped cache of already-loaded
     *                            nested files, keyed by absolute file path
     * @return string|null File content if found and not yet loaded this session, null otherwise
     */
    public function loadForPath(string $touchedPath, array &$sessionCache): ?string
    {
        $dir = dirname($touchedPath);

        // Walk upward from the touched file's directory toward repo root
        // Stop before the repo root itself (which loadRoot() handles)
        $repoRoot = rtrim($this->repoRoot, '/');
        $current = $dir;

        while ($current !== '' && $current !== '/' && str_starts_with($current, $repoRoot)) {
            // Check cache first
            $claudePath = $current . '/CLAUDE.md';
            $agentsPath = $current . '/AGENTS.md';

            if (!isset($sessionCache[$claudePath]) && !isset($sessionCache[$agentsPath])) {
                // Neither file in this directory has been checked yet
                $foundPath = null;

                if (file_exists($claudePath)) {
                    $foundPath = $claudePath;
                } elseif (file_exists($agentsPath)) {
                    $foundPath = $agentsPath;
                }

                if ($foundPath !== null) {
                    $sessionCache[$foundPath] = true;
                    $content = file_get_contents($foundPath);
                    return $content !== false ? $content : null;
                }
            }

            // Move to parent directory
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            // Don't search inside repo root itself (loadRoot handles that)
            if ($parent === $repoRoot) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Resolves glob patterns from config and returns matching file contents.
     *
     * These files are force-loaded every session regardless of what was touched.
     *
     * @return string[] Array of file contents
     */
    public function loadForced(): array
    {
        $contents = [];
        $seenPaths = [];
        foreach ($this->forcedInstructions as $pattern) {
            // glob patterns may be relative to repo root or absolute
            if (!str_starts_with($pattern, '/')) {
                $pattern = rtrim($this->repoRoot, '/') . '/' . $pattern;
            }
            $files = glob($pattern);
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    $realPath = realpath($file);
                    // Skip if already seen (deduplicate by resolved path)
                    if (isset($seenPaths[$realPath])) {
                        continue;
                    }
                    $seenPaths[$realPath] = true;
                    $content = file_get_contents($file);
                    if ($content !== false) {
                        $contents[] = $content;
                    }
                }
            }
        }
        return $contents;
    }
}
