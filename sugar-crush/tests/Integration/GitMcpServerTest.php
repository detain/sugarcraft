<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspCache;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\MCP\GitCommandHandlers;
use SugarCraft\Crush\MCP\GitMcpServer;
use SugarCraft\Crush\MCP\McpTool;

/**
 * Integration smoke test for Git MCP server and LSP client.
 *
 * Covers:
 * - GitMcpServer returns all 29 tools across 7 operation groups
 * - GitMcpServer start/stop lifecycle
 * - LspClient initialize + textDocument/definition graceful fallback
 *
 * @see GitMcpServer
 * @see LspClient
 */
final class GitMcpServerTest extends TestCase
{
    /**
     * Acceptance criterion 1: Git MCP server handles all 29 operations.
     *
     * The 7 groups are: git_context (5), git_history (5), git_commits (5),
     * git_branches (4), git_worktree (3), git_flow (4), git_lfs (4).
     * Total = 29 unique tools.
     */
    public function testGitMcpServerReturnsAll29Tools(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers());
        $tools = $server->listTools();

        $this->assertCount(29, $tools, 'GitMcpServer must expose exactly 29 tools');
    }

    /**
     * Verify every returned tool is a proper McpTool with non-empty name and schema.
     */
    public function testAllToolsAreValidMcpToolInstances(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers());
        $tools = $server->listTools();

        foreach ($tools as $tool) {
            $this->assertInstanceOf(McpTool::class, $tool);
            $this->assertNotEmpty($tool->name);
            $this->assertNotEmpty($tool->description);
            $this->assertIsArray($tool->inputSchema);
            $this->assertSame('git', $tool->serverName);
        }
    }

    /**
     * Verify all 29 expected tool names are present.
     */
    public function testAll29ExpectedToolNamesArePresent(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers());
        $names = array_map(fn(McpTool $t) => $t->name, $server->listTools());

        $expected = [
            // git_context (5)
            'gitStatus',
            'gitSnapshot',
            'gitConfigList',
            'gitConfigGet',
            'gitAliasList',
            // git_history (5)
            'gitLog',
            'gitShow',
            'gitBlame',
            'gitReflog',
            // git_commits (5)
            'gitAdd',
            'gitCommit',
            'gitAmend',
            'gitRevert',
            'gitReset',
            // git_branches (4)
            'gitBranchList',
            'gitBranchCreate',
            'gitBranchDelete',
            'gitBranchCheckout',
            // git_worktree (3)
            'gitWorktreeAdd',
            'gitWorktreeList',
            'gitWorktreeRemove',
            // git_flow (4)
            'gitFlowInit',
            'gitFlowFeature',
            'gitFlowRelease',
            'gitFlowHotfix',
            // git_lfs (4)
            'gitLfsTrack',
            'gitLfsUntrack',
            'gitLfsLocks',
            'gitLfsMigrate',
        ];

        $this->assertCount(29, $expected);
        foreach ($expected as $name) {
            $this->assertContains($name, $names, "Tool {$name} must be present");
        }
    }

    /**
     * GitMcpServer start() must not throw.
     */
    public function testStartDoesNotThrow(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers());
        $server->start();
        $this->assertTrue(true);
    }

    /**
     * GitMcpServer stop() after start() must not throw.
     */
    public function testStopAfterStartDoesNotThrow(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers());
        $server->start();
        $server->stop();
        $this->assertTrue(true);
    }

    /**
     * Acceptance criterion 2: LSP provides go-to-definition for PHP.
     *
     * Smoke test: instantiate LspClient with a real LspConnection (pointing at
     * a non-existent server so connect() fails gracefully) and verify that
     * textDocument/definition() returns an array without throwing.
     *
     * When no LSP server is available the client falls back to grep-based
     * definition lookup, so the call always returns an array — never throws.
     */
    public function testLspClientDefinitionsDoesNotThrowAndReturnsArray(): void
    {
        // Use a bogus server path so connect() fails gracefully.
        // LspClient falls back to grep when isConnected() returns false.
        $connection = new LspConnection('/nonexistent/php-language-server');
        $cache = new LspCache();
        $client = new LspClient($connection, $cache);

        // Calling definitions on a disconnected client must not throw.
        // It returns [] or grep-based results as a graceful fallback.
        $result = $client->definitions('file:///nonexistent/file.php', 0, 0);

        $this->assertIsArray($result);
    }

    /**
     * Verify LspClient can be instantiated and used with a real LspConnection
     * without any methods throwing during normal operation.
     */
    public function testLspClientInstantiationAndBasicUsageDoesNotThrow(): void
    {
        $connection = new LspConnection('/nonexistent/php-language-server');
        $cache = new LspCache();
        $client = new LspClient($connection, $cache);

        // use() returns a clone and must not throw.
        $phpClient = $client->use('php');
        $this->assertInstanceOf(LspClient::class, $phpClient);

        // definitions() must not throw even when disconnected.
        $result = $phpClient->definitions('file:///var/www/index.php', 10, 4);
        $this->assertIsArray($result);

        // references() must also not throw.
        $refs = $phpClient->references('file:///var/www/index.php', 10, 4);
        $this->assertIsArray($refs);
    }
}
