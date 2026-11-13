<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;
use SugarCraft\GitServer\Visibility;

/**
 * The anonymous-read fail-closed keystone (review C-13, strongest
 * cross-persona convergence): the world default is that NOTHING is
 * readable without an identity, and publication is an explicit,
 * per-repo, persisted opt-in (visibility marker) plus a per-server
 * lever (git.allow_anonymous_read) — never an accident of a hardcoded
 * true.
 *
 * Mutation-discriminating: reverting AccessControl::canRead to the old
 * "public wins unconditionally" body reddens the lever-off pin;
 * dropping the WWW-Authenticate header reddens the challenge pin;
 * making the receive-pack advertisement anonymous-readable reddens the
 * uniform-401 pin; breaking markerVisibility's corrupt-file default
 * reddens the fail-closed marker pin.
 *
 * @covers \SugarCraft\GitServer\AccessControl
 * @covers \SugarCraft\GitServer\Repo
 * @covers \SugarCraft\GitServer\Config
 */
final class AnonymousReadLeverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-lever-' . \uniqid('', true);
        \mkdir($this->tmpDir . '/repos', 0755, true);
        // tests/bootstrap.php resets the singleton per test; be explicit anyway.
        AccessControl::instance()->setAllowAnonymousRead(false);
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Config: the lever parses, and defaults fail-closed
    // -------------------------------------------------------------------------

    public function testTheLeverDefaultsToFalseFromBothConfigSources(): void
    {
        self::assertFalse(Config::fromDefaults()->allowAnonymousRead);

        $path = $this->tmpDir . '/no-git-block.yaml';
        \file_put_contents($path, "name: test\n");
        self::assertFalse(Config::load($path)->allowAnonymousRead, 'a config without git.allow_anonymous_read must fail closed');
    }

    public function testTheLeverParsesFromYaml(): void
    {
        $path = $this->tmpDir . '/anon-on.yaml';
        \file_put_contents($path, "git:\n allow_anonymous_read: true\n");
        self::assertTrue(Config::load($path)->allowAnonymousRead);

        $path = $this->tmpDir . '/anon-off.yaml';
        \file_put_contents($path, "git:\n allow_anonymous_read: false\n");
        self::assertFalse(Config::load($path)->allowAnonymousRead);
    }

    // -------------------------------------------------------------------------
    // AccessControl: publication is only meaningful when the lever is on
    // -------------------------------------------------------------------------

    public function testCanReadHonoursTheLeverForPublishedRepos(): void
    {
        $ac = AccessControl::instance();
        $published = Repo::new('pub.git', $this->tmpDir . '/repos/pub.git')->withVisibility(Visibility::Public);

        $ac->setAllowAnonymousRead(false);
        self::assertFalse($ac->canRead(null, $published), 'lever off: even a published repo refuses the anonymous caller');

        $ac->setAllowAnonymousRead(true);
        self::assertTrue($ac->canRead(null, $published), 'lever on: published repos answer the anonymous caller');

        // A signed-in non-admin still reads a published repo regardless of
        // the lever — the lever gates ANONYMOUS access, not authenticated
        // access.
        $ac->setAllowAnonymousRead(false);
        $reader = User::new('bob');
        $ac->setAllowAnonymousRead(false);
        self::assertTrue($ac->canRead($reader, $published), 'an authenticated user reading a published repo is not anonymous');
    }

    public function testTheLeverNeverUnlocksAPrivateRepo(): void
    {
        $ac = AccessControl::instance();
        $private = Repo::new('priv.git', $this->tmpDir . '/repos/priv.git')->withVisibility(Visibility::Private);

        $ac->setAllowAnonymousRead(true);
        self::assertFalse($ac->canRead(null, $private), 'the lever only unlocks publication, never privacy');
        $ac->setAllowAnonymousRead(false);
    }

    // -------------------------------------------------------------------------
    // Visibility marker: persisted, read back, fail-closed on garbage
    // -------------------------------------------------------------------------

    public function testMarkerVisibilityRoundTripsEveryStateAndFailClosesOnGarbage(): void
    {
        $gitDir = $this->tmpDir . '/repos/marker.git';
        \mkdir($gitDir, 0755, true);
        \file_put_contents($gitDir . '/HEAD', "ref: refs/heads/main\n");

        self::assertSame(
            Visibility::Private,
            Repo::markerVisibility($gitDir),
            'no marker means private — the scan must not invent publication'
        );

        $repo = Repo::new('marker.git', $gitDir);

        $repo->withVisibility(Visibility::Public)->writeVisibilityMarker();
        self::assertSame(Visibility::Public, Repo::markerVisibility($gitDir));
        self::assertSame("public\n", \file_get_contents($gitDir . '/' . Repo::VISIBILITY_MARKER));

        $repo->withVisibility(Visibility::CollaboratorOnly)->writeVisibilityMarker();
        self::assertSame(Visibility::CollaboratorOnly, Repo::markerVisibility($gitDir));

        \file_put_contents($gitDir . '/' . Repo::VISIBILITY_MARKER, 'who-knows');
        self::assertSame(
            Visibility::Private,
            Repo::markerVisibility($gitDir),
            'a corrupt marker fail-closes to private, never to public'
        );
    }

    // -------------------------------------------------------------------------
    // HTTP wire: lever off → 401 + WWW-Authenticate; lever on → 200
    // -------------------------------------------------------------------------

    public function testLeverOffAnAnonymousReadOfAPublishedRepoIsChallenged(): void
    {
        $server = $this->makeServer();
        AccessControl::instance()->setAllowAnonymousRead(false);

        $result = $server->handleRequest('GET', '/pub.git/info/refs', 'service=git-upload-pack', [], '');

        self::assertSame(401, $result['status']);
        self::assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate'] ?? null);
        self::assertStringNotContainsString('refs/heads/', $result['body'], 'a challenged advertisement must not leak refs');
    }

    public function testLeverOnAnAnonymousReadOfAPublishedRepoSucceeds(): void
    {
        $server = $this->makeServer();
        AccessControl::instance()->setAllowAnonymousRead(true);

        $result = $server->handleRequest('GET', '/pub.git/info/refs', 'service=git-upload-pack', [], '');

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('refs/heads/', $result['body']);
        self::assertArrayNotHasKey('WWW-Authenticate', $result['headers']);
    }

    public function testAnAuthenticatedNonAdminReadsAPublishedRepoWithTheLeverOff(): void
    {
        $server = $this->makeServer();
        AccessControl::instance()->setAllowAnonymousRead(false);

        $result = $server->handleRequest(
            'GET',
            '/pub.git/info/refs',
            'service=git-upload-pack',
            ['Authorization' => 'Basic ' . \base64_encode('reader:' . $this->passwordOf('reader'))],
            ''
        );

        self::assertSame(200, $result['status'], 'the lever gates anonymous reads; an authenticated reader is not anonymous');
    }

    public function testTheReceivePackAdvertisementNeverRespondsAnonymouslyEvenWithTheLeverOn(): void
    {
        $server = $this->makeServer();
        AccessControl::instance()->setAllowAnonymousRead(true);

        $result = $server->handleRequest('GET', '/pub.git/info/refs', 'service=git-receive-pack', [], '');

        self::assertSame(401, $result['status'], 'push advertisement always requires an identity ');
        self::assertStringNotContainsString('refs/heads/', $result['body']);
    }

    public function testAKnownUserWithoutWriteRightsGetsAForbiddenNotAChallenge(): void
    {
        $server = $this->makeServer();

        $result = $server->handleRequest(
            'GET',
            '/pub.git/info/refs',
            'service=git-receive-pack',
            ['Authorization' => 'Basic ' . \base64_encode('reader:' . $this->passwordOf('reader'))],
            ''
        );

        self::assertSame(403, $result['status'], 'a known identity lacking the right gets a policy answer, not a credential challenge');
        self::assertArrayNotHasKey('WWW-Authenticate', $result['headers']);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @var array<string,string> username => plaintext password (test-only). */
    private const PASSWORDS = ['reader' => 'readpass', 'boss' => 'adminpass'];

    private function passwordOf(string $username): string
    {
        return self::PASSWORDS[$username];
    }

    private function makeServer(): Server
    {
        $pubPath = $this->tmpDir . '/repos/pub.git';
        \mkdir($pubPath, 0755, true);
        \exec('git init --bare -q ' . \escapeshellarg($pubPath) . ' 2>/dev/null');

        $main = \trim((string) \shell_exec('git -C ' . \escapeshellarg($pubPath) . ' rev-parse refs/heads/master 2>/dev/null'));
        \file_put_contents($pubPath . '/refs/heads/feature', $main . "\n");

        $server = new Server(Config::fromDefaults());
        $server->registerRepo(Repo::new('pub.git', $pubPath)->withVisibility(Visibility::Public));
        $server->registerUser(User::new('reader')->withPasswordHash(\password_hash(self::PASSWORDS['reader'], \PASSWORD_DEFAULT)));
        $server->registerUser(User::new('boss')->withAdmin(true)->withPasswordHash(\password_hash(self::PASSWORDS['boss'], \PASSWORD_DEFAULT)));

        return $server;
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
}
