<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use RuntimeException;

/**
 * Git command handlers for the Git MCP server.
 *
 * Handles git_context (repository snapshot, config, aliases) and
 * git_history (log, show, blame, reflog) operation groups.
 *
 * @see GitOperationResult
 */
final readonly class GitCommandHandlers
{
    public function __construct(
        private ?string $cwd = null,
    ) {}

    // =========================================================================
    // git_context — Repository snapshot, config, aliases
    // =========================================================================

    /**
     * Get repository status.
     *
     * @see GitOperationResult
     */
    public function gitStatus(string $path = '.'): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'status', '--porcelain'],
            operation: 'git_status',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * Get repository snapshot: branch, remote, tags, and staged changes.
     *
     * @see GitOperationResult
     */
    public function gitSnapshot(string $path = '.'): GitOperationResult
    {
        $start = microtime(true);

        $branch = $this->gitBranchCurrent($path);
        $remote = $this->gitRemote($path);
        $tags = $this->gitTagList($path);
        $staged = $this->gitStaged($path);

        $output = [
            'branch' => $branch->getOutputOrNull(),
            'remote' => $remote->getOutputOrNull(),
            'tags' => $tags->getOutputOrNull(),
            'hasStagedChanges' => !empty($staged->getOutputOrNull()),
        ];

        return GitOperationResult::success(
            output: $output,
            operation: 'git_snapshot',
            group: 'git_context',
            executionTimeMs: $this->elapsed($start),
        );
    }

    /**
     * List git config entries.
     *
     * @see GitOperationResult
     */
    public function gitConfigList(string $path = '.'): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'config', '--list'],
            operation: 'git_config_list',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * Get a specific git config value.
     *
     * @see GitOperationResult
     */
    public function gitConfigGet(string $key, string $path = '.'): GitOperationResult
    {
        if ($key === '') {
            return GitOperationResult::failure(
                error: 'Config key cannot be empty',
                operation: 'git_config_get',
                group: 'git_context',
            );
        }

        return $this->execGit(
            command: ['git', 'config', '--get', $key],
            operation: 'git_config_get',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * List git aliases.
     *
     * @see GitOperationResult
     */
    public function gitAliasList(string $path = '.'): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'config', '--get-regexp', '^alias\\.'],
            operation: 'git_alias_list',
            group: 'git_context',
            cwd: $path,
        );
    }

    // =========================================================================
    // git_history — Log, show, blame, reflog
    // =========================================================================

    /**
     * Get git log entries.
     *
     * @param int $limit Maximum number of entries (default 50)
     * @param string $path Repository path
     * @see GitOperationResult
     */
    public function gitLog(int $limit = 50, string $path = '.'): GitOperationResult
    {
        if ($limit < 1) {
            $limit = 1;
        }

        $format = '%H|%ae|%an|%aI|%s';
        $command = [
            'git', 'log',
            "--max-count={$limit}",
            "--format={$format}",
        ];

        $result = $this->execGit(
            command: $command,
            operation: 'git_log',
            group: 'git_history',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $lines = is_string($result->output) ? ($result->output !== '' ? explode("\n", $result->output) : []) : [];
        $commits = array_map(function (string $line): array {
            $parts = explode('|', $line, 5);
            return [
                'hash' => $parts[0] ?? '',
                'authorEmail' => $parts[1] ?? '',
                'authorName' => $parts[2] ?? '',
                'date' => $parts[3] ?? '',
                'message' => $parts[4] ?? '',
            ];
        }, $lines);

        return GitOperationResult::success(
            output: $commits,
            operation: 'git_log',
            group: 'git_history',
            metadata: ['count' => count($commits)],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Show commit details.
     *
     * @param string $ref Commit hash, branch, or tag reference
     * @param string $path Repository path
     * @see GitOperationResult
     */
    public function gitShow(string $ref, string $path = '.'): GitOperationResult
    {
        if ($ref === '') {
            return GitOperationResult::failure(
                error: 'Ref cannot be empty',
                operation: 'git_show',
                group: 'git_history',
            );
        }

        $format = '%H|%ae|%an|%aI|%ci|%s|%b';
        $result = $this->execGit(
            command: ['git', 'show', '--format=' . $format, '--no-patch', $ref],
            operation: 'git_show',
            group: 'git_history',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $parts = explode('|', $result->output, 7);
        $output = [
            'hash' => $parts[0] ?? '',
            'authorEmail' => $parts[1] ?? '',
            'authorName' => $parts[2] ?? '',
            'authorDate' => $parts[3] ?? '',
            'commitDate' => $parts[4] ?? '',
            'subject' => $parts[5] ?? '',
            'body' => $parts[6] ?? '',
        ];

        return GitOperationResult::success(
            output: $output,
            operation: 'git_show',
            group: 'git_history',
            metadata: ['ref' => $ref],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Get git blame information for a file.
     *
     * @param string $filePath Relative path within the repository
     * @param string $path Repository path
     * @see GitOperationResult
     */
    public function gitBlame(string $filePath, string $path = '.'): GitOperationResult
    {
        if ($filePath === '') {
            return GitOperationResult::failure(
                error: 'File path cannot be empty',
                operation: 'git_blame',
                group: 'git_history',
            );
        }

        $command = ['git', 'blame', '--line-porcelain', '--', $filePath];
        $result = $this->execGit(
            command: $command,
            operation: 'git_blame',
            group: 'git_history',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $lines = is_string($result->output) ? ($result->output !== '' ? explode("\n", $result->output) : []) : [];
        $blame = [];
        $currentCommit = null;
        $currentLine = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, $currentCommit ?? '')) {
                // Continuation of previous blame entry
                if ($currentLine !== null) {
                    $currentLine['content'] = substr($line, 1);
                }
            } elseif (str_starts_with($line, 'commit ')) {
                $currentCommit = substr($line, 7);
                $currentLine = [
                    'commit' => $currentCommit,
                    'author' => '',
                    'authorMail' => '',
                    'authorTime' => '',
                    'summary' => '',
                    'content' => '',
                ];
                $blame[] = &$currentLine;
            } elseif (str_starts_with($line, 'author ')) {
                if ($currentLine !== null) {
                    $currentLine['author'] = substr($line, 7);
                }
            } elseif (str_starts_with($line, 'author-mail ')) {
                if ($currentLine !== null) {
                    $currentLine['authorMail'] = substr($line, 12);
                }
            } elseif (str_starts_with($line, 'author-time ')) {
                if ($currentLine !== null) {
                    $currentLine['authorTime'] = date('c', (int) substr($line, 12));
                }
            } elseif (str_starts_with($line, 'summary ')) {
                if ($currentLine !== null) {
                    $currentLine['summary'] = substr($line, 8);
                }
            } elseif (str_starts_with($line, "\t")) {
                if ($currentLine !== null) {
                    $currentLine['content'] = substr($line, 1);
                }
            }
        }

        return GitOperationResult::success(
            output: $blame,
            operation: 'git_blame',
            group: 'git_history',
            metadata: ['file' => $filePath, 'lines' => count($blame)],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Get git reflog entries.
     *
     * @param int $limit Maximum number of entries (default 50)
     * @param string $path Repository path
     * @see GitOperationResult
     */
    public function gitReflog(int $limit = 50, string $path = '.'): GitOperationResult
    {
        if ($limit < 1) {
            $limit = 1;
        }

        $format = '%H|%gd|%gs|%aI';
        $result = $this->execGit(
            command: ['git', 'reflog', "--format={$format}", "--max-count={$limit}"],
            operation: 'git_reflog',
            group: 'git_history',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $lines = is_string($result->output) ? ($result->output !== '' ? explode("\n", $result->output) : []) : [];
        $reflog = array_map(function (string $line) use ($path): array {
            $parts = explode('|', $line, 4);
            return [
                'commit' => $parts[0] ?? '',
                'reflogName' => $parts[1] ?? '',
                'action' => $parts[2] ?? '',
                'timestamp' => $parts[3] ?? '',
            ];
        }, $lines);

        return GitOperationResult::success(
            output: $reflog,
            operation: 'git_reflog',
            group: 'git_history',
            metadata: ['count' => count($reflog)],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    // =========================================================================
    // Helper methods (private, for internal use)
    // =========================================================================

    /**
     * Execute a git command and return a GitOperationResult.
     *
     * @param array<string> $command Git command as array of strings
     * @param string $operation Operation name
     * @param string $group Operation group
     * @param string|null $cwd Working directory (defaults to constructor's cwd)
     * @return GitOperationResult
     */
    private function execGit(
        array $command,
        string $operation,
        string $group,
        ?string $cwd = null,
    ): GitOperationResult {
        $start = microtime(true);
        $workDir = $cwd ?? $this->cwd ?? getcwd();

        if ($workDir === false || !is_dir($workDir)) {
            return GitOperationResult::failure(
                error: "Directory does not exist: {$workDir}",
                operation: $operation,
                group: $group,
                executionTimeMs: $this->elapsed($start),
            );
        }

        $escapedCommand = array_map(
            fn(string $arg): string => escapeshellarg($arg),
            $command,
        );
        // Command array already contains 'git' as first element, so join directly
        $commandLine = implode(' ', $escapedCommand);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Explicitly set PATH so git can be found in proc_open context
        $env = ['PATH' => '/usr/bin:' . getenv('PATH')];

        $process = @proc_open(
            $commandLine,
            $descriptorSpec,
            $pipes,
            $workDir,
            $env,
        );

        if (!is_resource($process)) {
            return GitOperationResult::failure(
                error: "Failed to execute git command",
                operation: $operation,
                group: $group,
                executionTimeMs: $this->elapsed($start),
            );
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errorMessage = trim($stderr) ?: "Git command failed with exit code {$exitCode}";
            return GitOperationResult::failure(
                error: $errorMessage,
                operation: $operation,
                group: $group,
                executionTimeMs: $this->elapsed($start),
            );
        }

        return GitOperationResult::success(
            output: $stdout,
            operation: $operation,
            group: $group,
            executionTimeMs: $this->elapsed($start),
        );
    }

    /**
     * Get current branch name.
     */
    private function gitBranchCurrent(string $path): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            operation: 'git_branch_current',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * Get git remote information.
     */
    private function gitRemote(string $path): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'remote', '-v'],
            operation: 'git_remote',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * List git tags.
     */
    private function gitTagList(string $path): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'tag', '--list'],
            operation: 'git_tag_list',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * Get staged changes summary.
     */
    private function gitStaged(string $path): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'diff', '--cached', '--name-only'],
            operation: 'git_staged',
            group: 'git_context',
            cwd: $path,
        );
    }

    /**
     * Calculate elapsed time in milliseconds.
     */
    private function elapsed(float $start): float
    {
        return (microtime(true) - $start) * 1000;
    }
}
