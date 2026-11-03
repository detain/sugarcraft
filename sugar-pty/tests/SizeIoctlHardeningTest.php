<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * Hardening pins for the winsize codec: the unsigned-short bound on
 * pack(), the rows-first unpack key order every size() docblock now
 * teaches, the absence of the (removed) pre-execution stty rate-limit
 * cache, and the posix_isatty courtesy gate shape.
 */
final class SizeIoctlHardeningTest extends TestCase
{
    public function testPackRefusesValuesAboveUnsignedShortRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must fit an unsigned short');

        SizeIoctl::pack(65536, 80);
    }

    public function testPackRefusesOversizeCols(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must fit an unsigned short');

        SizeIoctl::pack(24, 70000);
    }

    public function testPackRefusesOversizePixels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must fit an unsigned short');

        SizeIoctl::pack(24, 80, 1 << 16, 0);
    }

    public function testPackAcceptsTheBoundaryValues(): void
    {
        $this->requireFfi();

        $ws = SizeIoctl::pack(65535, 65535, 0, 0);
        $unpacked = SizeIoctl::unpack($ws);
        self::assertSame(65535, $unpacked['rows']);
        self::assertSame(65535, $unpacked['cols']);
    }

    public function testUnpackKeyOrderIsRowsFirst(): void
    {
        $this->requireFfi();

        $ws = SizeIoctl::pack(40, 120, 5, 6);
        self::assertSame(['rows', 'cols', 'xpix', 'ypix'], array_keys(SizeIoctl::unpack($ws)));
    }

    public function testNoSourceDocblockTeachesAColsFirstSizeShape(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src')) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) \file_get_contents($file->getPathname());
            if (\str_contains($text, 'array{cols: int, rows: int')) {
                $hits[] = $file->getPathname();
            }
        }
        self::assertSame([], $hits, 'size() docblocks must teach the rows-first shape unpack() emits (ConPty is excluded by spelling its literal inline)');
    }

    public function testSttySetSizeCarriesNoPreExecutionRateLimitCache(): void
    {
        $body = self::methodBody('sttySetSize');
        self::assertStringNotContainsString('static $lastCall', $body,
            'stty set must run EVERY call: a cache armed before execution launders rc=1 into a reported 0');
    }

    public function testRepeatedSttySetOnBrokenFdReportsFailureEveryTime(): void
    {
        $this->requireFfi();

        $tmp = \tmpfile();
        self::assertNotFalse($tmp);
        $fd = (int) $tmp;

        $invoke = new \ReflectionMethod(SizeIoctl::class, 'sttySetSize');
        $libc = Libc::lib();

        $first = $invoke->invoke(null, $libc, $fd, 40, 80);
        $second = $invoke->invoke(null, $libc, $fd, 40, 80);

        self::assertNotSame(0, $first, 'stty on a regular file must fail');
        self::assertNotSame(0, $second, 'the identical repeat must NOT be laundered into success');
    }

    public function testQueryNonTtyGateOnlyRunsWhenPosixIsLoaded(): void
    {
        $body = self::methodBody('query');
        self::assertStringNotContainsString("!\\function_exists('posix_isatty') ||", $body,
            'a missing ext-posix must not be reported as "not a tty" — the ioctl below answers');
        self::assertStringContainsString("\\function_exists('posix_isatty') && !\\posix_isatty", $body);
    }

    private function requireFfi(): void
    {
        if (!PtyCapability::ffiUsable()) {
            $this->markTestSkipped('ext-ffi is required (loaded-but-disabled counts as unavailable).');
        }
    }

    /** @return string the source slice of the named SizeIoctl method body */
    private static function methodBody(string $name): string
    {
        $src = (string) \file_get_contents((string) (new \ReflectionClass(SizeIoctl::class))->getFileName());
        $start = \strpos($src, "function {$name}(");
        self::assertIsInt($start, "method {$name} vanished from SizeIoctl");
        $brace = \strpos($src, '{', $start);
        self::assertIsInt($brace);
        $end = \strpos($src, "\n    }", $brace);
        self::assertIsInt($end, "method body of {$name} unmatched");
        return \substr($src, $brace, $end - $brace);
    }
}
