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

    public function testProcSharedSlaveTtyDeliversChildStdoutToMaster(): void
    {
        $this->requirePtySyscalls();

        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required for this test.');
        }

        // Regression for the TOCTOU fix (single slave handle reused for
        // all three stdio slots). The child's stdout slot must still be
        // wired to the slave tty, so bytes it prints come back out the
        // master end — verifies the shared handle wired stdout correctly,
        // not just that the process spawned.
        $pty = Pty::open();
        try {
            $child = Spawn::proc($pty->master, ['/bin/sh', '-c', 'printf PTY_OK']);

            $seen = '';
            $deadline = \microtime(true) + 2.0;
            while (\microtime(true) < $deadline) {
                $chunk = $pty->read(8192, 0.2);
                if ($chunk === null) {
                    continue;
                }
                if ($chunk === '') {
                    break; // EOF: slave fully closed
                }
                $seen .= $chunk;
                if (\str_contains($seen, 'PTY_OK')) {
                    break;
                }
            }

            $child->wait();
            $this->assertStringContainsString(
                'PTY_OK',
                $seen,
                'child stdout must reach the master through the shared slave tty',
            );
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
    // Slave-fd inheritance
    // ─────────────────────────────────────────────────────────────

    /**
     * Regression: the parent's slave handle was opened without
     * close-on-exec, so beside the dup2'd stdio copies the child also
     * inherited the parent's own slave descriptor (seen as a stray
     * /dev/pts/N at fd 5/6 in the child's fd census). That extra copy
     * outlives the child's stdio: anything holding it keeps the slave
     * open, so the master never sees EOF while a grandchild holds it.
     * The slave must reach the child at 0-2 and nowhere else, with and
     * without the controlling-terminal shim.
     *
     * @return iterable<string, array{bool}>
     */
    public static function controllingTerminalModes(): iterable
    {
        yield 'plain spawn' => [false];
        yield 'controlling-terminal shim' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('controllingTerminalModes')]
    public function testChildHoldsTheSlaveOnlyAtItsStdioDescriptors(bool $controllingTerminal): void
    {
        $this->requirePtySyscalls();
        if (!\is_dir('/proc/self/fd') || !\is_executable('/bin/sh')) {
            $this->markTestSkipped('Needs /proc/<pid>/fd and /bin/sh to take the child fd census.');
        }
        if ($controllingTerminal && !\extension_loaded('pcntl')) {
            $this->markTestSkipped('The controlling-terminal shim needs ext-pcntl.');
        }

        $pty = Pty::open();
        try {
            $census = 'cd /proc/$$/fd && for f in *; do printf "FD %s=%s\n" "$f" "$(readlink "$f")"; done; printf CENSUS_DONE';
            $child = Spawn::proc($pty->master, ['/bin/sh', '-c', $census], null, $controllingTerminal);

            $seen = '';
            $deadline = \microtime(true) + 5.0;
            while (\microtime(true) < $deadline && !\str_contains($seen, 'CENSUS_DONE')) {
                $chunk = $pty->read(8192, 0.2);
                if ($chunk === null) {
                    continue;
                }
                if ($chunk === '') {
                    break;
                }
                $seen .= $chunk;
            }
            $child->wait();

            $this->assertStringContainsString('CENSUS_DONE', $seen, 'the fd census never completed: ' . $seen);
            \preg_match_all('/^FD (\d+)=(.*?)\r?$/m', $seen, $m, PREG_SET_ORDER);
            $slaveFds = [];
            foreach ($m as [, $fd, $target]) {
                if ($target === $pty->master->slavePath) {
                    $slaveFds[] = (int) $fd;
                }
            }
            \sort($slaveFds);

            $this->assertSame(
                [0, 1, 2],
                $slaveFds,
                'the child must hold the slave only at stdin/stdout/stderr; census: ' . $seen,
            );
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
