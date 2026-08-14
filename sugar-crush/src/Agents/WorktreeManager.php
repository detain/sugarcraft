<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Manages creation, deletion, and listing of git worktrees per agent.
 *
 * Each agent gets its own worktree at {basePath}/{agentId}/ on a dedicated
 * branch (agent-{agentId}-{timestamp} by default). Worktrees are tracked
 * in a registry file so they can be enumerated and cleaned up.
 *
 * Worktree state is stored under:
 *     {basePath}/{agentId}/
 *
 * while the registry is stored at:
 *     {basePath}/.registry.json
 */
final class WorktreeManager
{
    /** @var array<string, array{branch: string, createdAt: string}> */
    private array $registry = [];

    private readonly string $expandedBasePath;
    private readonly string $registryPath;

    public function __construct(
        private readonly ?WorktreeConfig $config = null,
        private readonly string $repoRoot = '',
    ) {
        if ($this->config === null) {
            $this->config = WorktreeConfig::new();
        }
        $this->expandedBasePath = $this->expandPath($this->config->basePath);
        $this->registryPath = $this->expandedBasePath . '/.registry.json';
        $this->loadRegistry();
    }

    /**
     * Factory method matching the sibling WorktreeConfig::new() pattern.
     *
     * @param string $repoRoot Path to the git repository root.
     * @param WorktreeConfig|null $config Optional worktree configuration.
     */
    public static function new(string $repoRoot = '', ?WorktreeConfig $config = null): self
    {
        return new self($config, $repoRoot);
    }

    // -------------------------------------------------------------------------
    // Core operations
    // -------------------------------------------------------------------------

    /**
     * Create a new git worktree for the given agent.
     *
     * Creates a dedicated branch named "agent-{agentId}-{timestamp}" if no
     * branch name is provided, then runs "git worktree add {path} {branch}"
     * to create the isolated worktree directory.
     *
     * @param string $agentId Unique identifier for the agent (used as directory name).
     * @param string|null $branch Optional branch name; defaults to agent-{agentId}-{timestamp}.
     * @return string The absolute path to the newly created worktree.
     * @throws \InvalidArgumentException When agentId is empty or contains path traversal.
     * @throws \RuntimeException When git worktree creation fails.
     */
    public function createWorktree(string $agentId, ?string $branch = null): string
    {
        if ($agentId === '') {
            throw new \InvalidArgumentException('Agent ID must not be empty.');
        }

        if (str_contains($agentId, '..') || str_contains($agentId, '/') || str_contains($agentId, '\\')) {
            throw new \InvalidArgumentException(
                'Agent ID must not contain path traversal sequences, slashes, or backslashes.',
            );
        }

        if ($this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" already exists.', $agentId),
            );
        }

        $worktreePath = $this->expandedBasePath . '/' . $agentId;
        $branch ??= 'agent-' . $agentId . '-' . time();

        // Ensure parent directory exists
        if (!is_dir($this->expandedBasePath)) {
            mkdir($this->expandedBasePath, 0755, true);
        }

        // Create the worktree via git - use -b to create and checkout the new branch atomically
        $escapedPath = escapeshellarg($worktreePath);
        $escapedBranch = escapeshellarg($branch);
        $repoRootArg = $this->repoRoot !== '' ? '-C ' . escapeshellarg($this->repoRoot) . ' ' : '';

        $cmd = "git {$repoRootArg} worktree add -b {$escapedBranch} {$escapedPath} 2>&1";
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $outputStr = trim(implode("\n", $output));

        // Git writes worktree path to stdout on success; exit code 0 + dir exists = success.
        // Any exit code != 0 or output containing "fatal" indicates failure.
        if ($exitCode !== 0 || str_contains($outputStr, 'fatal')) {
            error_log("WorktreeManager: git worktree add failed for agent \"{$agentId}\" — exit {$exitCode}: {$outputStr}");
            throw new \RuntimeException(
                sprintf('Failed to create worktree for agent "%s": %s', $agentId, $outputStr ?: 'unknown error'),
            );
        }

        // Double-check the directory was actually created
        if (!is_dir($worktreePath)) {
            throw new \RuntimeException(
                sprintf('Failed to create worktree for agent "%s": directory not found after git command', $agentId),
            );
        }

        // Register the worktree
        $this->registry[$agentId] = [
            'branch' => $branch,
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'named' => false,
        ];
        $this->saveRegistry();

        // Copy .worktreeinclude-listed files (e.g. .env, composer auth) into
        // the fresh worktree — previously only reachable via manual reflection.
        $this->resolveWorktreeInclude($worktreePath);

        return $worktreePath;
    }

    /**
     * Remove the worktree for the given agent.
     *
     * Runs "git worktree remove {path}" to delete the worktree and its
     * working directory, then removes the entry from the registry.
     *
     * @param string $agentId The agent whose worktree should be removed.
     * @throws \InvalidArgumentException When agentId is empty.
     * @throws \RuntimeException When the worktree does not exist.
     */
    public function removeWorktree(string $agentId): void
    {
        if ($agentId === '') {
            throw new \InvalidArgumentException('Agent ID must not be empty.');
        }

        if (!$this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" does not exist.', $agentId),
            );
        }

        $worktreePath = $this->expandedBasePath . '/' . $agentId;

        // Remove via git first
        $escapedPath = escapeshellarg($worktreePath);
        if ($this->repoRoot !== '') {
            $cmd = sprintf(
                'git -C %s worktree remove %s 2>&1',
                escapeshellarg($this->repoRoot),
                $escapedPath,
            );
        } else {
            $cmd = "git worktree remove {$escapedPath} 2>&1";
        }

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $outputStr = trim(implode("\n", $output));

        // Git worktree remove returns exit code 0 on success, but may print to stderr.
        // Any exit code != 0 or output containing "fatal" indicates failure.
        if ($exitCode !== 0 || str_contains($outputStr, 'fatal')) {
            error_log("WorktreeManager: git worktree remove failed for agent \"{$agentId}\" — exit {$exitCode}: {$outputStr}");
        }

        // Remove the directory in case git didn't (e.g., dirty worktree was force-removed)
        if (is_dir($worktreePath)) {
            $this->removeDirectory($worktreePath);
        }

        unset($this->registry[$agentId]);
        $this->saveRegistry();
    }

    /**
     * Return the absolute path to the worktree for a given agent.
     *
     * @param string $agentId The agent whose worktree path is requested.
     * @return string The absolute path to the agent's worktree directory.
     * @throws \RuntimeException When the worktree does not exist.
     */
    public function getWorktreePath(string $agentId): string
    {
        if (!$this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" does not exist.', $agentId),
            );
        }

        return $this->expandedBasePath . '/' . $agentId;
    }

    /**
     * List all managed worktrees.
     *
     * Returns the registry of known agent worktrees with their branch
     * and creation timestamp.
     *
     * @return array<string, array{branch: string, createdAt: string}>
     */
    public function listWorktrees(): array
    {
        // Filter out entries whose directories no longer exist (orphaned)
        $valid = [];
        foreach ($this->registry as $agentId => $meta) {
            $path = $this->expandedBasePath . '/' . $agentId;
            if (is_dir($path)) {
                $valid[$agentId] = $meta;
            }
        }

        // Sync registry if any were removed externally
        if (count($valid) !== count($this->registry)) {
            $this->registry = $valid;
            $this->saveRegistry();
        }

        return $this->registry;
    }

    // -------------------------------------------------------------------------
    // .worktreeinclude resolution
    // -------------------------------------------------------------------------

    /**
     * Copy files matching patterns in .worktreeinclude into the new worktree.
     *
     * Reads .worktreeinclude (or the configured alternative) from the repo root,
     * interprets each line as a glob pattern (empty lines / # comments / !negs ignored),
     * and copies matching files into the newly-created worktree directory so that
     * normally-ignored files (e.g. .env, composer auth) are available to the agent.
     *
     * Mirrors: same approach as git's exclude file mechanism.
     *
     * @param string $worktreePath Absolute path to the newly created worktree.
     */
    public function resolveWorktreeInclude(string $worktreePath): void
    {
        if ($this->config->worktreeIncludeFile === '') {
            return;
        }

        $includeFile = $this->repoRoot !== ''
            ? $this->repoRoot . '/' . $this->config->worktreeIncludeFile
            : $this->config->worktreeIncludeFile;

        if (!file_exists($includeFile)) {
            return;
        }

        $lines = file($includeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and negation patterns
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
                continue;
            }

            $patterns = $this->resolveNegations($line, $includeFile);

            foreach ($patterns as $pattern) {
                $this->copyGlob($this->repoRoot, $worktreePath, $pattern);
            }
        }
    }

    /**
     * Resolve a pattern that may contain negations into a list of positive-only patterns.
     *
     * Negation patterns (lines starting with !) remove files from the result set.
     * This method expands a line containing ! patterns into individual positive patterns
     * after applying the negations.
     */
    private function resolveNegations(string $line, string $includeFile): array
    {
        $dir = dirname($includeFile);
        $positivePatterns = [];
        $negations = [];
        $parts = preg_split('/\s+/', $line);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '!')) {
                $negations[] = substr($part, 1);
            } else {
                $positivePatterns[] = $part;
            }
        }

        $result = [];
        foreach ($positivePatterns as $pattern) {
            $matched = $this->globAll($dir, $pattern);
            foreach ($matched as $file) {
                $isNegated = false;
                foreach ($negations as $neg) {
                    if ($this->matchesGlob($file, $neg, $dir)) {
                        $isNegated = true;
                        break;
                    }
                }
                if (!$isNegated) {
                    $result[] = $file;
                }
            }
        }

        return $result;
    }

    /**
     * Copy a single file or directory matching a glob pattern from src to dest.
     */
    private function copyGlob(string $srcRoot, string $destRoot, string $pattern): void
    {
        $srcPath = $srcRoot . '/' . $pattern;

        if (is_dir($srcPath)) {
            $destPath = $destRoot . '/' . $pattern;
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
            $this->copyDirectory($srcPath, $destPath);
            return;
        }

        if (is_file($srcPath)) {
            $destPath = $destRoot . '/' . $pattern;
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            copy($srcPath, $destPath);
        }
    }

    /**
     * Recursively copy a directory's contents.
     */
    private function copyDirectory(string $src, string $dest): void
    {
        if (!is_dir($src)) {
            return;
        }

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $entries = @scandir($src);
        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $srcPath = $src . '/' . $entry;
            $destPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }

    /**
     * Get all files matching a glob pattern relative to a base directory.
     *
     * @return array<string> List of relative file paths.
     */
    private function globAll(string $baseDir, string $pattern): array
    {
        if (str_contains($pattern, '**')) {
            return $this->globRecursive($baseDir, $pattern);
        }

        $escaped = addcslashes($pattern, './');
        $escaped = str_replace(['\\?', '\\*'], ['?', '*'], $escaped);

        $fullPattern = $baseDir . '/' . $escaped;
        $matches = glob($fullPattern);

        return $matches === false ? [] : array_map(
            fn(string $m): string => ltrim(substr($m, strlen($baseDir) + 1), '/'),
            $matches,
        );
    }

    /**
     * Recursive glob for ** patterns.
     *
     * @return array<string> List of relative file paths.
     */
    private function globRecursive(string $baseDir, string $pattern): array
    {
        $results = [];
        $prefix = rtrim(substr($pattern, 0, strpos($pattern, '**')), '/');
        $suffix = ltrim(substr($pattern, strpos($pattern, '**') + 2), '/');

        $searchDir = $prefix === '' ? $baseDir : $baseDir . '/' . $prefix;
        if (!is_dir($searchDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($searchDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }

            $relativePath = $file->getPathname();
            if ($prefix !== '') {
                $relativePath = substr($relativePath, strlen($baseDir) + 1);
            }

            if ($suffix !== '' && !$this->matchesGlob($relativePath, $suffix, $baseDir)) {
                continue;
            }

            if (str_starts_with($relativePath, './')) {
                $relativePath = substr($relativePath, 2);
            }

            $results[] = $relativePath;
        }

        return $results;
    }

    /**
     * Match a path against a glob pattern.
     */
    private function matchesGlob(string $path, string $pattern, string $baseDir): bool
    {
        if ($pattern === '*') {
            return !str_contains($path, '/');
        }

        if (str_ends_with($pattern, '/*')) {
            $dir = substr($pattern, 0, -2);
            return str_starts_with($path, $dir . '/') && !str_contains(substr($path, strlen($dir) + 1), '/');
        }

        if (str_ends_with($pattern, '/**')) {
            $prefix = substr($pattern, 0, -3);
            return str_starts_with($path, $prefix . '/');
        }

        return fnmatch($pattern, $path, FNM_PATHNAME);
    }

    // -------------------------------------------------------------------------
    // Cleanup policy
    // -------------------------------------------------------------------------

    /**
     * Determine whether a worktree has uncommitted changes.
     *
     * Uses `git status --porcelain` to detect any uncommitted modifications,
     * untracked files, or staged changes in the worktree.
     *
     * @param string $worktreePath Absolute path to the worktree.
     * @return bool True if the worktree has uncommitted changes, false otherwise.
     */
    public function worktreeHasUncommittedDiff(string $worktreePath): bool
    {
        if (!is_dir($worktreePath)) {
            return false;
        }

        $escapedPath = escapeshellarg($worktreePath);
        $cmd = "git -C {$escapedPath} status --porcelain 2>&1";

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = trim(implode("\n", $output));

        return $outputStr !== '';
    }

    /**
     * Remove stale worktrees older than the configured cleanup period.
     *
     * Implements the two-tier cleanup policy:
     *
     * 1. **Named worktrees** (created for explicit human sessions) are NEVER
     *    removed automatically — they are always preserved regardless of age.
     *
     * 2. **Unnamed (ephemeral) worktrees** follow a conditional auto-cleanup:
     *    - If the worktree is clean (no uncommitted diff), it is automatically removed.
     *    - If the worktree is dirty (has uncommitted changes), it is left alone
     *      so no work is lost.
     *
     * This method performs a periodic sweep and is typically called at startup
     * or by a background timer. It does not affect worktrees that are still active.
     *
     * @param int $days Worktrees older than this many days are considered stale. Defaults to config value.
     * @return int The number of worktrees actually removed.
     */
    public function cleanupStaleWorktrees(int $days = 0): int
    {
        if ($days <= 0) {
            $days = $this->config->worktreeCleanupPeriodDays;
        }

        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days");
        $removed = 0;

        foreach ($this->registry as $agentId => $meta) {
            $worktreePath = $this->expandedBasePath . '/' . $agentId;

            if (!is_dir($worktreePath)) {
                continue;
            }

            // Named worktrees are always preserved regardless of age
            if (($meta['named'] ?? false) === true) {
                continue;
            }

            $createdAt = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $meta['createdAt']);
            if ($createdAt === false || $createdAt > $cutoff) {
                continue;
            }

            // Unnamed + old + dirty = leave alone so work is not lost
            if ($this->worktreeHasUncommittedDiff($worktreePath)) {
                continue;
            }

            // Unnamed + old + clean = safe to remove
            try {
                $this->removeWorktree($agentId);
                $removed++;
            } catch (\Throwable) {
                // Skip worktrees that fail to remove (e.g., locked files)
            }
        }

        return $removed;
    }

    /**
     * Minimum time between two real cleanupStaleWorktrees() sweeps triggered
     * via sweepIfDue(), in seconds.
     */
    private const SWEEP_INTERVAL_SECONDS = 3600;

    /**
     * Run a cleanup sweep, but only if the last one is more than
     * SWEEP_INTERVAL_SECONDS old (or there has never been one).
     *
     * cleanupStaleWorktrees() previously had no real caller anywhere in the
     * codebase — this gives it one cheap-to-call entry point (a single
     * filemtime-style check on most calls) so Team::claimTask() can invoke it
     * on every claim without doing a full sweep every time.
     *
     * @return int The number of worktrees removed, or 0 if the sweep was skipped.
     */
    public function sweepIfDue(): int
    {
        $marker = $this->expandedBasePath . '/.last-sweep';
        $now = time();

        if (file_exists($marker)) {
            $lastSweep = (int) file_get_contents($marker);
            if (($now - $lastSweep) < self::SWEEP_INTERVAL_SECONDS) {
                return 0;
            }
        }

        if (!is_dir($this->expandedBasePath)) {
            mkdir($this->expandedBasePath, 0755, true);
        }
        file_put_contents($marker, (string) $now, LOCK_EX);

        return $this->cleanupStaleWorktrees();
    }

    /**
     * Mark a worktree as "named" (created for an explicit human session).
     *
     * Named worktrees are never removed automatically by cleanupStaleWorktrees().
     * This is called by P3.S3 after createWorktree() returns to set the named flag.
     *
     * @param string $agentId The agent whose worktree should be marked.
     * @throws \InvalidArgumentException When agentId is empty or contains path traversal.
     * @throws \RuntimeException When the worktree does not exist.
     */
    public function markWorktreeNamed(string $agentId): void
    {
        if ($agentId === '') {
            throw new \InvalidArgumentException('Agent ID must not be empty.');
        }

        if (str_contains($agentId, '..') || str_contains($agentId, '/') || str_contains($agentId, '\\')) {
            throw new \InvalidArgumentException(
                'Agent ID must not contain path traversal sequences, slashes, or backslashes.',
            );
        }

        if (!$this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" does not exist.', $agentId),
            );
        }

        $this->registry[$agentId]['named'] = true;
        $this->saveRegistry();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a worktree is registered for the given agent.
     */
    private function worktreeExists(string $agentId): bool
    {
        return isset($this->registry[$agentId]);
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }

        $items = array_diff($entries, ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }

    /**
     * Expand ~ to the server's HOME directory and validate the path.
     *
     * @param string $path A path that may begin with ~ (will be expanded).
     * @return string The expanded, absolute path.
     */
    private function expandPath(string $path): string
    {
        $envOverride = self::worktreesDirOverride();
        if ($envOverride !== null) {
            $path = $envOverride;
        }

        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException(
                sprintf('Path must not contain "..": %s', $path),
            );
        }

        if (str_starts_with($path, '~/')) {
            $home = HomeDirectory::path();
            $path = $home . '/' . substr($path, 2);
        }

        return $path;
    }

    /**
     * The base-path override from the environment, or null when unset/empty.
     *
     * The canonical name is SUGARCRUSH_WORKTREES_DIR. SUGAR_CRUSH_WORKTREES_DIR
     * is the original spelling and one of only two app variables that ever
     * carried the underscore after SUGAR — every other SUGARCRUSH_* variable
     * this app reads does not (crush_code.md Phase 4 item 4). It keeps working
     * for one release so an existing export does not silently relocate every
     * agent worktree back to the config default the day the rename lands; the
     * canonical name wins when both are set, which is the ordering that lets
     * an operator add the new export to a shared profile before removing the
     * old one.
     *
     * No deprecation warning is emitted: the only caller runs inside the
     * constructor, and the interactive TUI owns the terminal by then — a
     * stray STDERR line there corrupts the frame rather than informing anyone.
     */
    private static function worktreesDirOverride(): ?string
    {
        foreach (['SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Load the worktree registry from disk.
     */
    private function loadRegistry(): void
    {
        if (!file_exists($this->registryPath)) {
            $this->registry = [];
            return;
        }

        $fp = fopen($this->registryPath, 'r');
        if ($fp === false) {
            $this->registry = [];
            return;
        }

        flock($fp, LOCK_SH);

        // Read until EOF to avoid TOCTOU race between filesize() and fread()
        $content = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || $content === '') {
            $this->registry = [];
            return;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->registry = is_array($data) ? $data : [];
        } catch (\JsonException) {
            $this->registry = [];
        }
    }

    /**
     * Persist the current worktree registry to disk.
     *
     * @throws \RuntimeException When the file cannot be written.
     */
    private function saveRegistry(): void
    {
        $dir = dirname($this->registryPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = file_put_contents(
            $this->registryPath,
            json_encode($this->registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        if ($bytes === false) {
            throw new \RuntimeException(
                sprintf('Failed to write registry to "%s".', $this->registryPath),
            );
        }
    }
}
