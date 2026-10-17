<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
* Audit 2026-09-30: runExec()'s capture path used to read stdout to EOF, then stderr.
 * A child that overflows BOTH 64 KiB kernel pipes deadlocks in that shape —
 * stdout never reaches EOF because the child is parked writing stderr, and
 * stderr is never drained because the parent is parked reading stdout.
 *
 * The probe lives in a child process because the deadlock is a BLOCKING one
 * inside runExec(): an in-suite pin could only hang phpunit itself instead of
 * failing. The parent bounds the wait, so a regression goes red (after the
 * leash), never stuck.
 */
final class ProgramExecCaptureTest extends TestCase
{
    /** Seconds the probe child gets to run both pipes dry before we call deadlock. */
    private const CHILD_LEASH_SECONDS = 20.0;

    public function testCapturedExecDrainsStdoutAndStderrConcurrently(): void
    {
        $child = __DIR__ . '/Support/exec_both_streams_child.php';
        $this->assertFileExists($child);

        $proc = proc_open(
            [PHP_BINARY, $child],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        $exit = null;
        $deadline = microtime(true) + self::CHILD_LEASH_SECONDS;
        do {
            // Poll, never one-shot: pipe-EOF precedes waitability, so a single
            // proc_get_status after the child goes quiet can still say running.
            if (!(bool) (proc_get_status($proc)['running'] ?? false)) {
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($proc);
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($exit === null) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->fail(
                'exec-capture child never exited within ' . self::CHILD_LEASH_SECONDS
                . 's — runExec() is deadlocking on a child that overflows both pipes',
            );
        }

        $this->assertStringContainsString(
            'PROBE-OK stdout=200000 stderr=200000 exit=0 error=none',
            $stderr,
            'both captured streams must arrive complete (exit=' . $exit . ', stdout=' . $stdout . ')',
        );
        $this->assertSame(0, $exit);
    }
}
