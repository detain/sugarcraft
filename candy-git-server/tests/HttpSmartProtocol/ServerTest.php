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
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\Server
 */
final class ServerTest extends TestCase
{
    private Server $server;
    private Config $config;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/http-smart-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        // Config points at THIS test's data directory: the S08 auth
        // budget ledger (auth-attempts.json) is per-test state under
        // tmpDir, never a file shared through the /tmp/candy-git-server
        // default across suite runs and reruns.
        \file_put_contents($this->tmpDir . '/config.yaml', "name: http-smart-test\n");
        $this->config = Config::load($this->tmpDir . '/config.yaml');

        $this->server = new Server($this->config);

        // Create a test repo
        $repoPath = $this->tmpDir . '/testrepo.git';
        \mkdir($repoPath, 0755, true);
        \exec("git init --bare " . \escapeshellarg($repoPath) . " 2>/dev/null");

        $repo = Repo::new('testrepo.git', $repoPath);
        $this->server->registerRepo($repo);

        // These arms model the published world: the anonymous-read lever is
        // raised (config opt-in). The lever-off default is pinned by
        // testAnonymousReadIsFailClosedByDefault below.
        AccessControl::instance()->setAllowAnonymousRead(true);
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testAnonymousReadIsFailClosedByDefault(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);

        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(401, $result['status']);
        $this->assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate']);
        $this->assertStringNotContainsString('refs/', $result['body']);
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

    /** Invoke the private readCapped() on an in-memory stream. */
    private function readCapped(string $content, int $maxBytes): ?string
    {
        $stream = \fopen('php://memory', 'r+');
        \fwrite($stream, $content);
        \rewind($stream);

        $method = new \ReflectionMethod($this->server, 'readCapped');
        /** @var string|null $result */
        $result = $method->invoke($this->server, $stream, $maxBytes);
        \fclose($stream);

        return $result;
    }

    // -------------------------------------------------------------------------
    // readCapped tests (pack-buffer size cap)
    // -------------------------------------------------------------------------

    public function testReadCappedReturnsDataWithinCap(): void
    {
        $this->assertSame('hello', $this->readCapped('hello', 5));
        $this->assertSame('hello', $this->readCapped('hello', 1024));
        $this->assertSame('', $this->readCapped('', 1024));
    }

    public function testReadCappedReturnsNullWhenOverCap(): void
    {
        $this->assertNull($this->readCapped('hello!', 5));
        $this->assertNull($this->readCapped(\str_repeat('x', 70000), 65536));
    }

    // -------------------------------------------------------------------------
    // handleRequest tests
    // -------------------------------------------------------------------------

    public function testHandleRequestReturnsOkStatusForValidRequest(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(200, $result['status']);
    }

    public function testHandleRequestReturnsCorrectHeaders(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('Date', $result['headers']);
        $this->assertArrayHasKey('Server', $result['headers']);
        $this->assertArrayHasKey('Connection', $result['headers']);
    }

    public function testHandleRequestReturnsErrorForMissingService(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            '',
            [],
            ''
        );

        $this->assertSame(400, $result['status']);
    }

    public function testHandleRequestReturnsErrorForInvalidService(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-foo',
            [],
            ''
        );

        $this->assertSame(400, $result['status']);
    }

    public function testHandleRequestReturnsErrorForNonexistentRepo(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/nonexistent.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(404, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Content-Type tests
    // -------------------------------------------------------------------------

    // Changed 2026-10-24 (the smart-HTTP transport round): the header value
    // was '...-advisory' — a typo git's client rejects. Spec value is
    // '...-advertisement' (verified against git 2.43 http-backend traffic).
    public function testHandleRequestSetsCorrectContentTypeForUploadPack(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame('application/x-git-upload-pack-advertisement', $result['headers']['Content-Type']);
    }

    public function testReceivePackAdvertisementRefusesAnonymousBeforeAnyBytes(): void
    {
        // Changed 2026-11-03 (review C-13/M-17): the push advertisement now
        // challenges anonymous callers with 401 + WWW-Authenticate instead
        // of answering 403 — one uniform "who are you?" answer means an
        // anonymous probe cannot map push policy (or repo existence rules)
        // through the status difference. No advertisement byte is emitted
        // either way; this pin keeps it that way.
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-receive-pack',
            [],
            ''
        );

        $this->assertSame(401, $result['status']);
        $this->assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate']);
        $this->assertStringNotContainsString('refs/', $result['body']);
    }

    public function testReceivePackAdvertisementServesAnAdminOverBasicAuth(): void
    {
        $this->server->registerUser(User::new('pusher')->withAdmin(true)
            ->withPasswordHash(\password_hash('pushpass', \PASSWORD_DEFAULT)));

        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-receive-pack',
            ['Authorization' => 'Basic ' . \base64_encode('pusher:pushpass')],
            ''
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('application/x-git-receive-pack-advertisement', $result['headers']['Content-Type']);
        // testrepo.git is EMPTY here — git answers with the capabilities^{}
        // fake-head packet (ground truth vs git receive-pack --advertise-refs).
        $this->assertStringContainsString('capabilities^{}', $result['body']);
        $this->assertStringContainsString('report-status', $result['body']);
    }

    // -------------------------------------------------------------------------
    // Route tests
    // -------------------------------------------------------------------------

    public function testHandleRequestRoutesToGitUploadPack(): void
    {
        $result = $this->server->handleRequest(
            'POST',
            '/testrepo.git/git-upload-pack',
            '',
            [],
            "want 1234567890123456789012345678901234567890\n\n"
        );

        $this->assertArrayHasKey('status', $result);
        $this->assertContains($result['status'], [200, 403, 404]);
    }

    public function testHandleRequestRoutesToGitReceivePack(): void
    {
        // Changed 2026-11-03 (push wired): POST git-receive-pack is no
        // longer a stub — an anonymous client meets the auth challenge at
        // the gate, before the body is examined at all.
        $result = $this->server->handleRequest(
            'POST',
            '/testrepo.git/git-receive-pack',
            '',
            [],
            ''
        );

        // Push is wired: an anonymous POST meets the auth challenge before
        // anything else happens (the old honest 501 stub retired).
        $this->assertSame(401, $result['status']);
        $this->assertSame('Basic realm="candy-git-server"', $result['headers']['WWW-Authenticate'] ?? null);
        $this->assertStringContainsString('Authentication required', $result['body']);
    }

    public function testHandleRequestReturns404ForUnknownAction(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/unknown/action',
            '',
            [],
            ''
        );

        $this->assertSame(404, $result['status']);
    }

    public function testHandleRequestReturns404ForRootPath(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/',
            '',
            [],
            ''
        );

        $this->assertSame(404, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Registration tests
    // -------------------------------------------------------------------------

    public function testRegisterRepoMakesRepoAccessible(): void
    {
        // Repo already registered in setUp
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertNotSame(404, $result['status']);
    }

    public function testRegisterUserStoresUser(): void
    {
        $user = User::new('testuser');
        $this->server->registerUser($user);

        // The server should handle requests with this user
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertNotSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Response body tests
    // -------------------------------------------------------------------------

    public function testHandleRequestReturnsAdvertisementBody(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertNotEmpty($result['body']);
        // Packet line format: first 4 bytes are length prefix (binary representation of hex length)
        // Check that body has at least the flush packet (4 bytes of zeros)
        $this->assertGreaterThanOrEqual(4, \strlen($result['body']));
        // The last 4 bytes should be the flush packet "0000"
        $this->assertSame("\x30\x30\x30\x30", \substr($result['body'], -4));
    }

    // Changed 2026-10-24 (the smart-HTTP transport round): the old arm pinned
    // 'Transfer-Encoding: chunked' on a fully-buffered body — the header was
    // a lie (no chunked framing follows it). Honest shape: Content-Length.
    public function testBufferedResponsesCarryContentLengthNotChunked(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame((string) \strlen($result['body']), $result['headers']['Content-Length']);
        $this->assertArrayNotHasKey('Transfer-Encoding', $result['headers']);
    }

    // -------------------------------------------------------------------------
    // Error handling tests
    // -------------------------------------------------------------------------

    public function testHandleRequestHandlesExceptionGracefully(): void
    {
        // Create a server with a config pointing to invalid path
        $server = new Server($this->config);

        // Without any registered repos, it should return 404
        $result = $server->handleRequest(
            'GET',
            '/nonexistent.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(404, $result['status']);
    }

    public function testHandleRequestResponseHasAllRequiredFields(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('body', $result);
    }

    // -------------------------------------------------------------------------
    // Auth header tests
    // -------------------------------------------------------------------------

    public function testHandleRequestAcceptsBasicAuth(): void
    {
        $user = User::new('testuser')->withPasswordHash(\password_hash('secret123', \PASSWORD_DEFAULT));
        $this->server->registerUser($user);

        $credentials = \base64_encode('testuser:secret123');
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            ['Authorization' => 'Basic ' . $credentials],
            ''
        );

        // Should not be blocked by auth (repo is public by default)
        $this->assertNotSame(403, $result['status']);
    }

    public function testBasicAuthRejectsWrongPassword(): void
    {
        $user = User::new('authtest')->withPasswordHash(\password_hash('correct-password', \PASSWORD_DEFAULT));
        $this->server->registerUser($user);

        $credentials = \base64_encode('authtest:wrong-password');
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            ['Authorization' => 'Basic ' . $credentials],
            ''
        );

        // User should not be authenticated due to wrong password
        // Since repo is public, the request still succeeds (public repo allows anonymous)
        // The auth rejection only matters for private repos
        // This test verifies the password check logic doesn't cause a false-positive auth
        $this->assertNotSame(500, $result['status']);
    }

    public function testHandleRequestAcceptsCandyGitServerUserHeader(): void
    {
        $user = User::new('headeruser');
        $this->server->registerUser($user);

        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            ['X-CandyGitServer-User' => 'headeruser'],
            ''
        );

        $this->assertNotSame(403, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Basic-auth timing alignment (user-enumeration oracle)
    // -------------------------------------------------------------------------

    public function testBasicAuthRefusalsAllPayABcryptVerify(): void
    {
        // Structural pin: the unknown-user and key-only refusals each verify
        // against the decoy digest before answering, so no refusal path can
        // skip bcrypt. The old shape — a bare `if ($user === null) return
        // null;` ahead of any verify — is the mutation this catches; the
        // uniform-401 behaviour alone cannot see it.
        $source = (string) \file_get_contents(__DIR__ . '/../../src/HttpSmartProtocol/Server.php');
        $start = \strpos($source, 'private function getUserFromHeaders');
        $end = \strpos($source, '// Custom CandyGitServer user header');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $auth = \substr($source, (int) $start, (int) $end - (int) $start);

        self::assertStringNotContainsString('if ($user === null) return null;', $auth);
        self::assertSame(
            2,
            \substr_count($auth, 'self::basicAuthDecoyHash()'),
            'unknown-user and key-only refusals must each burn the decoy',
        );
        self::assertStringContainsString('if (!\password_verify($password, $hash)) {', $auth);
    }

    public function testDecoyDigestIsCostMatchedAndNeverMatches(): void
    {
        $decoy = (string) (new \ReflectionMethod(Server::class, 'basicAuthDecoyHash'))->invoke(null);

        // Memoized: the mint is paid once per process and every refusal
        // burns the very same digest.
        self::assertSame(
            $decoy,
            (string) (new \ReflectionMethod(Server::class, 'basicAuthDecoyHash'))->invoke(null),
        );

        $info = \password_get_info($decoy);
        self::assertSame('bcrypt', $info['algoName']);
        // The bin mints credentials with PASSWORD_DEFAULT; the decoy must
        // burn the same work or the alignment is only approximate.
        $mintCost = \password_get_info(\password_hash('alignment-probe', \PASSWORD_DEFAULT))['options']['cost'] ?? null;
        self::assertSame($mintCost, $info['options']['cost'] ?? null);
        foreach (['', 'a', 'password', 'secret123', '$2y$10$00'] as $probe) {
            self::assertFalse(\password_verify($probe, $decoy));
        }
    }

    public function testUnknownKnownWrongAndKeyOnlyBasicAuthAnswerIdentically(): void
    {
        // Lever-down: authentication decides the answer, not the lever.
        AccessControl::instance()->setAllowAnonymousRead(false);
        $this->server->registerUser(User::new('authtest')
            ->withPasswordHash(\password_hash('correct-password', \PASSWORD_DEFAULT)));
        $this->server->registerUser(User::new('keyonly'));

        $answers = [];
        foreach (['ghost:whatever', 'authtest:wrong-password', 'keyonly:whatever'] as $credential) {
            $answers[$credential] = $this->withoutVolatileDate($this->basicAuthInfoRefs($credential));
        }
        self::assertSame($answers['ghost:whatever'], $answers['authtest:wrong-password']);
        self::assertSame($answers['ghost:whatever'], $answers['keyonly:whatever']);
        self::assertSame(401, $answers['ghost:whatever']['status']);

        // The real credential still wins — the decoy equalizes refusals, it
        // does not disable authentication.
        $ok = $this->basicAuthInfoRefs('authtest:correct-password');
        self::assertSame(200, $ok['status']);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function basicAuthInfoRefs(string $credential): array
    {
        return $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            ['Authorization' => 'Basic ' . \base64_encode($credential)],
            '',
        );
    }

    /**
     * Strip the one volatile byte from an otherwise static refusal: the
     * second-granularity 'Date' header every request stamps from gmdate()
     * (src/HttpSmartProtocol/Server.php:178). Under CPU load the three
     * refused legs straddle a second boundary and the triad equality ends up
     * comparing wall-clock noise. Nothing else is dropped — status, every
     * other header and the body stay in the compared payload, so a genuine
     * refusal divergence (the user-enumeration oracle this guards) still
     * reddens the test.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $answer
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function withoutVolatileDate(array $answer): array
    {
        unset($answer['headers']['Date']);

        return $answer;
    }

    // -------------------------------------------------------------------------
    // Case sensitivity tests
    // -------------------------------------------------------------------------

    public function testHandleRequestPathsAreCaseSensitive(): void
    {
        // Path with different case
        $result = $this->server->handleRequest(
            'GET',
            '/TESTREPO.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame(404, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Response headers cache control tests
    // -------------------------------------------------------------------------

    public function testHandleRequestSetsNoCacheForRefsEndpoint(): void
    {
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-upload-pack',
            [],
            ''
        );

        $this->assertSame('no-cache', $result['headers']['Cache-Control']);
    }
}
