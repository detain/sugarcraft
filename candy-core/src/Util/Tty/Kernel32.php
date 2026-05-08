<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Thin FFI bindings to a subset of kernel32.dll needed for Windows
 * console TTY support.
 *
 * Every public method is a direct translation of the underlying Windows
 * API.  Callers must understand the semantics of each call; this class
 * provides no additional logic or error-suppression beyond what is
 * noted in method docblocks.
 *
 * ## Thread-safety note
 *
 * The {@see registerCtrlHandler()} callback passed to `SetConsoleCtrlHandler`
 * runs on a **separate OS thread**.  The Zend VM is **not reentrant**.
 * The handler MUST:
 *
 * - Write only to process-shared memory (e.g. a single `\FFI\CData(uint32_t)`
 *   allocated via `Ffi::new()`, NOT PHP strings/arrays/zvals).
 * - Return immediately after setting the flag.
 * - Never call any Zend memory allocator or PHP function.
 *
 * Failure to follow this rule will corrupt the PHP heap.  See caveats
 * 1 and 6 in the `x/windows.md` plan.
 *
 * ## Codepage note
 *
 * Restoring the codepage on exit is critical.  If PHP crashes after
 * `SetConsoleCP(65001)` without restoring it, cmd.exe is left in UTF-8
 * mode and subsequent non-Unicode output breaks.  Register a shutdown
 * function defensively.
 *
 * @see https://docs.microsoft.com/en-us/windows/console/setconsolecp
 */
final class Kernel32
{
    // ─── Standard handle IDs ────────────────────────────────────────────────

    public const STD_INPUT_HANDLE  = -10;
    public const STD_OUTPUT_HANDLE = -11;
    public const STD_ERROR_HANDLE  = -12;

    // ─── Console mode flag values ───────────────────────────────────────────

    /** @deprecated Use INPUT_PROCESSED instead */
    public const ENABLE_PROCESSED_INPUT = 0x0001;
    public const ENABLE_LINE_INPUT      = 0x0002;
    public const ENABLE_ECHO_INPUT      = 0x0004;
    public const ENABLE_WINDOW_INPUT    = 0x0008;
    public const ENABLE_VIRTUAL_TERMINAL_INPUT = 0x0200;

    public const ENABLE_PROCESSED_OUTPUT      = 0x0001;
    public const ENABLE_VIRTUAL_TERMINAL_PROCESSING = 0x0004;
    public const DISABLE_NEWLINE_AUTO_RETURN  = 0x0008;

    // ─── Generic file access constants ──────────────────────────────────────

    public const GENERIC_READ  = 0x80000000;
    public const GENERIC_WRITE = 0x40000000;
    public const FILE_SHARE_READ       = 0x00000001;
    public const FILE_SHARE_WRITE      = 0x00000002;
    public const OPEN_EXISTING = 3;

    // ─── Return codes ────────────────────────────────────────────────────────

    public const ERROR_INVALID_HANDLE = 6;

    // ─── Ctrl event types ────────────────────────────────────────────────────

    public const CTRL_C_EVENT     = 0;
    public const CTRL_BREAK_EVENT = 1;
    public const CTRL_CLOSE_EVENT = 2;

    /** Lazily-loaded FFI runtime. */
    private static ?\FFI $ffi = null;

    /**
     * Load (or return cached) FFI bindings to kernel32.dll.
     *
     * FFI definitions are deferred to first call to avoid loading the
     * .h headers at startup.  The definition is cached per-process.
     */
    public static function lib(): \FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }

        // Pass the standard handle IDs as PHP integers; the FFI layer
        // handles the implicit widening to DWORD where needed.

        self::$ffi = \FFI::cdef(
            <<<'CPROTO'
typedef void* HANDLE;
typedef unsigned long DWORD;
typedef unsigned int  UINT;
typedef int           BOOL;

HANDLE GetStdHandle(DWORD nStdHandle);

BOOL GetConsoleMode(HANDLE h, DWORD *lpMode);
BOOL SetConsoleMode(HANDLE h, DWORD dwMode);

BOOL GetConsoleScreenBufferInfo(HANDLE h, void *lpInfo);

BOOL SetConsoleCP(UINT wCodePageID);
BOOL SetConsoleOutputCP(UINT wCodePageID);
UINT GetConsoleCP(void);
UINT GetConsoleOutputCP(void);

BOOL SetConsoleCtrlHandler(void *HandlerRoutine, BOOL Add);

HANDLE CreateFileW(const wchar_t *lpFileName, DWORD dwDesiredAccess,
                   DWORD dwShareMode, void *lpSecurityAttributes,
                   DWORD dwCreationDisposition, DWORD dwFlagsAndAttributes,
                   HANDLE hTemplateFile);

BOOL CloseHandle(HANDLE h);
DWORD GetLastError(void);
CPROTO
            ,
            'kernel32.dll'
        );

        return self::$ffi;
    }

    // ─── Handle accessors ────────────────────────────────────────────────────

    public static function getStdHandle(int $nStdHandle): \FFI\CData
    {
        $stdHandle = self::lib()->new('unsigned long');
        \FFI::memcpy($stdHandle, [$nStdHandle], 4);
        $h = self::lib()->GetStdHandle($stdHandle);

        // Cast to pointer-sized int for comparison.
        return $h;
    }

    public static function stdIn(): \FFI\CData
    {
        return self::getStdHandle(self::STD_INPUT_HANDLE);
    }

    public static function stdOut(): \FFI\CData
    {
        return self::getStdHandle(self::STD_OUTPUT_HANDLE);
    }

    public static function stdErr(): \FFI\CData
    {
        return self::getStdHandle(self::STD_ERROR_HANDLE);
    }

    // ─── Console mode ────────────────────────────────────────────────────────

    public static function getConsoleMode(\FFI\CData $h): int|false
    {
        $mode = self::lib()->new('unsigned long');
        $ok   = self::lib()->GetConsoleMode($h, \FFI::addr($mode));

        return $ok ? $mode->cdata : false;
    }

    public static function setConsoleMode(\FFI\CData $h, int $dwMode): bool
    {
        return (bool) self::lib()->SetConsoleMode($h, $dwMode);
    }

    // ─── Codepage ────────────────────────────────────────────────────────────

    public static function setConsoleCP(int $wCodePageID): bool
    {
        return (bool) self::lib()->SetConsoleCP($wCodePageID);
    }

    public static function setConsoleOutputCP(int $wCodePageID): bool
    {
        return (bool) self::lib()->SetConsoleOutputCP($wCodePageID);
    }

    public static function getConsoleCP(): int
    {
        return self::lib()->GetConsoleCP();
    }

    public static function getConsoleOutputCP(): int
    {
        return self::lib()->GetConsoleOutputCP();
    }

    // ─── Console screen buffer info ──────────────────────────────────────────

    /**
     * Retrieve the console screen buffer dimensions and cursor position.
     *
     * The layout struct (24 bytes on x64) is:
     *   short(2)  CursorPosition.X
     *   short(2)  CursorPosition.Y
     *   short(2)  srWindow.Left
     *   short(2)  srWindow.Top
     *   short(2)  srWindow.Right
     *   short(2)  srWindow.Bottom
     *   short(2)  dwSize.X
     *   short(2)  dwSize.Y
     *   short(2)  dwMaximumWindowSize.X
     *   short(2)  dwMaximumWindowSize.Y
     *   word(2)   wAttributes
     *   short(2)  srWindow.Right  (again — actually dmSize from CONSOLE_SCREEN_BUFFER_INFO, 20 bytes)
     *   short(2)  srWindow.Bottom (again — actually dmSize from CONSOLE_SCREEN_BUFFER_INFO)
     *   // sizeof(CONSOLE_SCREEN_BUFFER_INFO) == 20 on x86, 24 on x64
     *   // We only care about srWindow so we read the 4 window bounds at offsets 8-14.
     *
     * @return array{cols:int, rows:int}|null
     */
    public static function getConsoleScreenBufferInfo(\FFI\CData $h): ?array
    {
        // Allocate enough for the full struct (24 bytes on x64).
        $info = self::lib()->new('unsigned char[24]');
        $ok   = self::lib()->GetConsoleScreenBufferInfo($h, \FFI::addr($info));

        if (!$ok) {
            return null;
        }

        // srWindow: Left=offset 4, Top=6, Right=8, Bottom=10  (short = 2 bytes each)
        // Unpack as signed shorts; window coords are always >= 0 so casting to int is safe.
        $left   = \FFI::memchr($info, 4, 2) !== null
            ? \FFI::cast('short*', \FFI::ptr($info, 4))->cdata : 0;
        $right  = \FFI::cast('short*', \FFI::ptr($info, 8))->cdata;
        $top    = \FFI::cast('short*', \FFI::ptr($info, 6))->cdata;
        $bottom = \FFI::cast('short*', \FFI::ptr($info, 10))->cdata;

        // Handle possible -1 values (not yet set by ConHost).
        $left   = $left   < 0 ? 0   : (int) $left;
        $right  = $right  < 0 ? 79  : (int) $right;
        $top    = $top    < 0 ? 0   : (int) $top;
        $bottom = $bottom < 0 ? 23  : (int) $bottom;

        return [
            'cols' => (int) ($right - $left + 1),
            'rows' => (int) ($bottom - $top + 1),
        ];
    }

    // ─── File open ───────────────────────────────────────────────────────────

    /**
     * Open a Windows device such as `CONIN$` or `CONOUT$`.
     *
     * @param resource|\FFI\CData|\Stringable|non-empty-string $name
     */
    public static function createFile(
        string $name,
        int $dwDesiredAccess,
        int $dwShareMode,
        int $dwCreationDisposition = self::OPEN_EXISTING,
    ): \FFI\CData|false {
        // Convert the name to a wide (UTF-16LE) char[] via a PHP string.
        $nameW = self::toWideString($name);

        $h = self::lib()->CreateFileW(
            $nameW,
            $dwDesiredAccess,
            $dwShareMode,
            \FFI::NULL,
            $dwCreationDisposition,
            0,
            \FFI::NULL,
        );

        // Cast the HANDLE to a pointer-sized value for comparison.
        $ptrVal = \FFI::cast('intptr_t', $h)->cdata;

        return $ptrVal === -1 || $ptrVal === 0 ? false : $h;
    }

    public static function closeHandle(\FFI\CData $h): bool
    {
        return (bool) self::lib()->CloseHandle($h);
    }

    public static function getLastError(): int
    {
        return (int) self::lib()->GetLastError();
    }

    // ─── Ctrl handler ────────────────────────────────────────────────────────

    /**
     * Register a Ctrl-handler callback with the process.
     *
     * The `$handler` MUST be a reentrant-safe C closure kept alive for
     * the duration of the process (assign it to a static or class
     * property).  See the class docblock for thread-safety requirements.
     *
     * @param \Closure(int $dwCtrlEvent):bool $handler
     * @return bool true when registration succeeded
     */
    public static function setConsoleCtrlHandler(\Closure $handler, bool $add = true): bool
    {
        // Cast the PHP closure to a plain C function pointer.
        // The cast result must be kept alive by the caller.
        $cHandler = \FFI::cast('void (*)(unsigned long)', \FFI::dynamicFunction($handler));

        return (bool) self::lib()->SetConsoleCtrlHandler(
            \FFI::cast('void*', $cHandler),
            $add ? 1 : 0,
        );
    }

    // ─── Wide-string helper ──────────────────────────────────────────────────

    /**
     * Convert a PHP string to a UTF-16LE C wchar_t array suitable for
     * passing to Windows API Wide functions.
     *
     * The returned \FFI\CData pointer is allocated with FFI::new() and
     * MUST be freed by the caller when no longer needed.
     *
     * @param resource|\FFI\CData|\Stringable|non-empty-string $str
     * @return \FFI\CData pointer to wchar_t[($len+1)]
     */
    public static function toWideString(string $str): \FFI\CData
    {
        $len = \mb_strlen($str, 'UTF-8');
        // +1 for null terminator; wchar_t is 2 bytes on Windows.
        $w = self::lib()->new("unsigned short[{$len} + 1]");

        for ($i = 0; $i < $len; $i++) {
            // Encode each code point as UTF-16LE.
            $cp = \mb_ord(\mb_substr($str, $i, 1, 'UTF-8'), 'UTF-8');
            $w[$i] = $cp; // Native byte order is LE on x86/x64 Windows.
        }
        $w[$len] = 0; // null terminator.

        return $w;
    }
}
