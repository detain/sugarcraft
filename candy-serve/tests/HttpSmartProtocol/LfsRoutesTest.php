<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Config;
use SugarCraft\Serve\HttpSmartProtocol\Server;
use SugarCraft\Serve\LFS\LocalStorageBackend;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\Stats;
use SugarCraft\Serve\User;

/**
 * LFS object HTTP routes on the smart-HTTP server — plan item 7.9.
 *
 * @covers \SugarCraft\Serve\HttpSmartProtocol\Server
 */
final class LfsRoutesTest extends TestCase
{
    private string $tmpDir;
    private Config $config;
    private Server $server;
    private Stats $stats;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/lfs-routes-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);

        // Load from a file so dataPath (and therefore lfsPath) is isolated
        // per test, and so the max_pack_bytes transfer cap is exercised.
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: LfsRoutes\nhttp:\n  max_pack_bytes: 64\n"
        );
        $this->config = Config::load($this->tmpDir . '/config.yaml');

        $this->server = new Server($this->config);
        $this->stats = new Stats();
        $this->server->setStats($this->stats);

        $this->server->registerRepo(Repo::new('testrepo.git', $this->tmpDir . '/testrepo.git'));
        $this->server->registerUser(User::new('admin')->withAdmin(true)->withPassword('adminpass'));
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

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function request(string $method, string $path, string $body = '', array $headers = []): array
    {
        return $this->server->handleRequest($method, $path, '', $headers, $body);
    }

    private function adminHeaders(): array
    {
        // Authenticate over the trusted Basic-auth path: the raw
        // X-CandyServe-User header is no longer trusted by default
        // (fail-closed impersonation fix; see UserTrustTest).
        return ['Authorization' => 'Basic ' . \base64_encode('admin:adminpass')];
    }

    /** Store $content as an LFS object; returns its OID. */
    private function storeObject(string $content): string
    {
        $oid = \hash('sha256', $content);
        $backend = new LocalStorageBackend($this->config->lfsPath());
        $stream = \fopen('php://temp', 'r+');
        \fwrite($stream, $content);
        \rewind($stream);
        $backend->write($oid, $stream);
        \fclose($stream);

        return $oid;
    }

    // -------------------------------------------------------------------------
    // Batch endpoint routing
    // -------------------------------------------------------------------------

    public function testBatchDownloadAdvertisesServedHref(): void
    {
        $oid = $this->storeObject('hello lfs');

        $result = $this->request(
            'POST',
            '/testrepo.git/info/lfs/objects/batch',
            \json_encode(['operation' => 'download', 'objects' => [['oid' => $oid, 'size' => 9]]]),
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('application/vnd.git-lfs+json', $result['headers']['Content-Type']);

        $decoded = \json_decode($result['body'], true);
        $this->assertSame('basic', $decoded['transfer']);
        $this->assertSame(
            "/testrepo.git/info/lfs/objects/{$oid}",
            $decoded['objects'][0]['actions']['download']['href'],
            'batch must advertise the URL the object routes actually serve',
        );
        $this->assertSame(1, $this->stats->lfsBatchRequests());
    }

    public function testBatchUploadRequiresWriteAccess(): void
    {
        $body = \json_encode(['operation' => 'upload', 'objects' => [['oid' => \str_repeat('a', 64), 'size' => 4]]]);

        $anonymous = $this->request('POST', '/testrepo.git/info/lfs/objects/batch', $body);
        $this->assertSame(403, $anonymous['status']);

        $admin = $this->request('POST', '/testrepo.git/info/lfs/objects/batch', $body, $this->adminHeaders());
        $this->assertSame(200, $admin['status']);
        $decoded = \json_decode($admin['body'], true);
        $this->assertArrayHasKey('upload', $decoded['objects'][0]['actions']);
        $this->assertArrayHasKey('verify', $decoded['objects'][0]['actions']);
    }

    public function testBatchRejectsInvalidJson(): void
    {
        $result = $this->request('POST', '/testrepo.git/info/lfs/objects/batch', 'not json {');

        $this->assertSame(400, $result['status']);
    }

    public function testBatchRejectsNonPostMethod(): void
    {
        $result = $this->request('GET', '/testrepo.git/info/lfs/objects/batch');

        $this->assertSame(405, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Download (GET)
    // -------------------------------------------------------------------------

    public function testDownloadHappyPath(): void
    {
        $content = 'hello lfs object';
        $oid = $this->storeObject($content);

        $result = $this->request('GET', "/testrepo.git/info/lfs/objects/{$oid}");

        $this->assertSame(200, $result['status']);
        $this->assertSame('application/octet-stream', $result['headers']['Content-Type']);
        $this->assertSame((string) \strlen($content), $result['headers']['Content-Length']);
        $this->assertSame($content, $result['body']);
        $this->assertSame(1, $this->stats->lfsObjectDownloads());
    }

    public function testDownloadRejectsMalformedOid(): void
    {
        foreach (['abc123', \str_repeat('g', 64), \str_repeat('A', 64), '../../../etc/passwd'] as $bad) {
            $result = $this->request('GET', "/testrepo.git/info/lfs/objects/{$bad}");
            $this->assertSame(400, $result['status'], "oid {$bad} must be rejected");
        }
        $this->assertSame(0, $this->stats->lfsObjectDownloads());
    }

    public function testDownloadMissingObjectReturns404(): void
    {
        $result = $this->request('GET', '/testrepo.git/info/lfs/objects/' . \str_repeat('0', 64));

        $this->assertSame(404, $result['status']);
    }

    public function testDownloadPrivateRepoRequiresRead(): void
    {
        $this->server->registerRepo(
            Repo::new('secret.git', $this->tmpDir . '/secret.git')->withPrivate(true)
        );
        $oid = $this->storeObject('secret bytes');

        $anonymous = $this->request('GET', "/secret.git/info/lfs/objects/{$oid}");
        $this->assertSame(403, $anonymous['status']);

        $admin = $this->request('GET', "/secret.git/info/lfs/objects/{$oid}", '', $this->adminHeaders());
        $this->assertSame(200, $admin['status']);
    }

    // -------------------------------------------------------------------------
    // Upload (PUT)
    // -------------------------------------------------------------------------

    public function testUploadHappyPathStoresObject(): void
    {
        $content = 'uploaded payload';
        $oid = \hash('sha256', $content);

        $result = $this->request('PUT', "/testrepo.git/info/lfs/objects/{$oid}", $content, $this->adminHeaders());

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $this->stats->lfsObjectUploads());

        $backend = new LocalStorageBackend($this->config->lfsPath());
        $this->assertTrue($backend->exists($oid));
        $this->assertSame($content, $backend->readAll($oid));

        // Round-trip: the freshly uploaded object is immediately downloadable.
        $download = $this->request('GET', "/testrepo.git/info/lfs/objects/{$oid}");
        $this->assertSame(200, $download['status']);
        $this->assertSame($content, $download['body']);
    }

    public function testUploadRequiresWriteAccess(): void
    {
        $content = 'nope';
        $oid = \hash('sha256', $content);

        $result = $this->request('PUT', "/testrepo.git/info/lfs/objects/{$oid}", $content);

        $this->assertSame(403, $result['status']);
        $this->assertFalse((new LocalStorageBackend($this->config->lfsPath()))->exists($oid));
    }

    public function testUploadRejectsOversizeBody(): void
    {
        // config caps transfers at http.max_pack_bytes = 64
        $content = \str_repeat('x', 65);
        $oid = \hash('sha256', $content);

        $result = $this->request('PUT', "/testrepo.git/info/lfs/objects/{$oid}", $content, $this->adminHeaders());

        $this->assertSame(413, $result['status']);
        $this->assertFalse((new LocalStorageBackend($this->config->lfsPath()))->exists($oid));
        $this->assertSame(0, $this->stats->lfsObjectUploads());
    }

    public function testUploadRejectsHashMismatch(): void
    {
        $oid = \hash('sha256', 'what the client claimed');

        $result = $this->request('PUT', "/testrepo.git/info/lfs/objects/{$oid}", 'different bytes', $this->adminHeaders());

        $this->assertSame(422, $result['status']);
        $this->assertFalse((new LocalStorageBackend($this->config->lfsPath()))->exists($oid));
    }

    public function testObjectRouteRejectsOtherMethods(): void
    {
        $oid = \str_repeat('0', 64);

        $result = $this->request('DELETE', "/testrepo.git/info/lfs/objects/{$oid}", '', $this->adminHeaders());

        $this->assertSame(405, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Verify (POST …/verify)
    // -------------------------------------------------------------------------

    public function testVerifyHappyPath(): void
    {
        $content = 'verified payload';
        $oid = $this->storeObject($content);

        $result = $this->request(
            'POST',
            "/testrepo.git/info/lfs/objects/{$oid}/verify",
            \json_encode(['oid' => $oid, 'size' => \strlen($content)]),
            $this->adminHeaders(),
        );

        $this->assertSame(200, $result['status']);
        $decoded = \json_decode($result['body'], true);
        $this->assertSame($oid, $decoded['oid']);
        $this->assertSame(\strlen($content), $decoded['size']);
    }

    public function testVerifyMissingObjectReturns404(): void
    {
        $oid = \str_repeat('1', 64);

        $result = $this->request(
            'POST',
            "/testrepo.git/info/lfs/objects/{$oid}/verify",
            \json_encode(['oid' => $oid, 'size' => 1]),
            $this->adminHeaders(),
        );

        $this->assertSame(404, $result['status']);
    }

    public function testVerifySizeMismatchReturns422(): void
    {
        $oid = $this->storeObject('four');

        $result = $this->request(
            'POST',
            "/testrepo.git/info/lfs/objects/{$oid}/verify",
            \json_encode(['oid' => $oid, 'size' => 999]),
            $this->adminHeaders(),
        );

        $this->assertSame(422, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Guard rails
    // -------------------------------------------------------------------------

    public function testLfsRoutesReturn404WhenLfsDisabled(): void
    {
        \file_put_contents(
            $this->tmpDir . '/config.yaml',
            "name: LfsOff\nlfs:\n  enabled: false\n"
        );
        $server = new Server(Config::load($this->tmpDir . '/config.yaml'));
        $server->setStats(new Stats());
        $server->registerRepo(Repo::new('testrepo.git', $this->tmpDir . '/testrepo.git'));

        $result = $server->handleRequest('POST', '/testrepo.git/info/lfs/objects/batch', '', [], '{}');

        $this->assertSame(404, $result['status']);
    }

    public function testLfsRoutesReturn404ForUnknownRepo(): void
    {
        $result = $this->request('POST', '/nope.git/info/lfs/objects/batch', '{}');

        $this->assertSame(404, $result['status']);
    }

    public function testLfsRoutesReturn404ForUnknownSubPath(): void
    {
        $result = $this->request('GET', '/testrepo.git/info/lfs/locks');

        $this->assertSame(404, $result['status']);
    }

    public function testStatsRecordConnectionsPerRequest(): void
    {
        $this->request('GET', '/testrepo.git/info/lfs/locks');
        $this->request('POST', '/testrepo.git/info/lfs/objects/batch', '{}');

        $this->assertSame(2, $this->stats->connections());
    }
}
