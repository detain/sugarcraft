<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\GitCommandHandlers;
use SugarCraft\Crush\MCP\GitOperationResult;

/**
 * @see GitCommandHandlers
 */
final class GitCommandHandlersTest extends TestCase
{
    private string $tempDir;
    private string $repoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/git_command_handlers_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        $this->repoPath = $this->tempDir . '/repo';

        mkdir($this->repoPath, 0777, true);
        exec('git init --quiet ' . escapeshellarg($this->repoPath) . ' 2>&1');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' config user.email "test@example.com" 2>&1');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' config user.name "Test User" 2>&1');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                is_dir($file->getPathname()) ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tempDir);
        }
    }

    private function makeCommit(string $message): void
    {
        $file = $this->repoPath . '/file_' . uniqid() . '.txt';
        file_put_contents($file, "content for {$message}");
        $git = '/usr/bin/git';
        exec($git . ' -C ' . escapeshellarg($this->repoPath) . ' add . 2>&1');
        exec($git . ' -C ' . escapeshellarg($this->repoPath) . ' commit --quiet -m ' . escapeshellarg($message) . ' 2>&1');
    }

    // =========================================================================
    // git_context — Repository snapshot, config, aliases
    // =========================================================================

    public function testGitStatusReturnsResult(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitStatus();

        $this->assertInstanceOf(GitOperationResult::class, $result);
        $this->assertSame('git_status', $result->operation);
        $this->assertSame('git_context', $result->group);
    }

    public function testGitStatusFailsForNonexistentDirectory(): void
    {
        $handlers = new GitCommandHandlers('/nonexistent/path');

        $result = $handlers->gitStatus();

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->error);
    }

    public function testGitSnapshotReturnsBranchAndRemoteAndTags(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitSnapshot();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_snapshot', $result->operation);
        $this->assertSame('git_context', $result->group);

        $output = $result->output;
        $this->assertIsArray($output);
        $this->assertArrayHasKey('branch', $output);
        $this->assertArrayHasKey('remote', $output);
        $this->assertArrayHasKey('tags', $output);
        $this->assertArrayHasKey('hasStagedChanges', $output);
    }

    public function testGitSnapshotDetectsStagedChanges(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        file_put_contents($this->repoPath . '/new_file.txt', 'staged content');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' add new_file.txt 2>&1');

        $result = $handlers->gitSnapshot();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['hasStagedChanges']);
    }

    public function testGitConfigListReturnsResult(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitConfigList();

        $this->assertInstanceOf(GitOperationResult::class, $result);
        $this->assertSame('git_config_list', $result->operation);
        $this->assertSame('git_context', $result->group);
    }

    public function testGitConfigGetReturnsUserName(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitConfigGet('user.name');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_config_get', $result->operation);
        $this->assertSame('git_context', $result->group);
    }

    public function testGitConfigGetFailsOnEmptyKey(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitConfigGet('');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('git_config_get', $result->operation);
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitConfigGetReturnsFailureForNonexistentKey(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitConfigGet('nonexistent.key');

        // git returns non-zero for missing config keys
        $this->assertFalse($result->isSuccess());
    }

    public function testGitAliasListReturnsResult(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitAliasList();

        $this->assertInstanceOf(GitOperationResult::class, $result);
        $this->assertSame('git_alias_list', $result->operation);
        $this->assertSame('git_context', $result->group);
    }

    // =========================================================================
    // git_history — Log, show, blame, reflog
    // =========================================================================

    public function testGitLogReturnsCommitEntries(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('first commit');
        $this->makeCommit('second commit');

        $result = $handlers->gitLog(10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_log', $result->operation);
        $this->assertSame('git_history', $result->group);
        $this->assertIsArray($result->output);
    }

    public function testGitLogRespectsLimit(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        for ($i = 0; $i < 5; $i++) {
            $this->makeCommit("commit {$i}");
        }

        $result = $handlers->gitLog(2);

        $this->assertTrue($result->isSuccess());
        $this->assertLessThanOrEqual(2, count($result->output));
    }

    public function testGitLogEnforcesMinimumLimit(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('one commit');

        $result = $handlers->gitLog(0);

        $this->assertTrue($result->isSuccess());
        // Should still return at least 1 (minimum)
        $this->assertLessThanOrEqual(1, count($result->output));
    }

    public function testGitLogReturnsEmptyArrayForNewRepo(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitLog();

        $this->assertTrue($result->isSuccess());
        $this->assertIsArray($result->output);
    }

    public function testGitShowReturnsCommitDetails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('test commit');

        // Get the latest commit hash
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' rev-parse HEAD 2>&1', $hashLines);
        $hash = trim($hashLines[0] ?? '');

        $result = $handlers->gitShow($hash);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_show', $result->operation);
        $this->assertSame('git_history', $result->group);

        $output = $result->output;
        $this->assertIsArray($output);
        $this->assertArrayHasKey('hash', $output);
        $this->assertArrayHasKey('authorName', $output);
        $this->assertArrayHasKey('authorEmail', $output);
        $this->assertArrayHasKey('authorDate', $output);
        $this->assertArrayHasKey('commitDate', $output);
        $this->assertArrayHasKey('subject', $output);
    }

    public function testGitShowFailsOnEmptyRef(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitShow('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitShowFailsOnNonexistentRef(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitShow('nonexistent-ref-12345');

        $this->assertFalse($result->isSuccess());
    }

    public function testGitBlameReturnsBlameEntries(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $file = $this->repoPath . '/blamed.txt';
        file_put_contents($file, "line one\nline two\nline three\n");
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' add blamed.txt 2>&1');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' commit --quiet -m "add file" 2>&1');

        $result = $handlers->gitBlame('blamed.txt');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_blame', $result->operation);
        $this->assertSame('git_history', $result->group);
        $this->assertIsArray($result->output);
    }

    public function testGitBlameFailsOnEmptyFilePath(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBlame('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitBlameFailsOnNonexistentFile(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBlame('nonexistent.txt');

        $this->assertFalse($result->isSuccess());
    }

    public function testGitReflogReturnsEntries(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitReflog();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_reflog', $result->operation);
        $this->assertSame('git_history', $result->group);
    }

    public function testGitReflogRespectsLimit(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        for ($i = 0; $i < 5; $i++) {
            $this->makeCommit("reflog commit {$i}");
        }

        $result = $handlers->gitReflog(2);

        $this->assertTrue($result->isSuccess());
        $this->assertLessThanOrEqual(2, count($result->output));
    }

    public function testGitReflogEnforcesMinimumLimit(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('one commit');

        $result = $handlers->gitReflog(0);

        $this->assertTrue($result->isSuccess());
        $this->assertLessThanOrEqual(1, count($result->output));
    }

    // =========================================================================
    // Execution time tracking
    // =========================================================================

    public function testGitLogRecordsExecutionTime(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('timed commit');

        $result = $handlers->gitLog();

        $this->assertNotNull($result->executionTimeMs);
        $this->assertGreaterThanOrEqual(0, $result->executionTimeMs);
    }

    public function testGitSnapshotRecordsExecutionTime(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('timed snapshot');

        $result = $handlers->gitSnapshot();

        $this->assertNotNull($result->executionTimeMs);
        $this->assertGreaterThanOrEqual(0, $result->executionTimeMs);
    }

    // =========================================================================
    // Directory not a git repo
    // =========================================================================

    public function testGitLogFailsForNonGitDirectory(): void
    {
        $nonGitDir = $this->tempDir . '/non-git';
        mkdir($nonGitDir, 0777, true);

        $handlers = new GitCommandHandlers($nonGitDir);

        $result = $handlers->gitLog();

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->error);
    }

    public function testGitConfigListFailsForNonGitDirectory(): void
    {
        $nonGitDir = $this->tempDir . '/non-git2';
        mkdir($nonGitDir, 0777, true);

        $handlers = new GitCommandHandlers($nonGitDir);

        $result = $handlers->gitConfigList();

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->error);
    }

    // =========================================================================
    // Metadata verification
    // =========================================================================

    public function testGitBlameContainsFileMetadata(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $file = $this->repoPath . '/meta.txt';
        file_put_contents($file, "content\n");
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' add meta.txt 2>&1');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' commit --quiet -m "meta file" 2>&1');

        $result = $handlers->gitBlame('meta.txt');

        $this->assertSame('meta.txt', $result->metadata['file']);
        $this->assertArrayHasKey('lines', $result->metadata);
    }

    public function testGitLogContainsCountMetadata(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        for ($i = 0; $i < 3; $i++) {
            $this->makeCommit("log meta {$i}");
        }

        $result = $handlers->gitLog();

        $this->assertArrayHasKey('count', $result->metadata);
        $this->assertSame(count($result->output), $result->metadata['count']);
    }

    public function testGitReflogContainsCountMetadata(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        for ($i = 0; $i < 3; $i++) {
            $this->makeCommit("reflog meta {$i}");
        }

        $result = $handlers->gitReflog();

        $this->assertArrayHasKey('count', $result->metadata);
        $this->assertSame(count($result->output), $result->metadata['count']);
    }

    public function testGitShowContainsRefMetadata(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('ref meta commit');

        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' rev-parse HEAD 2>&1', $hashLines);
        $hash = trim($hashLines[0] ?? '');

        $result = $handlers->gitShow($hash);

        $this->assertSame($hash, $result->metadata['ref']);
    }
}
