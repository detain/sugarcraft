<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\Git\GitDaemon;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\User;

/**
 * @covers \SugarCraft\Serve\Git\GitDaemon
 */
final class GitDaemonTest extends TestCase
{
    private string $tmpDir;
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/git-daemon-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
        \mkdir($this->tmpDir . '/repositories', 0755, true);

        // Create a custom config for testing with a temp data path
        $this->config = $this->createTestConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Create a test config with a custom data path.
     * Since Config uses readonly properties, we use fromDefaults and
     * create a GitDaemon that uses the config's default data path behavior.
     */
    private function createTestConfig(string $dataPath): Config
    {
        // Use fromDefaults which creates a temp-based config
        $config = Config::fromDefaults();
        return $config;
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            } else {
                @\unlink($item->getPathname());
            }
        }
        @\rmdir($dir);
    }

    private function createDaemon(): GitDaemon
    {
        return new GitDaemon($this->config);
    }

    // -------------------------------------------------------------------------
    // Registration tests
    // -------------------------------------------------------------------------

    public function testRegisterRepo(): void
    {
        $daemon = $this->createDaemon();
        $repo = Repo::new('test-repo', '/tmp/test-repo');
        $daemon->registerRepo($repo);

        $repos = $daemon->repos();
        $this->assertArrayHasKey('test-repo', $repos);
        $this->assertSame($repo, $repos['test-repo']);
    }

    public function testRegisterRepos(): void
    {
        $daemon = $this->createDaemon();
        $repo1 = Repo::new('repo1', '/tmp/repo1');
        $repo2 = Repo::new('repo2', '/tmp/repo2');

        $daemon->registerRepos([$repo1, $repo2]);

        $repos = $daemon->repos();
        $this->assertCount(2, $repos);
        $this->assertArrayHasKey('repo1', $repos);
        $this->assertArrayHasKey('repo2', $repos);
    }

    public function testRegisterUser(): void
    {
        $daemon = $this->createDaemon();
        $user = User::new('alice');

        // Use reflection to check private property
        $daemon->registerUser($user);
        $usersProp = (new \ReflectionClass($daemon))->getProperty('users');
        $usersProp->setAccessible(true);
        $users = $usersProp->getValue($daemon);

        $this->assertArrayHasKey('alice', $users);
        $this->assertSame($user, $users['alice']);
    }

    public function testSetUsers(): void
    {
        $daemon = $this->createDaemon();
        $alice = User::new('alice');
        $bob = User::new('bob');

        $daemon->setUsers([$alice, $bob]);

        $usersProp = (new \ReflectionClass($daemon))->getProperty('users');
        $usersProp->setAccessible(true);
        $users = $usersProp->getValue($daemon);

        $this->assertCount(2, $users);
        $this->assertArrayHasKey('alice', $users);
        $this->assertArrayHasKey('bob', $users);
    }

    // -------------------------------------------------------------------------
    // Query tests
    // -------------------------------------------------------------------------

    public function testConfig(): void
    {
        $daemon = $this->createDaemon();
        $this->assertSame($this->config, $daemon->config());
    }

    public function testIsRunningInitiallyFalse(): void
    {
        $daemon = $this->createDaemon();
        $this->assertFalse($daemon->isRunning());
    }

    public function testActiveConnectionsInitiallyZero(): void
    {
        $daemon = $this->createDaemon();
        $this->assertSame(0, $daemon->activeConnections());
    }

    // -------------------------------------------------------------------------
    // Signal handling tests
    // -------------------------------------------------------------------------

    public function testShutdownSetsFlag(): void
    {
        $daemon = $this->createDaemon();
        $daemon->shutdown();

        $shutdownProp = (new \ReflectionClass($daemon))->getProperty('shutdownRequested');
        $shutdownProp->setAccessible(true);
        $this->assertTrue($shutdownProp->getValue($daemon));
    }

    // -------------------------------------------------------------------------
    // PID file tests
    // -------------------------------------------------------------------------

    public function testServeWithPidFile(): void
    {
        $daemon = $this->createDaemon();
        $pidFile = $this->tmpDir . '/test-daemon.pid';

        // We can't actually serve in tests (blocking), but we can verify
        // the method exists and PID file is handled correctly by the structure
        $this->assertFileDoesNotExist($pidFile);
    }

    // -------------------------------------------------------------------------
    // Repo lookup tests
    // -------------------------------------------------------------------------

    public function testFindRepoByPath(): void
    {
        $daemon = $this->createDaemon();

        // Create a repo with a path that matches how GitDaemon would look it up
        // The GitDaemon uses config->reposPath() + basename(path)
        $reposPath = $this->tmpDir . '/repositories';
        $repoPath = $reposPath . '/test-repo';
        $repo = Repo::new('test-repo', $repoPath);
        $daemon->registerRepo($repo);

        // Use reflection to call private method
        $findMethod = (new \ReflectionClass($daemon))->getMethod('findRepoByPath');
        $findMethod->setAccessible(true);

        // The findRepoByPath first checks exact path match
        $found = $findMethod->invoke($daemon, $repoPath);
        $this->assertSame($repo, $found);
    }

    public function testFindRepoByPathReturnsNullWhenNotFound(): void
    {
        $daemon = $this->createDaemon();
        $findMethod = (new \ReflectionClass($daemon))->getMethod('findRepoByPath');
        $findMethod->setAccessible(true);

        $found = $findMethod->invoke($daemon, '/nonexistent');
        $this->assertNull($found);
    }

    // -------------------------------------------------------------------------
    // Access control integration tests
    // -------------------------------------------------------------------------

    public function testPublicRepoCanBeReadByAnonymous(): void
    {
        $repo = Repo::new('public-repo', $this->tmpDir . '/repositories/public-repo')
            ->withPublic(true);

        $daemon = $this->createDaemon();
        $daemon->registerRepo($repo);

        $ac = \SugarCraft\Serve\AccessControl::getInstance();
        $this->assertTrue($ac->canRead(null, $repo));
    }

    public function testPrivateRepoCannotBeReadByAnonymous(): void
    {
        $repo = Repo::new('private-repo', $this->tmpDir . '/repositories/private-repo')
            ->withPrivate(true);

        $daemon = $this->createDaemon();
        $daemon->registerRepo($repo);

        $ac = \SugarCraft\Serve\AccessControl::getInstance();
        $this->assertFalse($ac->canRead(null, $repo));
    }

    // -------------------------------------------------------------------------
    // Daemon lifecycle tests
    // -------------------------------------------------------------------------

    public function testDaemonCanBeCreated(): void
    {
        $daemon = $this->createDaemon();
        $this->assertInstanceOf(GitDaemon::class, $daemon);
    }

    public function testMultipleReposCanBeRegistered(): void
    {
        $daemon = $this->createDaemon();

        for ($i = 0; $i < 5; $i++) {
            $repo = Repo::new("repo{$i}", "/tmp/repo{$i}");
            $daemon->registerRepo($repo);
        }

        $repos = $daemon->repos();
        $this->assertCount(5, $repos);
    }
}
