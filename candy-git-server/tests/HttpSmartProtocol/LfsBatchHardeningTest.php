<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\LFS\LFSHandler;
use SugarCraft\GitServer\LFS\LFSStorageBackendInterface;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

/**
 * LFS batch hardening: malformed object specifications are refused with
 * per-object 400 entries WITHOUT the storage layer ever being consulted,
 * operation/transfer vocabularies are allowlisted at both the boundary
 * and the handler (predicate parity), and hrefs honour http.public_url.
 *
 * @covers \SugarCraft\GitServer\LFS\LFSHandler
 */
final class LfsBatchHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/lfs-batch-harden-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        AccessControl::instance()->setAllowAnonymousRead(false);
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

    private function publicRepo(): Repo
    {
        $path = $this->tmpDir . '/hardrepo';
        if (!\is_dir($path)) {
            \mkdir($path, 0755, true);
        }
        return Repo::new('hardrepo', $path)->withPublic(true);
    }

    private function privateRepo(): Repo
    {
        $path = $this->tmpDir . '/lockrepo';
        if (!\is_dir($path)) {
            \mkdir($path, 0755, true);
        }
        return Repo::new('lockrepo', $path)->withPublic(false);
    }

    public function testMalformedEntriesAreRefusedWithoutTouchingStorage(): void
    {
        $backend = new RecordingBackend();
        $handler = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            $backend,
            publicBaseUrl: ''
        );

        $request = [
            'operation' => 'download',
            'objects' => [
                ['oid' => \str_repeat('x', 64), 'size' => 5],
                ['oid' => '../' . \str_repeat('a', 61), 'size' => 5],
                ['oid' => \str_repeat('b', 64)],
                ['oid' => \str_repeat('c', 64), 'size' => 300000000],
                'garbage-string',
            ],
        ];

        $result = $handler->handleBatch($request);

        $this->assertSame('basic', $result['transfer']);
        $this->assertCount(5, $result['objects']);
        foreach ($result['objects'] as $entry) {
            $this->assertSame('', $entry['oid']);
            $this->assertSame(0, $entry['size']);
            $this->assertSame(400, $entry['error']['code']);
            $this->assertSame('Invalid object specification', $entry['error']['message']);
        }
        $this->assertSame(
            0,
            $backend->totalProbes(),
            'attacker-supplied oids must never reach the storage layer'
        );
    }

    public function testUnsupportedOperationIsRefusedWholeBatch(): void
    {
        $handler = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            new RecordingBackend(),
            publicBaseUrl: ''
        );

        $result = $handler->handleBatch([
            'operation' => 'frobinate',
            'objects' => [['oid' => \str_repeat('a', 64), 'size' => 1]],
        ]);

        $this->assertSame(400, $result['error']['code']);
        $this->assertSame('Unsupported operation', $result['error']['message']);
    }

    public function testUnsupportedTransferTypeIsRefused(): void
    {
        $handler = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            new RecordingBackend(),
            publicBaseUrl: ''
        );

        $result = $handler->handleBatch([
            'operation' => 'download',
            'transfers' => ['lfs-standalone-file'],
            'objects' => [['oid' => \str_repeat('a', 64), 'size' => 1]],
        ]);

        $this->assertSame(400, $result['error']['code']);
        $this->assertSame('Unsupported transfer type', $result['error']['message']);
    }

    public function testUploadOperationRequiresWriteAccessAtTheHandler(): void
    {
        // Predicate parity with the HTTP boundary: a reader (no write on a
        // private repo) forging an upload batch is refused INSIDE the
        // handler too — not just by Server's pre-gate.
        $handler = new LFSHandler(
            $this->privateRepo(),
            User::new('bob'),
            $this->tmpDir . '/lfs',
            new RecordingBackend(),
            publicBaseUrl: ''
        );

        $result = $handler->handleBatch([
            'operation' => 'upload',
            'objects' => [['oid' => \str_repeat('a', 64), 'size' => 1]],
        ]);

        $this->assertSame(403, $result['error']['code']);
        $this->assertSame('Access denied', $result['error']['message']);
    }

    public function testAsyncPathIsRefusedExactlyLikeTheSequentialOne(): void
    {
        $backend = new RecordingBackend();
        $handler = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            $backend,
            publicBaseUrl: ''
        );
        $request = [
            'operation' => 'download',
            'objects' => [
                ['oid' => \str_repeat('x', 64), 'size' => 5],
                ['oid' => \str_repeat('b', 64)],
            ],
        ];

        $sequential = $handler->handleBatch($request);

        $loop = new StreamSelectLoop();
        $async = null;
        $handler->handleBatchAsync($request, $loop)
            ->then(static function (array $r) use (&$async): void {
                $async = $r;
            });
        $loop->run();

        $this->assertIsArray($async, 'async batch promise must resolve');
        $this->assertSame($sequential, $async);
        $this->assertSame(0, $backend->totalProbes());
    }

    public function testHrefsHonourTheConfiguredPublicUrl(): void
    {
        $oid = \str_repeat('a', 64);

        $relative = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            new RecordingBackend(existsOids: [$oid]),
            publicBaseUrl: ''
        );
        $result = $relative->handleBatch([
            'operation' => 'download',
            'objects' => [['oid' => $oid, 'size' => 9]],
        ]);
        $this->assertSame("/hardrepo/info/lfs/objects/{$oid}", $result['objects'][0]['actions']['download']['href']);

        $absolute = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            new RecordingBackend(existsOids: [$oid]),
            publicBaseUrl: 'https://git.example.test'
        );
        $result = $absolute->handleBatch([
            'operation' => 'download',
            'objects' => [['oid' => $oid, 'size' => 9]],
        ]);
        $this->assertSame(
            "https://git.example.test/hardrepo/info/lfs/objects/{$oid}",
            $result['objects'][0]['actions']['download']['href']
        );

        $default = new LFSHandler(
            $this->publicRepo(),
            User::new('alice'),
            $this->tmpDir . '/lfs',
            new RecordingBackend(existsOids: [$oid])
        );
        $result = $default->handleBatch([
            'operation' => 'download',
            'objects' => [['oid' => $oid, 'size' => 9]],
        ]);
        $this->assertSame(
            "http://localhost:23232/hardrepo/info/lfs/objects/{$oid}",
            $result['objects'][0]['actions']['download']['href']
        );
    }
}

/**
 * Storage double that COUNTS every probe so validation failures can be
 * pinned as "the backend was never asked".
 */
final class RecordingBackend implements LFSStorageBackendInterface
{
    public int $existsCalls = 0;
    public int $sizeCalls = 0;
    public int $readCalls = 0;
    public int $writeCalls = 0;
    public int $deleteCalls = 0;
    public int $pathCalls = 0;

    /** @param list<string> $existsOids */
    public function __construct(private array $existsOids = [])
    {
    }

    public function totalProbes(): int
    {
        return $this->existsCalls + $this->sizeCalls + $this->readCalls
            + $this->writeCalls + $this->deleteCalls + $this->pathCalls;
    }

    public function exists(string $oid): bool
    {
        $this->existsCalls++;
        return \in_array($oid, $this->existsOids, true);
    }

    public function size(string $oid): int
    {
        $this->sizeCalls++;
        return 9;
    }

    public function read(string $oid)
    {
        $this->readCalls++;
        return \fopen('data://text/plain;base64,' . \base64_encode('content'), 'r');
    }

    public function write(string $oid, $stream): void
    {
        $this->writeCalls++;
    }

    public function delete(string $oid): void
    {
        $this->deleteCalls++;
    }

    public function path(string $oid): ?string
    {
        $this->pathCalls++;
        return null;
    }
}
