<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Git command handlers for the Git MCP server.
 *
 * Handles git_context (repository snapshot, config, aliases),
 * git_history (log, show, blame, reflog), git_commits (add, commit, amend,
 * revert, reset), and git_branches (list, create, delete, checkout).
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
    public function gitStatus(?string $path = null): GitOperationResult
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
    public function gitSnapshot(?string $path = null): GitOperationResult
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
     * Get git config entries.
     *
     * @see GitOperationResult
     */
    public function gitConfigList(?string $path = null): GitOperationResult
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
    public function gitConfigGet(string $key, ?string $path = null): GitOperationResult
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
    public function gitAliasList(?string $path = null): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'config', '--get-regexp', '^alias\.'],
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
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitLog(int $limit = 50, ?string $path = null): GitOperationResult
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

        // Empty-repo: `git log` exits with code 1 and empty output on a zero-commit repo.
        // But exclude "Not a git repository" which means it's a non-git directory (should fail).
        if ($result->isFailure() && !is_string($result->output) && !str_contains($result->error ?? '', 'Not a git repository')) {
            return GitOperationResult::success(
                output: [],
                operation: 'git_log',
                group: 'git_history',
                metadata: ['count' => 0],
                executionTimeMs: $result->executionTimeMs,
            );
        }

        if ($result->isFailure()) {
            return $result;
        }

        $lines = is_string($result->output) && $result->output !== '' ? array_filter(explode("\n", $result->output)) : [];
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
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitShow(string $ref, ?string $path = null): GitOperationResult
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

        $parts = is_string($result->output) ? explode('|', $result->output, 7) : [''];
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
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitBlame(string $filePath, ?string $path = null): GitOperationResult
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
            if ($currentCommit !== null && str_starts_with($line, $currentCommit)) {
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
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitReflog(int $limit = 50, ?string $path = null): GitOperationResult
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

        // Empty-repo: `git reflog` exits with code 1 and empty output on a zero-commit repo.
        // But exclude "Not a git repository" which means it's a non-git directory (should fail).
        if ($result->isFailure() && !is_string($result->output) && !str_contains($result->error ?? '', 'Not a git repository')) {
            return GitOperationResult::success(
                output: [],
                operation: 'git_reflog',
                group: 'git_history',
                metadata: ['count' => 0],
                executionTimeMs: $result->executionTimeMs,
            );
        }

        if ($result->isFailure()) {
            return $result;
        }

        $lines = is_string($result->output) && $result->output !== '' ? array_filter(explode("\n", $result->output)) : [];
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
    // git_commits — Add, commit, amend, revert, reset
    // =========================================================================

    /**
     * Stage files for commit.
     *
     * @param array<string> $paths Files to stage (empty array stages all)
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitAdd(array $paths = [], ?string $path = null): GitOperationResult
    {
        if ($paths === []) {
            // Stage all files: git add .
            return $this->execGit(
                command: ['git', 'add', '.'],
                operation: 'git_add',
                group: 'git_commits',
                cwd: $path,
            );
        }

        // Stage specific files
        $command = array_merge(['git', 'add'], $paths);
        return $this->execGit(
            command: $command,
            operation: 'git_add',
            group: 'git_commits',
            cwd: $path,
        );
    }

    /**
     * Create a commit with a message.
     *
     * @param string $message Commit message
     * @param bool $all Stage all modified files before committing
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitCommit(string $message, bool $all = false, ?string $path = null): GitOperationResult
    {
        if ($message === '') {
            return GitOperationResult::failure(
                error: 'Commit message cannot be empty',
                operation: 'git_commit',
                group: 'git_commits',
            );
        }

        $command = ['git', 'commit'];
        if ($all) {
            $command[] = '--all';
        }
        $command[] = '-m';
        $command[] = $message;

        return $this->execGit(
            command: $command,
            operation: 'git_commit',
            group: 'git_commits',
            cwd: $path,
        );
    }

    /**
     * Amend the last commit with staged changes (no message change).
     *
     * @param bool $all Stage all modified files before amending
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitAmend(bool $all = false, ?string $path = null): GitOperationResult
    {
        $command = ['git', 'commit', '--amend', '--no-edit'];
        if ($all) {
            $command[] = '--all';
        }

        return $this->execGit(
            command: $command,
            operation: 'git_amend',
            group: 'git_commits',
            cwd: $path,
        );
    }

    /**
     * Create a revert commit for a given commit.
     *
     * @param string $commit Commit hash or ref to revert
     * @param bool $noCommit Create the revert but do not commit
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitRevert(string $commit, bool $noCommit = false, ?string $path = null): GitOperationResult
    {
        if ($commit === '') {
            return GitOperationResult::failure(
                error: 'Commit cannot be empty',
                operation: 'git_revert',
                group: 'git_commits',
            );
        }

        $command = ['git', 'revert', $commit];
        if ($noCommit) {
            $command[] = '--no-commit';
        }

        return $this->execGit(
            command: $command,
            operation: 'git_revert',
            group: 'git_commits',
            cwd: $path,
        );
    }

    /**
     * Reset the repository to a given commit.
     *
     * @param string $commit Commit hash or ref to reset to
     * @param string $mode Reset mode: 'soft', 'mixed', or 'hard'
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitReset(string $commit, string $mode = 'mixed', ?string $path = null): GitOperationResult
    {
        if ($commit === '') {
            return GitOperationResult::failure(
                error: 'Commit cannot be empty',
                operation: 'git_reset',
                group: 'git_commits',
            );
        }

        $validModes = ['soft', 'mixed', 'hard'];
        if (!in_array($mode, $validModes, true)) {
            return GitOperationResult::failure(
                error: "Invalid reset mode '{$mode}'. Must be one of: soft, mixed, hard",
                operation: 'git_reset',
                group: 'git_commits',
            );
        }

        return $this->execGit(
            command: ['git', 'reset', "--{$mode}", $commit],
            operation: 'git_reset',
            group: 'git_commits',
            cwd: $path,
        );
    }

    // =========================================================================
    // git_branches — List, create, delete, checkout
    // =========================================================================

    /**
     * List branches.
     *
     * @param bool $all Show remote-tracking and local branches
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitBranchList(bool $all = false, ?string $path = null): GitOperationResult
    {
        $command = ['git', 'branch'];
        if ($all) {
            $command[] = '-a';
        }

        $result = $this->execGit(
            command: $command,
            operation: 'git_branch_list',
            group: 'git_branches',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $lines = is_string($result->output) && $result->output !== '' ? explode("\n", $result->output) : [];
        $branches = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Remote branches are prefixed with "remotes/", local with "* " for current
            $isCurrent = str_starts_with($line, '* ');
            $name = $isCurrent ? substr($line, 2) : $line;
            $branches[] = [
                'name' => $name,
                'current' => $isCurrent,
            ];
        }

        return GitOperationResult::success(
            output: $branches,
            operation: 'git_branch_list',
            group: 'git_branches',
            metadata: ['count' => count($branches)],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Create a new branch.
     *
     * @param string $name Branch name
     * @param bool $checkout Switch to the new branch after creation
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitBranchCreate(string $name, bool $checkout = false, ?string $path = null): GitOperationResult
    {
        if ($name === '') {
            return GitOperationResult::failure(
                error: 'Branch name cannot be empty',
                operation: 'git_branch_create',
                group: 'git_branches',
            );
        }

        // Refuse dangerously named branches
        if ($name === 'HEAD' || $name === 'MASTER' || $name === 'MAIN') {
            return GitOperationResult::failure(
                error: "Cannot create branch named '{$name}'",
                operation: 'git_branch_create',
                group: 'git_branches',
            );
        }

        if ($checkout) {
            $result = $this->execGit(
                command: ['git', 'checkout', '-b', $name],
                operation: 'git_branch_create',
                group: 'git_branches',
                cwd: $path,
            );
        } else {
            $result = $this->execGit(
                command: ['git', 'branch', $name],
                operation: 'git_branch_create',
                group: 'git_branches',
                cwd: $path,
            );
        }

        if ($result->isFailure()) {
            return $result;
        }

        return GitOperationResult::success(
            output: ['name' => $name, 'checkedOut' => $checkout],
            operation: 'git_branch_create',
            group: 'git_branches',
            metadata: ['name' => $name, 'checkedOut' => $checkout],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Delete a branch.
     *
     * @param string $name Branch name
     * @param bool $force Force delete even if branch has unmerged changes
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitBranchDelete(string $name, bool $force = false, ?string $path = null): GitOperationResult
    {
        if ($name === '') {
            return GitOperationResult::failure(
                error: 'Branch name cannot be empty',
                operation: 'git_branch_delete',
                group: 'git_branches',
            );
        }

        $command = ['git', 'branch'];
        if ($force) {
            $command[] = '-D';
        } else {
            $command[] = '-d';
        }
        $command[] = $name;

        return $this->execGit(
            command: $command,
            operation: 'git_branch_delete',
            group: 'git_branches',
            cwd: $path,
        );
    }

    /**
     * Checkout a branch or file.
     *
     * @param string $target Branch name, commit hash, or file path
     * @param bool $createBranch Create and checkout a new branch if true
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitBranchCheckout(string $target, bool $createBranch = false, ?string $path = null): GitOperationResult
    {
        if ($target === '') {
            return GitOperationResult::failure(
                error: 'Target cannot be empty',
                operation: 'git_branch_checkout',
                group: 'git_branches',
            );
        }

        $command = ['git', 'checkout'];
        if ($createBranch) {
            $command[] = '-b';
        }
        $command[] = $target;

        return $this->execGit(
            command: $command,
            operation: 'git_branch_checkout',
            group: 'git_branches',
            cwd: $path,
        );
    }

    // =========================================================================
    // git_worktree — Add, list, remove worktrees
    // =========================================================================

    /**
     * Add a worktree.
     *
     * @param string $worktreePath Path where the worktree will be created
     * @param string|null $branch Branch to check out in the worktree (default: current branch)
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitWorktreeAdd(string $worktreePath, ?string $branch = null, ?string $path = null): GitOperationResult
    {
        if ($worktreePath === '') {
            return GitOperationResult::failure(
                error: 'Worktree path cannot be empty',
                operation: 'git_worktree_add',
                group: 'git_worktree',
            );
        }

        $command = ['git', 'worktree', 'add'];
        if ($branch !== null) {
            $command[] = '-b';
            $command[] = $branch;
        }
        $command[] = $worktreePath;

        return $this->execGit(
            command: $command,
            operation: 'git_worktree_add',
            group: 'git_worktree',
            cwd: $path,
        );
    }

    /**
     * List worktrees.
     *
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitWorktreeList(?string $path = null): GitOperationResult
    {
        $result = $this->execGit(
            command: ['git', 'worktree', 'list', '--porcelain'],
            operation: 'git_worktree_list',
            group: 'git_worktree',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $output = is_string($result->output) ? $result->output : '';
        $worktrees = [];
        $entries = explode("\n", trim($output));
        $current = null;

        foreach ($entries as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $current = ['path' => substr($line, 9), 'branch' => null, 'HEAD' => null];
            } elseif ($current !== null && str_starts_with($line, 'branch ')) {
                $current['branch'] = substr($line, 8);
            } elseif ($current !== null && str_starts_with($line, 'HEAD ')) {
                $current['HEAD'] = substr($line, 5);
            } elseif ($current !== null && $line === '' && $current['path'] !== null) {
                $worktrees[] = $current;
                $current = null;
            }
        }
        if ($current !== null && $current['path'] !== null) {
            $worktrees[] = $current;
        }

        return GitOperationResult::success(
            output: $worktrees,
            operation: 'git_worktree_list',
            group: 'git_worktree',
            metadata: ['count' => count($worktrees)],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Remove a worktree.
     *
     * @param string $worktreePath Path to the worktree to remove
     * @param bool $force Force removal (default false)
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitWorktreeRemove(string $worktreePath, bool $force = false, ?string $path = null): GitOperationResult
    {
        if ($worktreePath === '') {
            return GitOperationResult::failure(
                error: 'Worktree path cannot be empty',
                operation: 'git_worktree_remove',
                group: 'git_worktree',
            );
        }

        $command = ['git', 'worktree', 'remove'];
        if ($force) {
            $command[] = '--force';
        }
        $command[] = $worktreePath;

        return $this->execGit(
            command: $command,
            operation: 'git_worktree_remove',
            group: 'git_worktree',
            cwd: $path,
        );
    }

    // =========================================================================
    // git_flow — Git-flow workflow support
    // =========================================================================

    /**
     * Initialize git-flow.
     *
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitFlowInit(?string $path = null): GitOperationResult
    {
        return $this->execGit(
            command: ['git', 'flow', 'init', '-d'],
            operation: 'git_flow_init',
            group: 'git_flow',
            cwd: $path,
        );
    }

    /**
     * Perform a git-flow action on a feature branch.
     *
     * @param string $action Action to perform: start, finish, publish, track, pull, rebase, checkout, diff, log, resurrect, squash
     * @param string|null $name Feature name (required for start/finish/publish/track/pull/rebase/squash)
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitFlowFeature(string $action, ?string $name = null, ?string $path = null): GitOperationResult
    {
        $validActions = ['start', 'finish', 'publish', 'track', 'pull', 'rebase', 'checkout', 'diff', 'log', 'resurrect', 'squash'];
        if (!in_array($action, $validActions, true)) {
            return GitOperationResult::failure(
                error: "Invalid git-flow feature action '{$action}'. Must be one of: " . implode(', ', $validActions),
                operation: 'git_flow_feature',
                group: 'git_flow',
            );
        }

        $needsName = in_array($action, ['start', 'finish', 'publish', 'track', 'pull', 'rebase', 'squash'], true);
        if ($needsName && ($name === null || $name === '')) {
            return GitOperationResult::failure(
                error: 'Feature name is required for this action',
                operation: 'git_flow_feature',
                group: 'git_flow',
            );
        }

        $command = ['git', 'flow', 'feature', $action];
        if ($name !== null && $name !== '') {
            $command[] = $name;
        }

        return $this->execGit(
            command: $command,
            operation: 'git_flow_feature',
            group: 'git_flow',
            cwd: $path,
        );
    }

    /**
     * Perform a git-flow action on a release branch.
     *
     * @param string $action Action to perform: start, finish, publish, track, pull, rebase
     * @param string|null $name Release name or version (required for start/finish/publish/track/pull/rebase)
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitFlowRelease(string $action, ?string $name = null, ?string $path = null): GitOperationResult
    {
        $validActions = ['start', 'finish', 'publish', 'track', 'pull', 'rebase'];
        if (!in_array($action, $validActions, true)) {
            return GitOperationResult::failure(
                error: "Invalid git-flow release action '{$action}'. Must be one of: " . implode(', ', $validActions),
                operation: 'git_flow_release',
                group: 'git_flow',
            );
        }

        $needsName = in_array($action, ['start', 'finish', 'publish', 'track', 'pull', 'rebase'], true);
        if ($needsName && ($name === null || $name === '')) {
            return GitOperationResult::failure(
                error: 'Release name is required for this action',
                operation: 'git_flow_release',
                group: 'git_flow',
            );
        }

        $command = ['git', 'flow', 'release', $action];
        if ($name !== null && $name !== '') {
            $command[] = $name;
        }

        return $this->execGit(
            command: $command,
            operation: 'git_flow_release',
            group: 'git_flow',
            cwd: $path,
        );
    }

    /**
     * Perform a git-flow action on a hotfix branch.
     *
     * @param string $action Action to perform: start, finish, publish, track, pull, rebase
     * @param string|null $name Hotfix name (required for start/finish/publish/track/pull/rebase)
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitFlowHotfix(string $action, ?string $name = null, ?string $path = null): GitOperationResult
    {
        $validActions = ['start', 'finish', 'publish', 'track', 'pull', 'rebase'];
        if (!in_array($action, $validActions, true)) {
            return GitOperationResult::failure(
                error: "Invalid git-flow hotfix action '{$action}'. Must be one of: " . implode(', ', $validActions),
                operation: 'git_flow_hotfix',
                group: 'git_flow',
            );
        }

        $needsName = in_array($action, ['start', 'finish', 'publish', 'track', 'pull', 'rebase'], true);
        if ($needsName && ($name === null || $name === '')) {
            return GitOperationResult::failure(
                error: 'Hotfix name is required for this action',
                operation: 'git_flow_hotfix',
                group: 'git_flow',
            );
        }

        $command = ['git', 'flow', 'hotfix', $action];
        if ($name !== null && $name !== '') {
            $command[] = $name;
        }

        return $this->execGit(
            command: $command,
            operation: 'git_flow_hotfix',
            group: 'git_flow',
            cwd: $path,
        );
    }

    // =========================================================================
    // git_lfs — LFS tracking and migration
    // =========================================================================

    /**
     * Track files with Git LFS.
     *
     * @param string $pattern File pattern to track (e.g., "*.psd", "images/*")
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitLfsTrack(string $pattern, ?string $path = null): GitOperationResult
    {
        if ($pattern === '') {
            return GitOperationResult::failure(
                error: 'Pattern cannot be empty',
                operation: 'git_lfs_track',
                group: 'git_lfs',
            );
        }

        return $this->execGit(
            command: ['git', 'lfs', 'track', $pattern],
            operation: 'git_lfs_track',
            group: 'git_lfs',
            cwd: $path,
        );
    }

    /**
     * Untrack files from Git LFS.
     *
     * @param string $pattern File pattern to untrack
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitLfsUntrack(string $pattern, ?string $path = null): GitOperationResult
    {
        if ($pattern === '') {
            return GitOperationResult::failure(
                error: 'Pattern cannot be empty',
                operation: 'git_lfs_untrack',
                group: 'git_lfs',
            );
        }

        return $this->execGit(
            command: ['git', 'lfs', 'untrack', $pattern],
            operation: 'git_lfs_untrack',
            group: 'git_lfs',
            cwd: $path,
        );
    }

    /**
     * List current LFS locks.
     *
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitLfsLocks(?string $path = null): GitOperationResult
    {
        $result = $this->execGit(
            command: ['git', 'lfs', 'locks'],
            operation: 'git_lfs_locks',
            group: 'git_lfs',
            cwd: $path,
        );

        if ($result->isFailure()) {
            return $result;
        }

        $output = is_string($result->output) ? $result->output : '';
        $lines = $output !== '' ? explode("\n", trim($output)) : [];
        $locks = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            // Format: "ID  Owner  Path  [lock info]"
            $parts = preg_split('/\s+/', $line, 4);
            if (count($parts) >= 3) {
                $locks[] = [
                    'id' => $parts[0] ?? '',
                    'owner' => $parts[1] ?? '',
                    'path' => $parts[2] ?? '',
                ];
            }
        }

        return GitOperationResult::success(
            output: $locks,
            operation: 'git_lfs_locks',
            group: 'git_lfs',
            metadata: ['count' => count($locks)],
            executionTimeMs: $result->executionTimeMs,
        );
    }

    /**
     * Migrate LFS objects (import from git or export to git).
     *
     * @param string $direction Migration direction: "import" or "export"
     * @param string|null $path Repository path
     * @see GitOperationResult
     */
    public function gitLfsMigrate(string $direction, ?string $path = null): GitOperationResult
    {
        $validDirections = ['import', 'export'];
        if (!in_array($direction, $validDirections, true)) {
            return GitOperationResult::failure(
                error: "Invalid LFS migration direction '{$direction}'. Must be one of: import, export",
                operation: 'git_lfs_migrate',
                group: 'git_lfs',
            );
        }

        return $this->execGit(
            command: ['git', 'lfs', 'migrate', $direction],
            operation: 'git_lfs_migrate',
            group: 'git_lfs',
            cwd: $path,
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

        // Guard: verify this is actually a git repository by checking for .git directory
        // git walks up parent directories by default, which would give false positives
        $gitDirPath = $workDir . '/.git';
        if (!is_dir($gitDirPath) && !is_file($gitDirPath)) {
            return GitOperationResult::failure(
                error: "Not a git repository: {$workDir}",
                operation: $operation,
                group: $group,
                executionTimeMs: $this->elapsed($start),
            );
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Explicitly set PATH so git can be found in proc_open context.
        // Also set HOME so git can locate ~/.gitconfig for config operations.
        $env = [
            'PATH' => '/usr/bin:' . getenv('PATH'),
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        // Pass command as array to bypass shell interpretation.
        // Using a string would invoke sh -c which misinterprets % in git format strings.
        $process = @proc_open(
            $command,
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
    private function gitBranchCurrent(?string $path): GitOperationResult
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
    private function gitRemote(?string $path): GitOperationResult
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
    private function gitTagList(?string $path): GitOperationResult
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
    private function gitStaged(?string $path): GitOperationResult
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
