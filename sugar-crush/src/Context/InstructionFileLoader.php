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
     * Every instruction file this loader has already put in front of the
     * model, keyed by resolved absolute path — whether emitted as its own
     * document (loadRoot()/loadForced()), inlined by an `@import`
     * expansion, or injected on-touch by loadForPath().
     *
     * One shared set across all four routes is what stops the same bytes
     * occupying the context window twice on EVERY turn. The motivating case
     * is this very repo: root CLAUDE.md contains `@./AGENTS.md`, so
     * ImportResolver inlines AGENTS.md's full body into the CLAUDE.md
     * document, and loadRoot() would then also emit AGENTS.md as a second
     * top-level document. It doubles as `@import` cycle protection — a file
     * that imports itself is marked before its own expansion runs.
     *
     * @var array<string, true>
     */
    private array $emittedPaths = [];

    private readonly ImportResolver $importResolver;

    /**
     * Session-lifetime caches for the two whole-session loaders.
     *
     * Runtime::buildSystemPrompt() runs once per agentic step, so without
     * memoization every model round-trip would re-read the same root files
     * from disk and re-expand their `@import` references.
     *
     * The trade-off is deliberate: an edit to CLAUDE.md/AGENTS.md made
     * mid-session is not picked up until the loader is rebuilt.
     *
     * Note that on the live TUI path EngineBackend::completeAsync() forks a
     * child per user turn, so a warmed cache dies with that child; the win
     * is collapsing the up-to-$maxSteps Runtime::run() reads inside a single
     * complete() down to one. Per-session caching would have to live on the
     * parent side of the fork.
     *
     * @var string[]|null
     */
    private ?array $rootCache = null;

    /** @var string[]|null */
    private ?array $forcedCache = null;

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
     * CLAUDE.md is read first, so when it `@import`s AGENTS.md (the shape
     * this repo's own root files use) AGENTS.md is emitted at the position
     * of that import and NOT repeated afterwards as a second document.
     * Precedence is unchanged — the earlier file still wins the slot.
     *
     * @return string[] Contents of whichever root files exist (missing files,
     *                  and files already inlined by an earlier file's import,
     *                  are skipped)
     */
    public function loadRoot(): array
    {
        if ($this->rootCache !== null) {
            return $this->rootCache;
        }

        $rootFiles = [
            $this->repoRoot . '/CLAUDE.md',
            $this->repoRoot . '/AGENTS.md',
        ];

        $contents = [];
        foreach ($rootFiles as $path) {
            if (!is_file($path)) {
                continue;
            }

            $realPath = realpath($path) ?: $path;
            if (isset($this->emittedPaths[$realPath])) {
                continue;
            }

            // Marked BEFORE expanding so a file importing itself resolves to
            // the already-included note rather than recursing to MAX_DEPTH.
            $this->emittedPaths[$realPath] = true;

            $raw = file_get_contents($path);
            $contents[] = $raw === false ? '' : $this->expandImports($raw, dirname($path));
        }

        return $this->rootCache = $contents;
    }

    /**
     * Resolve glob patterns from config and load matching file contents.
     *
     * These patterns (e.g. "candy-shine/CALIBER_LEARNINGS.md") force-load
     * regardless of what the agent has touched, providing cross-cutting
     * guidance that shouldn't depend on an agent opening the right file.
     *
     * Patterns are user config but their matches land verbatim in the model's
     * system prompt, so containment is enforced twice: absolute patterns are
     * rejected outright, and every glob match is realpath()-checked against
     * repoRoot afterwards. The second check is what stops a relative pattern
     * that traverses out (`../../../etc/ssh/*`) from exfiltrating files —
     * mirroring the boundary closure expandImports() hands to ImportResolver.
     *
     * Containment is judged on the realpath()-RESOLVED target, so a symlink
     * that lives inside the repo but points outside it (a common way to
     * vendor shared docs) is deliberately skipped too — a forced instruction
     * that silently never appears is most likely this.
     *
     * A match already emitted as a root document (or inlined by one of their
     * imports) is skipped: Runtime::buildSystemPrompt() drains loadRoot()
     * before loadForced(), so a glob that happens to cover CLAUDE.md adds
     * nothing but a second copy.
     *
     * @return string[] Contents of matching in-repo files (patterns with no
     *                  matches, matches outside repoRoot, and matches already
     *                  emitted elsewhere are skipped)
     */
    public function loadForced(): array
    {
        if ($this->forcedCache !== null) {
            return $this->forcedCache;
        }

        if ($this->forcedInstructions === []) {
            return $this->forcedCache = [];
        }

        // Trailing slash stripped so the two-branch check below stays correct
        // for a degenerate root: realpath('/') is '/', and comparing against
        // '//' would reject every path in the filesystem.
        $repoRoot = rtrim(realpath($this->repoRoot) ?: $this->repoRoot, '/');

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
                if (!is_file($path)) {
                    continue;
                }

                // A relative pattern can still traverse out via "..", so the
                // resolved match — not the pattern — is what must be contained.
                $realPath = realpath($path);
                if ($realPath === false || ($realPath !== $repoRoot && !str_starts_with($realPath, $repoRoot . '/'))) {
                    continue;
                }

                if (isset($this->emittedPaths[$realPath])) {
                    continue;
                }

                $raw = file_get_contents($realPath);
                if ($raw !== false) {
                    $this->emittedPaths[$realPath] = true;
                    $contents[] = $raw;
                }
            }
        }

        return $this->forcedCache = $contents;
    }

    /**
     * Load nested instruction file for a touched path.
     *
     * Walks up from the touched file's directory toward repoRoot, checking
     * each level for CLAUDE.md or AGENTS.md. CLAUDE.md is preferred over
     * AGENTS.md at the same level. Each nested file is injected at most once
     * per session — subsequent calls for the same path return null if already
     * injected, and a file the root documents already carried (directly or via
     * an `@import`) counts as injected too.
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
                if (!is_file($fullPath)) {
                    continue;
                }

                $realPath = realpath($fullPath) ?: $fullPath;
                if (!isset($this->emittedPaths[$realPath])) {
                    $this->emittedPaths[$realPath] = true;
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
     *
     * The same hook is where every followed import is recorded in
     * $emittedPaths, and where a repeat of an already-emitted file is
     * replaced by a short note instead of a second full copy of its body.
     * ImportResolver threads the closure through its recursion, so this is
     * the one place that sees every file the expansion would inline, at any
     * depth — which is why the de-duplication lives here rather than in
     * ImportResolver (which is stateless and shared) or in the caller (which
     * only ever sees the finished string).
     */
    private function expandImports(string $content, string $baseDir): string
    {
        $repoRoot = realpath($this->repoRoot) ?: rtrim($this->repoRoot, '/');

        $gate = function (string $realPath, string $pathFragment) use ($repoRoot): ?string {
            if ($realPath !== $repoRoot && !str_starts_with($realPath, $repoRoot . '/')) {
                return "<import-blocked reason=\"outside-repo-root\">Import '{$pathFragment}' resolves to"
                    . " '{$realPath}', outside the repository root, and was not followed.</import-blocked>";
            }

            if (isset($this->emittedPaths[$realPath])) {
                return "<import-skipped reason=\"already-included\">Import '{$pathFragment}' is already"
                    . ' included in this prompt and was not repeated.</import-skipped>';
            }

            $this->emittedPaths[$realPath] = true;

            return null; // in-repo, not yet seen -- let ImportResolver expand it
        };

        return $this->importResolver->expand($content, $baseDir, 0, $gate);
    }
}
