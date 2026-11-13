<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

/**
 * Whole-request pins for the http.user_trust_mode surface (M-46 family).
 * The pre-existing UserTrustTest unit-pins resolveTrustedUser directly and
 * its two handleRequest legs could only ever observe 401: the trust
 * decision resolved through the headless AccessControl singleton, which no
 * Server ever populated, so accounts registered via registerUser() were
 * invisible to proxy/token trust end-to-end.
 *
 * These pins walk the full route(): a trusted proxy carrying a header that
 * names a REGISTERED account must actually read the private repo (200),
 * an untrusted source must land anonymous (401 + WWW-Authenticate), and
 * the token mode must accept only the HMAC spelling of the same identity.
 *
 * Mutation-discriminating: reverting resolveTrustedUser to the
 * singleton-only lookup reddens the trusted legs; dropping the
 * config-armed lever re-arming in handleInfoRefs reddens the forged-header
 * leg (the process-global singleton stays armed from sibling fixtures).
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class TrustModeHttpProbeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-trust-http-' . \uniqid('', true);
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testTrustedProxyHeaderReadsAPrivateRepoEndToEnd(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $result = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyGitServer-User' => 'alice'],
            '',
            '10.9.9.9'
        );

        self::assertSame(200, $result['status'], 'a trusted proxy naming a registered account IS the identity — not anonymous');
        self::assertStringContainsString('refs/heads/main', $result['body']);
        self::assertArrayNotHasKey('WWW-Authenticate', $result['headers']);
    }

    public function testUntrustedProxySourceIsChallengedNotPolicyAnswered(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $result = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyGitServer-User' => 'alice'],
            '',
            '172.16.0.1'
        );

        self::assertSame(401, $result['status']);
        self::assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate'] ?? null);
    }

    public function testTokenModeSignatureGrantsTheReadAndAForgedHeaderDoesNot(): void
    {
        $server = $this->makeServer('user_trust_mode: "token", auth_secret: "s3cr3t"');
        $sig = Server::mintUserToken('alice', \time() + 60, 's3cr3t');

        $granted = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyGitServer-User' => 'alice', 'X-CandyGitServer-User-Sig' => $sig],
            '',
            '203.0.113.7'
        );
        self::assertSame(200, $granted['status'], 'a valid token signature reads through the trust path');
        self::assertStringContainsString('refs/heads/main', $granted['body']);

        // A peer that never passes the token check (no signature) while the
        // lever is off meets the challenge — the config-armed lever in
        // handleInfoRefs guarantees a sibling test arming the process-global
        // singleton cannot leak refs through this listener.
        $forged = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyGitServer-User' => 'alice'],
            '',
            '203.0.113.7'
        );
        self::assertSame(401, $forged['status']);

        // And the anonymous path through the SAME server with the global
        // singleton armed true still answers 401 — the per-request config
        // re-arm is what makes this listener's posture independent.
        AccessControl::instance()->setAllowAnonymousRead(true);
        $anon = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            [],
            '',
            '203.0.113.7'
        );
        self::assertSame(401, $anon['status'], 'private stays private even with the global lever armed by a sibling');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function makeServer(string $httpYaml): Server
    {
        $dir = $this->tmpDir . '/' . \uniqid('cfg', true);
        \mkdir($dir, 0755, true);
        \file_put_contents($dir . '/config.yaml', "http: { {$httpYaml} }\n");
        $repoPath = $dir . '/private.git';
        \exec('git init --bare -q ' . \escapeshellarg($repoPath) . ' 2>/dev/null');
        $main = \trim((string) \shell_exec('git -C ' . \escapeshellarg($repoPath) . ' hash-object -t tree /dev/null 2>/dev/null'));
        \file_put_contents($repoPath . '/refs/heads/main', $main . "\n");

        $server = new Server(Config::load($dir . '/config.yaml'), \SugarCraft\GitServer\AccessControl::instance());
        $server->registerUser(User::new('alice')->withAdmin(true));
        $server->registerRepo(Repo::new('private.git', $dir . '/private.git')->withPrivate(true));

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
