<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\GitCommandHandlers;
use SugarCraft\Crush\MCP\GitMcpServer;
use SugarCraft\Crush\MCP\GitOperationResult;
use SugarCraft\Crush\MCP\McpTool;

/**
 * @see GitMcpServer
 */
final class GitMcpServerTest extends TestCase
{
    private GitMcpServer $server;
    private GitCommandHandlers $handlers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handlers = new GitCommandHandlers();
        $this->server = new GitMcpServer($this->handlers);
    }

    // =========================================================================
    // Interface Compliance Tests
    // =========================================================================

    public function testImplementsMcpServerInterface(): void
    {
        $this->assertInstanceOf(\SugarCraft\Crush\MCP\McpServer::class, $this->server);
    }

    public function testStartDoesNotThrow(): void
    {
        $this->server->start();
        // Should not throw - just marks as running
        $this->assertTrue(true);
    }

    public function testStopDoesNotThrow(): void
    {
        $this->server->start();
        $this->server->stop();
        // Should not throw
        $this->assertTrue(true);
    }

    // =========================================================================
    // listTools Tests
    // =========================================================================

    public function testListToolsReturnsNonEmptyArray(): void
    {
        $tools = $this->server->listTools();

        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
    }

    public function testListToolsReturnsMcpToolInstances(): void
    {
        $tools = $this->server->listTools();

        foreach ($tools as $tool) {
            $this->assertInstanceOf(McpTool::class, $tool);
        }
    }

    public function testAllToolsHaveServerNameSet(): void
    {
        $tools = $this->server->listTools();

        foreach ($tools as $tool) {
            $this->assertSame('git', $tool->serverName);
        }
    }

    public function testAllToolsHaveNonEmptyName(): void
    {
        $tools = $this->server->listTools();

        foreach ($tools as $tool) {
            $this->assertNotEmpty($tool->name);
        }
    }

    public function testAllToolsHaveDescription(): void
    {
        $tools = $this->server->listTools();

        foreach ($tools as $tool) {
            $this->assertNotEmpty($tool->description);
        }
    }

    public function testAllToolsHaveInputSchema(): void
    {
        $tools = $this->server->listTools();

        foreach ($tools as $tool) {
            $this->assertIsArray($tool->inputSchema);
        }
    }

    public function testToolNamesAreUnique(): void
    {
        $tools = $this->server->listTools();
        $names = array_map(fn(McpTool $t) => $t->name, $tools);

        $this->assertCount(count($names), array_unique($names), 'Tool names must be unique');
    }

    /**
     * Verify all expected git tools are present.
     */
    public function testExpectedGitToolsArePresent(): void
    {
        $tools = $this->server->listTools();
        $names = array_map(fn(McpTool $t) => $t->name, $tools);

        $expectedTools = [
            // git_context
            'gitStatus',
            'gitSnapshot',
            'gitConfigList',
            'gitConfigGet',
            'gitAliasList',
            // git_history
            'gitLog',
            'gitShow',
            'gitBlame',
            'gitReflog',
            // git_commits
            'gitAdd',
            'gitCommit',
            'gitAmend',
            'gitRevert',
            'gitReset',
            // git_branches
            'gitBranchList',
            'gitBranchCreate',
            'gitBranchDelete',
            'gitBranchCheckout',
            // git_worktree
            'gitWorktreeAdd',
            'gitWorktreeList',
            'gitWorktreeRemove',
            // git_flow
            'gitFlowInit',
            'gitFlowFeature',
            'gitFlowRelease',
            'gitFlowHotfix',
            // git_lfs
            'gitLfsTrack',
            'gitLfsUntrack',
            'gitLfsLocks',
            'gitLfsMigrate',
        ];

        foreach ($expectedTools as $expected) {
            $this->assertContains($expected, $names, "Tool {$expected} should be present");
        }
    }

    // =========================================================================
    // Input Schema Tests
    // =========================================================================

    public function testGitCommitHasRequiredMessageField(): void
    {
        $tools = $this->server->listTools();
        $commitTool = null;
        foreach ($tools as $tool) {
            if ($tool->name === 'gitCommit') {
                $commitTool = $tool;
                break;
            }
        }

        $this->assertNotNull($commitTool);
        $this->assertArrayHasKey('message', $commitTool->inputSchema['properties']);
        $this->assertSame('string', $commitTool->inputSchema['properties']['message']['type']);
        $this->assertContains('message', $commitTool->inputSchema['required']);
    }

    public function testGitCommitAllFieldIsOptional(): void
    {
        $tools = $this->server->listTools();
        $commitTool = null;
        foreach ($tools as $tool) {
            if ($tool->name === 'gitCommit') {
                $commitTool = $tool;
                break;
            }
        }

        $this->assertNotNull($commitTool);
        $this->assertArrayHasKey('all', $commitTool->inputSchema['properties']);
        $this->assertSame('boolean', $commitTool->inputSchema['properties']['all']['type']);
        $this->assertNotContains('all', $commitTool->inputSchema['required']);
    }

    public function testGitLogHasOptionalLimitField(): void
    {
        $tools = $this->server->listTools();
        $logTool = null;
        foreach ($tools as $tool) {
            if ($tool->name === 'gitLog') {
                $logTool = $tool;
                break;
            }
        }

        $this->assertNotNull($logTool);
        $this->assertArrayHasKey('limit', $logTool->inputSchema['properties']);
        $this->assertSame('integer', $logTool->inputSchema['properties']['limit']['type']);
        $this->assertNotContains('limit', $logTool->inputSchema['required']);
    }

    public function testGitBranchListAllFieldIsOptional(): void
    {
        $tools = $this->server->listTools();
        $branchListTool = null;
        foreach ($tools as $tool) {
            if ($tool->name === 'gitBranchList') {
                $branchListTool = $tool;
                break;
            }
        }

        $this->assertNotNull($branchListTool);
        $this->assertArrayHasKey('all', $branchListTool->inputSchema['properties']);
        $this->assertSame('boolean', $branchListTool->inputSchema['properties']['all']['type']);
        $this->assertNotContains('all', $branchListTool->inputSchema['required']);
    }

    public function testPathFieldIsNullable(): void
    {
        $tools = $this->server->listTools();
        $statusTool = null;
        foreach ($tools as $tool) {
            if ($tool->name === 'gitStatus') {
                $statusTool = $tool;
                break;
            }
        }

        $this->assertNotNull($statusTool);
        $this->assertArrayHasKey('path', $statusTool->inputSchema['properties']);
        $this->assertTrue($statusTool->inputSchema['properties']['path']['nullable']);
        $this->assertNotContains('path', $statusTool->inputSchema['required']);
    }

    // =========================================================================
    // callTool Tests
    // =========================================================================

    public function testCallToolReturnsErrorForUnknownTool(): void
    {
        $result = $this->server->callTool('unknownTool', []);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown tool', $result['error']);
    }

    public function testCallToolHandlesNonGitDirectory(): void
    {
        // Use a path that definitely isn't a git repo
        $result = $this->server->callTool('gitStatus', ['path' => '/tmp']);

        $this->assertIsArray($result);
        // Either success or failure depending on whether /tmp happens to be a git repo
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCallToolWithEmptyArgsUsesDefaults(): void
    {
        // gitLog should work with defaults
        $result = $this->server->callTool('gitLog', []);

        $this->assertIsArray($result);
        // Non-git directory will fail, but the structure should be correct
        $this->assertArrayHasKey('success', $result);
    }

    public function testCallToolReturnsStructuredResult(): void
    {
        // Test with a known non-git directory
        $result = $this->server->callTool('gitStatus', ['path' => '/tmp/non_existent_path_12345']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('operation', $result);
        $this->assertArrayHasKey('group', $result);
        $this->assertArrayHasKey('metadata', $result);
    }

    public function testCallToolGitCommitReturnsErrorForEmptyMessage(): void
    {
        // This should fail because commit message is empty
        $result = $this->server->callTool('gitCommit', [
            'message' => '',
            'path' => '/tmp',
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('empty', strtolower($result['error'] ?? ''));
    }

    public function testCallToolGitResetReturnsErrorForInvalidMode(): void
    {
        $result = $this->server->callTool('gitReset', [
            'commit' => 'abc123',
            'mode' => 'invalid_mode',
            'path' => '/tmp',
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid reset mode', $result['error']);
    }

    public function testCallToolGitBranchCreateReturnsErrorForEmptyName(): void
    {
        $result = $this->server->callTool('gitBranchCreate', [
            'name' => '',
            'path' => '/tmp',
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Branch name cannot be empty', $result['error']);
    }

    public function testCallToolGitFlowFeatureReturnsErrorForInvalidAction(): void
    {
        $result = $this->server->callTool('gitFlowFeature', [
            'action' => 'invalid_action',
            'path' => '/tmp',
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid git-flow feature action', $result['error']);
    }

    public function testCallToolGitLfsMigrateReturnsErrorForInvalidDirection(): void
    {
        $result = $this->server->callTool('gitLfsMigrate', [
            'direction' => 'invalid_direction',
            'path' => '/tmp',
        ]);

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid LFS migration direction', $result['error']);
    }

    // =========================================================================
    // Custom Server Name Tests
    // =========================================================================

    public function testCustomServerName(): void
    {
        $customServer = new GitMcpServer($this->handlers, 'custom-git');
        $tools = $customServer->listTools();

        foreach ($tools as $tool) {
            $this->assertSame('custom-git', $tool->serverName);
        }
    }
}
