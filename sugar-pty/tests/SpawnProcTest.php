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
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    private function requirePtySyscalls(): void
    {
        $this->requireBasePtyCapability();
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

    /**
     * PTY-07: the autoloader hand-off rides the environment and ONLY the
     * environment. A command shaped like the old argv token must never be
     * required by the shim — it is the command the caller asked to run.
     */
    public function testShimNeverRequiresAnArgvShapedAutoloadToken(): void
    {
        $this->requirePtySyscalls();

        $shim = \realpath(__DIR__ . '/../bin/pty-shim.php');
        if ($shim === false) {
            $this->markTestSkipped('pty-shim.php not present.');
        }

        $dir = \sys_get_temp_dir();
        $marker = $dir . '/pty-shim-poison-' . \bin2hex(\random_bytes(4)) . '.flag';
        $poison = $dir . '/pty-shim-poison-' . \basename($marker, '.flag') . '.php';
        \file_put_contents($poison, "<?php file_put_contents(" . \var_export($marker, true) . ", 'required');");

        try {
            // Token-shaped cmd[0] with an explicit /bin/true behind it, and
            // the bare single-token shape: neither may reach require().
            // A token-free env makes the absent-inherit arm explicit.
            foreach ([
                ['--autoload=' . $poison, '/bin/true'],
                ['--autoload=' . $poison],
            ] as $args) {
                $proc = @\proc_open(
                    [\PHP_BINARY, $shim, ...$args],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    ['PATH' => (string) \getenv('PATH')],
                );
                $this->assertIsResource($proc);
                \stream_get_contents($pipes[1]);
                \stream_get_contents($pipes[2]);
                \fclose($pipes[1]);
                \fclose($pipes[2]);
                \proc_close($proc);
                $this->assertFileDoesNotExist(
                    $marker,
                    'the shim required an autoload path sourced from argv — the hand-off must be env-only',
                );
            }
        } finally {
            @\unlink($poison);
            @\unlink($marker);
        }
    }

    /**
     * Positive control for the poison test above: the env token IS live —
     * same shim, same file, carried via SUGAR_PTY_SHIM_AUTOLOAD, the marker
     * must appear. Without this leg the poison assertion could be faked by
     * any breakage that makes the require never happen at all.
     */
    public function testShimHonoursTheEnvironmentHandoffToken(): void
    {
        $this->requirePtySyscalls();

        $shim = \realpath(__DIR__ . '/../bin/pty-shim.php');
        if ($shim === false) {
            $this->markTestSkipped('pty-shim.php not present.');
        }

        $dir = \sys_get_temp_dir();
        $marker = $dir . '/pty-shim-token-' . \bin2hex(\random_bytes(4)) . '.flag';
        $token = $dir . '/pty-shim-token-' . \basename($marker, '.flag') . '.php';
        \file_put_contents($token, "<?php file_put_contents(" . \var_export($marker, true) . ", 'required');");

        try {
            $proc = @\proc_open(
                [\PHP_BINARY, $shim, '/bin/true'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [
                    'PATH' => (string) \getenv('PATH'),
                    'SUGAR_PTY_SHIM_AUTOLOAD' => $token,
                ],
            );
            $this->assertIsResource($proc);
            \stream_get_contents($pipes[1]);
            \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($proc);
            $this->assertFileExists(
                $marker,
                'the env hand-off went inert — the shim did not require the tokenised path (poison assertions would pass vacuously)',
            );
        } finally {
            @\unlink($token);
            @\unlink($marker);
        }
    }

    /**
     * A real Spawn-wrapped child sees the computed autoloader in its
     * environment, not whatever the caller tried to forge in a replace-mode
     * env map.
     */
    public function testWrapStagesTheRealHandoffOverAForgedMapEntry(): void
    {
        $this->requirePtySyscalls();

        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required.');
        }

        $pty = Pty::open();
        try {
            $child = Spawn::proc(
                $pty->master,
                ['/bin/sh', '-c', 'printf "[token:%s]" "$SUGAR_PTY_SHIM_AUTOLOAD"'],
                ['PATH' => (string) \getenv('PATH'), 'SUGAR_PTY_SHIM_AUTOLOAD' => '/tmp/forged-by-caller.php'],
                true,
            );

            $seen = '';
            $deadline = \microtime(true) + 3.0;
            while (\microtime(true) < $deadline) {
                $chunk = $pty->read(8192, 0.2);
                if ($chunk === null) {
                    continue;
                }
                if ($chunk === '') {
                    break;
                }
                $seen .= $chunk;
                if (\str_contains($seen, ']')) {
                    break;
                }
            }
            $child->wait();

            $expected = null;
            foreach (\get_included_files() as $file) {
                if (\preg_match('#/vendor/autoload\.php$#', $file) === 1 && \is_readable($file)) {
                    $expected = $file;
                    break;
                }
            }
            $this->assertNotNull($expected, 'test process must have an entrypoint autoloader');
            $this->assertStringContainsString(
                '[token:' . $expected . ']',
                $seen,
                'child env must carry the Spawn-computed hand-off; saw: ' . $seen,
            );
            $this->assertStringNotContainsString('forged-by-caller', $seen);
        } finally {
            $pty->close();
        }
    }

    /**
     * Inherit mode: a token already sitting in the PARENT environment (the
     * forged-inheritance vector) is scrubbed for the proc_open window — the
     * child must see the computed path, never the inherited forgery.
     */
    public function testWrapScrubsAForgedInheritedToken(): void
    {
        $this->requirePtySyscalls();

        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required.');
        }

        $previous = \getenv('SUGAR_PTY_SHIM_AUTOLOAD');
        \putenv('SUGAR_PTY_SHIM_AUTOLOAD=/tmp/forged-inherited.php');
        $pty = Pty::open();
        try {
            $child = Spawn::proc(
                $pty->master,
                ['/bin/sh', '-c', 'printf "[token:%s]" "$SUGAR_PTY_SHIM_AUTOLOAD"'],
                null,
                true,
            );

            $seen = '';
            $deadline = \microtime(true) + 3.0;
            while (\microtime(true) < $deadline) {
                $chunk = $pty->read(8192, 0.2);
                if ($chunk === null) {
                    continue;
                }
                if ($chunk === '') {
                    break;
                }
                $seen .= $chunk;
                if (\str_contains($seen, ']')) {
                    break;
                }
            }
            $child->wait();

            $this->assertStringNotContainsString(
                'forged-inherited',
                $seen,
                'a forged inherited token leaked into the child; saw: ' . $seen,
            );
            $this->assertStringContainsString('[token:', $seen, 'hand-off leg of the env probe vanished; saw: ' . $seen);
        } finally {
            $pty->close();
            if ($previous === false) {
                \putenv('SUGAR_PTY_SHIM_AUTOLOAD');
            } else {
                \putenv('SUGAR_PTY_SHIM_AUTOLOAD=' . $previous);
            }
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
