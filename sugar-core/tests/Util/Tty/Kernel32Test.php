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
 * non-Windows platforms. The methods that need the real kernel32.dll run
 * for real on Windows and skip by name elsewhere.
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
        self::requireWindows();

        $this->assertIsInt(self::live()->stdIn());
    }

    public function testStdOutRequiresWindows(): void
    {
        self::requireWindows();

        // INVALID_HANDLE_VALUE (-1) is the documented failure; any other int
        // (0 included, for a process with no stdout) is a real answer.
        $this->assertNotSame(-1, self::live()->stdOut());
    }

    public function testStdErrRequiresWindows(): void
    {
        self::requireWindows();

        $this->assertNotSame(-1, self::live()->stdErr());
    }

    public function testGetStdHandleRequiresWindows(): void
    {
        self::requireWindows();

        // Before the long long return, reading the returned pointer back
        // through FFI::cast('intptr_t') SEGFAULTED the process (exit 139):
        // the CI sugar-core Windows cell died here with no summary.
        $k = self::live();
        $this->assertSame($k->stdOut(), $k->getStdHandle(Kernel32Interface::STD_OUTPUT_HANDLE));
    }

    public function testFfiRequiresWindows(): void
    {
        self::requireWindows();

        $this->assertInstanceOf(\FFI::class, self::live()->ffi());
        // The real INPUT_RECORD is 20 bytes; the old 8-byte declaration let
        // ReadConsoleInputW overflow its buffer.
        $this->assertSame(20, \FFI::sizeof(self::live()->ffi()->new('INPUT_RECORD')));
    }

    public function testGetConsoleCPRequiresWindows(): void
    {
        self::requireWindows();

        $this->assertIsInt(self::live()->getConsoleCP());
    }

    public function testGetConsoleOutputCPRequiresWindows(): void
    {
        self::requireWindows();

        $this->assertIsInt(self::live()->getConsoleOutputCP());
    }

    public function testSetConsoleCPRequiresWindows(): void
    {
        self::requireWindows();

        $k = self::live();
        $cp = $k->getConsoleCP();
        if ($cp === 0) {
            $this->markTestSkipped('no console attached to this process, so there is no input code page to set');
        }
        $this->assertTrue($k->setConsoleCP($cp));
    }

    public function testSetConsoleOutputCPRequiresWindows(): void
    {
        self::requireWindows();

        $k = self::live();
        $cp = $k->getConsoleOutputCP();
        if ($cp === 0) {
            $this->markTestSkipped('no console attached to this process, so there is no output code page to set');
        }
        $this->assertTrue($k->setConsoleOutputCP($cp));
    }

    public function testGetConsoleModeRequiresWindows(): void
    {
        self::requireWindows();

        $k = self::live();
        $h = $k->createFile('NUL', Kernel32Interface::GENERIC_READ, Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE);
        $this->assertIsInt($h);
        try {
            // NUL is not a console: the honest answer is false, not a crash.
            $this->assertFalse($k->getConsoleMode($h));
        } finally {
            $k->closeHandle($h);
        }
    }

    /**
     * A-10 + C5 (structural pin): the native GetConsoleMode call receives the
     * $h parameter value as an int64 argument, never a freshly-zeroed or
     * cast-to-void* pointer CData.
     *
     * WHY A SOURCE SHAPE AND NOT A BEHAVIOR: on Linux ffi() throws before any
     * native call exists, so the defect this guards (the body historically cast
     * a zero-initialised intptr_t inline — a NULL handle — which made every
     * Windows call return false and WindowsBackend's raw-mode saves capture
     * mode 0 via `?? 0`) can only be pinned from the declaration itself.
     * WindowsConsole::enable() cannot honour its save/restore contract on real
     * Windows while this returns false unconditionally, so the pin ships with
     * A-10 rather than as a follow-up.  C5 adds the regression guard against
     * the opposite historical shape: the cast-to-void* materialization that
     * A-10 introduced faults natively on Windows (CI: silent mid-suite death).
     */
    public function testGetConsoleModeBodiesPassTheHandleArgumentToTheNativeCall(): void
    {
        $src     = (string) file_get_contents((new \ReflectionClass(Kernel32::class))->getFileName());
        // WHY: a Windows checkout (core.autocrlf=true rewrites the working
        // tree to CRLF) puts "\r" before the "\n" ending each line, so the
        // LF-anchored "\n    }\n" needle below could never match and the
        // pin would fail for the wrong reason. Normalising keeps this
        // assertion about the body's shape honest on every platform.
        $src     = str_replace("\r\n", "\n", $src);
        $start   = strpos($src, 'public function getConsoleMode(');
        self::assertNotFalse($start, 'Kernel32 no longer declares getConsoleMode() — update this pin, do not delete it.');
        $end     = strpos($src, "\n    }\n", $start);
        self::assertNotFalse($end, 'Could not find the end of getConsoleMode().');
        $body    = substr($src, $start, $end - $start);

        self::assertStringContainsString(
            '$handle = $h;',
            $body,
            'getConsoleMode() must pass the $h parameter value straight through to the native call (C5: the cdef declares the handle parameter long long, so the plain int is the argument).',
        );
        self::assertStringContainsString(
            'GetConsoleMode($handle',
            $body,
            'getConsoleMode() must pass the handle argument to the native call.',
        );
        self::assertStringNotContainsString(
            "cast('void*'",
            $body,
            'C5 REGRESSION GUARD: the body must never rebuild the cast-to-void* materialization — that argument shape faults inside libffi on Windows (CI: silent mid-suite death) and its ->cdata rejects int writes on PHP 8.3.',
        );
    }

    public function testSetConsoleModeRequiresWindows(): void
    {
        self::requireWindows();

        $k = self::live();
        $h = $k->createFile('NUL', Kernel32Interface::GENERIC_READ, Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE);
        $this->assertIsInt($h);
        try {
            $this->assertFalse($k->setConsoleMode($h, 0), 'NUL is not a console, so SetConsoleMode must refuse it');
        } finally {
            $k->closeHandle($h);
        }
    }

    public function testGetConsoleScreenBufferInfoRequiresWindows(): void
    {
        self::requireWindows();

        $k = self::live();
        $info = $k->getConsoleScreenBufferInfo($k->stdOut());
        // null without a console (a CI runner's piped stdout), a positive
        // geometry with one: either way, never a crash.
        if ($info === null) {
            $this->assertNull($info);

            return;
        }
        $this->assertGreaterThan(0, $info['cols']);
        $this->assertGreaterThan(0, $info['rows']);
    }

    public function testCreateFileRequiresWindows(): void
    {
        self::requireWindows();

        // \FFI::NULL is not a constant on any PHP, so this method used to
        // throw on every call and openTty: true never worked on Windows.
        $k = self::live();
        $h = $k->createFile('NUL', Kernel32Interface::GENERIC_READ, Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE);
        $this->assertIsInt($h);
        $this->assertTrue($k->closeHandle($h));
    }

    public function testCloseHandleRequiresWindows(): void
    {
        self::requireWindows();

        $this->assertFalse(self::live()->closeHandle(0), 'a NULL handle is not closable');
    }

    public function testGetLastErrorRequiresWindows(): void
    {
        self::requireWindows();

        $k = self::live();
        $k->closeHandle(0);
        $this->assertSame(6, $k->getLastError(), 'CloseHandle(NULL) leaves ERROR_INVALID_HANDLE');
    }

    public function testWaitForSingleObjectRequiresWindows(): void
    {
        self::requireWindows();

        $this->assertSame(0xFFFFFFFF, self::live()->waitForSingleObject(0, 0), 'a NULL handle is WAIT_FAILED');
    }

    public function testPeekConsoleInputRequiresWindows(): void
    {
        self::requireWindows();

        $records = [];
        $k = self::live();
        $h = $k->createFile('NUL', Kernel32Interface::GENERIC_READ, Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE);
        $this->assertIsInt($h);
        try {
            // Not a console input buffer: false, never the \FFI::ptr() fatal.
            $this->assertFalse($k->peekConsoleInput($h, $records, 4));
        } finally {
            $k->closeHandle($h);
        }
    }

    public function testReadConsoleInputRequiresWindows(): void
    {
        self::requireWindows();

        // ReadConsoleInputW blocks until an event arrives; the binding must
        // never wait. NUL is always signalled, so the read runs and is
        // refused (not a console) rather than blocking.
        $k = self::live();
        $h = $k->createFile('NUL', Kernel32Interface::GENERIC_READ, Kernel32Interface::FILE_SHARE_READ | Kernel32Interface::FILE_SHARE_WRITE);
        $this->assertIsInt($h);
        try {
            $started = \microtime(true);
            $this->assertFalse($k->readConsoleInput($h, 4));
            $this->assertLessThan(1.0, \microtime(true) - $started);
        } finally {
            $k->closeHandle($h);
        }
    }

    public function testSetConsoleCtrlHandlerRequiresWindows(): void
    {
        self::requireWindows();

        // FFI::dynamicFunction exists on no PHP release; the method is an
        // honest refusal, not a registration.
        $this->assertFalse(@self::live()->setConsoleCtrlHandler(static fn (int $e): bool => true));
    }

    public function testToWideStringRequiresWindows(): void
    {
        self::requireWindows();

        // Beyond the BMP a character is a surrogate PAIR; the old per-codepoint
        // store truncated it into one 16-bit unit.
        $w = self::live()->toWideString("a\u{1F600}");
        $this->assertSame([0x61, 0xD83D, 0xDE00, 0], [$w[0], $w[1], $w[2], $w[3]]);
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

    /**
     * Skip by name off Windows. On Windows these run for real: they were
     * skipped unconditionally before, which is how a segfaulting
     * getStdHandle(), a CreateFile that always threw and an overflowing
     * INPUT_RECORD buffer all shipped unseen.
     */
    private static function requireWindows(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows' || !\extension_loaded('ffi')) {
            self::markTestSkipped('Requires Windows kernel32.dll through ext-ffi');
        }
    }

    private static function live(): Kernel32
    {
        return new Kernel32();
    }
}
