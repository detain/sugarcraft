<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixChild;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Contract\Child;

final class PosixChildTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/true') || !\is_executable('/bin/false')) {
            $this->markTestSkipped('/bin/true and /bin/false are required for spawn tests.');
        }
    }

    public function testImplementsContractChild(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/true']);
            $this->assertInstanceOf(Child::class, $child);
            $this->assertInstanceOf(PosixChild::class, $child);
        } finally {
            $pair->master()->close();
        }
    }

    public function testSpawnTrueReturnsZeroExit(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/true']);
            $this->assertGreaterThan(0, $child->pid());
            $this->assertSame(0, $child->wait());
            $this->assertTrue($child->exited());
            $this->assertSame(0, $child->exitCode());
        } finally {
            $pair->master()->close();
        }
    }

    public function testSpawnFalseReturnsNonZeroExit(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/false']);
            $exit = $child->wait();
            $this->assertNotSame(0, $exit);
            $this->assertSame(1, $exit);
        } finally {
            $pair->master()->close();
        }
    }

    public function testWaitIsIdempotent(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/true']);
            $first  = $child->wait();
            $second = $child->wait();
            $third  = $child->wait();
            $this->assertSame($first, $second);
            $this->assertSame($second, $third);
        } finally {
            $pair->master()->close();
        }
    }

    public function testExitedFlipsOnceChildTerminates(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/sh', '-c', 'sleep 0.05']);
            $child->wait();
            $this->assertTrue($child->exited());
            $this->assertNotNull($child->exitCode());
        } finally {
            $pair->master()->close();
        }
    }

    public function testExitedReturnsFalseWhileRunning(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/sleep', '10']);
            $this->assertFalse($child->exited());
            $child->kill(Child::SIGKILL);
            $child->wait();
            $this->assertTrue($child->exited());
        } finally {
            $pair->master()->close();
        }
    }

    public function testKillSigterm(): void
    {
        $this->requirePtySyscalls();

        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required for signal tests.');
        }

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/sleep', '10']);

            $child->kill(Child::SIGTERM);
            $exit = $child->wait();
            $this->assertNotSame(0, $exit);
        } finally {
            $pair->master()->close();
        }
    }

    public function testKillSigkill(): void
    {
        $this->requirePtySyscalls();

        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension required for signal tests.');
        }

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/sleep', '10']);

            $child->kill(Child::SIGKILL);
            $exit = $child->wait();
            $this->assertNotSame(0, $exit, 'SIGKILL on sleep should result in non-zero exit');
        } finally {
            $pair->master()->close();
        }
    }

    public function testConstructorRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PosixChild(123, 'not-a-resource');
    }

    public function testExitCodeCaching(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/sh', '-c', 'exit 5']);

            $this->assertNull($child->exitCode());
            $first = $child->wait();
            $this->assertSame(5, $first);
            $this->assertSame(5, $child->exitCode());
            $this->assertSame(5, $child->wait());
        } finally {
            $pair->master()->close();
        }
    }

    public function testSpawnInheritsCustomEnv(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(
                ['/bin/sh', '-c', 'exit ${MY_CODE:-7}'],
                ['MY_CODE' => '3'],
            );
            $this->assertSame(3, $child->wait());
        } finally {
            $pair->master()->close();
        }
    }

    public function testWaitReturnsZeroWhenAlreadyExited(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open();

        try {
            $child = $pair->slave()->spawn(['/bin/true']);
            $exit1 = $child->wait();
            $exit2 = $child->wait();
            $this->assertSame($exit1, $exit2);
        } finally {
            $pair->master()->close();
        }
    }
}
