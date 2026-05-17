<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Vcr\Cli\RecordCommand;

/**
 * P6.5.4 — host termios safety net. The recorder installs a
 * shutdown_function + SIGTERM/SIGHUP handlers that always restore the
 * host termios, even when the in-band `finally` never runs (fatal
 * error, signal-driven exit). The handlers are signal-safe (no
 * allocation, no logging) and idempotent.
 */
final class RecordCommandSafetyNetTest extends TestCase
{
    protected function tearDown(): void
    {
        // Forcefully reset the static state so test order can't leak.
        $ref = new \ReflectionClass(RecordCommand::class);
        $snap = $ref->getProperty('rescueSnapshot');
        $snap->setAccessible(true);
        $snap->setValue(null, null);
        $marker = $ref->getProperty('rescueMarkerPath');
        $marker->setAccessible(true);
        $marker->setValue(null, '');
    }

    public function testRescueRestoreNoOpWhenNoSnapshotInstalled(): void
    {
        // Should not throw even when no snapshot is installed —
        // shutdown_function will fire after every PHP run, including
        // tests that never went through `record`.
        RecordCommand::rescueRestore();
        $this->assertTrue(true, 'rescueRestore must be safe to call with no prior install');
    }

    public function testRescueRestoreCallsTermiosRestoreOnce(): void
    {
        $termios = new TrackingTermios();
        self::primeSnapshot($termios);

        RecordCommand::rescueRestore();
        $this->assertSame(1, $termios->restoreCalls, 'restore must fire once');

        // Idempotency: a second call must NOT double-restore (snapshot
        // already cleared internally).
        RecordCommand::rescueRestore();
        $this->assertSame(1, $termios->restoreCalls, 'restore must not double-fire');
    }

    public function testHandleRescueSignalRestoresButDoesNotKillInTestEnvWhenPcntlMissing(): void
    {
        // We can't really `pcntl_signal($signo, SIG_DFL); posix_kill(...)`
        // from inside PHPUnit without killing the test. Verify the
        // restoration logic still runs by intercepting before the
        // re-raise — TrackingTermios records the call.
        $termios = new TrackingTermios();
        self::primeSnapshot($termios);

        // Spawn the handler in a child process so re-raise doesn't
        // kill PHPUnit. Verify via the marker file that the child got
        // through the restore() call before re-raising.
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required to exercise the re-raise path');
        }

        $marker = \tempnam(\sys_get_temp_dir(), 'rescue-handler-');
        $this->assertIsString($marker);

        $pid = \pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: prime, call handler with SIGTERM, then exit cleanly.
            // (handleRescueSignal re-raises only when both pcntl_signal +
            // posix_kill are available; in PHPUnit they are, so the
            // child WILL die. The TrackingTermios writes the marker file
            // before that.)
            $childTermios = new TrackingTermios($marker);
            self::primeSnapshot($childTermios);
            RecordCommand::handleRescueSignal(\SIGTERM);
            // Should not reach here — handler re-raises.
            \file_put_contents($marker, "REACHED_AFTER_HANDLER\n", \FILE_APPEND);
            exit(0);
        }

        // Parent: reap the child.
        $status = 0;
        \pcntl_waitpid($pid, $status);

        // The TrackingTermios wrote 'RESTORED' to the marker, and the
        // child was killed by SIGTERM before reaching the trailing
        // append, so the file contains exactly the restore marker.
        $contents = (string) @\file_get_contents($marker);
        $this->assertStringContainsString('RESTORED', $contents);
        $this->assertStringNotContainsString('REACHED_AFTER_HANDLER', $contents);

        // Exit was signal-driven.
        $this->assertTrue(\pcntl_wifsignaled($status), 'child should have died from the re-raised signal');
        $this->assertSame(\SIGTERM, \pcntl_wtermsig($status));

        if (\file_exists($marker)) {
            @\unlink($marker);
        }
    }

    public function testInstallRescueHandlersWritesMarkerWhenStdinIsTty(): void
    {
        if (!\function_exists('posix_ttyname')) {
            $this->markTestSkipped('posix_ttyname required to exercise the marker path');
        }
        if (@\posix_ttyname(\STDIN) === false) {
            $this->markTestSkipped('PHPUnit stdin is not a tty; marker is best-effort skipped');
        }

        $termios = new TrackingTermios();
        $ref = new \ReflectionClass(RecordCommand::class);
        $method = $ref->getMethod('installRescueHandlers');
        $method->setAccessible(true);
        $method->invoke(null, $termios);

        $markerPath = $ref->getProperty('rescueMarkerPath');
        $markerPath->setAccessible(true);
        $path = (string) $markerPath->getValue();
        $this->assertNotSame('', $path);
        $this->assertFileExists($path);
        $payload = (string) \file_get_contents($path);
        $this->assertStringContainsString('tty=', $payload);
        $this->assertStringContainsString('pid=' . \getmypid(), $payload);

        RecordCommand::rescueRestore();
        $this->assertFileDoesNotExist($path);
    }

    private static function primeSnapshot(Termios $termios): void
    {
        $ref = new \ReflectionClass(RecordCommand::class);
        $snap = $ref->getProperty('rescueSnapshot');
        $snap->setAccessible(true);
        $snap->setValue(null, $termios);
    }
}

/**
 * Test stub for {@see Termios} that records restore() / apply() calls
 * and optionally appends a marker line to a file on every restore so a
 * forked child's behaviour can be observed across process boundaries.
 */
final class TrackingTermios implements Termios
{
    public int $restoreCalls = 0;

    public function __construct(private readonly ?string $markerPath = null) {}

    public function current(): self { return $this; }
    public function makeRaw(): self { return $this; }
    public function apply(int $when = self::TCSANOW): void {}
    public function isAtty(): bool { return true; }

    public function restore(): void
    {
        $this->restoreCalls++;
        if ($this->markerPath !== null) {
            @\file_put_contents($this->markerPath, "RESTORED\n", \FILE_APPEND);
        }
    }
}
