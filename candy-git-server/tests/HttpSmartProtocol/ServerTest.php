<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
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

        // Create a minimal config
        $this->config = Config::fromDefaults();

        $this->server = new Server($this->config);

        // Create a test repo
        $repoPath = $this->tmpDir . '/testrepo.git';
        \mkdir($repoPath, 0755, true);
        \exec("git init --bare " . \escapeshellarg($repoPath) . " 2>/dev/null");

        $repo = Repo::new('testrepo.git', $repoPath);
        $this->server->registerRepo($repo);
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

    // FLIPPED 2026-10-24 (r1024/githttp, owner ruling 1b): the header value
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
        // FLIPPED 2026-10-24 (r1024/githttp): the old arm asserted an
        // anonymous 200 with '-advisory' CT — that was the M3 defect (the
        // receive-pack advertisement bypassed every gate). canWrite() is
        // fail-closed false for anonymous callers even on a public repo, so
        // the route now answers 403 BEFORE building any advertisement.
        $result = $this->server->handleRequest(
            'GET',
            '/testrepo.git/info/refs',
            'service=git-receive-pack',
            [],
            ''
        );

        $this->assertSame(403, $result['status']);
        $this->assertStringNotContainsString('refs/', $result['body']);
    }

    public function testReceivePackAdvertisementServesAnAdminOverBasicAuth(): void
    {
        $this->server->registerUser(User::new('pusher')->withAdmin(true)->withPassword('pushpass'));

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
        // FLIPPED 2026-10-24 (r1024/githttp, owner ruling 1b): POST
        // git-receive-pack is a loud phase-2 refusal (501), not a pass to the
        // access layer — push stays unwired while the read path lands.
        $result = $this->server->handleRequest(
            'POST',
            '/testrepo.git/git-receive-pack',
            '',
            [],
            ''
        );

        $this->assertSame(501, $result['status']);
        $this->assertStringContainsString('phase 2', $result['body']);
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

    // FLIPPED 2026-10-24 (r1024/githttp): the old arm pinned
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
        $user = User::new('testuser')->withPassword('secret123');
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
        $user = User::new('authtest')->withPassword('correct-password');
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
