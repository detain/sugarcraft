<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

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
    /** @var array<string, array{branch: string, createdAt: string, named: bool}> */
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
     * When a .worktreeinclude file is present in the repo root (per config),
     * any normally-ignored files it lists are copied into the new worktree
     * so agents have the same local configuration as the main checkout.
     *
     * @param string $agentId Unique identifier for the agent (used as directory name).
     * @param string|null $branch Optional branch name; defaults to agent-{agentId}-{timestamp}.
     * @param bool $named Whether this worktree is for a named task/session (vs an ephemeral sub-agent run).
     * @return string The absolute path to the newly created worktree.
     * @throws \InvalidArgumentException When agentId is empty or contains path traversal.
     * @throws \RuntimeException When git worktree creation fails.
     */
    public function createWorktree(string $agentId, ?string $branch = null, bool $named = false): string
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
        $output = trim(shell_exec($cmd) ?? '');

        // Check if git worktree command succeeded by verifying the directory exists
        if (!is_dir($worktreePath)) {
            throw new \RuntimeException(
                sprintf('Failed to create worktree for agent "%s": %s', $agentId, $output),
            );
        }

        // Copy .worktreeinclude files into the new worktree
        $this->resolveWorktreeInclude($worktreePath);

        // Register the worktree
        $this->registry[$agentId] = [
            'branch' => $branch,
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'named' => $named,
        ];
        $this->saveRegistry();

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

        // Suppress output - git worktree remove prints to stderr on success in some cases
        @shell_exec($cmd);

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
     * Returns the registry of known agent worktrees with their branch,
     * creation timestamp, and named flag.
     *
     * @return array<string, array{branch: string, createdAt: string, named: bool}>
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
    // Cleanup policy
    // -------------------------------------------------------------------------

    /**
     * Remove stale worktrees older than the specified number of days.
     *
     * Applies the two-tier cleanup policy:
     * - Named worktrees are skipped (they represent human-initiated sessions
     *   that should be preserved until explicitly removed).
     * - Unnamed/ephemeral worktrees that are older than $days AND have no
     *   uncommitted changes are removed; those with uncommitted diffs are
     *   left alone so nothing gets lost.
     *
     * @param int $days Worktrees older than this many days are considered stale.
     * @return int Number of worktrees removed.
     */
    public function cleanupStaleWorktrees(int $days): int
    {
        if ($days < 0) {
            $days = 0;
        }

        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days");
        $removed = 0;

        foreach ($this->registry as $agentId => $meta) {
            // Named worktrees are always skipped — they get explicit removal
            if (($meta['named'] ?? false) === true) {
                continue;
            }

            $createdAt = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $meta['createdAt']);
            if ($createdAt === false || $createdAt > $cutoff) {
                continue;
            }

            $worktreePath = $this->expandedBasePath . '/' . $agentId;

            // Skip if directory no longer exists
            if (!is_dir($worktreePath)) {
                unset($this->registry[$agentId]);
                $this->saveRegistry();
                continue;
            }

            // Skip if there are uncommitted changes — leave dirty worktrees alone
            if ($this->worktreeHasUncommittedDiff($worktreePath)) {
                continue;
            }

            // Safe to remove: old, unnamed, and clean
            $this->removeWorktree($agentId);
            $removed++;
        }

        return $removed;
    }

    /**
     * Check whether a worktree has any changed files (tracked or untracked).
     *
     * Uses `git status --porcelain` to detect staged, unstaged, and untracked
     * changes. Returns true if any such changes exist.
     *
     * Untracked files (e.g., those copied by .worktreeinclude) ARE included
     * in the dirty check — they represent the worktree's current state and
     * the worktree is preserved when dirty so nothing is lost.
     */
    private function worktreeHasUncommittedDiff(string $worktreePath): bool
    {
        $escapedPath = escapeshellarg($worktreePath);
        // git status --porcelain returns exit code 0 always; changes are
        // signaled by non-empty output (tracked + untracked changes).
        // Suppress stderr to handle edge cases like missing index gracefully.
        $cmd = "git -C {$escapedPath} status --porcelain 2>/dev/null";
        $output = [];
        // Errors are suppressed via 2>/dev/null in the command itself above,
        // letting PHP-level errors surface while silencing only git's stderr.
        exec($cmd, $output);

        // Any output means there are staged, unstaged, or untracked changes
        return $output !== [];
    }

    /**
     * Copy normally-ignored files listed in .worktreeinclude into a new worktree.
     *
     * The .worktreeinclude file uses glob syntax (same as .gitignore). Lines
     * beginning with ! are treated as negation patterns. Blank lines and lines
     * starting with # are ignored.
     *
     * This allows projects to include .env, vendor/, per-lib auth tokens, and
     * other gitignored files that agents need to function correctly.
     *
     * @param string $worktreePath Absolute path to the newly created worktree.
     */
    private function resolveWorktreeInclude(string $worktreePath): void
    {
        $includeFile = $this->config->worktreeIncludeFile;
        if ($includeFile === '') {
            return;
        }

        if ($this->repoRoot === '') {
            return;
        }

        $includePath = $this->repoRoot . '/' . $includeFile;
        if (!file_exists($includePath)) {
            return;
        }

        $lines = file($includePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        // First pass: collect exclusions (patterns starting with !)
        $exclusions = [];
        $patterns = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_starts_with($trimmed, '!')) {
                $exclusions[] = substr($trimmed, 1);
            } else {
                $patterns[] = $trimmed;
            }
        }

        // Resolve all glob patterns from repo root
        foreach ($patterns as $pattern) {
            $matches = glob($this->repoRoot . '/' . $pattern);
            if ($matches === false || $matches === []) {
                continue;
            }

            foreach ($matches as $matchedPath) {
                // Check if this specific matched file is negated by any exclusion
                $isExcluded = false;
                foreach ($exclusions as $exclusion) {
                    // Test the actual file path against the exclusion pattern
                    if (fnmatch($exclusion, $matchedPath)) {
                        $isExcluded = true;
                        break;
                    }
                }
                if ($isExcluded) {
                    continue;
                }

                $relativePath = substr($matchedPath, strlen($this->repoRoot) + 1);
                $targetPath = $worktreePath . '/' . $relativePath;

                // Create parent directories if needed
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }

                if (is_dir($matchedPath)) {
                    $this->copyDirectory($matchedPath, $targetPath);
                } else {
                    copy($matchedPath, $targetPath);
                }
            }
        }
    }

    /**
     * Recursively copy a directory's contents to a destination.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $items = array_diff(@scandir($source) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $sourcePath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
            }
        }
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
        // Respect SUGAR_CRUSH_WORKTREES_DIR environment variable override
        $envOverride = getenv('SUGAR_CRUSH_WORKTREES_DIR');
        if ($envOverride !== false && $envOverride !== '') {
            $path = $envOverride;
        }

        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException(
                sprintf('Path must not contain "..": %s', $path),
            );
        }

        if (str_starts_with($path, '~/')) {
            $home = $_SERVER['HOME'] ?? '/tmp';
            $path = $home . '/' . substr($path, 2);
        }

        return $path;
    }

    /**
     * Load the worktree registry from disk.
     *
     * @return array<string, array{branch: string, createdAt: string, named: bool}>
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

        // Backward-compat: normalize entries that lack the 'named' field
        foreach ($this->registry as $agentId => $meta) {
            if (!isset($meta['named'])) {
                $this->registry[$agentId]['named'] = false;
            }
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
