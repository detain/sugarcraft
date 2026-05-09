<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * SIGWINCH forwarding tests — drives the supervisor via the
 * resizable fixture and verifies child-side TIOCGWINSZ reflects
 * the host-side winsize after a SIGWINCH delivery.
 */
final class InProcessTransportSigwinchTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/_fixtures/runchild-resizable.php';

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH not available.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx unreadable on this host.');
        }
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh required.');
        }
        if (!\is_executable('/bin/stty') && !\is_executable('/usr/bin/stty')) {
            $this->markTestSkipped('stty required to read child-side dims.');
        }
    }

    public function testWithSizeProviderReturnsClonedTransport(): void
    {
        $original = new InProcessTransport();
        $clone = $original->withSizeProvider(fn (): array => ['cols' => 200, 'rows' => 60]);

        $this->assertNotSame($original, $clone, 'withSizeProvider must clone, not mutate');
    }

    public function testChildSeesNewSizeAfterSigwinchDelivery(): void
    {
        $this->requirePtySyscalls();

        $sizeFile = \tempnam(\sys_get_temp_dir(), 'wish-size-');
        $outFile  = \tempnam(\sys_get_temp_dir(), 'wish-out-');
        $this->assertNotFalse($sizeFile);
        $this->assertNotFalse($outFile);

        try {
            \file_put_contents($sizeFile, "100 30");

            // Inner cmd: loop continuously, log stty size to a file
            // each iteration. Test waits for the FIRST baseline
            // sample to land (deterministic), THEN bumps the size
            // file + sends SIGWINCH, then waits for the post-resize
            // sample. Polls instead of using fixed sleeps so the
            // test isn't racy against PHP startup timing.
            $innerScript = "for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do "
                . "echo \"\$i:\$(stty size)\" >> {$outFile}; "
                . "sleep 0.05; done";

            $argv = [
                PHP_BINARY,
                self::FIXTURE,
                $sizeFile,
                '100',
                '30',
                '/bin/sh',
                '-c',
                $innerScript,
            ];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $proc = \proc_open($argv, $descriptors, $pipes);
            $this->assertIsResource($proc);

            // Step 1: poll outFile until the first sample lands. This
            // deterministically waits past the PHP startup + shim +
            // exec setup time (~100-200ms in CI).
            $baselineDeadline = \microtime(true) + 5.0;
            $baselineCols = null;
            while (\microtime(true) < $baselineDeadline && $baselineCols === null) {
                \usleep(20_000);
                $log = (string) \file_get_contents($outFile);
                if (\preg_match('/^\d+:(\d+)\s+(\d+)$/m', $log, $m) === 1) {
                    $baselineCols = (int) $m[2];
                }
            }
            $this->assertSame(100, $baselineCols, "baseline must be 100 cols (initial Pty::spawn size); got " . \var_export($baselineCols, true));

            // Step 2: update size file + signal SIGWINCH.
            \file_put_contents($sizeFile, "150 50");
            $status = \proc_get_status($proc);
            $this->assertTrue($status['running'] ?? false, 'supervisor must still be running mid-loop');
            \posix_kill($status['pid'], \SIGWINCH);

            // Step 3: poll outFile until a 150-cols sample lands.
            $resizedDeadline = \microtime(true) + 5.0;
            $observed150 = false;
            while (\microtime(true) < $resizedDeadline) {
                \usleep(20_000);
                $log = (string) \file_get_contents($outFile);
                if (\preg_match('/:\d+\s+150$/m', $log) === 1) {
                    $observed150 = true;
                    break;
                }
            }
            $this->assertTrue(
                $observed150,
                "child should have observed 150 cols after SIGWINCH; log: " . \var_export(\file_get_contents($outFile), true),
            );

            // Drain output + reap.
            $reapDeadline = \microtime(true) + 5.0;
            while (\microtime(true) < $reapDeadline) {
                $st = \proc_get_status($proc);
                if (!($st['running'] ?? false)) {
                    break;
                }
                @\stream_set_blocking($pipes[1], false);
                @\fread($pipes[1], 4096);
                \usleep(20_000);
            }

            \fclose($pipes[0]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($proc);
        } finally {
            if (\file_exists($sizeFile)) {
                \unlink($sizeFile);
            }
            if (\file_exists($outFile)) {
                \unlink($outFile);
            }
        }
    }
}
