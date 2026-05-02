<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Process;

use CandyCore\Shell\Process\FakeProcess;
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
}
