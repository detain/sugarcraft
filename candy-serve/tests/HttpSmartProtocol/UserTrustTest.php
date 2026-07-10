<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\HttpSmartProtocol\Server;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\User;

/**
 * Regression coverage for the X-CandyServe-User impersonation fix: the
 * header is trusted only when the configured trust mode's check passes,
 * fail-closed by default.
 *
 * @covers \SugarCraft\Serve\HttpSmartProtocol\Server
 */
final class UserTrustTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/user-trust-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
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

    /**
     * Build a Server from an inline http-config YAML fragment and register
     * an admin user 'alice' plus a private repo (so trusting the header
     * actually flips access).
     */
    private function makeServer(string $httpYaml): Server
    {
        $dir = $this->tmpDir . '/' . \uniqid('cfg', true);
        \mkdir($dir, 0755, true);
        \file_put_contents($dir . '/config.yaml', "http: { {$httpYaml} }\n");
        $config = Config::load($dir . '/config.yaml');

        $server = new Server($config);
        $server->registerUser(User::new('alice')->withAdmin(true));

        $repoPath = $dir . '/private.git';
        \mkdir($repoPath, 0755, true);
        \exec('git init --bare ' . \escapeshellarg($repoPath) . ' 2>/dev/null');
        $server->registerRepo(Repo::new('private.git', $repoPath)->withPrivate(true));

        return $server;
    }

    /** Invoke the private trust-decision method without a live socket. */
    private function resolveTrustedUser(Server $server, array $headers, ?string $peerIp): ?User
    {
        $m = new \ReflectionMethod($server, 'resolveTrustedUser');
        $m->setAccessible(true);
        /** @var User|null $result */
        $result = $m->invoke($server, $headers, $peerIp);
        return $result;
    }

    private function sign(string $username, string $secret): string
    {
        return \hash_hmac('sha256', $username, $secret);
    }

    // -------------------------------------------------------------------------
    // off (default) — the header is ignored entirely
    // -------------------------------------------------------------------------

    public function testOffModeIgnoresUserHeaderEvenWithValidUsername(): void
    {
        $server = $this->makeServer('user_trust_mode: "off"');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '10.0.0.1')
        );
    }

    public function testDefaultModeIsOffAndFailsClosed(): void
    {
        // No trust config at all → default 'off'.
        $server = $this->makeServer('listen_addr: ":0"');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '127.0.0.1')
        );
    }

    public function testOffModeDeniesPrivateRepoThroughHandleRequest(): void
    {
        $server = $this->makeServer('user_trust_mode: "off"');

        $result = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyServe-User' => 'alice'],
            '',
            '10.0.0.1'
        );

        $this->assertSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // proxy — honored only for an allowlisted peer IP
    // -------------------------------------------------------------------------

    public function testProxyModeHonorsAllowlistedPeerViaCidr(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $user = $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '10.1.2.3');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user->username);
    }

    public function testProxyModeHonorsExactIpMatch(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["192.168.1.5"]');

        $user = $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '192.168.1.5');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user->username);
    }

    public function testProxyModeRejectsNonAllowlistedPeer(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '172.16.0.1')
        );
    }

    public function testProxyModeCidrExcludesOutOfRangePeer(): void
    {
        // 11.0.0.1 is just outside 10.0.0.0/8.
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '11.0.0.1')
        );
    }

    public function testProxyModeStripsPortFromPeerAddress(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $user = $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '10.4.5.6:54321');
        $this->assertNotNull($user);
        $this->assertSame('alice', $user->username);
    }

    public function testProxyModeMatchesIpv6Cidr(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["2001:db8::/32"]');

        $this->assertNotNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '2001:db8::1')
        );
        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '2001:db9::1')
        );
    }

    public function testProxyModeFailsClosedWithoutPeerIp(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], null)
        );
    }

    public function testProxyModeFailsClosedWhenAllowlistEmpty(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy"');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], '10.0.0.1')
        );
    }

    public function testProxyModeAllowsPrivateRepoThroughHandleRequest(): void
    {
        $server = $this->makeServer('user_trust_mode: "proxy", trusted_proxies: ["10.0.0.0/8"]');

        $trusted = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyServe-User' => 'alice'],
            '',
            '10.9.9.9'
        );
        $this->assertNotSame(403, $trusted['status']);

        $untrusted = $server->handleRequest(
            'GET',
            '/private.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyServe-User' => 'alice'],
            '',
            '172.16.0.1'
        );
        $this->assertSame(403, $untrusted['status']);
    }

    // -------------------------------------------------------------------------
    // token — honored only with a valid HMAC signature
    // -------------------------------------------------------------------------

    public function testTokenModeAcceptsValidSignature(): void
    {
        $server = $this->makeServer('user_trust_mode: "token", auth_secret: "s3cr3t"');

        $user = $this->resolveTrustedUser($server, [
            'X-CandyServe-User' => 'alice',
            'X-CandyServe-User-Sig' => $this->sign('alice', 's3cr3t'),
        ], null);

        $this->assertNotNull($user);
        $this->assertSame('alice', $user->username);
    }

    public function testTokenModeRejectsForgedSignature(): void
    {
        $server = $this->makeServer('user_trust_mode: "token", auth_secret: "s3cr3t"');

        $this->assertNull($this->resolveTrustedUser($server, [
            'X-CandyServe-User' => 'alice',
            'X-CandyServe-User-Sig' => $this->sign('alice', 'wrong-secret'),
        ], null));
    }

    public function testTokenModeRejectsMissingSignature(): void
    {
        $server = $this->makeServer('user_trust_mode: "token", auth_secret: "s3cr3t"');

        $this->assertNull(
            $this->resolveTrustedUser($server, ['X-CandyServe-User' => 'alice'], null)
        );
    }

    public function testTokenModeFailsClosedWhenNoSecretConfigured(): void
    {
        $server = $this->makeServer('user_trust_mode: "token"');

        // Even a signature computed against an empty secret must not pass.
        $this->assertNull($this->resolveTrustedUser($server, [
            'X-CandyServe-User' => 'alice',
            'X-CandyServe-User-Sig' => $this->sign('alice', ''),
        ], null));
    }

    public function testTokenSignatureIsBoundToUsername(): void
    {
        // A signature valid for 'alice' must not authenticate 'mallory'.
        $server = $this->makeServer('user_trust_mode: "token", auth_secret: "s3cr3t"');

        $this->assertNull($this->resolveTrustedUser($server, [
            'X-CandyServe-User' => 'mallory',
            'X-CandyServe-User-Sig' => $this->sign('alice', 's3cr3t'),
        ], null));
    }
}
