<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonInteractive;

/**
 * ISS-080: `readStdinIfPiped()` used to hand any non-TTY stdin straight to
 * `stream_get_contents()`, so `sleep 3 | candy-code -p hi` spent 3.05 s inside
 * the read and a wrapper that spawns with `stdio: 'pipe'` and never closes
 * fd 0 hung the binary forever. The fix watches the FIRST byte with
 * `stream_select()` for a bounded grace and treats silence as no context.
 *
 * These pins run against real pipes because that is the shape with the
 * hazard: an in-memory stream could not reproduce either half (it is
 * select-incompatible, and its "EOF" arrives instantly). The grace constant is
 * private; the timing floors/ceilings below bracket 2 s with enough slack to
 * survive a loaded CI box without tolerating a hang.
 */
final class NonInteractiveStdinGraceTest extends TestCase
{
    /**
     * A pipe that stays open and never writes: null answer, bounded wait.
     * Before ISS-080 this test would not fail — it would simply never return,
     * which is the entire defect (the 60 s defaultTimeLimit reported RISKY,
     * never a verdict naming stdin).
     */
    public function testASilentOpenPipeYieldsNoContextInBoundedTime(): void
    {
        // The PARENT-readable end is the one the child WRITES to: with the
        // child alive and silent, this handle has an open writer, no bytes,
        // and no EOF — the exact ISS-080 shape.
        $child = \proc_open('exec sleep 60', [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);

        try {
            $started = \microtime(true);
            $context = NonInteractive::readStdinIfPiped($pipes[1]);
            $elapsed = \microtime(true) - $started;
        } finally {
            \fclose($pipes[1]);
            \proc_terminate($child, 9);
            \proc_close($child);
        }

        self::assertNull($context);
        self::assertGreaterThanOrEqual(1.5, $elapsed, 'returned before the grace had elapsed — the watch is not waiting');
        self::assertLessThan(10.0, $elapsed, 'blocked far past the grace — the silent pipe is being read instead of watched');
    }

    /**
     * A pipe that carries bytes keeps them: the grace only refuses SILENCE,
     * never a writer that shows up (the issue's `sleep 1 | …` shape, run
     * against an instantly-written pipe so the pin stays quick).
     */
    public function testAReadablePipeStillYieldsItsContext(): void
    {
        $child = \proc_open('exec printf context-bytes', [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);

        try {
            $context = NonInteractive::readStdinIfPiped($pipes[1]);
        } finally {
            \fclose($pipes[1]);
            \proc_close($child);
        }

        self::assertSame('context-bytes', $context);
    }

    /**
     * The `tests/bootstrap.php` pin shape — an empty `php://memory` — must
     * answer null in bounded time even though `stream_select()` THROWS for
     * memory streams (measured: warning, then `ValueError`). If the catch ever
     * reads the throw as silence, the whole suite's `-p` path starts printing
     * the quiet-pipe notice (or worse, regresses); this pin keeps the
     * fall-through honest.
     */
    public function testTheSelectIncompatibleMemoryDefaultStillAnswersNull(): void
    {
        $memory = \fopen('php://memory', 'r+');
        self::assertIsResource($memory);

        $started = \microtime(true);
        self::assertNull(NonInteractive::readStdinIfPiped($memory));
        self::assertLessThan(1.0, \microtime(true) - $started);

        \fclose($memory);
    }

    /**
     * A regular file (the `candy-code -p hi < notes.txt` shape) is readable
     * instantly and must not pay the grace or lose its bytes.
     */
    public function testAFileRedirectionIsNotMistakenForSilence(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'iss080');
        self::assertIsString($path);
        \file_put_contents($path, 'from-the-file');
        $handle = \fopen($path, 'r');
        self::assertIsResource($handle);

        try {
            self::assertSame('from-the-file', NonInteractive::readStdinIfPiped($handle));
        } finally {
            \fclose($handle);
            @\unlink($path);
        }
    }
}
