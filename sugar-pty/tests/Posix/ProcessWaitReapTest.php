<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixProcess;

/**
 * m8 (round lib-fixes-1103): a cached exit code must settle every
 * handle on the early-return path too — pipes drained and closed, the
 * proc resource reaped — and wait() on a handle whose process was
 * already destroyed reports -1, never a fabricated success.
 */
final class ProcessWaitReapTest extends TestCase
{
    public function testSecondWaitIsIdempotentAndKeepsCaptureReadable(): void
    {
        $proc = PosixProcess::spawn(['/bin/echo', 'captured'], captureStdout: true);
        $first = $proc->wait();
        $second = $proc->wait();

        $this->assertSame(0, $first);
        $this->assertSame($first, $second, 'cached wait must return the same code');
        $this->assertSame('captured', trim($proc->stdoutBytes()));
    }

    public function testWaitClosesEveryHandle(): void
    {
        $proc = PosixProcess::spawn(['/bin/true'], captureStdout: true, captureStderr: true);
        $proc->wait();

        $reflection = new \ReflectionObject($proc);
        $process = $reflection->getProperty('process');
        $process->setAccessible(true);
        $this->assertNull($process->getValue($proc), 'wait() must reap the proc resource');

        $pipes = $reflection->getProperty('pipes');
        $pipes->setAccessible(true);
        foreach ($pipes->getValue($proc) as $pipe) {
            $this->assertNull($pipe, 'wait() must close capture pipes');
        }
    }

    public function testNonResourceEarlyReturnIsMinusOneNotFabricatedZero(): void
    {
        // The post-destruction wait() window cannot be rebuilt safely
        // (a destructed handle must not be touched again), so pin the
        // source law of the non-resource early return: unknown exit is
        // -1 ("gone, code uncapturable"), never a fabricated success.
        $source = (new \ReflectionMethod(PosixProcess::class, 'wait'))->getDeclaringClass()
            ->getFileName();
        $body = (string) file_get_contents((string) $source);
        $start = strpos($body, 'public function wait(): int');
        $this->assertIsInt($start);
        $slice = $sliceEnd = strpos($body, "while (true)", (int) $start); $slice = substr($body, (int) $start, (int) $sliceEnd - (int) $start);

        $this->assertStringContainsString('return $this->exitCode ?? -1;', $slice);
        $this->assertStringNotContainsString('?? 0;', $slice);
    }
}
