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
    /** @var array<string, array{branch: string, createdAt: string}> */
    private array $registry = [];

    private readonly string $expandedBasePath;
    private readonly string $registryPath;

    public function __construct(
        private readonly WorktreeConfig $config = new WorktreeConfig(),
        private readonly string $repoRoot = '',
    ) {
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

        if (str_contains($agentId, '..') || str_contains($agentId, '/')) {
            throw new \InvalidArgumentException(
                'Agent ID must not contain path traversal sequences or slashes.',
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

        // Register the worktree
        $this->registry[$agentId] = [
            'branch' => $branch,
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
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
     * @return array<string, array{branch: string, createdAt: string}>
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

        $content = fread($fp, filesize($this->registryPath) ?: 0);
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
