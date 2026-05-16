<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Spawns examples/spawn-bash.php as a child PHP process and asserts
 * the round-trip works end-to-end (PtySystemFactory → open → spawn →
 * drain → wait → close). Acts as a regression guard so the lib's
 * "simplest path" example stays runnable.
 */
final class SpawnBashExampleTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/spawn-bash.php';

    private function requireRuntime(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty examples are POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx unreadable on this host.');
        }
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash required.');
        }
        if (!\is_file(self::EXAMPLE)) {
            $this->markTestSkipped('spawn-bash example file missing: ' . self::EXAMPLE);
        }
    }

    public function testExampleRunsToCleanExit(): void
    {
        $this->requireRuntime();

        $marker = 'spawn-bash-test-' . \bin2hex(\random_bytes(4));
        $proc = \proc_open(
            [\PHP_BINARY, self::EXAMPLE, "echo {$marker}"],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        $this->assertIsResource($proc, 'proc_open of example failed');

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = \microtime(true) + 10.0;

        while (\microtime(true) < $deadline) {
            $chunk = @\fread($pipes[1], 4096);
            if (\is_string($chunk) && $chunk !== '') {
                $stdout .= $chunk;
            }
            $errChunk = @\fread($pipes[2], 4096);
            if (\is_string($errChunk) && $errChunk !== '') {
                $stderr .= $errChunk;
            }
            $status = \proc_get_status($proc);
            if (!$status['running']) {
                $tail = @\stream_get_contents($pipes[1]);
                if (\is_string($tail)) {
                    $stdout .= $tail;
                }
                $errTail = @\stream_get_contents($pipes[2]);
                if (\is_string($errTail)) {
                    $stderr .= $errTail;
                }
                break;
            }
            \usleep(20_000);
        }

        $status = \proc_get_status($proc);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        $this->assertFalse(
            $status['running'],
            "example must finish within 10s. stdout={$stdout} stderr={$stderr}",
        );
        $this->assertSame(
            0,
            $status['exitcode'],
            "example must exit 0. stdout={$stdout} stderr={$stderr}",
        );
        $this->assertStringContainsString(
            $marker,
            $stdout,
            "marker should round-trip through the PTY. stdout={$stdout}",
        );
        $this->assertStringContainsString(
            'slave path : /dev/pts/',
            $stdout,
            "example should print the slave path. stdout={$stdout}",
        );
    }
}
