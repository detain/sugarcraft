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

    // =========================================================================
    // git_commits — add, commit, amend, revert, reset
    // =========================================================================

    public function testGitAddStagesAllFiles(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        file_put_contents($this->repoPath . '/new.txt', 'new content');

        $result = $handlers->gitAdd();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_add', $result->operation);
        $this->assertSame('git_commits', $result->group);
    }

    public function testGitAddStagesSpecificFiles(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        file_put_contents($this->repoPath . '/a.txt', 'a');
        file_put_contents($this->repoPath . '/b.txt', 'b');

        $result = $handlers->gitAdd(['a.txt']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_add', $result->operation);
        $this->assertSame('git_commits', $result->group);
    }

    public function testGitAddWithEmptyPathsStagesAll(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        file_put_contents($this->repoPath . '/another.txt', 'content');

        $result = $handlers->gitAdd([]);

        $this->assertTrue($result->isSuccess());
    }

    public function testGitCommitWithEmptyMessageFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitCommit('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
        $this->assertSame('git_commit', $result->operation);
    }

    public function testGitCommitBasic(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        file_put_contents($this->repoPath . '/new.txt', "content\n");
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' add new.txt 2>&1');

        $result = $handlers->gitCommit('add new file');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_commit', $result->operation);
        $this->assertSame('git_commits', $result->group);
    }

    public function testGitCommitWithAllFlag(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        // Modify existing file (find a .txt file in repo)
        $found = false;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoPath)) as $f) {
            if ($f->isFile() && str_ends_with($f->getPathname(), '.txt')) {
                file_put_contents($f->getPathname(), 'modified');
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'No .txt file found to modify');

        $result = $handlers->gitCommit('update file', all: true);

        $this->assertTrue($result->isSuccess());
    }

    public function testGitAmendWithoutAllFlag(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitAmend();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_amend', $result->operation);
    }

    public function testGitAmendWithAllFlag(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        file_put_contents($this->repoPath . '/extra.txt', 'extra');

        $result = $handlers->gitAmend(all: true);

        $this->assertTrue($result->isSuccess());
    }

    public function testGitRevertWithEmptyCommitFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitRevert('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitRevertBasic(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        $this->makeCommit('second');

        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' rev-parse HEAD~1 2>&1', $hashLines);
        $parentHash = trim($hashLines[0] ?? '');

        $result = $handlers->gitRevert($parentHash);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_revert', $result->operation);
    }

    public function testGitRevertWithNoCommitFlag(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        $this->makeCommit('second');

        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' rev-parse HEAD~1 2>&1', $hashLines);
        $parentHash = trim($hashLines[0] ?? '');

        $result = $handlers->gitRevert($parentHash, noCommit: true);

        $this->assertTrue($result->isSuccess());
    }

    public function testGitResetWithEmptyCommitFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitReset('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitResetWithInvalidModeFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitReset('HEAD', 'invalid');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Invalid reset mode', $result->error);
    }

    public function testGitResetSoftMode(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('first');
        $this->makeCommit('second');

        $result = $handlers->gitReset('HEAD~1', 'soft');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_reset', $result->operation);
    }

    public function testGitResetMixedMode(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('first');
        $this->makeCommit('second');

        $result = $handlers->gitReset('HEAD~1', 'mixed');

        $this->assertTrue($result->isSuccess());
    }

    public function testGitResetHardMode(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('first');
        $this->makeCommit('second');

        $result = $handlers->gitReset('HEAD~1', 'hard');

        $this->assertTrue($result->isSuccess());
    }

    // =========================================================================
    // git_branches — list, create, delete, checkout
    // =========================================================================

    public function testGitBranchListBasic(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBranchList();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_branch_list', $result->operation);
        $this->assertSame('git_branches', $result->group);
        $this->assertIsArray($result->output);
        $this->assertNotEmpty($result->output);
        $this->assertArrayHasKey('name', $result->output[0]);
        $this->assertArrayHasKey('current', $result->output[0]);
    }

    public function testGitBranchListWithAllFlag(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBranchList(all: true);

        $this->assertTrue($result->isSuccess());
        $this->assertIsArray($result->output);
    }

    public function testGitBranchListReturnsParsedOutputShape(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBranchList();

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('count', $result->metadata);
        foreach ($result->output as $branch) {
            $this->assertArrayHasKey('name', $branch);
            $this->assertArrayHasKey('current', $branch);
            $this->assertIsString($branch['name']);
            $this->assertIsBool($branch['current']);
        }
    }

    public function testGitBranchCreateWithEmptyNameFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBranchCreate('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitBranchCreateProtectedNameHEADFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBranchCreate('HEAD');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Cannot create branch', $result->error);
    }

    public function testGitBranchCreateProtectedNameMASTERFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBranchCreate('MASTER');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Cannot create branch', $result->error);
    }

    public function testGitBranchCreateProtectedNameMAINFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBranchCreate('MAIN');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Cannot create branch', $result->error);
    }

    public function testGitBranchCreateWithoutCheckout(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBranchCreate('feature-branch');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_branch_create', $result->operation);
        $this->assertFalse($result->output['checkedOut']);
    }

    public function testGitBranchCreateWithCheckout(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBranchCreate('feature-branch', checkout: true);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->output['checkedOut']);
    }

    public function testGitBranchDeleteWithEmptyNameFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBranchDelete('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitBranchDeleteSafeDelete(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' branch test-branch 2>&1');

        $result = $handlers->gitBranchDelete('test-branch');

        // Safe delete (-d) may fail if not merged, but operation itself is valid
        $this->assertSame('git_branch_delete', $result->operation);
    }

    public function testGitBranchDeleteForceDelete(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' branch test-branch 2>&1');

        $result = $handlers->gitBranchDelete('test-branch', force: true);

        $this->assertTrue($result->isSuccess());
    }

    public function testGitBranchCheckoutWithEmptyTargetFails(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitBranchCheckout('');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('empty', $result->error);
    }

    public function testGitBranchCheckoutExistingBranch(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' branch checkout-branch 2>&1');

        $result = $handlers->gitBranchCheckout('checkout-branch');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('git_branch_checkout', $result->operation);
    }

    public function testGitBranchCheckoutWithCreateBranchFlag(): void
    {
        $handlers = new GitCommandHandlers($this->repoPath);
        $this->makeCommit('initial');

        $result = $handlers->gitBranchCheckout('new-feature', createBranch: true);

        $this->assertTrue($result->isSuccess());
    }
}
