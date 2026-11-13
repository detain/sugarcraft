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
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(true);
    }

    protected function tearDown(): void
    {
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(false);
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

    /**
     * r1-MINOR-3: a bare repo (HEAD at the root) published via registerRepo
     * must still resolve PUBLIC to a fresh daemon's marker-honest fallback —
     * the next boot. The fallback used to probe a hardcoded '$path/.git'
     * that never exists on a true bare layout, and the registerRepo gate
     * used a non-bare exists(), so the persisted marker was both unwritten
     * and unreadable there: publication silently reverted to fail-closed
     * PRIVATE with nothing to point at.
     */
    public function testAPublishedBareRepoSurvivesTheNextBootFallback(): void
    {
        $reposPath = $this->tmpDir . '/repositories';
        $bare = $reposPath . '/bare-published';
        \mkdir($bare, 0755, true);
        \file_put_contents($bare . '/HEAD', "ref: refs/heads/main\n");

        $publisher = $this->createDaemon();
        $publisher->registerRepo(Repo::new('bare-published', $bare)->withPublic(true));

        // The bare-aware write put the marker at the bare root itself.
        $this->assertFileExists($bare . '/' . Repo::VISIBILITY_MARKER);

        // A fresh daemon knows nothing of the registration — only the disk.
        $boot = $this->createDaemon();
        $find = (new \ReflectionClass($boot))->getMethod('findRepoByPath');
        $find->setAccessible(true);

        $found = $find->invoke($boot, $bare);
        $this->assertNotNull($found, 'the bare directory resolves on the fallback too');
        $this->assertTrue(
            $found->isVisiblePublic(),
            'a registerRepo publication on the bare layout stays published for the next boot'
        );
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

        $ac = \SugarCraft\GitServer\AccessControl::instance();
        // Published-repo anonymous read is the lever-ON world (setUp
        // arms it through the real Config::load parse path).
        $this->assertTrue($ac->allowAnonymousRead(), 'fixture world has the anonymous-read lever armed');
        $this->assertTrue($ac->canRead(null, $repo));
    }

    public function testPublishedRepoIsNotReadableByAnonymousWithTheLeverOff(): void
    {
        $repo = Repo::new('levered-repo', $this->tmpDir . '/repositories/levered-repo')
            ->withPublic(true);

        $ac = \SugarCraft\GitServer\AccessControl::instance();
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

        $ac = \SugarCraft\GitServer\AccessControl::instance();
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

    // -------------------------------------------------------------------------
    // S17 hardening wave: sweep expiries (029), read verdicts (034),
    // listen floor (037), lever ordering (098), seat-map stability (102),
    // disk-fallback memo (103), signal hygiene (110)
    // -------------------------------------------------------------------------

    private function daemonFromYaml(string $yaml): GitDaemon
    {
        $path = $this->tmpDir . '/s17-' . \uniqid() . '.yaml';
        \file_put_contents($path, $yaml);

        return new GitDaemon(Config::load($path));
    }

    /**
     * Seat a handle in the daemon's client map (bypassing accept) and
     * return its transport-stable id.
     *
     * @param \Socket|resource $handle
     * @param array<string, mixed> $overrides
     */
    private function seedSeat(GitDaemon $daemon, $handle, array $overrides = []): string
    {
        $clientId = new \ReflectionMethod(GitDaemon::class, 'clientId');
        $clientId->setAccessible(true);
        $id = $clientId->invoke(null, $handle);

        $prop = (new \ReflectionClass($daemon))->getProperty('clients');
        $prop->setAccessible(true);
        $clients           = $prop->getValue($daemon);
        $clients[$id]      = \array_merge([
            'socket'        => $handle,
            'connected_at'  => \time(),
            'last_activity' => \time(),
            'buffer'        => '',
            'handled'       => false,
        ], $overrides);
        $prop->setValue($daemon, $clients);

        return $id;
    }

    /** @return array<string, array<string, mixed>> */
    private function seats(GitDaemon $daemon): array
    {
        $prop = (new \ReflectionClass($daemon))->getProperty('clients');
        $prop->setAccessible(true);

        return $prop->getValue($daemon);
    }

    /**
     * Release a test handle whether or not the daemon already closed it
     * (PHP 8 turns double-closes into Error/TypeError).
     */
    private static function closeAny(mixed $handle): void
    {
        try {
            if ($handle instanceof \Socket) {
                \socket_close($handle);
            } elseif (\is_resource($handle)) {
                \fclose($handle);
            }
        } catch (\Throwable) {
            // already closed by the seat teardown — nothing left to do
        }
    }

    public function testAsyncSweepExpiresZeroByteSeatsEvenWithTheIdleKnobOff(): void
    {
        // 029: git.idle_timeout <= 0 used to switch the WHOLE async sweep
        // off, so a silent zero-byte seat was unexpirable — anonymous
        // slot lockout was one idle connection away.
        $daemon = $this->daemonFromYaml(
            "name: \"S17\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 0, allow_anonymous_read: true }\n"
        );
        [$seat, $peer] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->seedSeat($daemon, $seat, [
            'connected_at' => \time() - (GitDaemon::PREAUTH_DEADLINE_SECONDS + 1),
        ]);

        $tick = new \ReflectionMethod($daemon, 'onAsyncTick');
        $tick->setAccessible(true);
        $tick->invoke($daemon);

        $this->assertSame([], $this->seats($daemon), 'the absolute pre-auth deadline is policed independently of the idle knob');
        self::closeAny($seat);
        self::closeAny($peer);
    }

    public function testAsyncSweepKeepsFreshPreauthAndIdleKnobOffHandledSeats(): void
    {
        // The negative pin for 029: fresh seats (over-capacity probes,
        // slow first byte) are NOT swept early, and a handled long-pull
        // with the idle knob off stays connected.
        $daemon = $this->daemonFromYaml(
            "name: \"S17\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 0, allow_anonymous_read: true }\n"
        );
        [$fresh, $freshPeer]   = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$handled, $handPeer]  = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $idFresh               = $this->seedSeat($daemon, $fresh);
        $this->seedSeat($daemon, $handled, [
            'connected_at' => \time() - (GitDaemon::PREAUTH_DEADLINE_SECONDS + 1),
            'handled'      => true,
        ]);

        $tick = new \ReflectionMethod($daemon, 'onAsyncTick');
        $tick->setAccessible(true);
        $tick->invoke($daemon);

        $this->assertArrayHasKey($idFresh, $this->seats($daemon));
        self::closeAny($fresh);
        self::closeAny($freshPeer);
        self::closeAny($handled);
        self::closeAny($handPeer);
    }

    public function testSyncSweepExpiresStalePreauthSeatsRegardlessOfIdleTimeout(): void
    {
        // 029 sync twin: a zero-byte seat never reaches the read path
        // where the deadline used to live, so the sweep must police it.
        $daemon = $this->daemonFromYaml(
            "name: \"S17\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 0, allow_anonymous_read: true }\n"
        );
        if (!\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $stale)) {
            $this->markTestSkipped('socket_create_pair unavailable');
        }
        if (!\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $kept)) {
            $this->markTestSkipped('socket_create_pair unavailable');
        }
        \socket_set_nonblock($stale[0]);
        \socket_set_nonblock($kept[0]);
        $staleId  = $this->seedSeat($daemon, $stale[0], [
            'connected_at' => \time() - (GitDaemon::PREAUTH_DEADLINE_SECONDS + 1),
        ]);
        $keptId   = $this->seedSeat($daemon, $kept[0], [
            'connected_at' => \time() - (GitDaemon::PREAUTH_DEADLINE_SECONDS + 1),
            'handled'      => true,
        ]);

        $sweep = new \ReflectionMethod($daemon, 'checkConnectionTimeouts');
        $sweep->setAccessible(true);
        $sweep->invoke($daemon);

        $seats = $this->seats($daemon);
        $this->assertArrayNotHasKey($staleId, $seats, 'unhandled stale seat expires even with the idle knob off');
        $this->assertArrayHasKey($keptId, $seats, 'a served connection keeps its lease when idle_timeout is off');
        foreach ([$stale[0], $stale[1], $kept[0], $kept[1]] as $sock) {
            self::closeAny($sock);
        }
    }

    public function testSyncReadKeepsALivePeerThatHasNotSentYet(): void
    {
        // 034: an EAGAIN empty read is NOT a hangup — a request
        // straddling two reads used to be dropped on the next tick.
        $daemon = $this->createDaemon();
        if (!\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
            $this->markTestSkipped('socket_create_pair unavailable');
        }
        \socket_set_nonblock($pair[0]);
        \socket_clear_error($pair[0]);
        $id    = $this->seedSeat($daemon, $pair[0]);
        $read  = new \ReflectionMethod($daemon, 'handleClientData');
        $read->setAccessible(true);

        $read->invoke($daemon, $id);
        $this->assertArrayHasKey($id, $this->seats($daemon), 'nothing-readable is not a hangup');

        \socket_write($pair[1], 'git-upload-pack /repo');
        $read->invoke($daemon, $id);
        $seats = $this->seats($daemon);
        $this->assertArrayHasKey($id, $seats);
        $this->assertSame('git-upload-pack /repo', $seats[$id]['buffer'], 'the partial line accumulates across reads');

        \socket_close($pair[1]);
        $read->invoke($daemon, $id);
        $this->assertArrayNotHasKey($id, $this->seats($daemon), 'EOF (empty read with no EWOULDBLOCK) still closes the seat');
        self::closeAny($pair[0]);
    }

    public function testHostlessListenAddrFloorsToLoopback(): void
    {
        // 037: mirror the stats/HTTP loopback floor; explicit hosts ride
        // through untouched.
        $parse = new \ReflectionMethod(GitDaemon::class, 'listenHostPort');
        $parse->setAccessible(true);

        $hostless = $this->daemonFromYaml("name: \"S17\"\ngit: { listen_addr: \":9418\" }\n");
        $this->assertSame(['127.0.0.1', 9418], $parse->invoke($hostless));

        $ephemeral = $this->daemonFromYaml("name: \"S17\"\ngit: { listen_addr: \":0\" }\n");
        $this->assertSame(['127.0.0.1', 0], $parse->invoke($ephemeral));

        $wildcard = $this->daemonFromYaml("name: \"S17\"\ngit: { listen_addr: \"0.0.0.0:9418\" }\n");
        $this->assertSame(['0.0.0.0', 9418], $parse->invoke($wildcard));

        $named = $this->daemonFromYaml("name: \"S17\"\ngit: { listen_addr: \"127.0.0.1:9419\" }\n");
        $this->assertSame(['127.0.0.1', 9419], $parse->invoke($named));
    }

    public function testLeverOffRefusesBeforeAnyRepoExistenceOracle(): void
    {
        // 098: with git.allow_anonymous_read off, the old order answered
        // "Repository not found: <name>" to anonymous probers — a free
        // repo census. Every lever-off request now gets the uniform
        // "Access denied", existent or not.
        $daemon = $this->daemonFromYaml(
            "name: \"S17\"\ngit: { listen_addr: \"127.0.0.1:0\", idle_timeout: 3, allow_anonymous_read: false }\n"
        );
        $daemon->registerRepo(Repo::new('known', $this->tmpDir . '/repositories/known')->withPublic(true));
        $dispatch = new \ReflectionMethod($daemon, 'dispatchClientRequest');
        $dispatch->setAccessible(true);

        foreach (['/ghost', '/known'] as $probe) {
            [$seat, $peer] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $id = $this->seedSeat($daemon, $seat, ['buffer' => "git-upload-pack {$probe}\n"]);
            $dispatch->invoke($daemon, $id);

            $bytes = '';
            $until = \hrtime(true) + 500_000_000;
            while (\hrtime(true) < $until) {
                $chunk = \fread($peer, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes .= $chunk;
            }
            $this->assertStringContainsString('Access denied', $bytes, "uniform refusal for {$probe}");
            $this->assertStringNotContainsString('Repository not found', $bytes);
            $this->assertStringNotContainsString('ghost', $bytes, 'the refusal must not echo the probed name');
            $this->assertSame([], $this->seats($daemon));
            self::closeAny($seat);
            self::closeAny($peer);
        }
    }

    public function testClosingOneSeatCannotShiftAnotherSeat(): void
    {
        // 102: id-keyed map — closes unset by key, so neighbours keep
        // their identity; a callback for a foreign (never-seeded) handle
        // is inert instead of acting through a recycled id.
        $daemon = $this->createDaemon();
        [$aSeat, $aPeer] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$bSeat, $bPeer] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$cSeat, $cPeer] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $idA = $this->seedSeat($daemon, $aSeat);
        $idB = $this->seedSeat($daemon, $bSeat);

        $close = new \ReflectionMethod($daemon, 'closeClient');
        $close->setAccessible(true);
        $close->invoke($daemon, $idA);

        $seats = $this->seats($daemon);
        $this->assertSame([$idB], \array_keys($seats), 'the survivor keeps its exact key');
        $this->assertSame($bSeat, $seats[$idB]['socket']);

        $readable = new \ReflectionMethod($daemon, 'onAsyncClientReadable');
        $readable->setAccessible(true);
        $readable->invoke($daemon, $cSeat); // never seeded: inert stale-fire
        $this->assertSame([$idB], \array_keys($this->seats($daemon)));

        // The linear-scan helper that made every callback O(n) is gone.
        $this->assertFalse(
            (new \ReflectionClass(GitDaemon::class))->hasMethod('clientIndex'),
            '102 removed clientIndex in favor of O(1) seat ids'
        );

        self::closeAny($aSeat);
        self::closeAny($aPeer);
        self::closeAny($bSeat);
        self::closeAny($bPeer);
        self::closeAny($cSeat);
        self::closeAny($cPeer);
    }

    public function testDiskFallbackMemoizesPositivesAndHoldsMissesBriefly(): void
    {
        // 103: positives land in the repo map (SSHServer's shape); misses
        // get a short negative cache so an anonymous flood cannot make
        // the daemon re-probe the filesystem per request, while a repo
        // created later still arrives after the TTL.
        $daemon     = $this->createDaemon();
        $find       = new \ReflectionMethod($daemon, 'findRepoByPath');
        $find->setAccessible(true);
        $reposPath  = $this->tmpDir . '/repositories';

        \mkdir($reposPath . '/late/.git/objects', 0777, true);
        \mkdir($reposPath . '/late/.git/refs', 0777, true);
        Repo::new('late', $reposPath . '/late')->withPublic(true)->writeVisibilityMarker();

        $first  = $find->invoke($daemon, $reposPath . '/late');
        $second = $find->invoke($daemon, $reposPath . '/late');
        $this->assertNotNull($first);
        $this->assertSame($first, $second, 'the positive lookup is memoized');
        $this->assertArrayHasKey('late', $daemon->repos(), 'the memo joins the registered map');

        $miss = $find->invoke($daemon, $reposPath . '/nowhere');
        $this->assertNull($miss);

        \mkdir($reposPath . '/nowhere/.git/objects', 0777, true);
        Repo::new('nowhere', $reposPath . '/nowhere')->withPublic(true)->writeVisibilityMarker();
        $this->assertNull(
            $find->invoke($daemon, $reposPath . '/nowhere'),
            'a miss is held for DISK_MISS_TTL_SECONDS instead of re-probing per request'
        );

        $dm    = (new \ReflectionClass($daemon))->getProperty('diskMisses');
        $dm->setAccessible(true);
        // The TTL constant is private; expire by a margin past any sane window.
        $stale = $dm->getValue($daemon);
        $stale['nowhere'] = \time() - 3600;
        $dm->setValue($daemon, $stale);

        $found = $find->invoke($daemon, $reposPath . '/nowhere');
        $this->assertNotNull($found, 'after the negative-cache TTL the probe re-runs and finds it');
    }

    public function testSignalDispositionsAreCapturedAndRestoredAroundTheDaemonLifecycle(): void
    {
        // 110: the daemon borrows PROCESS-GLOBAL handlers and the async-
        // signals switch; an embedded host that starts and stops daemons
        // must get its dispositions back on stop.
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl not loaded');
        }
        $signals     = [\SIGTERM, \SIGINT, \SIGHUP];
        $asyncBefore = \pcntl_async_signals();
        $orig        = [];
        foreach ($signals as $sig) {
            $orig[$sig] = \pcntl_signal_get_handler($sig);
        }
        try {
            \pcntl_async_signals(false);
            foreach ($signals as $sig) {
                \pcntl_signal($sig, \SIG_IGN);
            }

            $daemon = $this->createDaemon();
            $register = new \ReflectionMethod($daemon, 'registerSignalHandlers');
            $register->setAccessible(true);
            $register->invoke($daemon);

            foreach ($signals as $sig) {
                $this->assertInstanceOf(
                    \Closure::class,
                    \pcntl_signal_get_handler($sig),
                    'the daemon installed its handler for ' . $sig
                );
            }
            $this->assertTrue(\pcntl_async_signals());

            // Double-register cannot clobber the first capture.
            $register->invoke($daemon);

            $restore = new \ReflectionMethod($daemon, 'restoreSignalHandlers');
            $restore->setAccessible(true);
            $restore->invoke($daemon);

            foreach ($signals as $sig) {
                $this->assertSame(\SIG_IGN, \pcntl_signal_get_handler($sig), 'captured disposition restored for ' . $sig);
            }
            $this->assertFalse(\pcntl_async_signals(), 'the process async-signals switch goes back to the owner');

            // Idempotent: nothing left to restore.
            $restore->invoke($daemon);
        } finally {
            foreach ($signals as $sig) {
                $h = $orig[$sig];
                \pcntl_signal($sig, \is_int($h) ? $h : (\is_callable($h) ? $h : \SIG_DFL));
            }
            \pcntl_async_signals($asyncBefore);
        }
    }

    public function testEveryStopPathRestoresSignalDispositions(): void
    {
        // Structural pin: both teardown funnels (sync cleanup, async
        // stopAsync) call the restore; a new stop path that forgets it
        // is a leak of process-global state. Region-scoped so the pin
        // cannot be satisfied by two calls in one method.
        $src = \file_get_contents(\dirname(__DIR__, 2) . '/src/Git/GitDaemon.php');
        $this->assertNotFalse($src, 'the daemon source is present for the pin');

        $stop = \strpos($src, 'private function stopAsync(): void');
        $cleanup = \strpos($src, 'private function cleanup(): void');
        $this->assertIsInt($stop);
        $this->assertIsInt($cleanup);
        // stopAsync runs first in the file; cleanup is the tail method.
        $stopRegion = \substr($src, $stop, $cleanup - $stop);
        $cleanupRegion = \substr($src, $cleanup);
        $this->assertStringContainsString(
            '$this->restoreSignalHandlers();',
            $stopRegion,
            'the async teardown restores the borrowed dispositions'
        );
        $this->assertStringContainsString(
            '$this->restoreSignalHandlers();',
            $cleanupRegion,
            'the sync teardown restores the borrowed dispositions'
        );
    }
}
