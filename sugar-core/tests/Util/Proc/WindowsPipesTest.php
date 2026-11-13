<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Proc\WindowsPipes;

/**
 * The host-independent legs use memory streams, whose fstat size is their
 * length: the same contract a Windows pipe answers through PeekNamedPipe.
 * The live legs spawn a real child and run on Windows only.
 */
final class WindowsPipesTest extends TestCase
{
    /** @return resource */
    private function memory(string $bytes)
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        \fwrite($stream, $bytes);
        \rewind($stream);

        return $stream;
    }

    public function testAvailableAndReadAvailableNeverExceedWhatIsWaiting(): void
    {
        $stream = $this->memory('abcdef');
        self::assertSame(6, WindowsPipes::available($stream));
        self::assertSame('abc', WindowsPipes::readAvailable($stream, 3));
        \fclose($stream);
        self::assertSame(0, WindowsPipes::available($stream), 'a closed stream has nothing');

        $empty = $this->memory('');
        self::assertSame('', WindowsPipes::readAvailable($empty));
        \fclose($empty);
    }

    public function testTheRuntimeGateIsTheRealOs(): void
    {
        self::assertSame(\PHP_OS_FAMILY === 'Windows', WindowsPipes::runtimeNeedsIt());
    }

    public function testRunningProbeTreatsAClosedHandleAsExited(): void
    {
        $proc = \proc_open([\PHP_BINARY, '-r', 'usleep(300000);'], [], $pipes);
        self::assertIsResource($proc);
        $probe = WindowsPipes::runningProbe($proc);
        self::assertTrue($probe());
        \proc_close($proc);
        self::assertFalse($probe());
    }

    public function testDrainUntilExitEndsOnAnEmptyPassAfterTheExit(): void
    {
        // A memory stream's fstat size is its total length, so its bytes come
        // back on the first pass; the exited probe ends the loop on the next.
        $out = $this->memory('hello');
        $err = $this->memory('');
        $t0 = \microtime(true);
        $r = WindowsPipes::drainUntilExit([1 => $out, 2 => $err], static fn (): bool => false, $t0 + 10, 3);

        self::assertFalse($r['timedOut']);
        self::assertSame('hel', $r['buffers'][1], 'kept up to the cap');
        self::assertTrue($r['overflowed']);
        self::assertSame('', $r['buffers'][2]);
        self::assertLessThan(1.0, \microtime(true) - $t0);
    }

    public function testDrainUntilExitHonoursTheDeadlineWhileTheChildRuns(): void
    {
        $out = $this->memory('');
        $t0 = \microtime(true);
        $r = WindowsPipes::drainUntilExit([1 => $out], static fn (): bool => true, $t0 + 0.2);

        self::assertTrue($r['timedOut']);
        self::assertLessThan(1.0, \microtime(true) - $t0);
    }

    public function testLiveSilentChildTimesOutOnTimeAndALoudOneIsReadWhole(): void
    {
        if (!WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('Live Windows pipe behavior; elsewhere pipes are select()able.');
        }
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $proc = \proc_open([\PHP_BINARY, '-r', 'sleep(10);'], $spec, $pipes);
        self::assertIsResource($proc);
        $t0 = \microtime(true);
        $r = WindowsPipes::drainUntilExit([1 => $pipes[1], 2 => $pipes[2]], WindowsPipes::runningProbe($proc), $t0 + 1.0);
        self::assertTrue($r['timedOut']);
        self::assertLessThan(2.0, \microtime(true) - $t0, 'a blocking read would have parked until the child exited');
        \proc_terminate($proc);
        \proc_close($proc);

        $proc = \proc_open([\PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("o", 200000)); fwrite(STDERR, "e");'], $spec, $pipes);
        self::assertIsResource($proc);
        $r = WindowsPipes::drainUntilExit([1 => $pipes[1], 2 => $pipes[2]], WindowsPipes::runningProbe($proc), \microtime(true) + 20);
        \proc_close($proc);
        self::assertFalse($r['timedOut']);
        self::assertSame(200000, \strlen($r['buffers'][1]));
        self::assertSame('e', $r['buffers'][2]);
    }
}
