<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\GitServer\LFS\{LFSHandler, LFSStorageBackendInterface, LocalStorageBackend};
use SugarCraft\GitServer\{Repo, User};

/**
 * Async (event-loop) LFS batch processing.
 *
 * @covers \SugarCraft\GitServer\LFS\LFSHandler
 */
final class LFSHandlerAsyncTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/lfs-handler-async-test-' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
        // handleBatch consults canRead; these pins model the published
        // world where the operator opted into anonymous reads (the fail-closed
        // default is pinned in AnonymousReadLeverTest).
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(true);
    }

    protected function tearDown(): void
    {
        \SugarCraft\GitServer\AccessControl::instance()->setAllowAnonymousRead(false);
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

    private function createRepo(string $name = 'test-repo'): Repo
    {
        $path = $this->tmpDir . '/repos/' . $name;
        \mkdir($path, 0755, true);

        return Repo::new($name, $path)->withPublic(true);
    }

    /** Resolve an async batch on a private StreamSelectLoop. */
    private function awaitBatch(LFSHandler $handler, array $request): array
    {
        $loop = new StreamSelectLoop();
        $result = null;
        $handler->handleBatchAsync($request, $loop)
            ->then(static function (array $r) use (&$result): void {
                $result = $r;
            });
        $loop->run();

        $this->assertIsArray($result, 'async batch promise must resolve');

        return $result;
    }

    // -------------------------------------------------------------------------
    // Parity with the sequential path
    // -------------------------------------------------------------------------

    public function testAsyncBatchProducesIdenticalResultsToSequential(): void
    {
        $repo = $this->createRepo();
        $lfsDir = $this->tmpDir . '/lfs';
        $backend = new LocalStorageBackend($lfsDir);

        // Two stored objects + one missing → mixed action/error entries.
        foreach ([\str_repeat('a', 64), \str_repeat('c', 64)] as $i => $oid) {
            $stream = \fopen('data://text/plain;base64,' . \base64_encode("Content {$i}"), 'r');
            $backend->write($oid, $stream);
            \fclose($stream);
        }

        $handler = new LFSHandler($repo, User::new('alice'), $lfsDir, $backend, 2, publicBaseUrl: '');
        $request = [
            'operation' => 'download',
            'objects' => [
                ['oid' => \str_repeat('a', 64), 'size' => 9],
                ['oid' => \str_repeat('b', 64), 'size' => 100],
                ['oid' => \str_repeat('c', 64), 'size' => 9],
            ],
        ];

        $sequential = $handler->handleBatch($request);
        $async = $this->awaitBatch($handler, $request);

        $this->assertSame($sequential, $async);
        // Per-object error entries preserved exactly as sequential reports them.
        $this->assertSame(404, $async['objects'][1]['error']['code']);
        $this->assertSame('Object not found', $async['objects'][1]['error']['message']);
    }

    public function testAsyncBatchEmptyObjects(): void
    {
        $repo = $this->createRepo();
        $handler = new LFSHandler($repo, null, $this->tmpDir . '/lfs', publicBaseUrl: '');

        $result = $this->awaitBatch($handler, ['operation' => 'download', 'objects' => []]);

        $this->assertSame(['transfer' => 'basic', 'objects' => []], $result);
    }

    public function testAsyncBatchAccessDeniedResolves403(): void
    {
        $repo = $this->createRepo()->withPublic(false)->withPrivate(true);
        $handler = new LFSHandler($repo, User::new('mallory'), $this->tmpDir . '/lfs', publicBaseUrl: '');

        $result = $this->awaitBatch($handler, [
            'operation' => 'download',
            'objects' => [['oid' => \str_repeat('d', 64), 'size' => 1]],
        ]);

        $this->assertSame(['error' => ['code' => 403, 'message' => 'Access denied']], $result);
    }

    public function testAsyncBatchRefusesNonArrayObjectsAtTheGate(): void
    {
        // 026: the async wave shares the sync gate — a scalar 'objects'
        // resolves the 400 answer instead of arming a promise over a
        // foreach warning.
        $repo = $this->createRepo();
        $handler = new LFSHandler($repo, null, $this->tmpDir . '/lfs', publicBaseUrl: '');

        $result = $this->awaitBatch($handler, ['operation' => 'download', 'objects' => 'not-an-array']);

        $this->assertSame(['error' => ['code' => 400, 'message' => 'Invalid objects']], $result);
    }

    // -------------------------------------------------------------------------
    // Error isolation
    // -------------------------------------------------------------------------

    public function testBackendExceptionOnOneObjectDoesNotSinkTheBatch(): void
    {
        $repo = $this->createRepo();
        $backend = new class implements LFSStorageBackendInterface {
            public function exists(string $oid): bool
            {
                if ($oid === \str_repeat('e', 64)) {
                    throw new \RuntimeException('backend exploded');
                }

                return false;
            }

            public function size(string $oid): int
            {
                return 0;
            }

            public function read(string $oid)
            {
                throw new \RuntimeException('unused');
            }

            public function write(string $oid, $stream): void
            {
            }

            public function delete(string $oid): void
            {
            }

            public function path(string $oid): ?string
            {
                return null;
            }
        };

        $handler = new LFSHandler($repo, null, $this->tmpDir . '/lfs', $backend, 4, publicBaseUrl: '');
        $result = $this->awaitBatch($handler, [
            'operation' => 'download',
            'objects' => [
                ['oid' => \str_repeat('e', 64), 'size' => 1],
                ['oid' => \str_repeat('f', 64), 'size' => 2],
            ],
        ]);

        $this->assertCount(2, $result['objects']);

        // Failed object reported in place, not thrown at the batch level.
        $this->assertSame(\str_repeat('e', 64), $result['objects'][0]['oid']);
        $this->assertSame(500, $result['objects'][0]['error']['code']);
        $this->assertSame('Internal storage error', $result['objects'][0]['error']['message']);
        // Step 20: the backend exception text — which in production can
        // embed absolute filesystem paths — must not surface anywhere in
        // the wire payload.
        $this->assertStringNotContainsString('backend exploded', \json_encode($result));

        // The other object still processed and reported like sequential would.
        $this->assertSame(\str_repeat('f', 64), $result['objects'][1]['oid']);
        $this->assertSame(404, $result['objects'][1]['error']['code']);
    }

    /**
     * Step 20: the storage-backend exception detail on BOTH batch paths
     * (the sync walk and the async walk share safeObject as their one
     * choke) is logged server-side — where a real failure's absolute
     * directory is legitimate diagnostics — and the wire payload carries
     * only the generic 500.
     */
    public function testStorageDetailIsLoggedServerSideAndNeverEchoedOnTheWire(): void
    {
        $repo = $this->createRepo();
        $canary = 'Cannot create LFS directory: ' . $this->tmpDir . '/lfs/objects/c4';
        $backend = new class($canary) implements LFSStorageBackendInterface {
            public function __construct(private string $failureMessage)
            {
            }

            public function exists(string $oid): bool
            {
                throw new \RuntimeException($this->failureMessage);
            }

            public function size(string $oid): int
            {
                return 0;
            }

            public function read(string $oid)
            {
                throw new \RuntimeException('unused');
            }

            public function write(string $oid, $stream): void
            {
            }

            public function delete(string $oid): void
            {
            }

            public function path(string $oid): ?string
            {
                return null;
            }
        };
        $handler = new LFSHandler($repo, null, $this->tmpDir . '/lfs', $backend, 2, publicBaseUrl: '');

        $oid = \str_repeat('a', 64);
        $logFile = $this->tmpDir . '/error.log';
        $previous = \ini_get('error_log');
        \ini_set('error_log', $logFile);
        try {
            $sync = $handler->handleBatch([
                'operation' => 'download',
                'objects'   => [['oid' => $oid, 'size' => 1]],
            ]);
            $async = $this->awaitBatch($handler, [
                'operation' => 'download',
                'objects'   => [['oid' => $oid, 'size' => 1]],
            ]);
        } finally {
            \ini_set('error_log', \is_string($previous) ? $previous : '');
        }

        foreach (['sync' => $sync, 'async' => $async] as $label => $result) {
            $this->assertSame(500, $result['objects'][0]['error']['code'], $label);
            $this->assertSame('Internal storage error', $result['objects'][0]['error']['message'], $label);
            $this->assertStringNotContainsString($canary, \json_encode($result), $label);
        }

        $this->assertFileExists($logFile);
        $logged = (string) \file_get_contents($logFile);
        $this->assertSame(2, \substr_count($logged, $canary), 'both batch paths must log the detail');
        $this->assertStringContainsString($oid, $logged);
    }

    // -------------------------------------------------------------------------
    // Scheduling instrumentation
    // -------------------------------------------------------------------------

    /**
     * Instrumented backend stub: with the pool bound at
     * $concurrentTransfers, per-object storage calls happen once per
     * object and in input order (the pool never reorders or duplicates
     * work). The bound itself is proven with overlapping promise tasks
     * in PromisePoolTest::testBoundedConcurrencyRespected — LFS object
     * inspection is synchronous file I/O inside its tick, so backend
     * calls can never overlap by construction.
     */
    public function testAsyncBatchCallsBackendOncePerObjectInInputOrder(): void
    {
        $repo = $this->createRepo();
        $backend = new class implements LFSStorageBackendInterface {
            /** @var list<string> */
            public array $calls = [];

            public function exists(string $oid): bool
            {
                $this->calls[] = $oid;

                return false;
            }

            public function size(string $oid): int
            {
                return 0;
            }

            public function read(string $oid)
            {
                throw new \RuntimeException('unused');
            }

            public function write(string $oid, $stream): void
            {
            }

            public function delete(string $oid): void
            {
            }

            public function path(string $oid): ?string
            {
                return null;
            }
        };

        $oids = [\str_repeat('1', 64), \str_repeat('2', 64), \str_repeat('3', 64), \str_repeat('4', 64), \str_repeat('5', 64), \str_repeat('6', 64)];
        $handler = new LFSHandler($repo, null, $this->tmpDir . '/lfs', $backend, 2, publicBaseUrl: '');
        $result = $this->awaitBatch($handler, [
            'operation' => 'download',
            'objects' => \array_map(static fn (string $o): array => ['oid' => $o, 'size' => 1], $oids),
        ]);

        $this->assertSame($oids, $backend->calls);
        $this->assertSame($oids, \array_column($result['objects'], 'oid'));
    }
}
