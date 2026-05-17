<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Process;

use SugarCraft\Shell\Process\FakeProcess;
use PHPUnit\Framework\TestCase;

final class FakeProcessTest extends TestCase
{
    public function testStartsRunning(): void
    {
        $p = new FakeProcess();
        $this->assertNull($p->exitCode());
    }

    public function testFinishSetsCode(): void
    {
        $p = new FakeProcess();
        $p->finish(7);
        $this->assertSame(7, $p->exitCode());
    }

    public function testTerminateAndCloseFlags(): void
    {
        $p = new FakeProcess();
        $p->terminate();
        $this->assertTrue($p->terminated);
        $p->finish(0);
        $this->assertSame(0, $p->close());
        $this->assertTrue($p->closed);
    }

    public function testStdoutBytesForwardsToBufferedStdout(): void
    {
        $p = new FakeProcess();
        $p->bufferedStdout = "hello\n";
        $this->assertSame("hello\n", $p->stdoutBytes());
    }

    public function testStderrBytesForwardsToBufferedStderr(): void
    {
        $p = new FakeProcess();
        $p->bufferedStderr = "error\n";
        $this->assertSame("error\n", $p->stderrBytes());
    }
}
