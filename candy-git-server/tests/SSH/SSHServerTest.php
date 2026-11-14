<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\SSH;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\{AccessControl, Config, Repo, SSH\SSHServer, User};

/**
 * @covers \SugarCraft\GitServer\SSH\SSHServer
 */
final class SSHServerTest extends TestCase
{
    private string $tmpDir;
    private Config $config;
    private SSHServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/ssh-server-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
        \mkdir($this->tmpDir . '/repositories', 0755, true);

        $this->config = Config::fromDefaults();
        $this->server = new SSHServer($this->config);
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

    private function createRepoPath(string $name): string
    {
        $path = $this->tmpDir . '/repositories/' . $name;
        \mkdir($path, 0755, true);
        return $path;
    }

    // -------------------------------------------------------------------------
    // Registration tests
    // -------------------------------------------------------------------------

    public function testRegisterUser(): void
    {
        $user = User::new('alice');
        $this->server->registerUser($user);

        $this->assertArrayHasKey('alice', $this->server->users());
    }

    public function testRegisterRepo(): void
    {
        $path = $this->createRepoPath('test-repo');
        $repo = Repo::new('test-repo', $path);
        $this->server->registerRepo($repo);

        $this->assertArrayHasKey('test-repo', $this->server->repos());
    }

    // -------------------------------------------------------------------------
    // Auth tests (Step 6). These arms drive the transport's auth decision
    // directly — no handshake, no ssh2: the extension never participates in
    // authentication (099), so nothing here is a "non-ssh2 path".
    // -------------------------------------------------------------------------

    public function testAuthenticateWithUnknownUserReturns1(): void
    {
        $stream = \fopen('php://memory', 'r+');
        // Unknown user - should fail
        $result = $this->server->handleConnection($stream, 'nobody', 'git-upload-pack /test');
        $this->assertSame(1, $result);
    }

    public function testAuthenticateWithKnownUserAndNoSsh2PassesEarlyCheck(): void
    {
        $user = User::new('alice');
        $this->server->registerUser($user);

        $stream = \fopen('php://memory', 'r+');
        // Post-fix truth (067): a KNOWN user presenting no key is refused
        // at authenticate() — the null key can never authenticate as a
        // user — so this dies at AUTH, not (as the old comment claimed) at
        // repo lookup. rc 1 cannot tell the two branches apart; the
        // accept-side pair below proves the lever branch by reaching an rc
        // the refusal branch never can.
        $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /nonexistent');
        $this->assertSame(1, $result);
        $this->assertNull($this->server->authenticatedUser());
    }

    /**
     * Finding 099: the anonymous-read gate answers AccessControl alone.
     * The ssh2 extension never participates in authentication, so its
     * presence must not be consulted there — on a host without ssh2 the
     * old coupling silently refused every anonymous read even with the
     * lever armed. This host loads ssh2, so no behavior pin can see the
     * difference; the structural pin is the honest guard (mutation-proof:
     * re-adding the condition to authenticate() reddens it).
     */
    public function testAnonymousReadGateConsultsAccessControlNotSsh2Capability(): void
    {
        $source = \file_get_contents(__DIR__ . '/../../src/SSH/SSHServer.php');
        $this->assertIsString($source);

        $start = \strpos($source, 'private function authenticate(');
        $this->assertIsInt($start, 'the authenticate method must exist to be policed');
        $body = \substr($source, $start, \strpos($source, "\n    }", $start) - $start);

        $this->assertStringNotContainsString(
            'extension_loaded',
            $body,
            'finding 099: an extension the auth path never calls must not gate anonymous reads'
        );

        // The capability gate stays exactly where the capability is used.
        $userSource = \file_get_contents(__DIR__ . '/../../src/User.php');
        $this->assertIsString($userSource);
        $this->assertMatchesRegularExpression(
            '/function generateKeyPair\(\)[\s\S]{0,120}extension_loaded\(\'ssh2\'\)/',
            $userSource,
            'generateKeyPair keeps its correct ssh2 gate'
        );
    }

    public function testLogSafeNeutralizesControlCharactersClipsAndStaysIdentityOnRealNames(): void
    {
        /** @var callable(string): string $logSafe */
        $logSafe = \Closure::bind(
            static fn (string $token): string => SSHServer::logSafe($token),
            null,
            SSHServer::class
        );

        // A CR/LF inside a client-claimed name must not be able to forge a
        // second operator-log line: controls collapse to spaces.
        $forged = "alice\r\nFeb  3 00:00:00 host sshd[1]: forged line";
        $neutralized = $logSafe($forged);
        $this->assertStringNotContainsString("\n", $neutralized);
        $this->assertStringNotContainsString("\r", $neutralized);
        $this->assertLessThanOrEqual(64, \strlen($neutralized), 'the byte clip bounds every emitted token');

        // NUL and the rest of the C0 block plus DEL are all stripped.
        $this->assertSame(
            0,
            \preg_match('/[\x00-\x1F\x7F]/', $logSafe("a\x00b\x01c\x1bd\x7f")),
            'no control byte may survive into the log token'
        );

        // Unbounded names are clipped to the 64-byte budget.
        $this->assertSame(\str_repeat('x', 64), $logSafe(\str_repeat('x', 500)));

        // Identity on legitimate names: accounts are looked up RAW, so the
        // sanitizer must not rewrite a name that could exist.
        $this->assertSame('alice', $logSafe('alice'));

        // An all-control token stays visible in the log rather than vanishing.
        $this->assertSame('<empty>', $logSafe("\n"));
    }

    public function testHandleConnectionStderrLinesNeverInterpolateTheRawClientClaimedTokens(): void
    {
        // Structural guard for the same class as the behavioral pin: the
        // emission sites live in handleConnection, and a regression to
        // "Authentication failed for {$username}" there would re-open the
        // log-forgery hole while every behavioral test still passed.
        $reflection = new \ReflectionMethod(SSHServer::class, 'handleConnection');
        $lines = \file((string) $reflection->getFileName());
        $window = \implode('', \array_slice(
            (array) $lines,
            (int) $reflection->getStartLine(),
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine()
        ));

        $emissions = [];
        foreach (\explode("\n", $window) as $line) {
            if (\str_contains($line, 'fwrite') && \str_contains($line, 'STDERR')) {
                $emissions[] = $line;
            }
        }

        $this->assertNotCount(0, $emissions, 'the call-site scan would be vacuous without emissions');
        foreach ($emissions as $line) {
            if (\str_contains($line, '$username') || \str_contains($line, '$command')) {
                $this->assertStringContainsString(
                    'logSafe(',
                    $line,
                    'every STDERR emission of a client-claimed token must route through logSafe: ' . \trim($line)
                );
            }
        }

        // The username reaches exactly two emissions (deactivated + auth
        // failure) — a third raw path would dodge the per-line arms above.
        $this->assertSame(
            2,
            \substr_count($window, 'self::logSafe($username)'),
            'both STDERR paths that name the claimed user must sanitize'
        );
    }

    public function testHandleConnectionRejectsWrongPresentedKey(): void
    {
        $authorized = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';
        // A different BLOB, not just a label: after the comment-insensitive
        // key-identity fix, relabelled copies of the same key ARE the same
        // key — the discriminator auth must refuse is the cryptographic one.
        $wrong = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLK mallory@host';

        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey($authorized);
        $this->server->registerUser($user);

        $stream = \fopen('php://memory', 'r+');
        // Admin push would succeed (see testCommandRegexParsesReceivePack), so a
        // failure here can only come from key verification.
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /key-check-repo', $wrong);
        $this->assertSame(1, $result);
        $this->assertNull($this->server->authenticatedUser());
    }

    /**
     * A duplex temp file pre-seeded with an empty push request (the ASCII
     * flush packet '0000' = a valid command list of zero commands).
     *
     * PHP's stream_select cannot represent php://memory streams, and the
     * delegated stdio pump drives its event loop through select - a memory
     * handle that ever reaches the pump stalls it to the idle deadline and
     * the child's exit code is lost. Connection tests that may spawn a git
     * child therefore use files, never memory streams.
     *
     * @return array{0: resource, 1: string}
     */
    private function flushRequest(string $tag): array
    {
        $file = $this->tmpDir . '/' . $tag . '.duplex';
        \file_put_contents($file, '0000');

        // 'r+b' not 'w+b': fopen's 'w' mode would truncate the seeded request.
        return [\fopen($file, 'r+b'), $file];
    }

    /** A registered, real-on-disk bare repo so the service never touches the shared default data dir. */
    private function registerBareRepo(string $name): Repo
    {
        $path = $this->tmpDir . '/repos/' . $name;
        $repo = Repo::new($name, $path)->init();
        $this->server->registerRepo($repo);

        return $repo;
    }

    public function testHandleConnectionAcceptsMatchingPresentedKey(): void
    {
        $authorized = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';

        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey($authorized);
        $this->server->registerUser($user);
        $this->registerBareRepo('key-check-repo');

        [$stream] = $this->flushRequest('key-check');
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /key-check-repo', $authorized);
        // rc 0 is now git's own answer to an empty command list, measured
        // against the real receive-pack binary. The auth discriminator is
        // the identity below; a wrong key fails earlier with rc 1 and never
        // spawns the child (see the rejection pins).
        $this->assertSame(0, $result);
        $this->assertSame('alice', $this->server->authenticatedUser()?->username);
    }

    // -------------------------------------------------------------------------
    // Empty/null-key auth-bypass regression tests
    //
    // A known username plus a null / empty / whitespace-only presented key
    // must NEVER authenticate as that user. authenticatedUser() stays null and
    // handleConnection() returns non-zero — the peer is treated as having
    // supplied no valid credential, not as $user.
    // -------------------------------------------------------------------------

    private const ALICE_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';

    public function testNullPresentedKeyDoesNotAuthenticateAsUser(): void
    {
        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
        $this->server->registerUser($user);

        $stream = \fopen('php://memory', 'r+');
        // No key material at all — must not be trusted as alice.
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /null-key-repo', null);

        $this->assertSame(1, $result);
        $this->assertNull($this->server->authenticatedUser());
    }

    public function testEmptyStringPresentedKeyDoesNotAuthenticateAsUser(): void
    {
        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
        $this->server->registerUser($user);

        $stream = \fopen('php://memory', 'r+');
        // Empty string key is the concrete bypass — must be rejected.
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /empty-key-repo', '');

        $this->assertSame(1, $result);
        $this->assertNull($this->server->authenticatedUser());
    }

    public function testWhitespacePresentedKeyDoesNotAuthenticateAsUser(): void
    {
        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
        $this->server->registerUser($user);

        $stream = \fopen('php://memory', 'r+');
        // Whitespace-only key is a blank credential — must be rejected.
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /ws-key-repo', " \t \n ");

        $this->assertSame(1, $result);
        $this->assertNull($this->server->authenticatedUser());
    }

    // -------------------------------------------------------------------------
    // Command parsing tests
    // -------------------------------------------------------------------------

    public function testCommandRegexParsesUploadPack(): void
    {
        $stream = \fopen('php://memory', 'r+');
        $user = User::new('alice')->withAdmin(true);
        $this->server->registerUser($user);

        // Well-formed command passes the parse and dies at the repo-lookup
        // gate, before any child could spawn (a memory stream is therefore
        // safe here). The name is unregistered AND absent on disk, so no
        // leftover repository from another run can shift the outcome.
        $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /parse-check-ghost-' . \uniqid());
        $this->assertSame(1, $result);
    }

    public function testCommandRegexParsesReceivePack(): void
    {
        // Authenticating as a user now requires a real, verified key (an
        // empty key can never authenticate as a user), so present alice's key.
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL6m3B1t3xK7qVQ5JxF9kE3xM8qFV2hG4pR9e2mY3xLk alice@host';
        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey($key);
        $this->server->registerUser($user);
        $this->registerBareRepo('valid-name');

        [$stream] = $this->flushRequest('receive-parse');
        // Delegated to the real receive-pack child with an empty command
        // list: the parse + auth + routing chain completes with rc 0.
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /valid-name', $key);
        $this->assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // Name validation tests (Step 4)
    // -------------------------------------------------------------------------

    public function testRejectsInvalidRepoName(): void
    {
        $stream = \fopen('php://memory', 'r+');
        $user = User::new('alice')->withAdmin(true);
        $this->server->registerUser($user);

        // Path traversal repo name - should be rejected
        $result = $this->server->handleConnection($stream, 'alice', 'git-upload-pack /../../etc');
        $this->assertSame(1, $result);
    }

    // -------------------------------------------------------------------------
    // On-demand create tests (Step 4)
    // -------------------------------------------------------------------------

    public function testOnDemandCreateRequiresCreatePermission(): void
    {
        $stream = \fopen('php://memory', 'r+');
        // Non-admin user
        $user = User::new('alice');
        $this->server->registerUser($user);

        // Try to push to a brand-new repo - must fail without the create
        // permission, before any child spawns. Name unregistered AND absent
        // so no leftover repository on disk can soften the outcome.
        $result = $this->server->handleConnection($stream, 'alice', 'git-receive-pack /create-permission-check-' . \uniqid());
        $this->assertSame(1, $result);
    }

    public function testAnAdminPushToAnUnknownRepoCreatesItPrivateOnDisk(): void
    {
        // (113) The push-create POSITIVE arm: the refusal without create
        // permission is pinned directly above, but the admin path — the
        // repo materializing inside the jail, served PRIVATE, connection
        // answering rc 0 — never ran in any test. Config::load sets
        // dataPath=dirname(path), so createRepo lands in this fixture's
        // repositories dir, never the shared default jail.
        if (!\exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('the arm drives the real receive-pack child');
        }

        $yaml = $this->tmpDir . '/create.yaml';
        \file_put_contents($yaml, "name: ssh-create\n");
        $server = new SSHServer(Config::load($yaml));
        $user = User::new('alice')->withAdmin(true)->addAuthorizedKey(self::ALICE_KEY);
        $server->registerUser($user);

        [$stream] = $this->flushRequest('push-create');
        $result = $server->handleConnection($stream, 'alice', 'git-receive-pack /pushed-into.git', self::ALICE_KEY);
        \fclose($stream);

        $this->assertSame(0, $result, 'an admin push to an unknown repo must create it and serve the connection');

        $created = $this->tmpDir . '/repositories/pushed-into.git';
        $this->assertDirectoryExists($created, 'the repo materializes inside the jail');
        $this->assertDirectoryExists($created . '/.git', 'init lays the bare store the service then speaks to');

        $registered = $server->repos()['pushed-into.git'] ?? null;
        $this->assertNotNull($registered, 'the created repo joins the registry');
        $this->assertFalse(
            $registered->isVisiblePublic(),
            'the registered posture is PRIVATE on arrival — publishing is an explicit operator act (065 law)'
        );

        $reloaded = Repo::new('pushed-into.git', $created);
        $this->assertFalse(
            $reloaded->isVisiblePublic(),
            'and the disk carries no marker, so the next boot resolves it private too'
        );
        $this->assertFileDoesNotExist($created . '/' . Repo::VISIBILITY_MARKER);
    }

    // -------------------------------------------------------------------------
    // Anonymous-read lever over the SSH transport (067) — the accept
    // branch of authenticate(null) + UploadPack's canRead, previously zero
    // coverage: deleting either answer kept this file green.
    // -------------------------------------------------------------------------

    /** Seed a bare repo with one real commit inside THIS fixture's jail. */
    private function seedPublicBare(string $name): string
    {
        $rc = 0;
        $work = $this->tmpDir . '/' . $name . '-work';
        \mkdir($work, 0755, true);
        \exec('git init -q -b main ' . \escapeshellarg($work), $o, $rc);
        self::assertSame(0, $rc, 'fixture: work init failed');
        \file_put_contents($work . '/a.txt', "hello\n");
        \exec('git -C ' . \escapeshellarg($work) . ' add a.txt && git -C ' . \escapeshellarg($work) . ' -c user.email=t@t -c user.name=t commit -q -m seed', $o, $rc);
        self::assertSame(0, $rc, 'fixture: seed commit failed');

        $bare = $this->tmpDir . '/repositories/' . $name;
        \exec('git init -q --bare ' . \escapeshellarg($bare), $o, $rc);
        self::assertSame(0, $rc, 'fixture: bare init failed');
        \exec('git -C ' . \escapeshellarg($work) . ' push -q ' . \escapeshellarg($bare) . ' main', $o, $rc);
        self::assertSame(0, $rc, 'fixture: seed push failed');

        $sha = \trim((string) \shell_exec('git -C ' . \escapeshellarg($bare) . ' rev-parse refs/heads/main 2>/dev/null'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha, 'fixture seed commit failed — not a real sha');

        return $bare;
    }

    public function testTheArmedLeverServesAnAnonymousUploadPackEndToEnd(): void
    {
        // Honest gate: the dependency is the git binary (the accept arm
        // drives the real upload-pack child) — ext-ssh2 is NOT consulted
        // anywhere on this path (099), so it must not gate the pin.
        if (!\exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('the accept arm drives the real upload-pack child');
        }

        $yaml = $this->tmpDir . '/anon.yaml';
        \file_put_contents($yaml, "name: ssh-anon\n");
        $server = new SSHServer(Config::load($yaml));
        $bare = $this->seedPublicBare('anon.git');
        $server->registerRepo(Repo::new('anon.git', $bare)->withPublic(true));

        $sha = \trim((string) \shell_exec('git -C ' . \escapeshellarg($bare) . ' rev-parse refs/heads/main 2>/dev/null'));
        $request = \SugarCraft\GitServer\Git\PktLine::encode("want {$sha}\n")
            . \SugarCraft\GitServer\Git\PktLine::FLUSH
            . \SugarCraft\GitServer\Git\PktLine::encode("done\n");

        $file = $this->tmpDir . '/anon.duplex';
        \file_put_contents($file, $request);
        $stream = \fopen($file, 'r+b');
        self::assertIsResource($stream);

        AccessControl::instance()->setAllowAnonymousRead(true);
        try {
            $result = $server->handleConnection($stream, 'no-such-user', 'git-upload-pack /anon.git');
            $this->assertSame(0, $result, 'lever armed: the anonymous accept branch must run the fetch to completion');
        } finally {
            \fclose($stream);
            AccessControl::instance()->setAllowAnonymousRead(false);
        }

        $wire = (string) \file_get_contents($file);
        $answer = \substr($wire, \strlen($request));
        $this->assertStringContainsString('refs/heads/', $answer, 'the accepted connection carried a real advertisement');
        $this->assertMatchesRegularExpression('/(ACK|NAK)/', $answer);
        $this->assertMatchesRegularExpression('/PACK\x00\x00\x00[\x02\x03]/', $answer, 'and a real packfile');
    }

    public function testTheLeverDownRefusesTheSameAnonymousUploadPackRequest(): void
    {
        // The pair: identical fixture, lever down — the connection dies at
        // authenticate() with rc 1 and the server writes no answer bytes
        // (the child never spawns, so the file still holds only the
        // request).
        $yaml = $this->tmpDir . '/anon-off.yaml';
        \file_put_contents($yaml, "name: ssh-anon-off\n");
        $server = new SSHServer(Config::load($yaml));
        $bare = $this->seedPublicBare('anon-off.git');
        $server->registerRepo(Repo::new('anon-off.git', $bare)->withPublic(true));

        $request = '0000';
        $file = $this->tmpDir . '/anon-off.duplex';
        \file_put_contents($file, $request);
        $stream = \fopen($file, 'r+b');
        self::assertIsResource($stream);

        AccessControl::instance()->setAllowAnonymousRead(false);
        $result = $server->handleConnection($stream, 'no-such-user', 'git-upload-pack /anon-off.git');
        \fclose($stream);

        $this->assertSame(1, $result, 'lever down: an anonymous read is refused');
        $this->assertSame(
            $request,
            (string) \file_get_contents($file),
            'the refusal answers no bytes — nothing reached the wire'
        );
    }

    public function testAuthenticateAnswersTheLeverOnBothDirections(): void
    {
        // The end-to-end pair above proves the lever reaches the wire, but
        // the refusal arm cannot discriminate its own cause — UploadPack
        // gates canRead downstream and answers the same rc 1 with nothing
        // written. This reflection pin isolates the authenticate(null)
        // branch itself: armed accepts, disarmed refuses. It is also the
        // 099 posture guard in behavior form — the answer comes from
        // AccessControl, never from the ssh2 capability.
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $method = new \ReflectionMethod(SSHServer::class, 'authenticate');
        $method->setAccessible(true);

        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->assertFalse(
            $method->invoke($this->server, $stream, null, null),
            'lever down: the anonymous branch must refuse'
        );

        AccessControl::instance()->setAllowAnonymousRead(true);
        try {
            $this->assertTrue(
                $method->invoke($this->server, $stream, null, null),
                'lever armed: the anonymous branch must accept'
            );
        } finally {
            \fclose($stream);
            AccessControl::instance()->setAllowAnonymousRead(false);
        }
    }

    // -------------------------------------------------------------------------
    // Query tests
    // -------------------------------------------------------------------------

    public function testTheDiskFallbackCarriesTheVisibilityMarker(): void
    {
        // Config::load sets dataPath=dirname(path), so the server's
        // reposPath lands inside this fixture tmpDir (same material route
        // the daemon pins use).
        $yaml = $this->tmpDir . '/fallback.yaml';
        \file_put_contents($yaml, "name: ssh-fallback\n");
        $server = new SSHServer(Config::load($yaml));

        $dir = $this->createRepoPath('markerless');
        \mkdir($dir . '/.git/objects', 0777, true);

        $find = (new \ReflectionClass($server))->getMethod('findRepoByPath');
        $find->setAccessible(true);

        $found = $find->invoke($server, $dir);
        $this->assertNotNull($found, 'the directory still resolves');
        $this->assertFalse(
            $found->isVisiblePublic(),
            'no marker means fail-closed PRIVATE for the SSH transport'
        );

        $published = $this->createRepoPath('published');
        \mkdir($published . '/.git/objects', 0777, true);
        Repo::new('published', $published)->withPublic(true)->writeVisibilityMarker();

        $found = $find->invoke($server, $published);
        $this->assertNotNull($found);
        $this->assertTrue($found->isVisiblePublic(), 'the marker is the only path to public');
    }

    /**
     * r1-MINOR-3 mirror of the daemon pin: a bare repo (HEAD at the root)
     * published via registerRepo must stay visible to a fresh server's
     * marker-honest fallback. The non-bare exists() gate used to skip the
     * marker write on bare layouts and the hardcoded '$path/.git' probe
     * used to miss it on reads — publication silently reverted to
     * fail-closed PRIVATE.
     */
    public function testAPublishedBareRepoSurvivesTheNextBootFallback(): void
    {
        $yaml = $this->tmpDir . '/bare-fallback.yaml';
        \file_put_contents($yaml, "name: ssh-bare-fallback\n");
        $publisher = new SSHServer(Config::load($yaml));

        $bare = $this->createRepoPath('bare-published');
        \file_put_contents($bare . '/HEAD', "ref: refs/heads/main\n");
        $publisher->registerRepo(Repo::new('bare-published', $bare)->withPublic(true));

        // The bare-aware write put the marker at the bare root itself.
        $this->assertFileExists($bare . '/' . Repo::VISIBILITY_MARKER);

        // A fresh server knows nothing of the registration — only the disk.
        $boot = new SSHServer(Config::load($yaml));
        $find = (new \ReflectionClass($boot))->getMethod('findRepoByPath');
        $find->setAccessible(true);

        $found = $find->invoke($boot, $bare);
        $this->assertNotNull($found, 'the bare directory resolves on the fallback too');
        $this->assertTrue(
            $found->isVisiblePublic(),
            'a registerRepo publication on the bare layout stays published for the next boot'
        );
    }

    public function testConfig(): void
    {
        $this->assertSame($this->config, $this->server->config());
    }

    public function testAuthenticatedUserIsNullInitially(): void
    {
        $this->assertNull($this->server->authenticatedUser());
    }
}
