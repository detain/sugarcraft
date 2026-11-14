<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\Backend;
use SugarCraft\Core\Util\Tty\InterruptFlags;
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

    public function testSizeFallsBackToSharedLastResortSizeWhenNoScreenBufferInfo(): void
    {
        $fake = new FakeKernel32();
        $fake->setScreenBufferInfo(null);

        $size = $this->backend($fake)->size();

        // The literal 200x60 is deliberate: pre-m17 this site answered its own
        // 80x24 under the shared size() contract. Any revert of either side of
        // the unification reddens this witness.
        $this->assertSame(['cols' => 200, 'rows' => 60], $size);
        $this->assertSame(Backend::LAST_RESORT_SIZE, $size);
    }

    public function testSizeReturnsScreenBufferInfoWhenAvailable(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001); // so getConsoleMode doesn't return false
        $fake->setScreenBufferInfo(['cols' => 120, 'rows' => 40]);

        $size = $this->backend($fake)->size();

        $this->assertSame(120, $size['cols']);
        $this->assertSame(40, $size['rows']);
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
        $this->assertSame(437, $cpInCalls[1]);    // restored
        $this->assertSame(65001, $cpOutCalls[0]);
        $this->assertSame(437, $cpOutCalls[1]);   // restored
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
        // openTty() requires a real console; on headless Windows CI (no ConHost)
        // it returns null. Skip the test in that environment.
        if (DIRECTORY_SEPARATOR === '\\' && WindowsBackend::openTty() === null) {
            $this->markTestSkipped('openTty() returned null: no console attached on this Windows environment');
        }
        // Use default FakeKernel32 — createFile returns fds 0/1 (stdio fds)
        // for CONIN$/CONOUT$, so fopen("php://fd/0") succeeds.
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

    // ─── PR5: drainSignals + CONIN$ key detection (sc-02 / finding 052) ────

    /**
     * A KEY_EVENT record in the shape Kernel32::readConsoleInput() ships:
     * full field set, real control-state bits (not the superseded fake's
     * 'ctrl' => true boolean that pre-sc-02 pins seeded).
     *
     * @return array<string, mixed>
     */
    private static function keyRecord(
        int $vk,
        int $char = 0,
        bool $down = true,
        int $ctrl = 0,
        int $repeat = 1,
    ): array {
        return [
            'type'    => Kernel32Interface::KEY_EVENT,
            'keyDown' => $down,
            'repeat'  => $repeat,
            'vk'      => $vk,
            'scan'    => 0,
            'char'    => $char,
            'ctrl'    => $ctrl,
        ];
    }

    /**
     * The machine, end to end: a plain key is INPUT (forwarded, not eaten,
     * not a signal), a Ctrl+C key-up is nothing, and the Ctrl+C key-down is
     * the interrupt. The superseded heuristic answered the opposite on all
     * three arms: first-seen KEY_EVENT of any kind flipped a permanent latch
     * to "interrupt", and every record went to the shredder.
     */
    public function testDrainSignalsMapsOnlyCtrlCKeyDownAndForwardsPlainKeys(): void
    {
        $fake = new FakeKernel32();
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());
        $this->assertNotNull(WindowsBackend::openTty());

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([
            self::keyRecord(0x41, char: 0x61),                                   // plain 'a' down
            self::keyRecord(Kernel32Interface::VK_C, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED, down: false),  // Ctrl+C UP: no signal
            self::keyRecord(Kernel32Interface::VK_C, char: 0x03, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED),   // Ctrl+C DOWN: the signal
        ]);

        $result = WindowsBackend::drainSignals();

        $this->assertNotFalse($result);
        $this->assertNotSame(0, $result & WindowsBackend::SIGNAL_INTERRUPT);
        $this->assertSame('a', WindowsBackend::takePendingKeyBytes(), 'the plain key rode the forward, not the shredder');
        $this->assertSame('', WindowsBackend::takePendingKeyBytes(), 'the take empties');
        // Ctrl+C down was consumed as the SIGNAL, its bytes not forwarded too.
        $this->assertStringNotContainsString("\x03", WindowsBackend::takePendingKeyBytes());

        WindowsBackend::resetStaticState();
    }

    public function testDrainSignalsMapsCtrlBreakKeyDownToInterrupt(): void
    {
        $fake = new FakeKernel32();
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());
        $this->assertNotNull(WindowsBackend::openTty());

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([
            self::keyRecord(Kernel32Interface::VK_CANCEL, char: 0x1E, ctrl: Kernel32Interface::RIGHT_CTRL_PRESSED),
        ]);

        $result = WindowsBackend::drainSignals();
        $this->assertNotFalse($result);
        $this->assertNotSame(0, $result & WindowsBackend::SIGNAL_INTERRUPT);

        WindowsBackend::resetStaticState();
    }

    /**
     * Ctrl held over an ordinary key is input, not an interrupt — and its
     * decoded control byte reaches the input pump. Ctrl+A arrives under
     * ENABLE_VIRTUAL_TERMINAL_INPUT as vk 'A' with the Ctrl state and the
     * xterm byte 0x01 in UnicodeChar.
     */
    public function testPlainCtrlCombinationsAreInputNotInterrupt(): void
    {
        $fake = new FakeKernel32();
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());
        $this->assertNotNull(WindowsBackend::openTty());

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([
            self::keyRecord(0x41, char: 0x01, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED), // Ctrl+A
            self::keyRecord(0x56, char: 0x16, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED), // Ctrl+V
        ]);

        $this->assertFalse(WindowsBackend::drainSignals(), 'neither record is a signal');
        $this->assertSame("\x01\x16", WindowsBackend::takePendingKeyBytes());

        WindowsBackend::resetStaticState();
    }

    /**
     * The interrupt is per-PRESS, not one-shot-per-process: two Ctrl+C downs
     * across two ticks must both answer SIGNAL_INTERRUPT. The superseded
     * $interruptKeySeen latch delivered only the first one in the process.
     */
    public function testConsecutiveInterruptPressesBothSignal(): void
    {
        $fake = new FakeKernel32();
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());
        $this->assertNotNull(WindowsBackend::openTty());

        $down = self::keyRecord(Kernel32Interface::VK_C, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED);

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([$down]);
        $first = WindowsBackend::drainSignals();
        $fake->setReadConsoleInputSequence([$down]);
        $second = WindowsBackend::drainSignals();

        $this->assertNotSame(0, (int) $first & WindowsBackend::SIGNAL_INTERRUPT);
        $this->assertNotSame(0, (int) $second & WindowsBackend::SIGNAL_INTERRUPT);

        WindowsBackend::resetStaticState();
    }

    /**
     * Record-shape contract (sc-02 / finding 053): the interface names the
     * slot dataIndex, the real binding stamps dataIndex — the fake must not
     * drift into modelling a shape the kernel never returns.
     */
    public function testFakeReadConsoleInputCarriesTheDataIndexContractSlot(): void
    {
        $fake = new FakeKernel32();
        $fake->setReadConsoleInputSequence([['type' => Kernel32Interface::KEY_EVENT]]);

        // Peek first — it must NOT consume (the real API leaves the queue
        // intact) — then the consuming read.
        $peeked = [];
        $this->assertSame(1, $fake->peekConsoleInput(1, $peeked, 8));
        $this->assertArrayHasKey('dataIndex', $peeked[0]);

        $records = $fake->readConsoleInput(1, 8);

        $this->assertIsArray($records);
        $this->assertArrayHasKey('dataIndex', $records[0]);
    }

    // ─── sc-02 / finding 051: acquisition survives stream failure ──────────

    /**
     * A HANDLE is not a CRT fd: on a live Windows console the php://fd wrap
     * can legitimately fail (named Windows-CI survivor). The interrupt poll
     * runs on the HANDLE, so a failed wrap must keep the poll alive and only
     * answer null for the stream pair the caller asked for. The superseded
     * shape closed both handles and nulled the CONIN$ static on wrap failure
     * — a stream stumble silently murdered signal detection with it.
     */
    public function testOpenTtyKeepsConsolePollWhenStreamWrapFails(): void
    {
        $fake = new FakeKernel32();
        // Realistic Windows handles: large pointer-ish ints, no such fd here,
        // so both php://fd wraps fail.
        $fake->setCreateFileHandle('CONIN$', 999);
        $fake->setCreateFileHandle('CONOUT$', 998);
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());

        $this->assertNull(WindowsBackend::openTty(), 'no stream pair on a failed wrap');

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([
            self::keyRecord(Kernel32Interface::VK_C, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED),
        ]);
        $result = WindowsBackend::drainSignals();
        $this->assertNotFalse($result, 'the CONIN$ poll survived the wrap failure');
        $this->assertNotSame(0, $result & WindowsBackend::SIGNAL_INTERRUPT);

        WindowsBackend::resetStaticState();
    }

    /** The output side refusing to open must not blind the input side either. */
    public function testOpenTtyKeepsConsolePollWhenConoutRefuses(): void
    {
        $fake = new FakeKernel32();
        $fake->setCreateFileHandle('CONOUT$', false);
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());

        $this->assertNull(WindowsBackend::openTty());

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([
            self::keyRecord(0x41, char: 0x61), // plain key: forwarded, no signal
        ]);
        $this->assertFalse(WindowsBackend::drainSignals());
        $this->assertSame('a', WindowsBackend::takePendingKeyBytes());

        WindowsBackend::resetStaticState();
    }

    /** Only a failed CONIN$ acquisition itself leaves the machine dormant. */
    public function testOpenTtyStaysDormantWhenConinAcquisitionFails(): void
    {
        $fake = new FakeKernel32();
        $fake->setCreateFileHandle('CONIN$', false);
        WindowsBackend::setTestKernel32($fake);
        WindowsBackend::setTestInterruptFlags(new FakeInterruptFlags());

        $this->assertNull(WindowsBackend::openTty());

        $fake->setWaitSignalled(true);
        $fake->setReadConsoleInputSequence([
            self::keyRecord(Kernel32Interface::VK_C, ctrl: Kernel32Interface::LEFT_CTRL_PRESSED),
        ]);
        $this->assertFalse(WindowsBackend::drainSignals(), 'nothing was acquired, nothing polls');

        WindowsBackend::resetStaticState();
    }

    // ─── sc-02 / finding 053: restore() keeps process-shared machinery ─────

    /**
     * One backend's restore() (and every __destruct rides it) used to call
     * InterruptFlags::self()->destroy() — tearing the ONE shared segment out
     * from under sibling backends and leaving the cached singleton as a
     * zombie that answered consume()===false forever. Segment lifetime is now
     * the process (enableRawMode's single shutdown hook); a restore must keep
     * the singleton intact for every other holder.
     */
    public function testRestoreKeepsTheSharedInterruptSegmentForSiblings(): void
    {
        $before = InterruptFlags::self();

        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        $backend = $this->backend($fake);
        $backend->enableRawMode();
        $backend->restore();

        $this->assertSame($before, InterruptFlags::self(), 'restore() must not strand the shared flag');
    }

    // ─── sc-40 / finding 059: panic-rescue + shutdown lifecycle ──────────────

    /**
     * restoreLast() used to call a method that never existed
     * (`self::kernel32()`); `Error` is a Throwable, so the best-effort catch
     * swallowed the fatal on EVERY invocation — the rescue path was a silent
     * no-op end to end. Pinned observable through the injected double: the
     * classic arm/fire shape must actually save, then actually restore.
     */
    public function testRestoreLastSaveThenFireDrivesTheKernel(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        $fake->setConsoleCpIn(1252);
        WindowsBackend::setTestKernel32($fake);

        WindowsBackend::restoreLast(); // arm: save current
        $this->assertSame([], $fake->getSetConsoleModeCalls(), 'the save pass must not write any mode');
        $this->assertSame(0x0003, $this->staticSlot('lastInputMode'));

        WindowsBackend::restoreLast(); // fire: restore saved
        $this->assertContains([$fake->stdIn(), 0x0003], $fake->getSetConsoleModeCalls());
        $this->assertContains([$fake->stdOut(), 0x0001], $fake->getSetConsoleModeCalls());
        $this->assertContains(1252, $fake->getSetConsoleCPCalls(), 'the codepage side of the rescue must ride too');
        $this->assertNull($this->staticSlot('lastInputMode'), 'a fired rescue clears its slot');
    }

    /**
     * Coherence (finding 059): after a raw enable, the rescue slot holds the
     * PRE-raw modes seeded by enableRawMode itself — so a panic handler's
     * first restoreLast() lands on the real original state instead of
     * "saving" the raw mode it was meant to undo. First-writer-wins across
     * re-instantiation: the second enable (which now captures a raw world)
     * must not overwrite the first instance's original snapshot.
     */
    public function testEnableRawModeSeedsTheRescueSlotFirstWriterWins(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        WindowsBackend::setTestKernel32($fake);

        $first = $this->backend($fake);
        $first->enableRawMode();
        $this->assertSame(0x0003, $this->staticSlot('lastInputMode'), 'the enable itself must seed the rescue slot');

        // The fake now reports the RAW modes the first enable installed; a
        // second enable captures those, but the slot keeps the original.
        $after = $fake->getSetConsoleModeCalls();
        $this->assertNotSame([], $after);
        $fake->setConsoleModeStdin(end($after)[1]);
        $fake->setConsoleModeStdout(end($after)[1]);

        $secondStream = fopen('php://memory', 'r+');
        $this->assertNotFalse($secondStream);
        $second = new WindowsBackend($secondStream, $fake);
        try {
            $second->enableRawMode();
            $this->assertSame(0x0003, $this->staticSlot('lastInputMode'), 'a later enable must not overwrite the original rescue snapshot');

            WindowsBackend::restoreLast(); // seeded slot: first call already restores
            $this->assertContains([$fake->stdIn(), 0x0003], $fake->getSetConsoleModeCalls(), 'the rescue must replay the ORIGINAL mode, not the raw one');
        } finally {
            $second->restore();
            if (is_resource($secondStream)) {
                fclose($secondStream);
            }
        }
    }

    /**
     * The shutdown backstop is armed per INSTANCE now. The method-local
     * `static $registered` gave ONE process-wide registration while
     * backends re-instantiate freely: the second instance that entered raw
     * mode rode no backstop at all, and its closure referenced the FIRST
     * instance's snapshot anyway.
     */
    public function testShutdownBackstopIsArmedPerInstance(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        WindowsBackend::setTestKernel32($fake);

        $first = $this->backend($fake);
        $first->enableRawMode();

        $secondStream = fopen('php://memory', 'r+');
        $this->assertNotFalse($secondStream);
        $second = new WindowsBackend($secondStream, $fake);
        try {
            $second->enableRawMode();

            $prop = new \ReflectionProperty(WindowsBackend::class, 'shutdownRegistered');
            $this->assertTrue($prop->getValue($first), 'the enabling instance must hold a shutdown backstop');
            $this->assertTrue($prop->getValue($second), 'backstops do not stack-share: every raw-mode instance needs its own');
        } finally {
            $second->restore();
            if (is_resource($secondStream)) {
                fclose($secondStream);
            }
        }
    }

    /** Read a private static slot off WindowsBackend. */
    private function staticSlot(string $name): mixed
    {
        $prop = new \ReflectionProperty(WindowsBackend::class, $name);
        $prop->setAccessible(true);

        return $prop->getValue(null);
    }
}
