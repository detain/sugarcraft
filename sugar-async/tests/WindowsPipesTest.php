<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\WindowsPipes;

/**
 * WindowsPipes reads only what fstat() says is waiting, so it never blocks.
 *
 * The host-independent legs use a memory stream, whose fstat size is its
 * length: the same contract a Windows pipe answers through PeekNamedPipe.
 * The live legs over real proc_open pipes are ChildPumpTest's
 * `testWindows*` cases.
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

    public function testTheRuntimeGateIsTheRealOs(): void
    {
        self::assertSame(\PHP_OS_FAMILY === 'Windows', WindowsPipes::runtimeNeedsIt());
    }

    public function testRunningProbeTreatsANonResourceAsExited(): void
    {
        $proc = \proc_open([\PHP_BINARY, '-r', 'usleep(200000);'], [], $pipes);
        self::assertIsResource($proc);
        $probe = WindowsPipes::runningProbe($proc);
        self::assertTrue($probe(), 'a child sleeping 200 ms is still running right after spawn');
        \proc_close($proc);

        self::assertFalse($probe());
    }
}
