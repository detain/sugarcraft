<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Process;

use CandyCore\Shell\Process\RealProcess;
use PHPUnit\Framework\TestCase;

final class RealProcessTest extends TestCase
{
    public function testSpawnReadsExitCodeFromTrueCommand(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        // Poll briefly — `exit 0` should finish near-instantly.
        $code = null;
        for ($i = 0; $i < 50 && $code === null; $i++) {
            $code = $proc->exitCode();
            if ($code === null) usleep(10_000);
        }
        $this->assertSame(0, $code);
        $proc->close();
    }

    public function testSpawnPropagatesNonZeroExit(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 7']);
        $code = null;
        for ($i = 0; $i < 50 && $code === null; $i++) {
            $code = $proc->exitCode();
            if ($code === null) usleep(10_000);
        }
        $this->assertSame(7, $code);
        $proc->close();
    }

    public function testCloseIsIdempotentAfterCachedExit(): void
    {
        // Regression for the leak: even when exitCode() has already
        // resolved (and cached) the child's status, close() must still
        // reap the proc_open handle exactly once.
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $first  = $proc->close();   // should call proc_close() under the hood
        $second = $proc->close();   // safe no-op
        $this->assertSame($first, $second);
    }

    public function testTerminateIsNoOpAfterClose(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'exit 0']);
        for ($i = 0; $i < 50 && $proc->exitCode() === null; $i++) {
            usleep(10_000);
        }
        $proc->close();
        // After close() the underlying handle is gone; terminate() must
        // not fall through to proc_terminate() on a closed resource.
        $proc->terminate();
        $this->assertSame(0, $proc->close());
    }
}
