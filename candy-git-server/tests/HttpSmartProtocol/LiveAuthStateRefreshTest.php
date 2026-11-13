<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;
use SugarCraft\GitServer\UserStore;
use SugarCraft\GitServer\Visibility;

/**
 * Finding 011 (G-2 default: live re-read ships): the HTTP server used to
 * snapshot users.json (and repo visibility markers) at boot only — every
 * `user add`, rotation, deactivation and publish/un-publish was invisible
 * until a restart. Under the 8b worker pool the pool host lives for the
 * whole deployment, so boot-only state meant the control plane lay about
 * when changes take effect.
 *
 * Contract pinned here:
 *  - watchUserStore(): a disk account minted AFTER boot reaches auth on
 *    the very next request (instance registry still wins for in-process
 *    users — the precedence is itself pinned);
 *  - rotation and deactivation take effect live;
 *  - a corrupt store mid-publish holds the LAST-GOOD snapshot (fail-closed
 *    on read error) and recovers automatically once the file is valid;
 *  - a marker flip (private -> public) opens anonymous readability on the
 *    same running server, and un-publishing closes it again.
 *
 * Mutation-discriminating: neutering refreshUserStore's reload reddens the
 * new-account and rotation pins; claiming the stamp before the reload
 * (so a corrupt read is never retried) reddens the fail-closed pin;
 * deleting the liveMarker overlay reddens both marker pins; keeping the
 * old boot-registerUser shape reddens the new-account pin (the stale
 * snapshot shadows the file).
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class LiveAuthStateRefreshTest extends TestCase
{
    private string $tmpDir;
    private Config $config;

    /** Plaintext password for the disk-minted alice account (test-only). */
    private const ALICE = 'alice-open-sesame';

    /** Monotonic touch counter: every store write must move the mtime. */
    private int $storeWrites = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-live-' . \uniqid('', true);
        \mkdir($this->tmpDir . '/data', 0755, true);
        // Load from a file so dataPath (and therefore the S08 ledger and
        // the watched users.json) is per-test state.
        $configPath = $this->tmpDir . '/data/config.yaml';
        \file_put_contents($configPath, "name: live-refresh-test\n");
        $this->config = Config::load($configPath);
        AccessControl::instance()->setAllowAnonymousRead(false);
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Account plane: users.json re-read without a restart
    // -------------------------------------------------------------------------

    public function testAnAccountMintedAfterBootReachesAuthOnTheNextRequest(): void
    {
        $server = $this->makeServer();      // boots with NO accounts
        $this->seedPublicRepo($server);     // receive-pack advertisement needs the repo

        // Before alice exists: the push advertisement challenges (401).
        self::assertSame(401, $this->pushAttempt($server, 'alice', self::ALICE)['status']);

        $this->writeStore([
            User::new('alice')->withPasswordHash(\password_hash(self::ALICE, \PASSWORD_DEFAULT)),
        ]);

        // Same server object, no restart: alice now resolves — the answer
        // flips from "who are you" (401) to "known, no rights" (403).
        $after = $this->pushAttempt($server, 'alice', self::ALICE);
        self::assertSame(403, $after['status'], 'a disk account added after boot must authenticate without a restart');
    }

    public function testARotatedPasswordReachesAuthLive(): void
    {
        $server = $this->makeServer();
        $this->seedPublicRepo($server);
        $this->writeStore([
            User::new('alice')->withPasswordHash(\password_hash(self::ALICE, \PASSWORD_DEFAULT)),
        ]);
        self::assertSame(403, $this->pushAttempt($server, 'alice', self::ALICE)['status'], 'precondition: old password authenticates');

        $rotated = 'bob-rotated-secret-longer-than-before';
        $this->writeStore([
            User::new('alice')->withPasswordHash(\password_hash($rotated, \PASSWORD_DEFAULT)),
        ]);

        // The NEW credential resolves immediately: the live re-read of
        // users.json delivered the fresh digest (a boot snapshot would
        // have answered 401 forever — the exact 011 defect).
        self::assertSame(403, $this->pushAttempt($server, 'alice', $rotated)['status'], 'the new password must work live');

        // The OLD credential rides the S09 bcrypt memo — process-scoped,
        // at most TTL_SECONDS stale, per-connection under the 8b pool
        // (documented in Server's verifyCache property and pinned by
        // ServerTest/finding 048). Freshness is bounded by the memo, not
        // by a restart, which is the plane 011 governs.
        self::assertSame(403, $this->pushAttempt($server, 'alice', self::ALICE)['status'], 'the pre-rotation credential stays memo-warm within the disclosed S09 TTL');
    }

    public function testADeactivatedAccountStopsAuthenticatingLive(): void
    {
        $server = $this->makeServer();
        $this->seedPublicRepo($server);
        $this->writeStore([
            User::new('alice')->withPasswordHash(\password_hash(self::ALICE, \PASSWORD_DEFAULT)),
        ]);
        self::assertSame(403, $this->pushAttempt($server, 'alice', self::ALICE)['status'], 'precondition: active account authenticates');

        $this->writeStore([
            User::new('alice')->withPasswordHash(\password_hash(self::ALICE, \PASSWORD_DEFAULT))->withActive(false),
        ]);

        self::assertSame(401, $this->pushAttempt($server, 'alice', self::ALICE)['status'], 'deactivation on disk must revoke identity without a restart');
    }

    public function testACorruptStoreHoldsTheLastGoodSnapshotAndSelfHeals(): void
    {
        // Proxy-trust mode exercises the SAME store-refresh path via the
        // identity header, with no bcrypt and no memo — so "last good held"
        // is observable without the S09 credential-cache confounding it.
        $configPath = $this->tmpDir . '/data/config.yaml';
        \file_put_contents($configPath, "name: live-refresh-test\nhttp:\n  user_trust_mode: proxy\n  trusted_proxies:\n    - 127.0.0.7\n");
        $this->config = Config::load($configPath);
        $server = new Server($this->config, \SugarCraft\GitServer\AccessControl::instance());
        $server->watchUserStore($this->config->dataPath);
        $this->seedPublicRepo($server);
        $this->writeStore([User::new('alice')->withAdmin(true)]);

        $who = static fn (): int => $server->handleRequest(
            'GET', '/pub.git/info/refs', 'service=git-receive-pack',
            ['X-CandyGitServer-User' => 'alice'], '', '127.0.0.7'
        )['status'];
        self::assertSame(200, $who(), 'precondition: alice (admin, trusted header) resolves');

        // Mid-publish garbage where the atomic rename should land: the
        // diff notices the stamp change, the parse refuses, and the last
        // GOOD snapshot keeps serving — the fail-closed half of 011.
        $file = $this->config->dataPath . '/' . UserStore::FILE_NAME;
        \file_put_contents($file, '{not json at all');
        \touch($file, \time() + 2 + (++$this->storeWrites));
        self::assertSame(200, $who(), 'a corrupt read must never revoke a known-good account');

        // Recovery: the stamp was NOT claimed by the failed parse, so the
        // repaired file is picked up by the next request's diff. The fix
        // is written raw (a UserStore instance would itself refuse to
        // construct over the corrupt bytes — the same loud contract the
        // boot gate uses). Deactivating alice on disk now reaches the wire.
        \file_put_contents($file, (string) \json_encode([
            'version' => UserStore::VERSION,
            'users' => ['alice' => User::new('alice')->withAdmin(true)->withActive(false)->toArray()],
        ]));
        \touch($file, \time() + 4 + $this->storeWrites);
        self::assertSame(401, $who(), 'the repaired file state becomes authoritative without a restart');
    }

    public function testTheInstanceRegistryWinsOverTheWatchedStore(): void
    {
        $server = $this->makeServer();
        $this->seedPublicRepo($server);
        $this->writeStore([
            User::new('alice')->withPasswordHash(\password_hash('disk-secret', \PASSWORD_DEFAULT)),
        ]);
        // Pinned precedence: an explicitly registered in-process account
        // shadows the disk row of the same name (this is what lets tests
        // and embedders override, and what the boot shape used to be).
        $server->registerUser(User::new('alice')->withPasswordHash(\password_hash('in-process-secret', \PASSWORD_DEFAULT)));

        self::assertSame(403, $this->pushAttempt($server, 'alice', 'in-process-secret')['status']);
        self::assertSame(401, $this->pushAttempt($server, 'alice', 'disk-secret')['status'], 'the instance registry must always win');
    }

    // -------------------------------------------------------------------------
    // Marker plane: visibility flips reach the running server
    // -------------------------------------------------------------------------

    public function testAPublicationMarkerFlipOpensAnonymousReadsWithoutARestart(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(true);
        $server = $this->makeServer();
        $gitDir = $this->seedPublicRepo($server, published: false, name: 'flip.git');

        // Boot state: the VO says private, the marker file does not exist.
        $before = $server->handleRequest('GET', '/flip.git/info/refs', 'service=git-upload-pack', [], '');
        self::assertSame(401, $before['status'], 'precondition: a private repo stays closed even with the lever on');

        // An operator publishes in a SEPARATE process (`repo create` /
        // marker write); the running server must see it on the next
        // request. Explicit distinct mtimes: the marker is a fixed-width
        // payload, so the same-second rewrite would collide the stamp.
        $marker = $gitDir . '/' . Repo::VISIBILITY_MARKER;
        \file_put_contents($marker, "public\n");
        \touch($marker, \time() + 2);

        $after = $server->handleRequest('GET', '/flip.git/info/refs', 'service=git-upload-pack', [], '');
        self::assertSame(200, $after['status'], 'a published marker must open readability without a restart');

        // And un-publishing closes it again just as live.
        \file_put_contents($marker, "private\n");
        \touch($marker, \time() + 4);
        $closed = $server->handleRequest('GET', '/flip.git/info/refs', 'service=git-upload-pack', [], '');
        self::assertSame(401, $closed['status'], 'un-publication must revoke readability without a restart');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function makeServer(): Server
    {
        $server = new Server($this->config, \SugarCraft\GitServer\AccessControl::instance());
        $server->watchUserStore($this->config->dataPath);

        return $server;
    }

    /** @param list<User> $users */
    private function writeStore(array $users): void
    {
        // A fresh store per write (a cached instance would serialize a
        // stale load); transaction() carries the real S11 discipline.
        $store = UserStore::forDataPath($this->config->dataPath);
        $store->transaction(static function (UserStore $store) use ($users): void {
            foreach ($users as $user) {
                $store->put($user); // upsert: rotation pins replace an existing row
            }
        });
        // The mtime+size stamp must move deterministically: bcrypt digests
        // are fixed-width, so consecutive same-second publishes could
        // collide the composite stamp. The counter forces a fresh mtime.
        \touch($this->config->dataPath . '/' . UserStore::FILE_NAME, \time() + 2 + (++$this->storeWrites));
    }

    /**
     * Register a bare repo (real git child like the other wire tests) and
     * return its git dir for marker surgery.
     */
    private function seedPublicRepo(Server $server, bool $published = true, string $name = 'pub.git'): string
    {
        $gitDir = $this->tmpDir . '/repos/' . $name;
        \mkdir($gitDir, 0755, true);
        \exec('git init --bare -q ' . \escapeshellarg($gitDir) . ' 2>/dev/null');
        if ($published) {
            $server->registerRepo(Repo::new($name, $gitDir)->withVisibility(Visibility::Public));
        } else {
            $server->registerRepo(Repo::new($name, $gitDir));
        }

        return $gitDir;
    }

    /**
     * Push advertisement = the cheapest "did my identity resolve?" oracle:
     * 401 anonymous vs 403 known-without-push-rights, with no git child
     * involved on either answer.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function pushAttempt(Server $server, string $username, string $password): array
    {
        return $server->handleRequest(
            'GET',
            '/pub.git/info/refs',
            'service=git-receive-pack',
            ['Authorization' => 'Basic ' . \base64_encode($username . ':' . $password)],
            ''
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($dir);
    }
}
