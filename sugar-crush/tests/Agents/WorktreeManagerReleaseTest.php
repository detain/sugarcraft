<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeManager;
use SugarCraft\Crush\Workspace\GitRunner;

/**
 * Roadmap 4.9, the manager's half of isolated runs: a tree is released only
 * when it holds no work (removed with its branch), kept when it holds
 * uncommitted changes or commits of its own, and never when it is named; the
 * registry merges concurrent writers instead of letting the last one win; a
 * relative base is the repository's, not the process's working directory.
 */
final class WorktreeManagerReleaseTest extends TestCase
{
    private string $root;

    private string $repo;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('needs a git executable');
        }
        foreach (['SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'] as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
        $this->root = sys_get_temp_dir() . '/sc-wt-rel-' . bin2hex(random_bytes(5));
        $this->repo = $this->root . '/repo';
        mkdir($this->repo, 0o700, true);
        $git = GitRunner::new($this->repo);
        self::assertTrue($git->run('init', '-q', '-b', 'main')['ok']);
        file_put_contents($this->repo . '/a.txt', "one\n");
        self::assertTrue($git->run('add', 'a.txt')['ok']);
        self::assertTrue($git->run('commit', '-q', '-m', 'initial')['ok']);
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        if (isset($this->root) && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root) . ' 2>&1');
        }
    }

    public function testAnUnusedTreeIsReleasedWithItsBranch(): void
    {
        $manager = WorktreeManager::new($this->repo);
        $path = $manager->createWorktree('idle');
        $branch = $manager->branchOf('idle');
        $this->assertNotNull($branch);

        $this->assertFalse($manager->hasWork('idle'));
        $this->assertTrue($manager->releaseIfUnused('idle'));

        $this->assertDirectoryDoesNotExist($path);
        $this->assertNull($manager->branchOf('idle'));
        $this->assertSame('', trim(GitRunner::new($this->repo)->run('branch', '--list', $branch)['stdout']));
    }

    public function testATreeWithUncommittedChangesIsKept(): void
    {
        $manager = WorktreeManager::new($this->repo);
        $path = $manager->createWorktree('dirty');
        file_put_contents($path . '/new.txt', "x\n");

        $this->assertTrue($manager->hasWork('dirty'));
        $this->assertFalse($manager->releaseIfUnused('dirty'));
        $this->assertFileExists($path . '/new.txt');
    }

    public function testATreeWhoseBranchHasItsOwnCommitsIsKept(): void
    {
        $manager = WorktreeManager::new($this->repo);
        $path = $manager->createWorktree('committed');
        file_put_contents($path . '/b.txt', "b\n");
        $git = GitRunner::new($path);
        $this->assertTrue($git->run('add', 'b.txt')['ok']);
        $this->assertTrue($git->run('commit', '-q', '-m', 'agent work')['ok']);

        $this->assertFalse($manager->worktreeHasUncommittedDiff($path), 'clean status');
        $this->assertTrue($manager->hasWork('committed'), 'but a commit past its start');
        $this->assertFalse($manager->releaseIfUnused('committed'));
        $this->assertDirectoryExists($path);
    }

    public function testANamedTreeIsNeverReleased(): void
    {
        $manager = WorktreeManager::new($this->repo);
        $path = $manager->createWorktree('session');
        $manager->markWorktreeNamed('session');

        $this->assertFalse($manager->releaseIfUnused('session'));
        $this->assertDirectoryExists($path);
    }

    public function testTwoManagersLoadedTogetherKeepEachOthersEntries(): void
    {
        // Two forked members hold the registry as it stood when they loaded.
        $first = WorktreeManager::new($this->repo);
        $second = WorktreeManager::new($this->repo);

        $first->createWorktree('member-a');
        $second->createWorktree('member-b');

        $this->assertSame(['member-a', 'member-b'], array_keys(WorktreeManager::new($this->repo)->listWorktrees()));

        $first->removeWorktree('member-a');
        $this->assertSame(['member-b'], array_keys(WorktreeManager::new($this->repo)->listWorktrees()), 'a removal drops only its own entry');
    }

    public function testARelativeBaseIsTheRepositorysNotTheWorkingDirectorys(): void
    {
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere);
        $cwd = getcwd();
        chdir($elsewhere);
        try {
            $path = WorktreeManager::new($this->repo)->createWorktree('anchored');
        } finally {
            chdir((string) $cwd);
        }

        $this->assertStringStartsWith($this->repo . '/.sugar-crush/worktrees/', $path);
        $this->assertDirectoryExists($path);
        $this->assertDirectoryDoesNotExist($elsewhere . '/.sugar-crush');
        $this->assertFileExists($this->repo . '/.sugar-crush/worktrees/.gitignore');
    }
}
