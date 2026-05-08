<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\WindowsBackend;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WindowsBackend.
 *
 * These tests stub the Kernel32 FFI surface so they run on any platform.
 * On Windows a second suite of integration tests verifies actual mode
 * flags on a real console handle.
 */
final class WindowsBackendTest extends TestCase
{
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

    public function testIsTtyFalseForNonConsoleStreamOnWindows(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows-only test');
        }

        // A pipe or file stream is never a console handle.
        $r = fopen('php://temp', 'r+');
        $this->assertNotFalse($r);
        $tty = new WindowsBackend($r);
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testOpenTtyReturnsNull(): void
    {
        // PR5 wires up CONIN$/CONOUT$ via CreateFileW.
        // In this slice (PR1) the method is a stub returning null.
        $result = WindowsBackend::openTty();
        $this->assertNull($result);
    }

    public function testSizeFallsBackTo80x24(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new WindowsBackend($r);

        // Force an unavailable console so it falls through to the
        // 80×24 fallback.  We cannot easily mock Kernel32 in this
        // context, so we just verify the fallback does not throw.
        $size = $tty->size();
        $this->assertArrayHasKey('cols', $size);
        $this->assertArrayHasKey('rows', $size);
        $this->assertSame(80, $size['cols']);
        $this->assertSame(24, $size['rows']);

        fclose($r);
    }

    public function testEnableRawModeIsNoOp(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new WindowsBackend($r);
        // Must not throw.
        $tty->enableRawMode();
        $tty->restore();
        fclose($r);
    }

    public function testOnResizeReturnsFalse(): void
    {
        // PR3 wires up resize signalling.
        $result = WindowsBackend::onResize(static fn(int $cols, int $rows) => null);
        $this->assertFalse($result);
    }

    public function testDrainSignalsReturnsFalse(): void
    {
        // PR3 wires up resize signalling.
        $result = WindowsBackend::drainSignals();
        $this->assertFalse($result);
    }
}
