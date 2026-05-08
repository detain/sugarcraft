<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\WindowsBackend;
use SugarCraft\Core\Tests\Util\Tty\FakeInterruptFlags;
use SugarCraft\Core\Tests\Util\Tty\FakeKernel32;
use SugarCraft\Core\Util\Tty\Kernel32Interface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WindowsBackend.
 *
 * These tests use FakeKernel32 to stub the Kernel32 surface so they
 * run on any platform.  On Windows a second suite of integration tests
 * verifies actual mode flags on a real console handle.
 */
final class WindowsBackendTest extends TestCase
{
    private ?WindowsBackend $windowsBackend = null;

    protected function setUp(): void
    {
        parent::setUp();
        WindowsBackend::resetStaticState();
    }

    protected function tearDown(): void
    {
        // Restore any raw mode that was enabled during a test.
        if ($this->windowsBackend !== null) {
            $this->windowsBackend->restore();
            $this->windowsBackend = null;
        }

        // Reset static state so the next test starts clean.
        // Do NOT call setTestKernel32(null) here — the next test's setUp()
        // will inject its own FakeKernel32 (or null to use the real Kernel32).
        // Clearing it would cause the next test's size() call to use the real
        // Kernel32 singleton (FFI crash on Linux before it can inject its fake).
        WindowsBackend::setTestInterruptFlags(null);
        WindowsBackend::resetStaticState();
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function backend(?FakeKernel32 $fake = null): WindowsBackend
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $this->windowsBackend = new WindowsBackend($r, $fake);
        return $this->windowsBackend;
    }

    // ─── isTty() ─────────────────────────────────────────────────────────────

    public function testIsTtyFalseForMemoryStream(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows-only test');
        }

        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new WindowsBackend($r);
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    // ─── openTty() ───────────────────────────────────────────────────────────

    public function testOpenTtyReturnsNull(): void
    {
        $this->assertNull(WindowsBackend::openTty());
    }

    // ─── size() ──────────────────────────────────────────────────────────────

    public function testSizeFallsBackTo80x24WhenNoScreenBufferInfo(): void
    {
        $fake = new FakeKernel32();
        $fake->setScreenBufferInfo(null);

        $size = $this->backend($fake)->size();

        $this->assertSame(80, $size['cols']);
        $this->assertSame(24, $size['rows']);
    }

    public function testSizeReturnsScreenBufferInfoWhenAvailable(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001); // so getConsoleMode doesn't return false
        $fake->setScreenBufferInfo(['cols' => 120, 'rows' => 40]);

        $size = $this->backend($fake)->size();

        $this->assertSame(120, $size['cols']);
        $this->assertSame(40,  $size['rows']);
    }

    // ─── enableRawMode() / restore() ─────────────────────────────────────────

    public function testEnableRawModeCapturesAndSetsModes(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);   // ENABLE_LINE_INPUT | ENABLE_ECHO_INPUT
        $fake->setConsoleModeStdout(0x0001);  // ENABLE_PROCESSED_OUTPUT
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $tty = $this->backend($fake);
        $tty->enableRawMode();

        $modeCalls = $fake->getSetConsoleModeCalls();
        $this->assertCount(2, $modeCalls);

        // Input: saved=0x0003 → raw = 0x0003 & ~0x0007 | VT_INPUT(0x0200) | WINDOW_INPUT(0x0008) = 0x0208
        [$stdinH, $stdinMode] = $modeCalls[0];
        $this->assertSame(0x0208, $stdinMode);

        // Output: saved=0x0001 → raw = 0x0001 | VT_PROCESSING(0x0004) | DISABLE_NL(0x0008) = 0x000D
        [$stdoutH, $stdoutMode] = $modeCalls[1];
        $this->assertSame(0x000D, $stdoutMode);

        // Codepages set to 65001 (UTF-8)
        $cpInCalls  = $fake->getSetConsoleCPCalls();
        $cpOutCalls = $fake->getSetConsoleOutputCPCalls();
        $this->assertCount(1, $cpInCalls);
        $this->assertCount(1, $cpOutCalls);
        $this->assertSame(65001, $cpInCalls[0]);
        $this->assertSame(65001, $cpOutCalls[0]);
    }

    public function testRestoreReversesAllCapturedState(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $tty = $this->backend($fake);
        $tty->enableRawMode();
        $tty->restore();

        $modeCalls = $fake->getSetConsoleModeCalls();
        $this->assertCount(4, $modeCalls); // 2 enable + 2 restore

        // Restore stdin: second call pair, first member
        $this->assertSame(0x0003, $modeCalls[2][1]);
        // Restore stdout: second call pair, second member
        $this->assertSame(0x0001, $modeCalls[3][1]);

        $cpInCalls  = $fake->getSetConsoleCPCalls();
        $cpOutCalls = $fake->getSetConsoleOutputCPCalls();
        $this->assertCount(2, $cpInCalls);   // set UTF-8, then restore
        $this->assertCount(2, $cpOutCalls);  // set UTF-8, then restore
        $this->assertSame(65001, $cpInCalls[0]);
        $this->assertSame(437,    $cpInCalls[1]);    // restored
        $this->assertSame(65001, $cpOutCalls[0]);
        $this->assertSame(437,    $cpOutCalls[1]);   // restored
    }

    public function testEnableRawModeIsIdempotent(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $tty = $this->backend($fake);
        $tty->enableRawMode();
        $tty->enableRawMode(); // second call: no-op (savedInputMode already set)
        $tty->restore();
        $tty->restore();       // second restore: no-op

        $modeCalls = $fake->getSetConsoleModeCalls();
        // Only one enable pair (2 calls) + one restore pair (2 calls)
        $this->assertCount(4, $modeCalls);
    }

    public function testEnableRawModeBailsOnGetConsoleModeFailure(): void
    {
        $fake = new FakeKernel32();
        // Leave consoleModeStdin as null → getConsoleMode($stdin) returns false
        // → bail condition is met → no modes set.
        $fake->setConsoleModeStdout(0);
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $tty = $this->backend($fake);
        // Must not throw; must leave console state completely untouched.
        $tty->enableRawMode();

        $modeCalls = $fake->getSetConsoleModeCalls();
        $this->assertCount(0, $modeCalls); // Nothing set on failure

        $cpInCalls  = $fake->getSetConsoleCPCalls();
        $cpOutCalls = $fake->getSetConsoleOutputCPCalls();
        $this->assertCount(0, $cpInCalls);  // No codepage changes either
        $this->assertCount(0, $cpOutCalls);
    }

    // ─── Resize signalling: onResize() / drainSignals() ──────────────────────

    public function testOnResizeReturnsTrueAndStoresCallback(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001);
        $fake->setScreenBufferInfoSequence([
            ['cols' => 120, 'rows' => 40],
        ]);
        WindowsBackend::setTestKernel32($fake);

        $fired = [];
        $result = WindowsBackend::onResize(
            static function (int $cols, int $rows) use (&$fired): void {
                $fired[] = [$cols, $rows];
            },
        );

        $this->assertTrue($result);
        // Drain the first poll (fires immediately because no last size)
        $drained = WindowsBackend::drainSignals();
        $this->assertNotFalse($drained);
        $this->assertNotSame(0, $drained & WindowsBackend::SIGNAL_RESIZE);
        $this->assertSame([[120, 40]], $fired);
    }

    public function testDrainSignalsDetectsResizeAcrossTicks(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001);
        // Sequence: initial 120x40, then resize to 100x30, then same 100x30.
        $fake->setScreenBufferInfoSequence([
            ['cols' => 120, 'rows' => 40],  // tick 1: new → fires
            ['cols' => 100, 'rows' => 30],  // tick 2: changed → fires
            ['cols' => 100, 'rows' => 30],  // tick 3: same → no fire
        ]);
        WindowsBackend::setTestKernel32($fake);

        $fired = [];
        WindowsBackend::onResize(
            static function (int $cols, int $rows) use (&$fired): void {
                $fired[] = [$cols, $rows];
            },
        );

        // Tick 1: no last size → fires immediately with first poll result
        $r1 = WindowsBackend::drainSignals();
        $this->assertNotFalse($r1);
        $this->assertNotSame(0, $r1 & WindowsBackend::SIGNAL_RESIZE);
        // Tick 2: 120x40 → 100x30 (fires)
        $r2 = WindowsBackend::drainSignals();
        $this->assertNotFalse($r2);
        $this->assertNotSame(0, $r2 & WindowsBackend::SIGNAL_RESIZE);
        // Tick 3: 100x30 → 100x30 (no change)
        $this->assertFalse(WindowsBackend::drainSignals());
        // Sequence exhausted, falls back to null → false
        $this->assertFalse(WindowsBackend::drainSignals());

        $this->assertCount(2, $fired);
        $this->assertSame([120, 40], $fired[0]);
        $this->assertSame([100, 30], $fired[1]);
    }

    public function testDrainSignalsReturnsFalseWhenNoCallbackRegistered(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001);
        $fake->setScreenBufferInfoSequence([
            ['cols' => 120, 'rows' => 40],
        ]);
        WindowsBackend::setTestKernel32($fake);

        // No onResize() call — no callback registered.
        $this->assertFalse(WindowsBackend::drainSignals());
        $this->assertFalse(WindowsBackend::drainSignals());
    }

    public function testDrainSignalsUsesTestKernel32(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001);
        // Only provide one entry; after sequence is exhausted, falls back to
        // $screenBufferInfo (null) → drainSignals returns false.
        $fake->setScreenBufferInfoSequence([
            ['cols' => 200, 'rows' => 60],
        ]);
        WindowsBackend::setTestKernel32($fake);

        $fired = [];
        WindowsBackend::onResize(
            static function (int $cols, int $rows) use (&$fired): void {
                $fired[] = [$cols, $rows];
            },
        );

        // First tick fires (200x60)
        $r = WindowsBackend::drainSignals();
        $this->assertNotFalse($r);
        $this->assertNotSame(0, $r & WindowsBackend::SIGNAL_RESIZE);
        $this->assertSame([200, 60], $fired[0]);

        // Second tick: sequence exhausted, falls back to null → no fire
        $this->assertFalse(WindowsBackend::drainSignals());
        $this->assertCount(1, $fired); // Still only one fire
    }

    public function testResetStaticStateClearsResizeState(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001);
        $fake->setScreenBufferInfoSequence([
            ['cols' => 120, 'rows' => 40],
        ]);
        WindowsBackend::setTestKernel32($fake);

        $fired = [];
        WindowsBackend::onResize(
            static function (int $cols, int $rows) use (&$fired): void {
                $fired[] = [$cols, $rows];
            },
        );
        WindowsBackend::drainSignals(); // fires

        // Verify state is set
        $this->assertCount(1, $fired);

        // Reset — simulating a fresh process / new test
        WindowsBackend::resetStaticState();

        // After reset, drainSignals returns false (no callback)
        // even though testKernel32 is still injected.
        $this->assertFalse(WindowsBackend::drainSignals());
    }

    // ─── Interrupt signalling (PR4) ─────────────────────────────────────────

    public function testDrainSignalsReturnsInterruptSignalWhenFlagSet(): void
    {
        $fakeFlags = new FakeInterruptFlags();
        WindowsBackend::setTestInterruptFlags($fakeFlags);
        WindowsBackend::setTestKernel32(new FakeKernel32());

        // Simulate a Ctrl+C arriving on the native handler thread.
        $fakeFlags->set();

        $result = WindowsBackend::drainSignals();

        $this->assertNotFalse($result);
        $this->assertNotSame(0, $result & WindowsBackend::SIGNAL_INTERRUPT);
    }

    public function testDrainSignalsReturnsZeroWhenNoInterruptFlagSet(): void
    {
        $fakeFlags = new FakeInterruptFlags();
        WindowsBackend::setTestInterruptFlags($fakeFlags);

        // No interrupt flag set, no resize callback registered.
        $result = WindowsBackend::drainSignals();

        // Returns false (no signals, no errors).
        $this->assertFalse($result);
    }

    public function testResetStaticStateClearsInterruptFlags(): void
    {
        $fakeFlags = new FakeInterruptFlags();
        $fakeFlags->set(); // simulate Ctrl+C was pending
        WindowsBackend::setTestInterruptFlags($fakeFlags);

        // Verify signal is pending.
        $this->assertNotSame(0, WindowsBackend::drainSignals() & WindowsBackend::SIGNAL_INTERRUPT);

        // Reset static state.
        WindowsBackend::resetStaticState();

        // After reset: no interrupt flag is injected, so drainSignals
        // uses the real InterruptFlags singleton.  The previous set()
        // was on the fake, which is now dropped — no signal pending.
        // drainSignals returns false (no interrupt flag injected, no resize callback).
        $result = WindowsBackend::drainSignals();
        $this->assertFalse($result);
        $this->assertFalse($result);
    }

    // ─── PR5: openTty ───────────────────────────────────────────────────────

    public function testOpenTtyReturnsHandlesWhenCreateFileSucceeds(): void
    {
        // Use default FakeKernel32 — createFile returns stream_socket_pair fds
        // for CONIN$/CONOUT$, so fopen("php://fd/N") succeeds on Linux.
        $fake = new FakeKernel32();
        WindowsBackend::setTestKernel32($fake);

        $handles = WindowsBackend::openTty();

        $this->assertNotNull($handles);
        $this->assertCount(2, $handles);
        [$fin, $fout] = $handles;
        $this->assertIsResource($fin);
        $this->assertIsResource($fout);

        WindowsBackend::resetStaticState();
    }

    public function testOpenTtyReturnsNullWhenConinFails(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('CONIN$ failure simulation requires Windows');
        }
        $fake = new FakeKernel32();
        // Simulate createFile returning false for CONIN$ (no console attached).
        $fake->setCreateFileHandle('CONIN$', false);
        WindowsBackend::setTestKernel32($fake);

        $this->assertNull(WindowsBackend::openTty());

        WindowsBackend::resetStaticState();
    }

    // ─── PR5: drainSignals + CONIN$ key detection ──────────────────────────

    public function testDrainSignalsReturnsInterruptWhenConinHandleOpenAndKeyEvents(): void
    {
        $fake = new FakeKernel32();
        // Seed a KEY_EVENT into the console input queue (simulates Ctrl+C).
        $fake->setReadConsoleInputSequence([
            ['type' => Kernel32Interface::KEY_EVENT],
        ]);
        WindowsBackend::setTestKernel32($fake);
        // Open TTY so drainSignals reads CONIN$ for key events.
        $opened = WindowsBackend::openTty();
        $this->assertNotNull($opened);

        // No interrupt flag set; drainSignals must detect via KEY_EVENT polling.
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());

        $result = WindowsBackend::drainSignals();

        $this->assertNotFalse($result);
        $this->assertNotSame(0, $result & WindowsBackend::SIGNAL_INTERRUPT);

        WindowsBackend::resetStaticState();
    }
}
