<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\Kernel32;
use SugarCraft\Core\Util\Tty\Kernel32Interface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Kernel32 FFI bindings.
 *
 * These tests verify the Kernel32 class methods that can be tested on
 * non-Windows platforms. Platform-specific methods that require actual
 * kernel32.dll FFI are marked as skipped with clear reasons.
 *
 * ## Coverage strategy
 *
 * Kernel32 is a thin FFI binding layer to kernel32.dll. Most methods
 * require the actual Windows DLL to be loaded via FFI::cdef(), which
 * throws on non-Windows platforms. On Linux we can only verify:
 *
 * 1. The `self()` factory method (doesn't call FFI)
 * 2. Interface constants (defined in Kernel32Interface)
 *
 * All instance methods call `ffi()` internally and will throw
 * FFI\Exception on Linux because kernel32.dll cannot be loaded.
 *
 * The WindowsBackend tests use FakeKernel32 to test the WindowsBackend
 * logic with a test double that doesn't require the real DLL.
 */
final class Kernel32Test extends TestCase
{
    // ─── self() factory ───────────────────────────────────────────────────────

    public function testSelfReturnsKernel32Instance(): void
    {
        $instance = Kernel32::self();

        $this->assertInstanceOf(Kernel32::class, $instance);
    }

    public function testSelfReturnsInterfaceCompatibleInstance(): void
    {
        $instance = Kernel32::self();

        $this->assertInstanceOf(Kernel32Interface::class, $instance);
    }

    // ─── Instance methods that require FFI (skipped on Linux) ─────────────────
    //
    // All of the following methods call ffi() internally, which throws
    // FFI\Exception on Linux because kernel32.dll cannot be loaded:
    // - getStdHandle(), stdIn(), stdOut(), stdErr()
    // - getConsoleMode(), setConsoleMode()
    // - getConsoleCP(), setConsoleCP(), getConsoleOutputCP(), setConsoleOutputCP()
    // - getConsoleScreenBufferInfo()
    // - createFile(), closeHandle(), getLastError()
    // - waitForSingleObject()
    // - peekConsoleInput(), readConsoleInput()
    // - setConsoleCtrlHandler()
    // - toWideString()
    //
    // These are tested via FakeKernel32 in WindowsBackendTest which provides
    // a test double for all interface methods without requiring FFI.

    public function testStdInRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - stdIn() calls getStdHandle() which calls ffi()');
    }

    public function testStdOutRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - stdOut() calls getStdHandle() which calls ffi()');
    }

    public function testStdErrRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - stdErr() calls getStdHandle() which calls ffi()');
    }

    public function testGetStdHandleRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testFfiRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - FFI::cdef() throws when DLL not found');
    }

    public function testGetConsoleCPRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testGetConsoleOutputCPRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testSetConsoleCPRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testSetConsoleOutputCPRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testGetConsoleModeRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testSetConsoleModeRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testGetConsoleScreenBufferInfoRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testCreateFileRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testCloseHandleRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testGetLastErrorRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testWaitForSingleObjectRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testPeekConsoleInputRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testReadConsoleInputRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls ffi() internally');
    }

    public function testSetConsoleCtrlHandlerRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll + PHP 8.4+ FFI::dynamicFunction - calls ffi() internally');
    }

    public function testToWideStringRequiresWindows(): void
    {
        $this->markTestSkipped('Requires Windows kernel32.dll - calls lib() which calls ffi() internally');
    }

    // ─── Interface constants ──────────────────────────────────────────────────

    public function testStdInputHandleConstantIsNegativeTen(): void
    {
        $this->assertSame(-10, Kernel32Interface::STD_INPUT_HANDLE);
    }

    public function testStdOutputHandleConstantIsNegativeEleven(): void
    {
        $this->assertSame(-11, Kernel32Interface::STD_OUTPUT_HANDLE);
    }

    public function testStdErrorHandleConstantIsNegativeTwelve(): void
    {
        $this->assertSame(-12, Kernel32Interface::STD_ERROR_HANDLE);
    }

    public function testWaitTimeoutConstant(): void
    {
        $this->assertSame(0x00000102, Kernel32Interface::WAIT_TIMEOUT);
    }

    public function testWaitObjectZeroConstant(): void
    {
        $this->assertSame(0x00000000, Kernel32Interface::WAIT_OBJECT_0);
    }

    public function testKeyEventConstant(): void
    {
        $this->assertSame(0x0001, Kernel32Interface::KEY_EVENT);
    }

    public function testWindowBufferSizeEventConstant(): void
    {
        $this->assertSame(0x0004, Kernel32Interface::WINDOW_BUFFER_SIZE_EVENT);
    }

    public function testOpenExistingConstant(): void
    {
        $this->assertSame(3, Kernel32Interface::OPEN_EXISTING);
    }

    public function testGenericReadConstant(): void
    {
        $this->assertSame(0x80000000, Kernel32Interface::GENERIC_READ);
    }

    public function testGenericWriteConstant(): void
    {
        $this->assertSame(0x40000000, Kernel32Interface::GENERIC_WRITE);
    }

    public function testFileShareReadConstant(): void
    {
        $this->assertSame(0x00000001, Kernel32Interface::FILE_SHARE_READ);
    }

    public function testFileShareWriteConstant(): void
    {
        $this->assertSame(0x00000002, Kernel32Interface::FILE_SHARE_WRITE);
    }

    public function testEnableProcessedInputConstant(): void
    {
        $this->assertSame(0x0001, Kernel32Interface::ENABLE_PROCESSED_INPUT);
    }

    public function testEnableLineInputConstant(): void
    {
        $this->assertSame(0x0002, Kernel32Interface::ENABLE_LINE_INPUT);
    }

    public function testEnableEchoInputConstant(): void
    {
        $this->assertSame(0x0004, Kernel32Interface::ENABLE_ECHO_INPUT);
    }

    public function testEnableWindowInputConstant(): void
    {
        $this->assertSame(0x0008, Kernel32Interface::ENABLE_WINDOW_INPUT);
    }

    public function testEnableVirtualTerminalInputConstant(): void
    {
        $this->assertSame(0x0200, Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_INPUT);
    }

    public function testEnableProcessedOutputConstant(): void
    {
        $this->assertSame(0x0001, Kernel32Interface::ENABLE_PROCESSED_OUTPUT);
    }

    public function testEnableVirtualTerminalProcessingConstant(): void
    {
        $this->assertSame(0x0004, Kernel32Interface::ENABLE_VIRTUAL_TERMINAL_PROCESSING);
    }

    public function testDisableNewlineAutoReturnConstant(): void
    {
        $this->assertSame(0x0008, Kernel32Interface::DISABLE_NEWLINE_AUTO_RETURN);
    }

    public function testErrorInvalidHandleConstant(): void
    {
        $this->assertSame(6, Kernel32Interface::ERROR_INVALID_HANDLE);
    }

    public function testCtrlCEventConstant(): void
    {
        $this->assertSame(0, Kernel32Interface::CTRL_C_EVENT);
    }

    public function testCtrlBreakEventConstant(): void
    {
        $this->assertSame(1, Kernel32Interface::CTRL_BREAK_EVENT);
    }

    public function testCtrlCloseEventConstant(): void
    {
        $this->assertSame(2, Kernel32Interface::CTRL_CLOSE_EVENT);
    }

    public function testCtrlLogoffEventConstant(): void
    {
        $this->assertSame(5, Kernel32Interface::CTRL_LOGOFF_EVENT);
    }

    public function testCtrlShutdownEventConstant(): void
    {
        $this->assertSame(6, Kernel32Interface::CTRL_SHUTDOWN_EVENT);
    }
}
