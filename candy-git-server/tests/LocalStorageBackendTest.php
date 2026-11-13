<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\LFS\LocalStorageBackend;

/**
 * @covers \SugarCraft\GitServer\LFS\LocalStorageBackend
 */
final class LocalStorageBackendTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/lfs-backend-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
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

    public function testObjectPathGeneration(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '904cbdbe7979166aadb98c9c00d60736d71ad995c61f34a409d1c568f35e7ce4';

        // Use reflection to test private method
        $reflection = new \ReflectionClass($backend);
        $method = $reflection->getMethod('objectPath');
        $method->setAccessible(true);

        $path = $method->invoke($backend, $oid);

        // Path should follow LFS convention: {lfsPath}/{first 2 chars}/{next 2 chars}/{oid}
        $this->assertSame($this->tmpDir . '/90/4c/904cbdbe7979166aadb98c9c00d60736d71ad995c61f34a409d1c568f35e7ce4', $path);
    }

    public function testSizeReturnsZeroForNonexistentObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        $size = $backend->size('ded3622590016d80baa0af802c34321a98f36c0b0ef4d0af7d0d227072b37a3c');

        $this->assertSame(0, $size);
    }

    public function testSizeReturnsActualSize(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '5e11e02e1dc2f7367c963fdc37c264004751d3a795dd3558e7d5af7b2b7b87ac';

        // Write content of known size
        $content = 'Hello World';
        $stream = fopen('data://text/plain;base64,' . base64_encode($content), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $size = $backend->size($oid);

        $this->assertSame(\strlen($content), $size);
    }

    public function testReadThrowsExceptionForNonexistentObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $backend->read('65acc4da954246fe4d887e4510f96d1042689034531f3b0c03d94bb7bff9abf6');
    }

    public function testReadReturnsResourceForExistingObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '24f9b272395018cab5fadab4ff408ecc3263d01c642f3b8c4c93c59886859dc9';

        // Write content
        $content = 'Read Test Content';
        $stream = fopen('data://text/plain;base64,' . base64_encode($content), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $resource = $backend->read($oid);

        $this->assertIsResource($resource);
        $readContent = stream_get_contents($resource);
        $this->assertSame($content, $readContent);
        fclose($resource);
    }

    public function testReadAllReturnsFullContent(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '75c4ec55302e56f2ffdfa582e6e0c3655ed576d768b1fe8f03d7854a1e4bf179';

        $content = 'Read All Content';
        $stream = fopen('data://text/plain;base64,' . base64_encode($content), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $this->assertSame($content, $backend->readAll($oid));
    }

    public function testReadAllThrowsExceptionForNonexistentObject(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $backend->readAll('65acc4da954246fe4d887e4510f96d1042689034531f3b0c03d94bb7bff9abf6');
    }

    public function testWriteCreatesNestedDirectories(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '8370faf2339871a44f17b0d579c8b26b74aa896fc79e5f8897f29170ffb8019d';

        // LFS path should create directories like: ab/cd/oid
        $stream = fopen('data://text/plain;base64,' . base64_encode('Nested'), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $this->assertTrue($backend->exists($oid));
        $this->assertDirectoryExists($this->tmpDir . '/83');
        $this->assertDirectoryExists($this->tmpDir . '/83/70');
    }

    public function testPathReturnsFullPath(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '4e0c10a56289e8c0fc6858af026e8e342bbfda615636a6c51356afc81d3db322';

        $stream = fopen('data://text/plain;base64,' . base64_encode('Path Test'), 'r');
        $backend->write($oid, $stream);
        fclose($stream);

        $path = $backend->path($oid);

        $this->assertNotNull($path);
        $this->assertSame($this->tmpDir . '/4e/0c/4e0c10a56289e8c0fc6858af026e8e342bbfda615636a6c51356afc81d3db322', $path);
    }

    public function testPathReturnsNullForNonexistent(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        // Actually path() returns the path regardless of existence
        $path = $backend->path('65acc4da954246fe4d887e4510f96d1042689034531f3b0c03d94bb7bff9abf6');

        $this->assertNotNull($path);
        $this->assertStringContainsString('65acc4da954246fe4d887e4510f96d1042689034531f3b0c03d94bb7bff9abf6', $path);
    }

    public function testWriteAndOverwrite(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = '6ac66fb734a10d5465f242d268a7baa0c2f686824514197bdb8b813bd1577d9a';

        // Write initial content
        $stream1 = fopen('data://text/plain;base64,' . base64_encode('Original'), 'r');
        $backend->write($oid, $stream1);
        fclose($stream1);

        // Overwrite with new content
        $stream2 = fopen('data://text/plain;base64,' . base64_encode('Updated'), 'r');
        $backend->write($oid, $stream2);
        fclose($stream2);

        // Verify new content
        $resource = $backend->read($oid);
        $content = stream_get_contents($resource);
        fclose($resource);

        $this->assertSame('Updated', $content);
    }

    public function testDeleteNonExistentDoesNotThrow(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);

        // Honest shape: nothing existed, nothing may appear — the old arm
        // asserted only that no exception flew out.
        $backend->delete('777babe0292c639d0d4cb2c03f8e80f6c68ba8023bf51e1476f1049bfcf3a29a');

        $this->assertSame([], \array_diff(\scandir($this->tmpDir), ['.', '..']));
    }

    public function testWritePublishesAtomicallyWithPrivateModeAndNoResidue(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = \hash('sha256', 'atomic-payload');
        $source = \fopen('data://text/plain,atomic-payload', 'rb');
        $this->assertIsResource($source);

        $backend->write($oid, $source);
        \fclose($source);

        $path = $this->tmpDir . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;
        $this->assertFileExists($path);
        $this->assertSame('atomic-payload', \file_get_contents($path));
        // Mode arrives via chmod-on-temp-then-rename, never a widened final.
        $this->assertSame(0600, \fileperms($path) & 0777);
        // The same-directory temp is renamed away: only the object remains.
        $bucket = $this->tmpDir . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2);
        $this->assertSame(
            [\basename($path)],
            \array_values(\array_diff(\scandir($bucket), ['.', '..'])),
            'no .tmp- residue may survive a successful write'
        );
    }

    public function testFailedWriteLeavesNeitherObjectNorTempBehind(): void
    {
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = \hash('sha256', 'doomed-payload');
        $path = $this->tmpDir . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;

        // A closed source stream fails the copy before publication.
        $source = \fopen('data://text/plain,x', 'rb');
        $this->assertIsResource($source);
        \fclose($source);

        try {
            $backend->write($oid, $source);
            $this->fail('write() must refuse a dead source stream');
        } catch (\Throwable) {
            // Expected shape: TypeError or RuntimeException, never silence.
        }

        $this->assertFileDoesNotExist($path, 'a failed write must never publish the final object');
        $bucket = \dirname($path);
        if (\is_dir($bucket)) {
            $this->assertSame([], \array_values(\array_diff(\scandir($bucket), ['.', '..'])),
                'the temp file must be swept on failure');
        }
    }

    public function testWriteSourceIsTheStructuralPinForTempThenRename(): void
    {
        // Functional arms can pass under a 'chmod AFTER rename' mutation —
        // the window where the final object is world-readable is exactly
        // what must stay structurally impossible. Since 049 the shape
        // lives once in publish() and BOTH doors route through it, so the
        // guard moved with the code: seam ordering here, door wiring
        // below, and no door may open the final path directly.
        $source = (string) \file_get_contents(\dirname(__DIR__) . '/src/LFS/LocalStorageBackend.php');
        \preg_match('/private function publish\(.*?\n    \}/s', $source, $body);
        $this->assertNotEmpty($body, 'the publish seam vanished from the backend source');

        $this->assertStringContainsString("fopen(\$temp, 'xb')", $body[0], 'temp-file publish lost');
        $this->assertStringContainsString('rename($temp, $path)', $body[0]);
        $this->assertStringContainsString('chmod($temp, 0600)', $body[0], 'mode must settle on the temp, before rename');
        $chmod = \strpos($body[0], 'chmod($temp, 0600)');
        $copy = \strpos($body[0], '$sink($handle)');
        // Order: chmod strictly before ANY payload sink runs, and the sink
        // strictly before the publish rename. The mode arrives on a temp
        // inode that never held bytes at the umask default, so neither the
        // temp nor the renamed target is ever world-readable — the
        // chmod-after-copy shape left exactly that window open.
        $this->assertLessThan($copy, $chmod, 'chmod-after-copy reopens the world-readable temp window');
        $this->assertLessThan(
            \strpos($body[0], 'rename($temp, $path)'),
            $copy,
            'payload must land in the temp before the rename publishes it'
        );
        $this->assertStringNotContainsString('fopen($path', $body[0], 'direct write to the final path is back');

        // 041: the durability barrier sits between the sink and the
        // publish — bytes flush, fsync, THEN the name moves. Dropping the
        // fsync revives the power-loss window where a renamed object
        // exists with content that never reached the media, and a
        // content-addressed store serves that poisoned oid forever.
        $this->assertStringContainsString('fsync($handle)', $body[0], 'fsync-before-rename discipline lost');
        $this->assertLessThan(
            \strpos($body[0], 'rename($temp, $path)'),
            \strpos($body[0], 'fsync($handle)'),
            'the rename must not outrun the durability barrier'
        );
        $this->assertLessThan(
            \strpos($body[0], 'fsync($handle)'),
            \strpos($body[0], '$sink($handle)'),
            'every payload byte must be in the handle before it is fsynced'
        );

        \preg_match('/public function write\(.*?\n    \}/s', $source, $writeDoor);
        \preg_match('/public function writeBytes\(.*?\n    \}/s', $source, $bytesDoor);
        $this->assertNotEmpty($writeDoor, 'the stream door vanished');
        $this->assertNotEmpty($bytesDoor, 'the bytes door vanished');
        $this->assertStringContainsString('$this->publish($oid,', $writeDoor[0], 'the stream door must route through the shared seam');
        $this->assertStringContainsString('$this->publish($oid,', $bytesDoor[0], 'the bytes door must route through the shared seam');
        $this->assertStringNotContainsString('fopen($path', $writeDoor[0] . $bytesDoor[0], 'no door may open the final path directly');
    }

    public function testWritePublishesWithModeZeroSixHundredEvenOverALoosePredecessor(): void
    {
        // The rename carries the temp's settled 0600 onto the target: a
        // pre-existing world-readable object at the final path is tightened
        // by replacement, never left loose.
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = \str_repeat('a', 64);
        $backend->write($oid, \fopen('data://text/plain,first', 'rb'));

        $path = $backend->path($oid);
        $this->assertSame(0600, \fileperms($path) & 0777, 'fresh object must be 0600');
        @\chmod($path, 0644); // simulate a loose predecessor
        $backend->write($oid, \fopen('data://text/plain,second', 'rb'));
        $this->assertSame(0600, \fileperms($path) & 0777, 'replacement must re-tighten the mode');
        $this->assertSame('second', \file_get_contents($path));
    }

    public function testWriteBytesPublishesSliceCrossingPayloadsByteExactlyAtPrivateMode(): void
    {
        // 049: the upload router's bytes door. Past one 64 KiB slice so
        // the bounded loop's reassembly is what's under test, not a
        // single lucky fwrite — and through the SHARED publish seam, so
        // the mode/temp/rename guarantees of write() carry over.
        $backend = new LocalStorageBackend($this->tmpDir);
        $payload = \str_repeat('lfs', 30000); // 90_000 bytes
        $oid = \hash('sha256', $payload);

        $backend->writeBytes($oid, $payload);

        $this->assertTrue($backend->exists($oid));
        $this->assertSame(\strlen($payload), $backend->size($oid));
        $this->assertSame($payload, $backend->readAll($oid), 'slice reassembly must be byte-exact');

        $path = $this->tmpDir . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;
        $this->assertSame(0600, \fileperms($path) & 0777, 'the bytes door settles 0600 before the first payload byte');
        $this->assertSame(
            [\basename($path)],
            \array_values(\array_diff(\scandir(\dirname($path)), ['.', '..'])),
            'no .tmp- residue may survive a successful writeBytes'
        );
    }

    public function testWriteBytesRefusesAndSweepsWhenThePayloadCannotLand(): void
    {
        // The writeBytes sink's failure leg: a zero-length fwrite from a
        // disk-full handle is not testable here, so arm the deterministic
        // one — an oid whose object path collides with a DIRECTORY, which
        // makes rename (and thus publish) fail after the sink ran, and
        // the seam must sweep the temp and throw.
        $backend = new LocalStorageBackend($this->tmpDir);
        $oid = \hash('sha256', 'collision');
        $path = $this->tmpDir . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;
        \mkdir(\dirname($path), 0755, true);
        \mkdir($path); // final object slot occupied by a directory

        try {
            $backend->writeBytes($oid, 'payload bytes');
            $this->fail('writeBytes() must refuse a publish its rename cannot complete');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot publish LFS object', $e->getMessage());
        }

        $bucket = \dirname($path);
        $this->assertSame(
            [\basename($path)],
            \array_values(\array_diff(\scandir($bucket), ['.', '..'])),
            'the temp must be swept when publication fails'
        );
    }

    public function testOidGuardRefusesOffShapeIdsAtEveryOidAddressedDoor(): void
    {
        // 097: containment is storage-side truth, not an upstream favor.
        // objectPath() is the single choke point every door derives
        // through, so one guard must answer for all eight doors — and
        // the octet every one of them historically waved through.
        $backend = new LocalStorageBackend($this->tmpDir);

        $offShape = [
            'traversal' => '../../etc/passwd',
            'empty' => '',
            'short' => 'abc123',
            'upper' => \strtoupper(\hash('sha256', 'x')),
            'odd-chars' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbee-',
            'nul-byte' => \str_repeat('a', 61) . "\0xy",
            'one-too-long' => \str_repeat('0', 65),
        ];

        /** @var array<string, callable(LocalStorageBackend, string): mixed> $doors */
        $doors = [
            'exists' => static fn (LocalStorageBackend $b, string $o): bool => $b->exists($o),
            'size' => static fn (LocalStorageBackend $b, string $o): int => $b->size($o),
            'read' => static fn (LocalStorageBackend $b, string $o) => $b->read($o),
            'readAll' => static fn (LocalStorageBackend $b, string $o): string => $b->readAll($o),
            'path' => static fn (LocalStorageBackend $b, string $o): ?string => $b->path($o),
            'delete' => static function (LocalStorageBackend $b, string $o): void {
                $b->delete($o);
            },
            'write' => static function (LocalStorageBackend $b, string $o): void {
                $stream = \fopen('php://memory', 'rb');
                try {
                    $b->write($o, $stream);
                } finally {
                    \fclose($stream);
                }
            },
            'writeBytes' => static function (LocalStorageBackend $b, string $o): void {
                $b->writeBytes($o, 'x');
            },
        ];

        foreach ($offShape as $shape => $oid) {
            foreach ($doors as $door => $call) {
                try {
                    $call($backend, $oid);
                    $this->fail("the {$door} door accepted an off-shape oid ({$shape})");
                } catch (\InvalidArgumentException) {
                    // The prescribed refusal: containment never depends
                    // on what the caller's path resolves to.
                }
            }
        }

        $this->assertSame([], \array_values(\array_diff(\scandir($this->tmpDir), ['.', '..'])),
            'a refused oid may not leave so much as a shard directory behind');
    }

    public function testOidGuardIsTheTwinOfTheHandlerValidator(): void
    {
        // The backend docblock names LFSHandler::isValidOid as the twin
        // this pattern must not drift from (the import would cycle
        // handler→backend→handler) — pin the parity instead.
        $samples = ['', 'a', 'ABCDEF', \str_repeat('0', 63), \str_repeat('0', 65),
            \strtoupper(\hash('sha256', 'twin')), \hash('sha256', 'twin'), '../../x'];

        foreach ($samples as $oid) {
            $this->assertSame(
                \SugarCraft\GitServer\LFS\LFSHandler::isValidOid($oid),
                LocalStorageBackend::isValidOid($oid),
                "backend and handler disagree on oid shape: {$oid}"
            );
        }

        $this->assertTrue(LocalStorageBackend::isValidOid(\hash('sha256', 'twin')));
    }
}
