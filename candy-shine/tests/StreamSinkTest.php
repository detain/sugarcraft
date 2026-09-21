<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use SugarCraft\Shine\StreamSink;
use SugarCraft\Shine\Tests\Support\ProbeStreamWrapper;

use function dirname;
use function file_exists;
use function fopen;
use function fwrite;
use function getmypid;
use function is_dir;
use function is_resource;
use function mkdir;
use function posix_getuid;
use function sprintf;
use function str_contains;
use function sys_get_temp_dir;
use function tempnam;

/**
 * StreamSink doors and the write-through flush law — every rejection shape
 * must fire before a single byte of caller data moves, and every accepted
 * write must reach the wire without waiting for close().
 */
final class StreamSinkTest extends TestCase
{
    /** @var list<string> temp paths for cleanup */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->paths = [];
    }

    public function testToStreamRejectsNonResources(): void
    {
        foreach ([42, 'not-a-resource', null, 3.14, true, [], new stdClass()] as $value) {
            try {
                StreamSink::toStream($value);
                self::fail('expected the door to reject ' . gettype($value));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('write() requires a stream resource', $e->getMessage());
            }
        }
    }

    public function testToStreamRejectsNotWritableModes(): void
    {
        $path = $this->scratchPath();
        file_put_contents($path, "seed bytes\n");

        foreach (['r', 'rb'] as $mode) {
            $handle = fopen($path, $mode);
            $this->assertNotFalse($handle);

            try {
                StreamSink::toStream($handle);
                self::fail('expected the door to reject mode ' . $mode);
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString(
                    sprintf('write() requires a writable stream, got mode "%s"', $mode),
                    $e->getMessage()
                );
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        self::assertSame("seed bytes\n", file_get_contents($path), 'a rejected handle must leave the file untouched');
    }

    public function testToStreamAdoptsTheHandleAndNeverClosesIt(): void
    {
        $path   = $this->scratchPath();
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);

        $sink = StreamSink::toStream($handle);
        $sink->write('sink-wrote-');
        $sink->close();

        self::assertFalse($sink->isOpen());
        self::assertTrue(is_resource($handle), 'an adopted handle must survive a sink close');

        fwrite($handle, 'caller-wrote-');
        fclose($handle);

        self::assertSame('sink-wrote-caller-wrote-', file_get_contents($path));
    }

    public function testToPathCreatesTheFileAndWritesThrough(): void
    {
        $path = $this->scratchPath();
        $sink = StreamSink::toPath($path);

        self::assertSame(26, $sink->write('first part and second part'));

        $sink->close();
        self::assertSame('first part and second part', file_get_contents($path));
    }

    public function testToPathMissingParentThrowsParentMissing(): void
    {
        $missing = sys_get_temp_dir() . '/shine-sink-missing-' . getmypid() . '/out.txt';
        self::assertFalse(is_dir(dirname($missing)));

        try {
            StreamSink::toPath($missing);
            self::fail('expected parent_missing');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('output directory does not exist', $e->getMessage());
        }
    }

    public function testToPathUnwritableParentThrowsPathUnwritable(): void
    {
        // Windows has no POSIX mode bits: chmod() there flips only the read-only
        // attribute on files, never a directory's writability, so a 0555 dir
        // still accepts new children and there is no unwritable door to judge.
        if (!\function_exists('posix_getuid')) {
            $this->markTestSkipped('ext-posix absent (Windows) — directory mode bits are not enforced');
        }

        if (0 === posix_getuid()) {
            $this->markTestSkipped('uid 0 ignores mode bits — the door is only judgable for non-root');
        }

        $dir = $this->scratchDir();
        chmod($dir, 0555);

        try {
            StreamSink::toPath($dir . '/out.txt');
            self::fail('expected path_unwritable');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('output path is not writable', $e->getMessage());
        } finally {
            chmod($dir, 0755);
        }
    }

    public function testToPathOnAnExistingDirectoryFailsTheOpenDoor(): void
    {
        $dir = $this->scratchDir();

        try {
            StreamSink::toPath($dir);
            self::fail('expected open_failed');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not writable')) {
                self::fail('a writable directory must ride the open door, not the writability door: ' . $e->getMessage());
            }
            self::assertStringContainsString('failed to open output path', $e->getMessage());
        }
    }

    public function testWriteThroughFlushLaw(): void
    {
        $handle = ProbeStreamWrapper::open('sink');
        $this->assertNotFalse($handle);

        $sink = StreamSink::toStream($handle);
        $sink->write('a');
        $sink->write('b');

        self::assertSame('ab', ProbeStreamWrapper::$committed['sink'], 'bytes must reach the wire without close()');
        self::assertSame('', ProbeStreamWrapper::$pending['sink']);
        self::assertSame(2, ProbeStreamWrapper::$writes['sink']);
        self::assertSame(2, ProbeStreamWrapper::$flushes['sink']);

        $sink->close();
    }

    public function testWriteAfterCloseThrowsLogicException(): void
    {
        $handle = $this->resource();
        $sink   = StreamSink::toStream($handle);
        $sink->close();

        self::assertFalse($sink->isOpen());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the sink is closed');
        $sink->write('late bytes');
    }

    public function testCloseIsIdempotentAndNeverDoubleFrees(): void
    {
        $sink = StreamSink::toPath($this->scratchPath());
        $sink->close();
        $sink->close();
        $sink->close();

        self::assertFalse($sink->isOpen());
    }

    public function testToPathCloseReleasesOwnedHandle(): void
    {
        $path = $this->scratchPath();
        $sink = StreamSink::toPath($path);
        $sink->write('x');

        self::assertTrue($sink->isOpen());
        $sink->close();
        self::assertFalse($sink->isOpen());

        self::assertSame('x', file_get_contents($path));
    }

    /** @return resource */
    private function resource()
    {
        $handle = fopen($this->scratchPath(), 'wb');
        $this->assertNotFalse($handle);

        return $handle;
    }

    private function scratchPath(): string
    {
        $path          = tempnam(sys_get_temp_dir(), 'shine-sink-') . '.bin';
        $this->paths[] = $path;

        return $path;
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/shine-sink-dir-' . getmypid() . '-' . count($this->paths);
        self::assertTrue(mkdir($dir, 0777, true));
        $this->paths[] = $dir;

        return $dir;
    }
}
