<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\GitOperationResult;

/**
 * @see GitOperationResult
 */
final class GitOperationResultTest extends TestCase
{
    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithAllFields(): void
    {
        $result = new GitOperationResult(
            success: true,
            output: ['commit' => 'abc123'],
            error: null,
            operation: 'git_commit',
            group: 'git_commits',
            metadata: ['branch' => 'main'],
            executionTimeMs: 45.5,
        );

        $this->assertTrue($result->success);
        $this->assertSame(['commit' => 'abc123'], $result->output);
        $this->assertNull($result->error);
        $this->assertSame('git_commit', $result->operation);
        $this->assertSame('git_commits', $result->group);
        $this->assertSame(['branch' => 'main'], $result->metadata);
        $this->assertSame(45.5, $result->executionTimeMs);
    }

    public function testCanBeCreatedWithMinimalFields(): void
    {
        $result = new GitOperationResult(
            success: true,
            output: 'some output',
            error: null,
            operation: 'git_status',
            group: 'git_context',
        );

        $this->assertTrue($result->success);
        $this->assertSame('some output', $result->output);
        $this->assertNull($result->error);
        $this->assertSame('git_status', $result->operation);
        $this->assertSame('git_context', $result->group);
        $this->assertSame([], $result->metadata);
        $this->assertNull($result->executionTimeMs);
    }

    public function testDefaultsToEmptyMetadata(): void
    {
        $result = new GitOperationResult(
            success: false,
            output: null,
            error: 'Not a git repository',
            operation: 'git_log',
            group: 'git_history',
        );

        $this->assertSame([], $result->metadata);
    }

    // =========================================================================
    // success() Factory Tests
    // =========================================================================

    public function testSuccessFactoryCreatesSuccessfulResult(): void
    {
        $result = GitOperationResult::success(
            output: ['files' => ['a.php', 'b.php']],
            operation: 'git_add',
            group: 'git_commits',
            metadata: ['all' => true],
            executionTimeMs: 12.3,
        );

        $this->assertTrue($result->success);
        $this->assertSame(['files' => ['a.php', 'b.php']], $result->output);
        $this->assertNull($result->error);
        $this->assertSame('git_add', $result->operation);
        $this->assertSame('git_commits', $result->group);
        $this->assertSame(['all' => true], $result->metadata);
        $this->assertSame(12.3, $result->executionTimeMs);
    }

    public function testSuccessFactoryWithMinimalArguments(): void
    {
        $result = GitOperationResult::success(
            output: 'branch-name',
            operation: 'git_branch_current',
            group: 'git_branches',
        );

        $this->assertTrue($result->success);
        $this->assertSame('branch-name', $result->output);
        $this->assertNull($result->error);
        $this->assertSame('git_branch_current', $result->operation);
        $this->assertSame('git_branches', $result->group);
        $this->assertSame([], $result->metadata);
        $this->assertNull($result->executionTimeMs);
    }

    // =========================================================================
    // failure() Factory Tests
    // =========================================================================

    public function testFailureFactoryCreatesFailedResult(): void
    {
        $result = GitOperationResult::failure(
            error: 'fatal: not a git repository',
            operation: 'git_commit',
            group: 'git_commits',
            metadata: ['path' => '/nonexistent'],
            executionTimeMs: 5.0,
        );

        $this->assertFalse($result->success);
        $this->assertNull($result->output);
        $this->assertSame('fatal: not a git repository', $result->error);
        $this->assertSame('git_commit', $result->operation);
        $this->assertSame('git_commits', $result->group);
        $this->assertSame(['path' => '/nonexistent'], $result->metadata);
        $this->assertSame(5.0, $result->executionTimeMs);
    }

    public function testFailureFactoryWithMinimalArguments(): void
    {
        $result = GitOperationResult::failure(
            error: 'operation timed out',
            operation: 'git_clone',
            group: 'git_context',
        );

        $this->assertFalse($result->success);
        $this->assertNull($result->output);
        $this->assertSame('operation timed out', $result->error);
        $this->assertSame('git_clone', $result->operation);
        $this->assertSame('git_context', $result->group);
        $this->assertSame([], $result->metadata);
        $this->assertNull($result->executionTimeMs);
    }

    // =========================================================================
    // isSuccess / isFailure Tests
    // =========================================================================

    public function testIsSuccessReturnsTrueForSuccessfulResult(): void
    {
        $result = GitOperationResult::success('output', 'git_status', 'git_context');
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }

    public function testIsSuccessReturnsFalseForFailedResult(): void
    {
        $result = GitOperationResult::failure('error', 'git_status', 'git_context');
        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
    }

    // =========================================================================
    // getOutputOrNull Tests
    // =========================================================================

    public function testGetOutputOrNullReturnsOutputOnSuccess(): void
    {
        $output = ['commit' => 'abc123'];
        $result = GitOperationResult::success($output, 'git_commit', 'git_commits');
        $this->assertSame($output, $result->getOutputOrNull());
    }

    public function testGetOutputOrNullReturnsNullOnFailure(): void
    {
        $result = GitOperationResult::failure('error', 'git_commit', 'git_commits');
        $this->assertNull($result->getOutputOrNull());
    }

    // =========================================================================
    // Operation Group Coverage Tests
    // =========================================================================

    public function testCanRepresentGitContextOperations(): void
    {
        $result = GitOperationResult::success(
            output: ['config' => ['user.name' => 'Test']],
            operation: 'git_config_list',
            group: 'git_context',
        );

        $this->assertSame('git_context', $result->group);
        $this->assertSame('git_config_list', $result->operation);
    }

    public function testCanRepresentGitHistoryOperations(): void
    {
        $result = GitOperationResult::success(
            output: [['hash' => 'abc', 'message' => 'fix auth']],
            operation: 'git_log',
            group: 'git_history',
        );

        $this->assertSame('git_history', $result->group);
    }

    public function testCanRepresentGitCommitsOperations(): void
    {
        $result = GitOperationResult::success(
            output: 'new-commit-hash',
            operation: 'git_commit',
            group: 'git_commits',
        );

        $this->assertSame('git_commits', $result->group);
    }

    public function testCanRepresentGitBranchesOperations(): void
    {
        $result = GitOperationResult::success(
            output: ['main', 'develop', 'feature-x'],
            operation: 'git_branch_list',
            group: 'git_branches',
        );

        $this->assertSame('git_branches', $result->group);
    }

    public function testCanRepresentGitWorktreeOperations(): void
    {
        $result = GitOperationResult::success(
            output: ['path' => '/repo/worktrees/feature', 'branch' => 'feature-x'],
            operation: 'git_worktree_add',
            group: 'git_worktree',
        );

        $this->assertSame('git_worktree', $result->group);
    }

    public function testCanRepresentGitFlowOperations(): void
    {
        $result = GitOperationResult::success(
            output: 'develop',
            operation: 'git_flow_feature_start',
            group: 'git_flow',
        );

        $this->assertSame('git_flow', $result->group);
    }

    public function testCanRepresentGitLfsOperations(): void
    {
        $result = GitOperationResult::success(
            output: ['tracking' => ['*.psd', '*.ai']],
            operation: 'git_lfs_track',
            group: 'git_lfs',
        );

        $this->assertSame('git_lfs', $result->group);
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testInstancesAreImmutable(): void
    {
        $result1 = GitOperationResult::success('output1', 'op1', 'group1');
        $result2 = GitOperationResult::failure('error2', 'op2', 'group2');

        $this->assertNotSame($result1, $result2);
        $this->assertSame('output1', $result1->output);
        $this->assertSame('error2', $result2->error);
    }

    public function testOutputArrayIsNotModifiedByCaller(): void
    {
        $originalOutput = ['files' => ['a.php']];
        $result = GitOperationResult::success($originalOutput, 'git_add', 'git_commits');

        $originalOutput['files'][] = 'b.php';
        $this->assertSame(['files' => ['a.php']], $result->output);
    }

    public function testMetadataArrayIsNotModifiedByCaller(): void
    {
        $originalMetadata = ['key' => 'value'];
        $result = new GitOperationResult(
            success: true,
            output: 'test',
            error: null,
            operation: 'git_status',
            group: 'git_context',
            metadata: $originalMetadata,
        );

        $originalMetadata['key'] = 'modified';
        $this->assertSame('value', $result->metadata['key']);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testHandlesNullOutputOnSuccess(): void
    {
        // Some operations like git_add with nothing to add might return null
        $result = GitOperationResult::success(null, 'git_add', 'git_commits');
        $this->assertTrue($result->success);
        $this->assertNull($result->output);
    }

    public function testHandlesComplexOutputStructures(): void
    {
        $complexOutput = [
            'commits' => [
                ['hash' => 'abc123', 'author' => 'Test <test@example.com>', 'message' => 'First commit'],
                ['hash' => 'def456', 'author' => 'Test <test@example.com>', 'message' => 'Second commit'],
            ],
            'branches' => ['main', 'develop'],
            'tags' => ['v1.0.0'],
        ];

        $result = GitOperationResult::success($complexOutput, 'git_log', 'git_history');
        $this->assertSame($complexOutput, $result->output);
    }

    public function testHandlesEmptyErrorMessage(): void
    {
        $result = new GitOperationResult(
            success: false,
            output: null,
            error: '',
            operation: 'git_rebase',
            group: 'git_commits',
        );

        $this->assertFalse($result->success);
        $this->assertSame('', $result->error);
    }
}
