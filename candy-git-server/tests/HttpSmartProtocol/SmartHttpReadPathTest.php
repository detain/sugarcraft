<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Git\PktLine;
use SugarCraft\GitServer\HttpSmartProtocol\Server;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;
use SugarCraft\GitServer\Visibility;

/**
 * Spec-shape pins for the smart-HTTP READ path added by r1024/githttp
 * (owner ruling 1b): the advertisement bytes, a full clone negotiation
 * against the real git binary, and the two trust gates that must answer
 * 403 BEFORE a single advertisement byte is built.
 *
 * The advertisement and the negotiation delegate to `git
 * --stateless-rpc`, so these tests require the git binary (declared host
 * requirement, see README); they skip loudly when it is absent. The wire
 * shapes asserted here are ground truth captured from git 2.43
 * (upload-pack --stateless-rpc --advertise-refs and an http-backend
 * clone body) — not hand-imagined bytes.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 * @covers \SugarCraft\GitServer\Git\StatelessRpc
 */
final class SmartHttpReadPathTest extends TestCase
{
    private Server $server;
    private string $tmpDir;
    private string $headSha = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireGit();

        $this->tmpDir = \sys_get_temp_dir() . '/smart-read-path-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        $this->server = new Server(Config::fromDefaults());

        // Seed a bare repo with one commit: work repo → push into bare.
        $work = $this->tmpDir . '/work';
        $bare = $this->tmpDir . '/seeded.git';
        \mkdir($work, 0755, true);
        \exec('git init -q -b main ' . \escapeshellarg($work) . ' 2>/dev/null');
        \file_put_contents($work . '/a.txt', "hello\n");
        \exec('git -C ' . \escapeshellarg($work) . ' add a.txt'
            . ' && git -C ' . \escapeshellarg($work)
            . ' -c user.email=t@t -c user.name=t commit -q -m seed 2>/dev/null');
        \exec('git init -q --bare ' . \escapeshellarg($bare) . ' 2>/dev/null');
        \exec('git -C ' . \escapeshellarg($work) . ' push -q ' . \escapeshellarg($bare) . ' main 2>/dev/null');

        \exec('git -C ' . \escapeshellarg($bare) . ' rev-parse refs/heads/main 2>/dev/null', $out);
        $this->headSha = \trim($out[0] ?? '');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->headSha, 'fixture seed commit failed');

        $this->server->registerRepo(Repo::new('seeded.git', $bare));
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tmpDir)) {
            \exec('rm -rf ' . \escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    private function requireGit(): void
    {
        if (!\is_executable('/usr/bin/git')) {
            self::markTestSkipped('smart-HTTP read-path pins require the git binary (host requirement, see README)');
        }
    }

    public function testAdvertisementCarriesServiceHeaderFlushAndCapabilitiedRefLine(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/seeded.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        self::assertSame(200, $result['status']);
        self::assertSame('application/x-git-upload-pack-advertisement', $result['headers']['Content-Type']);

        // http-backend preamble, pkt-framed through the shared codec:
        // '# service=...\n' is 26 bytes → 001e, then a flush packet.
        $servicePkt = PktLine::encode("# service=git-upload-pack\n");
        self::assertSame($servicePkt . PktLine::FLUSH, \substr($result['body'], 0, \strlen($servicePkt) + 4));

        // The ref advertisement itself is git's own bytes: a
        // "<oid> refs/heads/main\0caps" packet carrying today's
        // upload-pack defaults (thin-pack + side-band-64k).
        $packets = PktLine::decode(\substr($result['body'], \strlen($servicePkt) + 4));
        self::assertSame($this->headSha . ' refs/heads/main', \substr((string) $packets[0], 0, 56));
        self::assertStringContainsString("\x00", (string) $packets[0]);
        self::assertStringContainsString('thin-pack', (string) $packets[0]);
        self::assertStringContainsString('side-band-64k', (string) $packets[0]);
        self::assertNull($packets[\count($packets) - 1], 'advertisement must end with a flush packet');
    }

    public function testRouteFindsTheRepoWithoutTheGitSuffix(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/seeded/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        self::assertSame(200, $result['status']);
    }

    public function testCloneNegotiationReturnsSideBandedPack(): void
    {
        // Full http-backend POST body: service header, flush, want line
        // carrying the caps the client negotiated for, flush, done, flush
        // (byte shape captured from a real git 2.43 clone request).
        $body = PktLine::encode("# service=git-upload-pack\n")
            . PktLine::flush()
            . PktLine::encode('want ' . $this->headSha
                . " multi_ack_detailed side-band-64k thin-pack no-progress ofs-delta\n")
            . PktLine::flush()
            . PktLine::encode("done\n")
            . PktLine::flush();

        $result = $this->server->handleRequest('POST', '/seeded.git/git-upload-pack', '', [], $body);

        self::assertSame(200, $result['status']);
        self::assertSame('application/x-git-upload-pack-result', $result['headers']['Content-Type']);
        // side-band-64k was requested → the PACK arrives multiplexed on
        // channel 1, after a NAK verdict packet (git 2.43 ground truth).
        self::assertStringContainsString('NAK', $result['body']);
        self::assertStringContainsString("\x01PACK", $result['body']);
    }

    public function testPrivateRepoRefusesAnonymousBeforeAnyAdvertisementBytes(): void
    {
        $secret = $this->tmpDir . '/secret.git';
        \exec('git init -q --bare ' . \escapeshellarg($secret) . ' 2>/dev/null');
        $this->server->registerRepo(
            Repo::new('secret.git', $secret)->withVisibility(Visibility::Private)
        );

        $result = $this->server->handleRequest(
            'GET',
            '/secret.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        self::assertSame(403, $result['status']);
        self::assertStringNotContainsString('refs/', $result['body']);
        self::assertStringNotContainsString('advertisement', $result['headers']['Content-Type']);
    }

    public function testDeactivatedUserIsNotTrustedEvenAsAdmin(): void
    {
        $secret = $this->tmpDir . '/ghostsecret.git';
        \exec('git init -q --bare ' . \escapeshellarg($secret) . ' 2>/dev/null');
        $this->server->registerRepo(
            Repo::new('ghostsecret.git', $secret)->withVisibility(Visibility::Private)
        );
        // An ACTIVE admin would read this repo (canRead short-circuits on
        // admin); deactivating the user must strip that trust at the header
        // gate, so the request arrives anonymous and 403s (gap #5 cure).
        $this->server->registerUser(
            User::new('ghost')->withAdmin(true)->withPassword('pw')->withActive(false)
        );

        $result = $this->server->handleRequest(
            'GET',
            '/ghostsecret.git/info/refs',
            'service=git-upload-pack',
            ['Authorization' => 'Basic ' . \base64_encode('ghost:pw')],
            ''
        );

        self::assertSame(403, $result['status']);
        self::assertStringNotContainsString('refs/', $result['body']);
    }
}
