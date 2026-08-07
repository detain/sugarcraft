<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeIsolationMode;
use SugarCraft\Crush\Agents\WorktreeManager;

/**
 * Tests for WorktreeManager - core create/remove/list operations.
 */
final class WorktreeManagerTest extends TestCase
{
    private string $tmpRoot;
    private string $repoRoot;
    private WorktreeManager $manager;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/sugar-crush-worktree-test-' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);

        // Create a bare git repo to serve as the "main" repository
        $this->repoRoot = $this->tmpRoot . '/repo.git';
        shell_exec("git init --bare " . escapeshellarg($this->repoRoot) . " 2>&1");

        // Clone it so we have a working tree
        $this->repoRoot = $this->tmpRoot . '/repo';
        shell_exec("git clone " . escapeshellarg($this->repoRoot) . " " . escapeshellarg($this->repoRoot) . " 2>&1");

        $config = new WorktreeConfig(
            basePath: $this->tmpRoot . '/worktrees/',
            autoCleanup: true,
            isolationMode: WorktreeIsolationMode::Worktree,
        );

        $this->manager = new WorktreeManager($config, $this->repoRoot);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (isset($this->tmpRoot) && is_dir($this->tmpRoot)) {
            $this->removeDirectory($this->tmpRoot);
        }
    }

    // -------------------------------------------------------------------------
    // createWorktree()
    // -------------------------------------------------------------------------

    public function testCreateWorktreeCreatesDirectory(): void
    {
        $agentId = 'test-agent-' . uniqid('', true);
        $path = $this->manager->createWorktree($agentId);

        $this->assertNotEmpty($path);
        $this->assertTrue(is_dir($path), 'Worktree directory should exist');
        $this->assertStringStartsWith($this->tmpRoot . '/worktrees/', $path);
    }

    public function testCreateWorktreeReturnsAbsolutePath(): void
    {
        $agentId = 'test-agent-' . uniqid('', true);
        $path = $this->manager->createWorktree($agentId);

        $this->assertTrue(str_starts_with($path, '/'), 'Path should be absolute');
    }

    public function testCreateWorktreeWithCustomBranch(): void
    {
        $agentId = 'test-agent-custom-branch';
        $branch = 'my-custom-branch';
        $path = $this->manager->createWorktree($agentId, $branch);

        $this->assertNotEmpty($path);
        $this->assertTrue(is_dir($path));

        // Verify the branch was used in git worktree list
        $output = shell_exec("git -C " . escapeshellarg($this->repoRoot) . " worktree list --porcelain 2>&1");
        $this->assertStringContainsString($branch, $output ?? '');
    }

    public function testCreateWorktreeDefaultsToAgentBranch(): void
    {
        $agentId = 'test-agent-defaults';
        $path = $this->manager->createWorktree($agentId);

        // Branch name should contain agent ID and timestamp
        $worktrees = $this->manager->listWorktrees();
        $this->assertArrayHasKey($agentId, $worktrees);
        $this->assertStringStartsWith('agent-' . $agentId, $worktrees[$agentId]['branch']);
    }

    public function testCreateWorktreeWithEmptyAgentIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent ID must not be empty');

        $this->manager->createWorktree('');
    }

    public function testCreateWorktreeWithPathTraversalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path traversal sequences, slashes, or backslashes');

        $this->manager->createWorktree('../etc/passwd');
    }

    public function testCreateWorktreeWithSlashInAgentIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path traversal sequences, slashes, or backslashes');

        $this->manager->createWorktree('agent/../../../etc');
    }

    public function testCreateWorktreeWithExistingAgentThrows(): void
    {
        $agentId = 'duplicate-agent';

        $this->manager->createWorktree($agentId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->manager->createWorktree($agentId);
    }

    public function testCreateWorktreeReturnsPathEndingWithAgentId(): void
    {
        $agentId = 'path-verification-agent';
        $path = $this->manager->createWorktree($agentId);

        $this->assertStringEndsWith('/' . $agentId, $path);
    }

    // -------------------------------------------------------------------------
    // removeWorktree()
    // -------------------------------------------------------------------------

    public function testRemoveWorktreeDeletesDirectory(): void
    {
        $agentId = 'remove-test-agent';
        $path = $this->manager->createWorktree($agentId);

        $this->assertTrue(is_dir($path));

        $this->manager->removeWorktree($agentId);

        $this->assertFalse(is_dir($path), 'Worktree directory should be removed');
    }

    public function testRemoveWorktreeRemovesFromRegistry(): void
    {
        $agentId = 'registry-cleanup-agent';
        $this->manager->createWorktree($agentId);

        $this->assertArrayHasKey($agentId, $this->manager->listWorktrees());

        $this->manager->removeWorktree($agentId);

        $this->assertArrayNotHasKey($agentId, $this->manager->listWorktrees());
    }

    public function testRemoveWorktreeWithEmptyAgentIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent ID must not be empty');

        $this->manager->removeWorktree('');
    }

    public function testRemoveWorktreeWithNonexistentAgentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->manager->removeWorktree('nonexistent-agent-xyz');
    }

    public function testRemoveWorktreeMultipleTimesThrows(): void
    {
        $agentId = 'double-remove-agent';
        $this->manager->createWorktree($agentId);

        $this->manager->removeWorktree($agentId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->manager->removeWorktree($agentId);
    }

    // -------------------------------------------------------------------------
    // getWorktreePath()
    // -------------------------------------------------------------------------

    public function testGetWorktreePathReturnsCorrectPath(): void
    {
        $agentId = 'path-lookup-agent';
        $createdPath = $this->manager->createWorktree($agentId);

        $retrievedPath = $this->manager->getWorktreePath($agentId);

        $this->assertSame($createdPath, $retrievedPath);
    }

    public function testGetWorktreePathWithNonexistentAgentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->manager->getWorktreePath('nonexistent-agent-abc');
    }

    public function testGetWorktreePathReturnsAbsolutePath(): void
    {
        $agentId = 'absolute-path-agent';
        $this->manager->createWorktree($agentId);

        $path = $this->manager->getWorktreePath($agentId);

        $this->assertTrue(str_starts_with($path, '/'), 'Path should be absolute');
    }

    // -------------------------------------------------------------------------
    // listWorktrees()
    // -------------------------------------------------------------------------

    public function testListWorktreesReturnsEmptyArrayInitially(): void
    {
        $manager = new WorktreeManager(new WorktreeConfig(
            basePath: $this->tmpRoot . '/empty-worktrees/',
            autoCleanup: true,
        ), $this->repoRoot);

        $this->assertSame([], $manager->listWorktrees());
    }

    public function testListWorktreesReturnsAllCreatedWorktrees(): void
    {
        $agent1 = 'list-test-agent-1';
        $agent2 = 'list-test-agent-2';

        $this->manager->createWorktree($agent1);
        $this->manager->createWorktree($agent2);

        $worktrees = $this->manager->listWorktrees();

        $this->assertArrayHasKey($agent1, $worktrees);
        $this->assertArrayHasKey($agent2, $worktrees);
        $this->assertCount(2, $worktrees);
    }

    public function testListWorktreesIncludesBranchAndCreatedAt(): void
    {
        $agentId = 'metadata-test-agent';
        $this->manager->createWorktree($agentId, 'my-branch');

        $worktrees = $this->manager->listWorktrees();

        $this->assertArrayHasKey('branch', $worktrees[$agentId]);
        $this->assertArrayHasKey('createdAt', $worktrees[$agentId]);
        $this->assertSame('my-branch', $worktrees[$agentId]['branch']);
    }

    public function testListWorktreesExcludesOrphanedEntries(): void
    {
        $agentId = 'orphan-agent';
        $this->manager->createWorktree($agentId);

        // Manually remove directory to simulate orphaned worktree
        $path = $this->manager->getWorktreePath($agentId);
        $this->removeDirectory($path);

        $worktrees = $this->manager->listWorktrees();

        $this->assertArrayNotHasKey($agentId, $worktrees);
    }

    public function testListWorktreesReturnsCorrectCount(): void
    {
        $agents = ['count-agent-1', 'count-agent-2', 'count-agent-3'];

        foreach ($agents as $agentId) {
            $this->manager->createWorktree($agentId);
        }

        $worktrees = $this->manager->listWorktrees();

        $this->assertCount(3, $worktrees);
    }

    public function testListWorktreesRegistryIsAccessible(): void
    {
        $agentId = 'registry-access-agent';
        $this->manager->createWorktree($agentId);

        // Registry file should exist
        $registryPath = $this->tmpRoot . '/worktrees/.registry.json';
        $this->assertFileExists($registryPath);

        $content = file_get_contents($registryPath);
        $this->assertNotEmpty($content);

        $data = json_decode($content, true);
        $this->assertArrayHasKey($agentId, $data);
    }

    // -------------------------------------------------------------------------
    // Immutability - each worktree operation is independent
    // -------------------------------------------------------------------------

    public function testMultipleWorktreesAreIndependent(): void
    {
        $agent1 = 'independent-agent-1';
        $agent2 = 'independent-agent-2';

        $path1 = $this->manager->createWorktree($agent1);
        $path2 = $this->manager->createWorktree($agent2);

        $this->assertNotSame($path1, $path2);
        $this->assertStringEndsWith('/' . $agent1, $path1);
        $this->assertStringEndsWith('/' . $agent2, $path2);

        $this->assertTrue(is_dir($path1));
        $this->assertTrue(is_dir($path2));

        // Remove one and verify the other still exists
        $this->manager->removeWorktree($agent1);

        $this->assertFalse(is_dir($path1));
        $this->assertTrue(is_dir($path2));
    }

    public function testIsolatePath(): void
    {
        $agent1 = 'isolate-agent-1';
        $agent2 = 'isolate-agent-2';

        $path1 = $this->manager->createWorktree($agent1);
        $path2 = $this->manager->createWorktree($agent2);

        // Paths must not overlap — each worktree gets its own directory
        $this->assertNotSame($path1, $path2);
        $this->assertStringEndsWith('/' . $agent1, $path1);
        $this->assertStringEndsWith('/' . $agent2, $path2);

        // Each path resolves to a distinct, real directory
        $this->assertTrue(is_dir($path1));
        $this->assertTrue(is_dir($path2));

        // Operations on one worktree must not affect the other
        $this->manager->removeWorktree($agent1);

        // agent2's worktree still exists and is accessible
        $this->assertFalse(is_dir($path1));
        $this->assertTrue(is_dir($path2));
        $this->assertTrue($this->manager->getWorktreePath($agent2) === $path2);

        // Registry reflects only agent2 remaining
        $worktrees = $this->manager->listWorktrees();
        $this->assertArrayNotHasKey($agent1, $worktrees);
        $this->assertArrayHasKey($agent2, $worktrees);
        $this->assertCount(1, $worktrees);
    }

    // -------------------------------------------------------------------------
    // cleanupStaleWorktrees()
    // -------------------------------------------------------------------------

    public function testCleanupStaleWorktreesReturnsZeroWhenNothingToClean(): void
    {
        $manager = new WorktreeManager(new WorktreeConfig(
            basePath: $this->tmpRoot . '/empty-cleanup/',
            autoCleanup: true,
        ), $this->repoRoot);

        $removed = $manager->cleanupStaleWorktrees(7);

        $this->assertSame(0, $removed);
    }

    /**
     * @group P3.S3
     */
    public function testCleanupStaleWorktreesPreservesNamedWorktrees(): void
    {
        $agentId = 'named-stale-agent';

        $this->manager->createWorktree($agentId);
        $this->manager->markWorktreeNamed($agentId);

        $path = $this->manager->getWorktreePath($agentId);
        $this->assertTrue(is_dir($path));

        // Backdate the worktree's createdAt to make it stale
        $cutoff = (new \DateTimeImmutable())->modify('-8 days')->format(\DateTimeImmutable::ATOM);
        $this->manager = new WorktreeManager(new WorktreeConfig(
            basePath: $this->tmpRoot . '/worktrees/',
            autoCleanup: true,
        ), $this->repoRoot);

        $reflection = new \ReflectionClass($this->manager);
        $registryProp = $reflection->getProperty('registry');
        $registryProp->setAccessible(true);
        $registry = $registryProp->getValue($this->manager);
        $registry[$agentId]['createdAt'] = $cutoff;
        $registry[$agentId]['named'] = true;
        $registryProp->setValue($this->manager, $registry);

        // Cleanup should not remove named worktree
        $removed = $this->manager->cleanupStaleWorktrees(7);

        $this->assertSame(0, $removed, 'Named worktree should not be removed');
        $this->assertTrue(is_dir($path), 'Named worktree directory should still exist');
        $this->assertArrayHasKey($agentId, $this->manager->listWorktrees());
    }

    /**
     * @group P3.S3
     */
    public function testCleanupStaleWorktreesRemovesOldUnnamedCleanWorktree(): void
    {
        $agentId = 'unnamed-clean-stale-agent';

        $this->manager->createWorktree($agentId);

        $path = $this->manager->getWorktreePath($agentId);
        $this->assertTrue(is_dir($path));

        // Backdate the worktree's createdAt to make it stale (but leave it clean)
        $cutoff = (new \DateTimeImmutable())->modify('-8 days')->format(\DateTimeImmutable::ATOM);

        $reflection = new \ReflectionClass($this->manager);
        $registryProp = $reflection->getProperty('registry');
        $registryProp->setAccessible(true);
        $registry = $registryProp->getValue($this->manager);
        $registry[$agentId]['createdAt'] = $cutoff;
        $registry[$agentId]['named'] = false;
        $registryProp->setValue($this->manager, $registry);

        $removed = $this->manager->cleanupStaleWorktrees(7);

        $this->assertSame(1, $removed, 'Old unnamed clean worktree should be removed');
        $this->assertFalse(is_dir($path), 'Worktree directory should be gone');
        $this->assertArrayNotHasKey($agentId, $this->manager->listWorktrees());
    }

    /**
     * @group P3.S3
     */
    public function testCleanupStaleWorktreesPreservesOldUnnamedDirtyWorktree(): void
    {
        $agentId = 'unnamed-dirty-stale-agent';

        $path = $this->manager->createWorktree($agentId);

        // Make the worktree dirty by adding an uncommitted file
        file_put_contents($path . '/DIRTY_MARKER.txt', 'uncommitted content');

        // Also backdate so it's stale
        $cutoff = (new \DateTimeImmutable())->modify('-8 days')->format(\DateTimeImmutable::ATOM);

        $reflection = new \ReflectionClass($this->manager);
        $registryProp = $reflection->getProperty('registry');
        $registryProp->setAccessible(true);
        $registry = $registryProp->getValue($this->manager);
        $registry[$agentId]['createdAt'] = $cutoff;
        $registry[$agentId]['named'] = false;
        $registryProp->setValue($this->manager, $registry);

        $removed = $this->manager->cleanupStaleWorktrees(7);

        // Dirty worktree must not be removed
        $this->assertSame(0, $removed, 'Dirty worktree should be preserved');
        $this->assertTrue(is_dir($path), 'Worktree directory should still exist');
        $this->assertFileExists($path . '/DIRTY_MARKER.txt');
        $this->assertArrayHasKey($agentId, $this->manager->listWorktrees());
    }

    // -------------------------------------------------------------------------
    // worktreeHasUncommittedDiff()
    // -------------------------------------------------------------------------

    /**
     * @group P3.S3
     */
    public function testWorktreeHasUncommittedDiffReturnsFalseForCleanWorktree(): void
    {
        $agentId = 'clean-diff-agent';
        $path = $this->manager->createWorktree($agentId);

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('worktreeHasUncommittedDiff');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($this->manager, $path));
    }

    /**
     * @group P3.S3
     */
    public function testWorktreeHasUncommittedDiffReturnsTrueForDirtyWorktree(): void
    {
        $agentId = 'dirty-diff-agent';
        $path = $this->manager->createWorktree($agentId);

        file_put_contents($path . '/NEW_FILE.txt', 'new content');

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('worktreeHasUncommittedDiff');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->manager, $path));
    }

    // -------------------------------------------------------------------------
    // resolveWorktreeInclude()
    // -------------------------------------------------------------------------

    /**
     * @group P3.S3
     */
    public function testResolveWorktreeIncludeCopiesMatchingFiles(): void
    {
        // Set up .worktreeinclude and source files BEFORE creating the worktree
        $includeFile = $this->repoRoot . '/.worktreeinclude';
        file_put_contents($includeFile, ".env.example\nsubdir/\n");
        file_put_contents($this->repoRoot . '/.env.example', 'TEST=value');

        // Create a subdir with a file to test recursive copy
        mkdir($this->repoRoot . '/subdir', 0755);
        file_put_contents($this->repoRoot . '/subdir/nested.txt', 'nested content');

        // Create a worktree — resolveWorktreeInclude is called separately after creation (P3.S3 pattern)
        $agentId = 'include-test-agent';
        $config = new WorktreeConfig(
            basePath: $this->tmpRoot . '/worktrees/',
            autoCleanup: true,
            worktreeIncludeFile: '.worktreeinclude',
        );
        $manager = new WorktreeManager($config, $this->repoRoot);
        $path = $manager->createWorktree($agentId);

        // Manually resolve .worktreeinclude files after worktree creation (P3.S3 scope)
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('resolveWorktreeInclude');
        $method->setAccessible(true);
        $method->invoke($manager, $path);

        // .env.example should have been copied into the worktree
        $this->assertFileExists($path . '/.env.example');
        $this->assertSame('TEST=value', file_get_contents($path . '/.env.example'));

        // subdir should have been copied recursively
        $this->assertFileExists($path . '/subdir/nested.txt');
        $this->assertSame('nested content', file_get_contents($path . '/subdir/nested.txt'));
    }

    /**
     * @group P3.S3
     */
    public function testResolveWorktreeIncludeHandlesNonexistentIncludeFile(): void
    {
        $agentId = 'no-include-agent';
        $config = new WorktreeConfig(
            basePath: $this->tmpRoot . '/worktrees/',
            autoCleanup: true,
            worktreeIncludeFile: '.nonexistent-include-file',
        );
        $manager = new WorktreeManager($config, $this->repoRoot);
        $path = $manager->createWorktree($agentId);

        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('resolveWorktreeInclude');
        $method->setAccessible(true);

        // Should not throw even if file doesn't exist
        $method->invoke($manager, $path);

        $this->assertTrue(is_dir($path));
    }

    // -------------------------------------------------------------------------
    // markWorktreeNamed() - P3.S3
    // -------------------------------------------------------------------------

    /**
     * @group P3.S3
     */
    public function testCreateWorktreeWithNamedFlagStoresNamedState(): void
    {
        $agentId = 'named-state-agent';

        $path = $this->manager->createWorktree($agentId);
        $this->manager->markWorktreeNamed($agentId);

        $this->assertNotEmpty($path);
        $this->assertTrue(is_dir($path));

        $worktrees = $this->manager->listWorktrees();
        $this->assertArrayHasKey($agentId, $worktrees);
        $this->assertTrue($worktrees[$agentId]['named'] ?? false);
    }

    /**
     * @group P3.S3
     */
    public function testCreateWorktreeWithoutNamedFlagStoresUnnamedState(): void
    {
        $agentId = 'unnamed-state-agent';

        $path = $this->manager->createWorktree($agentId);

        $this->assertNotEmpty($path);
        $this->assertTrue(is_dir($path));

        $worktrees = $this->manager->listWorktrees();
        $this->assertArrayHasKey($agentId, $worktrees);
        $this->assertFalse($worktrees[$agentId]['named'] ?? true);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path), ['.', '..']);
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
}
