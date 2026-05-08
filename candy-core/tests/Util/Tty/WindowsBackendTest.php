<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\WindowsBackend;
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
    public function testIsTtyFalseForMemoryStream(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows-only test');
        }

        $r = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r);
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testOpenTtyReturnsNull(): void
    {
        $result = WindowsBackend::openTty();
        $this->assertNull($result);
    }

    public function testSizeFallsBackTo80x24WhenNoScreenBufferInfo(): void
    {
        $fake = new FakeKernel32();
        $fake->setScreenBufferInfo(null);

        $r   = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r, $fake);
        $size = $tty->size();

        $this->assertSame(80, $size['cols']);
        $this->assertSame(24, $size['rows']);
        fclose($r);
    }

    public function testSizeReturnsScreenBufferInfoWhenAvailable(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdout(0x0001); // needed so getConsoleMode returns a value, not false
        $fake->setScreenBufferInfo(['cols' => 120, 'rows' => 40]);

        $r   = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r, $fake);
        $size = $tty->size();

        $this->assertSame(120, $size['cols']);
        $this->assertSame(40,  $size['rows']);
        fclose($r);
    }

    public function testEnableRawModeCapturesAndSetsModes(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);   // ENABLE_LINE_INPUT | ENABLE_ECHO_INPUT
        $fake->setConsoleModeStdout(0x0001);  // ENABLE_PROCESSED_OUTPUT
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $r   = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r, $fake);
        $tty->enableRawMode();

        $modeCalls = $fake->getSetConsoleModeCalls();
        $this->assertCount(2, $modeCalls);

        // Input:  saved=0x0003 → raw = 0x0003 & ~0x0007 | VT_INPUT(0x0200) | WINDOW_INPUT(0x0008) = 0x0208
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

        fclose($r);
    }

    public function testRestoreReversesAllCapturedState(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $r   = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r, $fake);

        $tty->enableRawMode();
        $tty->restore();

        $modeCalls = $fake->getSetConsoleModeCalls();
        $this->assertCount(4, $modeCalls); // 2 enable + 2 restore

        // Restore stdin: second call pair first member
        $this->assertSame(0x0003, $modeCalls[2][1]);
        // Restore stdout: second call pair second member
        $this->assertSame(0x0001, $modeCalls[3][1]);

        $cpInCalls  = $fake->getSetConsoleCPCalls();
        $cpOutCalls = $fake->getSetConsoleOutputCPCalls();
        $this->assertCount(2, $cpInCalls);   // set UTF-8, then restore
        $this->assertCount(2, $cpOutCalls);  // set UTF-8, then restore
        $this->assertSame(65001, $cpInCalls[0]);
        $this->assertSame(437,    $cpInCalls[1]);   // restore
        $this->assertSame(65001, $cpOutCalls[0]);
        $this->assertSame(437,    $cpOutCalls[1]);  // restore

        fclose($r);
    }

    public function testEnableRawModeIsIdempotent(): void
    {
        $fake = new FakeKernel32();
        $fake->setConsoleModeStdin(0x0003);
        $fake->setConsoleModeStdout(0x0001);
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $r   = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r, $fake);

        $tty->enableRawMode();
        $tty->enableRawMode(); // Second call: no-op (savedInputMode already set)
        $tty->restore();
        $tty->restore(); // Second restore: no-op

        $modeCalls = $fake->getSetConsoleModeCalls();
        // Only one enable pair (2 calls), then one restore pair (2 calls)
        $this->assertCount(4, $modeCalls);

        fclose($r);
    }

    public function testEnableRawModeBailsOnGetConsoleModeFailure(): void
    {
        $fake = new FakeKernel32();
        // Leave consoleModeStdin as null so getConsoleMode($stdin) returns
        // false, simulating a non-console handle.
        $fake->setConsoleModeStdout(0);
        $fake->setConsoleCpIn(437);
        $fake->setConsoleCpOut(437);

        $r   = fopen('php://memory', 'r+');
        $tty = new WindowsBackend($r, $fake);

        // Must not throw; must leave console mode untouched.
        $tty->enableRawMode();

        $modeCalls = $fake->getSetConsoleModeCalls();
        $this->assertCount(0, $modeCalls); // No modes set on failure

        fclose($r);
    }

    public function testOnResizeReturnsFalse(): void
    {
        $result = WindowsBackend::onResize(static fn(int $cols, int $rows) => null);
        $this->assertFalse($result);
    }

    public function testDrainSignalsReturnsFalse(): void
    {
        $result = WindowsBackend::drainSignals();
        $this->assertFalse($result);
    }
}
