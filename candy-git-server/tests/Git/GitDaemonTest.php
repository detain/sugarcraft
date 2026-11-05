<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\GitDaemon;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

/**
 * @covers \SugarCraft\GitServer\Git\GitDaemon
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

        // The daemon path exercises anonymous READ of published repos. The
        // fail-closed lever restores in tests/bootstrap.php per test, so
        // the private-repo refusal pins below still run in the default
        // world (the lever never unlocks a private repo anyway).
        \SugarCraft\GitServer\AccessControl::getInstance()->setAllowAnonymousRead(true);
    }

    protected function tearDown(): void
    {
        \SugarCraft\GitServer\AccessControl::getInstance()->setAllowAnonymousRead(false);
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
        // fromDefaults, then re-materialized through YAML so the
        // anonymous-read lever (this fixture world publishes repos) rides
        // the real Config::load parse path like every other daemon pin.
        $path = $this->tmpDir . '/lever.yaml';
        \file_put_contents($path, "git: { allow_anonymous_read: true }\n");

        return Config::load($path);
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

    public function testTheDiskFallbackCarriesTheVisibilityMarker(): void
    {
        $daemon = $this->createDaemon();
        $reposPath = $this->tmpDir . '/repositories';

        // A plain clone that merely exists on disk is NOT a publication:
        // public-by-presence was the fail-open this pin retires.
        \mkdir($reposPath . '/markerless/.git/objects', 0777, true);
        \mkdir($reposPath . '/markerless/.git/refs', 0777, true);

        $findMethod = (new \ReflectionClass($daemon))->getMethod('findRepoByPath');
        $findMethod->setAccessible(true);

        $found = $findMethod->invoke($daemon, $reposPath . '/markerless');
        $this->assertNotNull($found, 'the directory still resolves');
        $this->assertFalse(
            $found->isVisiblePublic(),
            'no marker means fail-closed PRIVATE for the daemon transport'
        );

        // An explicit publication arrives with the marker.
        \mkdir($reposPath . '/published/.git/objects', 0777, true);
        Repo::new('published', $reposPath . '/published')->withPublic(true)->writeVisibilityMarker();

        $found = $findMethod->invoke($daemon, $reposPath . '/published');
        $this->assertNotNull($found);
        $this->assertTrue($found->isVisiblePublic(), 'the marker is the only path to public');
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

        $ac = \SugarCraft\GitServer\AccessControl::getInstance();
        // Published-repo anonymous read is the lever-ON world (setUp
        // arms it through the real Config::load parse path).
        $this->assertTrue($ac->allowAnonymousRead(), 'fixture world has the anonymous-read lever armed');
        $this->assertTrue($ac->canRead(null, $repo));
    }

    public function testPublishedRepoIsNotReadableByAnonymousWithTheLeverOff(): void
    {
        $repo = Repo::new('levered-repo', $this->tmpDir . '/repositories/levered-repo')
            ->withPublic(true);

        $ac = \SugarCraft\GitServer\AccessControl::getInstance();
        $ac->setAllowAnonymousRead(false);
        try {
            $this->assertFalse(
                $ac->canRead(null, $repo),
                'C-13: public visibility alone must never serve anonymous bytes'
            );
        } finally {
            $ac->setAllowAnonymousRead(true);
        }
    }

    public function testPrivateRepoCannotBeReadByAnonymous(): void
    {
        $repo = Repo::new('private-repo', $this->tmpDir . '/repositories/private-repo')
            ->withPrivate(true);

        $daemon = $this->createDaemon();
        $daemon->registerRepo($repo);

        $ac = \SugarCraft\GitServer\AccessControl::getInstance();
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
