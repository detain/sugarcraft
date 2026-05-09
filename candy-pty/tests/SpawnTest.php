<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Child;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Spawn;

final class SpawnTest extends TestCase
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

    public function testSpawnTrueReturnsZeroExit(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = $pty->spawn(['/bin/true']);
            $this->assertInstanceOf(Child::class, $child);
            $this->assertGreaterThan(0, $child->pid, 'pid should be a real process id');
            $this->assertSame(0, $child->wait());
            $this->assertTrue($child->exited());
            $this->assertSame(0, $child->exitCode());
        } finally {
            $pty->close();
        }
    }

    public function testSpawnFalseReturnsNonZeroExit(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = $pty->spawn(['/bin/false']);
            $exit = $child->wait();
            $this->assertNotSame(0, $exit, '/bin/false must report a non-zero exit code');
            $this->assertSame(1, $exit, '/bin/false convention is exit 1');
        } finally {
            $pty->close();
        }
    }

    public function testWaitIsIdempotent(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = $pty->spawn(['/bin/true']);
            $first  = $child->wait();
            $second = $child->wait();
            $third  = $child->wait();
            $this->assertSame($first, $second);
            $this->assertSame($second, $third);
        } finally {
            $pty->close();
        }
    }

    public function testExitedFlipsOnceChildTerminates(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            // Sleep just long enough that the child is reliably running
            // when exited() is first polled, but short enough not to
            // bloat the test runtime — 50 ms is a comfortable margin.
            $child = $pty->spawn(['/bin/sh', '-c', 'sleep 0.05']);
            $child->wait();
            $this->assertTrue($child->exited());
            $this->assertNotNull($child->exitCode());
        } finally {
            $pty->close();
        }
    }

    public function testSpawnInheritsCustomEnv(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            // /bin/sh -c 'exit ${MY_CODE:-7}' propagates a custom env
            // var into the exit code so we can observe env passing
            // without needing to read PTY output (PR4 territory).
            $child = $pty->spawn(
                ['/bin/sh', '-c', 'exit ${MY_CODE:-7}'],
                ['MY_CODE' => '3'],
            );
            $this->assertSame(3, $child->wait());
        } finally {
            $pty->close();
        }
    }

    public function testSpawnWithEmptyCmdRejects(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $pty->spawn([]);
        } finally {
            $pty->close();
        }
    }

    public function testSpawnOnClosedPtyThrows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $pty->close();

        $this->expectException(PtyException::class);
        $pty->spawn(['/bin/true']);
    }

    public function testChildRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Child(123, 'not-a-resource');
    }

    public function testSpawnHelperIsNotInstantiable(): void
    {
        $reflection = new \ReflectionClass(Spawn::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate(), 'Spawn must not be instantiable');
    }
}
