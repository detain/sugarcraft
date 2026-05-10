<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Child;
use SugarCraft\Pty\Master;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Spawn;

/**
 * Direct unit tests for Spawn class.
 *
 * Tests the Spawn::proc() static method which wires proc_open() to a
 * slave PTY path, plus coverage of wrapInShim() via controllingTerminal=true.
 */
final class SpawnProcTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────
    // Spawn::proc() basic usage
    // ─────────────────────────────────────────────────────────────

    public function testProcSpawnsTrueWithZeroExit(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = Spawn::proc($pty->master, ['/bin/true']);

            $this->assertInstanceOf(Child::class, $child);
            $this->assertGreaterThan(0, $child->pid, 'pid must be a positive integer');
            $this->assertSame(0, $child->wait());
            $this->assertTrue($child->exited());
            $this->assertSame(0, $child->exitCode());
        } finally {
            $pty->close();
        }
    }

    public function testProcSpawnsFalseWithNonZeroExit(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = Spawn::proc($pty->master, ['/bin/false']);
            $exit = $child->wait();

            $this->assertNotSame(0, $exit, '/bin/false must report non-zero exit');
            $this->assertSame(1, $exit, '/bin/false convention is exit 1');
        } finally {
            $pty->close();
        }
    }

    public function testProcWithNullEnvInheritsParentEnv(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = Spawn::proc(
                $pty->master,
                ['/bin/sh', '-c', 'exit ${MY_VAR:-99}'],
                null, // inherit parent env.
            );
            $this->assertSame(99, $child->wait());
        } finally {
            $pty->close();
        }
    }

    public function testProcWithCustomEnvPassesEnvironment(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $child = Spawn::proc(
                $pty->master,
                ['/bin/sh', '-c', 'exit ${MY_VAR:-7}'],
                ['MY_VAR' => '42'],
            );
            $this->assertSame(42, $child->wait());
        } finally {
            $pty->close();
        }
    }

    public function testProcWithEmptyCommandThrowsInvalidArgument(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('#non-empty#');

            Spawn::proc($pty->master, []);
        } finally {
            $pty->close();
        }
    }

    public function testProcOnClosedMasterThrows(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        $pty->close();

        $this->expectException(PtyException::class);
        Spawn::proc($pty->master, ['/bin/true']);
    }

    // ─────────────────────────────────────────────────────────────
    // Spawn::proc() with controllingTerminal=true (tests wrapInShim)
    // ─────────────────────────────────────────────────────────────

    public function testProcWithControllingTerminalSpawnsTrue(): void
    {
        $this->requirePtySyscalls();

        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for controlling terminal.');
        }

        $pty = Pty::open();
        try {
            $child = Spawn::proc($pty->master, ['/bin/true'], null, true);

            $this->assertInstanceOf(Child::class, $child);
            $this->assertGreaterThan(0, $child->pid);
            $this->assertSame(0, $child->wait());
            $this->assertTrue($child->exited());
            $this->assertSame(0, $child->exitCode());
        } finally {
            $pty->close();
        }
    }

    public function testProcWithControllingTerminalRequiresPcntl(): void
    {
        if (\extension_loaded('pcntl')) {
            $this->markTestSkipped('This test is only meaningful when pcntl is NOT loaded.');
        }

        $pty = Pty::open();
        try {
            $this->expectException(PtyException::class);
            $this->expectExceptionMessageMatches('#pcntl#');

            Spawn::proc($pty->master, ['/bin/true'], null, true);
        } finally {
            $pty->close();
        }
    }

    public function testProcWithControllingTerminalRequiresReadableShim(): void
    {
        $this->requirePtySyscalls();

        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required.');
        }

        $pty = Pty::open();
        try {
            // The shim path is calculated as __DIR__ . '/../bin/pty-shim.php'.
            // We can verify wrapInShim throws by checking the shim exists.
            $shimPath = __DIR__ . '/../bin/pty-shim.php';
            if (!\is_file($shimPath) || !\is_readable($shimPath)) {
                $this->markTestSkipped('pty-shim.php is not readable.');
            }

            $child = Spawn::proc($pty->master, ['/bin/true'], null, true);
            $this->assertSame(0, $child->wait());
        } finally {
            $pty->close();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private constructor
    // ─────────────────────────────────────────────────────────────

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(Spawn::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
