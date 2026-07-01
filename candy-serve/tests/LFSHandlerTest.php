<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\{AccessControl, Config, Repo, User};
use SugarCraft\Serve\LFS\{LFSHandler, LocalStorageBackend, LFSStorageBackendInterface};

/**
 * @covers \SugarCraft\Serve\LFS\LFSHandler
 */
final class LFSHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/lfs-handler-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function createRepo(string $name = 'test-repo'): Repo
    {
        $path = $this->tmpDir . '/repos/' . $name;
        mkdir($path, 0755, true);
        return Repo::new($name, $path)->withPublic(true);
    }

    private function createUser(string $username = 'alice'): User
    {
        return User::new($username);
    }

    // -------------------------------------------------------------------------
    // handleBatch tests
    // -------------------------------------------------------------------------

    public function testHandleBatchReturns403ForAccessDenied(): void
    {
        $repo = $this->createRepo();
        $repo = $repo->withPublic(false)->withPrivate(true);
        $user = $this->createUser();
        $handler = new LFSHandler($repo, $user, $this->tmpDir);

        $request = [
            'operation' => 'download',
            'objects' => [['oid' => 'abc123', 'size' => 100]],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame(403, $result['error']['code']);
        $this->assertSame('Access denied', $result['error']['message']);
    }

    public function testHandleBatchWithDefaultDownloadOperation(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $backend = new LocalStorageBackend($this->tmpDir . '/lfs');
        $handler = new LFSHandler($repo, $user, $this->tmpDir . '/lfs', $backend);

        // No operation specified - defaults to download
        $request = [
            'objects' => [['oid' => 'missing-oid', 'size' => 100]],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(1, $result['objects']);
        $this->assertSame('missing-oid', $result['objects'][0]['oid']);
        $this->assertSame(404, $result['objects'][0]['error']['code']);
    }

    public function testHandleBatchDownloadObjectNotFound(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $backend = new LocalStorageBackend($this->tmpDir . '/lfs');
        $handler = new LFSHandler($repo, $user, $this->tmpDir . '/lfs', $backend);

        $request = [
            'operation' => 'download',
            'objects' => [['oid' => 'nonexistent-oid-12345', 'size' => 100]],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(1, $result['objects']);
        $this->assertSame('nonexistent-oid-12345', $result['objects'][0]['oid']);
        $this->assertSame(404, $result['objects'][0]['error']['code']);
        $this->assertSame('Object not found', $result['objects'][0]['error']['message']);
    }

    public function testHandleBatchDownloadObjectFound(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $lfsDir = $this->tmpDir . '/lfs';
        $backend = new LocalStorageBackend($lfsDir);

        // Write an LFS object first
        $oid = 'abcd1234efgh5678';
        $stream = fopen('data://text/plain;base64,' . base64_encode('Hello LFS'), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $handler = new LFSHandler($repo, $user, $lfsDir, $backend);

        $request = [
            'operation' => 'download',
            'objects' => [['oid' => $oid, 'size' => 10]],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(1, $result['objects']);
        $this->assertSame($oid, $result['objects'][0]['oid']);
        $this->assertArrayHasKey('actions', $result['objects'][0]);
        $this->assertArrayHasKey('download', $result['objects'][0]['actions']);
        $this->assertArrayHasKey('href', $result['objects'][0]['actions']['download']);
        // Authorization header is no longer hardcoded — clients add their own Bearer token
        $this->assertArrayNotHasKey('header', $result['objects'][0]['actions']['download']);
    }

    public function testHandleBatchUploadPreparesUploadEndpoint(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $backend = new LocalStorageBackend($this->tmpDir . '/lfs');
        $handler = new LFSHandler($repo, $user, $this->tmpDir . '/lfs', $backend);

        $request = [
            'operation' => 'upload',
            'objects' => [['oid' => 'upload-oid-12345', 'size' => 256]],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(1, $result['objects']);
        $this->assertSame('upload-oid-12345', $result['objects'][0]['oid']);
        $this->assertArrayHasKey('actions', $result['objects'][0]);
        $this->assertArrayHasKey('upload', $result['objects'][0]['actions']);
        $this->assertStringContainsString('/repos/test-repo/info/lfs/objects/upload-oid-12345', $result['objects'][0]['actions']['upload']['href']);
    }

    public function testHandleBatchWithMultipleObjects(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $lfsDir = $this->tmpDir . '/lfs';
        $backend = new LocalStorageBackend($lfsDir);

        // Write one object
        $oid1 = 'first-object-oid12';
        $stream = fopen('data://text/plain;base64,' . base64_encode('Content 1'), 'r');
        $backend->write($oid1, $stream);
        fclose($stream);

        $handler = new LFSHandler($repo, $user, $lfsDir, $backend);

        $request = [
            'operation' => 'download',
            'objects' => [
                ['oid' => $oid1, 'size' => 9],
                ['oid' => 'missing-oid-second', 'size' => 100],
            ],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(2, $result['objects']);
        // First object found
        $this->assertSame($oid1, $result['objects'][0]['oid']);
        $this->assertArrayHasKey('actions', $result['objects'][0]);
        // Second object not found
        $this->assertSame('missing-oid-second', $result['objects'][1]['oid']);
        $this->assertSame(404, $result['objects'][1]['error']['code']);
    }

    public function testHandleBatchEmptyObjectsArray(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $backend = new LocalStorageBackend($this->tmpDir . '/lfs');
        $handler = new LFSHandler($repo, $user, $this->tmpDir . '/lfs', $backend);

        $request = [
            'operation' => 'download',
            'objects' => [],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(0, $result['objects']);
    }

    public function testHandleBatchAnonymousUserCanReadPublicRepo(): void
    {
        $repo = $this->createRepo();
        $backend = new LocalStorageBackend($this->tmpDir . '/lfs');
        $handler = new LFSHandler($repo, null, $this->tmpDir . '/lfs', $backend);

        $request = [
            'operation' => 'download',
            'objects' => [['oid' => 'any-oid', 'size' => 100]],
        ];

        $result = $handler->handleBatch($request);

        // Should not be a 403 - anonymous can read public repos
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('basic', $result['transfer']);
    }

    // -------------------------------------------------------------------------
    // withStorageBackend tests
    // -------------------------------------------------------------------------

    public function testWithStorageBackendReturnsNewInstance(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $originalBackend = new LocalStorageBackend($this->tmpDir . '/original');
        $newBackend = new LocalStorageBackend($this->tmpDir . '/new');

        $handler = new LFSHandler($repo, $user, $this->tmpDir, $originalBackend);
        $newHandler = $handler->withStorageBackend($newBackend);

        $this->assertNotSame($handler, $newHandler);
        $this->assertSame($newBackend, $newHandler->storageBackend());
        // Original unchanged
        $this->assertSame($originalBackend, $handler->storageBackend());
    }

    // -------------------------------------------------------------------------
    // withConcurrentTransfers tests
    // -------------------------------------------------------------------------

    public function testWithConcurrentTransfersReturnsNewInstance(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $backend = new LocalStorageBackend($this->tmpDir);

        $handler = new LFSHandler($repo, $user, $this->tmpDir, $backend, 4);
        $newHandler = $handler->withConcurrentTransfers(16);

        $this->assertNotSame($handler, $newHandler);
        $this->assertSame(4, $handler->concurrentTransfers());
        $this->assertSame(16, $newHandler->concurrentTransfers());
    }

    public function testWithConcurrentTransfersZeroDisabledConcurrency(): void
    {
        $repo = $this->createRepo();
        $user = $this->createUser();
        $backend = new LocalStorageBackend($this->tmpDir);

        $handler = new LFSHandler($repo, $user, $this->tmpDir, $backend, 1);
        $newHandler = $handler->withConcurrentTransfers(0);

        $this->assertSame(0, $newHandler->concurrentTransfers());
    }
}
