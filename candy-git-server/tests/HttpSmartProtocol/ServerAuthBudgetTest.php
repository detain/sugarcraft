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
 * End-to-end pins for the HTTP auth attempt budget (finding 008): the
 * 429 answer, the refund-on-success neutrality, and — the property the
 * file-backed ledger exists for — persistence ACROSS Server instances,
 * because under the worker-per-request pool every connection is served
 * by a freshly forked process with its own Server.
 *
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class ServerAuthBudgetTest extends TestCase
{
    private string $tmpDir;
    private Config $config;

    /** Registered accounts: password is the user name + '-pw'. */
    private const USERS = ['alice', 'bob', 'carol', 'dave'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/auth-budget-http-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
        \file_put_contents($this->tmpDir . '/config.yaml', "name: budget-test\nhttp:\n  auth_max_attempts: 3\n");
        $this->config = Config::load($this->tmpDir . '/config.yaml');

        $repoPath = $this->tmpDir . '/gates.git';
        \mkdir($repoPath, 0755, true);
        \exec('git init -q --bare ' . \escapeshellarg($repoPath) . ' 2>/dev/null');

        // Lever down: every request here needs a real identity to pass,
        // so the status answers isolate the auth layer cleanly.
        AccessControl::instance()->setAllowAnonymousRead(false);
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function server(): Server
    {
        $server = new Server($this->config);
        $server->registerRepo(Repo::new('gates.git', $this->tmpDir . '/gates.git'));
        foreach (self::USERS as $name) {
            // cost-4 digest: the budget is about attempt COUNTS, and this
            // keeps the suite honest about time (the decoy path mints
            // PASSWORD_DEFAULT once per process regardless).
            $server->registerUser(User::new($name)
                ->withAdmin(true)
                ->withPasswordHash(\password_hash($name . '-pw', \PASSWORD_BCRYPT, ['cost' => 4])));
        }

        return $server;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function attempt(Server $server, string $credential, string $peerIp): array
    {
        return $server->handleRequest(
            'GET',
            '/gates.git/info/refs',
            'service=git-upload-pack',
            ['Authorization' => 'Basic ' . \base64_encode($credential)],
            '',
            $peerIp,
        );
    }

    public function testBurstOfWrongPasswordsEndsIn429WithRetryAfter(): void
    {
        $server = $this->server();

        foreach ([1, 2, 3] as $i) {
            $r = $this->attempt($server, 'alice:nope', '10.1.0.1:40001');
            self::assertSame(401, $r['status'], "attempt $i is a regular refusal");
            self::assertSame('Basic realm="candy-git-server"', $r['headers']['WWW-Authenticate']);
        }

        $denied = $this->attempt($server, 'alice:nope', '10.1.0.1:40001');
        self::assertSame(429, $denied['status']);
        self::assertStringContainsString('Too many authentication attempts', $denied['body']);
        $retry = $denied['headers']['Retry-After'] ?? null;
        self::assertIsString($retry);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $retry);
    }

    public function testBudgetIsSharedAcrossFreshServerInstances(): void
    {
        // The worker-per-request property: connection 1 and connection 2
        // are DIFFERENT processes in production; here they are different
        // Server objects sharing only the on-disk ledger.
        $first = $this->server();
        foreach ([1, 2, 3] as $i) {
            self::assertSame(401, $this->attempt($first, 'bob:nope', '10.2.0.2')['status']);
        }

        $second = $this->server();
        self::assertSame(429, $this->attempt($second, 'bob:nope', '10.2.0.2')['status']);
    }

    public function testPortVariantsNormalizeToTheSamePeerBucket(): void
    {
        $server = $this->server();
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(401, $this->attempt($server, 'alice:nope', '10.3.0.3:' . (41000 + $i))['status']);
        }
        // Same peer, new ephemeral port: normalizeIp strips it, so the
        // budget sees one client, not 64k of them.
        self::assertSame(429, $this->attempt($server, 'alice:nope', '10.3.0.3:42000')['status']);
    }

    public function testUsernameRotationCannotOutrunThePeerBucket(): void
    {
        $server = $this->server();
        self::assertSame(401, $this->attempt($server, 'alice:nope', '10.4.0.4')['status']);
        self::assertSame(401, $this->attempt($server, 'bob:nope', '10.4.0.4')['status']);
        self::assertSame(401, $this->attempt($server, 'carol:nope', '10.4.0.4')['status']);
        self::assertSame(429, $this->attempt($server, 'dave:nope', '10.4.0.4')['status']);
    }

    public function testCorrectCredentialsNeverDrainTheBudget(): void
    {
        $server = $this->server();
        for ($i = 0; $i < 8; $i++) { // capacity is 3
            self::assertSame(200, $this->attempt($server, 'alice:alice-pw', '10.5.0.5')['status']);
        }
        // The burst is intact: one wrong password is a 401, not a 429.
        self::assertSame(401, $this->attempt($server, 'alice:nope', '10.5.0.5')['status']);
    }

    public function testExhaustionRefusesEvenTheCorrectPasswordForTheWindow(): void
    {
        // The documented trade-off of a username-keyed budget: the denial
        // must precede bcrypt, so the server cannot know the refused
        // attempt would have succeeded. Pinning the shape forces future
        // changes to be deliberate.
        $server = $this->server();
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(401, $this->attempt($server, 'carol:nope', '10.6.0.6')['status']);
        }
        self::assertSame(429, $this->attempt($server, 'carol:carol-pw', '10.6.0.6')['status']);
    }

    public function testAnonymousRequestsAreNotCharged(): void
    {
        // No credentials offered: nothing reaches the bcrypt paths, so
        // the ledger must stay empty no matter how many probe requests
        // arrive.
        $server = $this->server();
        for ($i = 0; $i < 12; $i++) {
            $r = $server->handleRequest('GET', '/gates.git/info/refs', 'service=git-upload-pack', [], '', '10.7.0.7');
            self::assertSame(401, $r['status']);
        }
        self::assertFileDoesNotExist($this->tmpDir . '/auth-attempts.json');
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
