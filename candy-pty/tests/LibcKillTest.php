<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Libc;

/**
 * {@see Libc::kill()} is the one signal-sending path in the package, so a
 * host without ext-posix can still terminate a child (via libc `kill(2)`
 * over FFI) instead of hitting a fatal "undefined function posix_kill()".
 */
final class LibcKillTest extends TestCase
{
    private function requireFfi(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for the libc kill(2) path.');
        }
    }

    public function testCdefDeclaresKill(): void
    {
        $this->assertMatchesRegularExpression('/\bint\s+kill\(int pid, int sig\);/', Libc::cdef());
    }

    public function testKillViaLibcDeliversASignalToALiveChild(): void
    {
        $this->requireFfi();

        $proc = \proc_open(['/bin/sleep', '30'], [], $pipes);
        $this->assertIsResource($proc);
        $pid = (int) \proc_get_status($proc)['pid'];

        try {
            $this->assertTrue(Libc::killViaLibc($pid, 0), 'signal 0 probes a live pid');
            $this->assertTrue(Libc::killViaLibc($pid, 15), 'SIGTERM must be delivered');

            // Keep the status from the call that observed the exit: only that
            // one carries signaled/termsig (later calls report a reaped pid).
            $deadline = \microtime(true) + 5.0;
            while (($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            $this->assertFalse($status['running'], 'the child survived SIGTERM sent through libc kill(2)');
            $this->assertTrue($status['signaled']);
            $this->assertSame(15, $status['termsig']);
        } finally {
            if (\proc_get_status($proc)['running']) {
                \proc_terminate($proc, 9);
            }
            \proc_close($proc);
        }
    }

    public function testKillReportsFailureForAPidThatDoesNotExist(): void
    {
        $this->requireFfi();

        // Above every Linux pid_max (4194304) and macOS's 99998.
        $this->assertFalse(Libc::killViaLibc(999_999_999, 0));
        $this->assertFalse(Libc::kill(999_999_999, 0));
    }

    /**
     * Drift guard: no class in src/ calls `posix_kill()` bare again. Every
     * signal goes through {@see Libc::kill()}, whose ext-posix use is
     * guarded; the child kill() methods used to call it unguarded.
     */
    public function testNoSourceFileCallsPosixKillOutsideLibc(): void
    {
        $root = \dirname(__DIR__) . '/src';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getPathname() === $root . '/Libc.php') {
                continue;
            }
            foreach (\PhpToken::tokenize((string) \file_get_contents($file->getPathname())) as $token) {
                if (($token->is(\T_STRING) || $token->is(\T_NAME_FULLY_QUALIFIED)) && \ltrim($token->text, '\\') === 'posix_kill') {
                    $offenders[] = \substr($file->getPathname(), \strlen($root) + 1) . ':' . $token->line;
                }
            }
        }

        $this->assertSame([], $offenders, 'call Libc::kill() instead of posix_kill() so hosts without ext-posix can still signal children');
    }
}
