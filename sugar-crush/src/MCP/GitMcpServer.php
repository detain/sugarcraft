<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\MCP\GitCommandHandlers;

/**
 * Git MCP server implementation that wraps GitCommandHandlers.
 *
 * Provides all git operations as MCP tools including:
 * - git_context: status, snapshot, config, aliases
 * - git_history: log, show, blame, reflog
 * - git_commits: add, commit, amend, revert, reset
 * - git_branches: list, create, delete, checkout
 * - git_worktree: add, list, remove
 * - git_flow: init, feature, release, hotfix
 * - git_lfs: track, untrack, locks, migrate
 *
 * @implements McpServer
 * @see McpServer
 * @see GitCommandHandlers
 */
final class GitMcpServer implements McpServer
{
    /** @var array<McpTool> */
    private array $tools = [];

    private bool $running = false;

    public function __construct(
        private readonly GitCommandHandlers $handlers,
        private readonly string $name = 'git',
    ) {
        $this->tools = $this->buildToolList();
    }

    /**
     * Build the complete list of Git tools with their input schemas.
     *
     * @return array<McpTool>
     */
    private function buildToolList(): array
    {
        return [
            // git_context
            new McpTool(
                name: 'gitStatus',
                description: 'Get repository status as a list of changed files',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitSnapshot',
                description: 'Get repository snapshot: branch, remote, tags, and staged changes',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitConfigList',
                description: 'List all git config entries',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitConfigGet',
                description: 'Get a specific git config value by key',
                inputSchema: $this->schema(['key' => ['type' => 'string'], 'path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitAliasList',
                description: 'List all configured git aliases',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            // git_history
            new McpTool(
                name: 'gitLog',
                description: 'Get git log entries with author, date, and message',
                inputSchema: $this->schema([
                    'limit' => ['type' => 'integer', 'default' => 50],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitShow',
                description: 'Show commit details for a given ref',
                inputSchema: $this->schema([
                    'ref' => ['type' => 'string'],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitBlame',
                description: 'Get git blame information for a file',
                inputSchema: $this->schema([
                    'filePath' => ['type' => 'string'],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitReflog',
                description: 'Get git reflog entries',
                inputSchema: $this->schema([
                    'limit' => ['type' => 'integer', 'default' => 50],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            // git_commits
            new McpTool(
                name: 'gitAdd',
                description: 'Stage files for commit',
                inputSchema: $this->schema([
                    'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitCommit',
                description: 'Create a commit with a message',
                inputSchema: $this->schema([
                    'message' => ['type' => 'string'],
                    'all' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitAmend',
                description: 'Amend the last commit with staged changes (no message change)',
                inputSchema: $this->schema([
                    'all' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitRevert',
                description: 'Create a revert commit for a given commit',
                inputSchema: $this->schema([
                    'commit' => ['type' => 'string'],
                    'noCommit' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitReset',
                description: 'Reset the repository to a given commit',
                inputSchema: $this->schema([
                    'commit' => ['type' => 'string'],
                    'mode' => ['type' => 'string', 'default' => 'mixed'],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            // git_branches
            new McpTool(
                name: 'gitBranchList',
                description: 'List branches',
                inputSchema: $this->schema([
                    'all' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitBranchCreate',
                description: 'Create a new branch',
                inputSchema: $this->schema([
                    'name' => ['type' => 'string'],
                    'checkout' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitBranchDelete',
                description: 'Delete a branch',
                inputSchema: $this->schema([
                    'name' => ['type' => 'string'],
                    'force' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitBranchCheckout',
                description: 'Checkout a branch, commit, or file',
                inputSchema: $this->schema([
                    'target' => ['type' => 'string'],
                    'createBranch' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            // git_worktree
            new McpTool(
                name: 'gitWorktreeAdd',
                description: 'Add a worktree',
                inputSchema: $this->schema([
                    'worktreePath' => ['type' => 'string'],
                    'branch' => ['type' => 'string', 'nullable' => true],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitWorktreeList',
                description: 'List worktrees',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitWorktreeRemove',
                description: 'Remove a worktree',
                inputSchema: $this->schema([
                    'worktreePath' => ['type' => 'string'],
                    'force' => ['type' => 'boolean', 'default' => false],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            // git_flow
            new McpTool(
                name: 'gitFlowInit',
                description: 'Initialize git-flow',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitFlowFeature',
                description: 'Perform a git-flow feature action',
                inputSchema: $this->schema([
                    'action' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'nullable' => true],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitFlowRelease',
                description: 'Perform a git-flow release action',
                inputSchema: $this->schema([
                    'action' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'nullable' => true],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitFlowHotfix',
                description: 'Perform a git-flow hotfix action',
                inputSchema: $this->schema([
                    'action' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'nullable' => true],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            // git_lfs
            new McpTool(
                name: 'gitLfsTrack',
                description: 'Track files with Git LFS',
                inputSchema: $this->schema([
                    'pattern' => ['type' => 'string'],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitLfsUntrack',
                description: 'Untrack files from Git LFS',
                inputSchema: $this->schema([
                    'pattern' => ['type' => 'string'],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitLfsLocks',
                description: 'List current LFS locks',
                inputSchema: $this->schema(['path' => ['type' => 'string', 'nullable' => true]]),
                serverName: $this->name,
            ),
            new McpTool(
                name: 'gitLfsMigrate',
                description: 'Migrate LFS objects (import or export)',
                inputSchema: $this->schema([
                    'direction' => ['type' => 'string'],
                    'path' => ['type' => 'string', 'nullable' => true],
                ]),
                serverName: $this->name,
            ),
        ];
    }

    /**
     * Build a JSON Schema for tool input.
     *
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, mixed>
     */
    private function schema(array $properties): array
    {
        $props = [];
        $required = [];

        foreach ($properties as $name => $def) {
            $props[$name] = $def;
            // Fields are not required if they are nullable OR have a default value
            if (!($def['nullable'] ?? false) && !array_key_exists('default', $def)) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $props,
            'required' => $required,
        ];
    }

    /**
     * Routing map from tool name to method name.
     *
     * @return array<string, string>
     */
    private static function methodMap(): array
    {
        return [
            // git_context
            'gitStatus' => 'gitStatus',
            'gitSnapshot' => 'gitSnapshot',
            'gitConfigList' => 'gitConfigList',
            'gitConfigGet' => 'gitConfigGet',
            'gitAliasList' => 'gitAliasList',
            // git_history
            'gitLog' => 'gitLog',
            'gitShow' => 'gitShow',
            'gitBlame' => 'gitBlame',
            'gitReflog' => 'gitReflog',
            // git_commits
            'gitAdd' => 'gitAdd',
            'gitCommit' => 'gitCommit',
            'gitAmend' => 'gitAmend',
            'gitRevert' => 'gitRevert',
            'gitReset' => 'gitReset',
            // git_branches
            'gitBranchList' => 'gitBranchList',
            'gitBranchCreate' => 'gitBranchCreate',
            'gitBranchDelete' => 'gitBranchDelete',
            'gitBranchCheckout' => 'gitBranchCheckout',
            // git_worktree
            'gitWorktreeAdd' => 'gitWorktreeAdd',
            'gitWorktreeList' => 'gitWorktreeList',
            'gitWorktreeRemove' => 'gitWorktreeRemove',
            // git_flow
            'gitFlowInit' => 'gitFlowInit',
            'gitFlowFeature' => 'gitFlowFeature',
            'gitFlowRelease' => 'gitFlowRelease',
            'gitFlowHotfix' => 'gitFlowHotfix',
            // git_lfs
            'gitLfsTrack' => 'gitLfsTrack',
            'gitLfsUntrack' => 'gitLfsUntrack',
            'gitLfsLocks' => 'gitLfsLocks',
            'gitLfsMigrate' => 'gitLfsMigrate',
        ];
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<mixed>
     */
    public function callTool(string $toolName, array $args): array
    {
        $map = self::methodMap();

        if (!isset($map[$toolName])) {
            return [
                'success' => false,
                'error' => "Unknown tool: {$toolName}",
            ];
        }

        $method = $map[$toolName];

        if (!method_exists($this->handlers, $method)) {
            return [
                'success' => false,
                'error' => "Handler method {$method} does not exist",
            ];
        }

        try {
            $result = $this->handlers->$method(...$args);

            if (!$result instanceof GitOperationResult) {
                return [
                    'success' => false,
                    'error' => 'Handler did not return a GitOperationResult',
                ];
            }

            return [
                'success' => $result->success,
                'output' => $result->output,
                'error' => $result->error,
                'operation' => $result->operation,
                'group' => $result->group,
                'metadata' => $result->metadata,
                'executionTimeMs' => $result->executionTimeMs,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
