<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\WindowsPipes;
use SugarCraft\Code\Support\WindowsShell;

/**
 * WindowsPipes reads only what fstat() says is waiting, so it never blocks.
 *
 * The host-independent legs use a memory stream, whose fstat size is its
 * length. That is the same contract a Windows pipe answers through
 * PeekNamedPipe. The live leg, a real proc_open pipe on Windows, lives in
 * tests/Windows/WindowsBashSmokeTest.php, which the windows-smoke CI job runs.
 */
final class WindowsPipesTest extends TestCase
{
    public function testAvailableReportsTheBytesFstatSeesWaiting(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        \fwrite($stream, 'abcdef');
        \rewind($stream);

        self::assertSame(6, WindowsPipes::available($stream));
        \fclose($stream);
    }

    /**
     * The pipe arm follows the PHP runtime's own OS, never WindowsShell's
     * injectable family. On Linux, fstat() of a pipe is always size 0, so a
     * family-keyed arm read nothing when a test drove the Windows dialect
     * over real Linux pipes.
     */
    public function testTheRuntimeGateIsTheRealOsNotTheInjectableFamily(): void
    {
        WindowsShell::setOsFamilyForTesting('Windows');

        try {
            self::assertSame(\PHP_OS_FAMILY === 'Windows', WindowsPipes::runtimeNeedsIt());
        } finally {
            WindowsShell::setOsFamilyForTesting(null);
        }
    }

    public function testAvailableIsZeroForAClosedStream(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        \fclose($stream);

        self::assertSame(0, WindowsPipes::available($stream));
    }

    public function testReadAvailableNeverAsksForMoreThanItsCap(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        \fwrite($stream, 'abcdef');
        \rewind($stream);

        self::assertSame('abc', WindowsPipes::readAvailable($stream, 3));
        \fclose($stream);
    }

    public function testReadAvailableAnswersEmptyWhenNothingWaits(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        self::assertSame('', WindowsPipes::readAvailable($stream));
        \fclose($stream);
    }
}
