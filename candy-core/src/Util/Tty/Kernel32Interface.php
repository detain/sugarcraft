<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Contract for kernel32.dll FFI bindings.
 *
 * This interface enables test doubles on non-Windows platforms.
 * The concrete implementation is {@see Kernel32}.
 *
 * All methods are instance-based so that a test double can be
 * constructed and passed to {@see WindowsBackend} without static
 * state or FFI availability.
 *
 * ## Handle representation
 *
 * Handles are represented as plain PHP `int` values rather than
 * `\FFI\CData`.  On real Windows the handle is a 32/64-bit pointer
 * address; on the test double it is a stable small integer.  This
 * avoids all `void*` CData pitfalls on Linux while preserving full
 * functional equivalence.
 */
interface Kernel32Interface
{
    // ─── Standard handle IDs ────────────────────────────────────────────────

    public const STD_INPUT_HANDLE  = -10;
    public const STD_OUTPUT_HANDLE = -11;
    public const STD_ERROR_HANDLE  = -12;

    // ─── Console mode flag values ───────────────────────────────────────────

    public const ENABLE_PROCESSED_INPUT         = 0x0001;
    public const ENABLE_LINE_INPUT              = 0x0002;
    public const ENABLE_ECHO_INPUT              = 0x0004;
    public const ENABLE_WINDOW_INPUT            = 0x0008;
    public const ENABLE_VIRTUAL_TERMINAL_INPUT  = 0x0200;

    public const ENABLE_PROCESSED_OUTPUT            = 0x0001;
    public const ENABLE_VIRTUAL_TERMINAL_PROCESSING = 0x0004;
    public const DISABLE_NEWLINE_AUTO_RETURN        = 0x0008;

    // ─── File access constants ──────────────────────────────────────────────

    public const GENERIC_READ  = 0x80000000;
    public const GENERIC_WRITE = 0x40000000;
    public const FILE_SHARE_READ  = 0x00000001;
    public const FILE_SHARE_WRITE = 0x00000002;
    public const OPEN_EXISTING = 3;

    public const ERROR_INVALID_HANDLE = 6;

    // ─── Console input event types ────────────────────────────────────────

    public const KEY_EVENT                   = 0x0001;
    public const WINDOW_BUFFER_SIZE_EVENT    = 0x0004;

    // ─── Wait states ──────────────────────────────────────────────────────

    public const WAIT_TIMEOUT  = 0x00000102;
    public const WAIT_OBJECT_0 = 0x00000000;

    // ─── Ctrl event constants ───────────────────────────────────────────────

    public const CTRL_C_EVENT     = 0;
    public const CTRL_BREAK_EVENT = 1;
    public const CTRL_CLOSE_EVENT = 2;
    public const CTRL_LOGOFF_EVENT = 5;
    public const CTRL_SHUTDOWN_EVENT = 6;

    // ─── Handle accessors ──────────────────────────────────────────────────

    /**
     * Returns the raw FFI instance for cases that need it.
     */
    public function ffi(): \FFI;

    /**
     * Return a shared Kernel32 instance bound to the current process.
     *
     * On Windows this returns the real FFI-backed singleton.
     * On non-Windows this throws — callers MUST check the platform first.
     */
    public static function self(): Kernel32Interface;

    /**
     * @return int Handle value (pointer address on real Windows; plain int on test double)
     */
    public function getStdHandle(int $nStdHandle): int;
    public function stdIn(): int;
    public function stdOut(): int;
    public function stdErr(): int;

    // ─── Console mode ───────────────────────────────────────────────────────

    /**
     * @param int $h handle value from {@see stdIn()} / {@see stdOut()}
     * @return int|false false when the handle is not a console or on error
     */
    public function getConsoleMode(int $h): int|false;
    public function setConsoleMode(int $h, int $dwMode): bool;

    // ─── Codepage ───────────────────────────────────────────────────────────

    public function setConsoleCP(int $wCodePageID): bool;
    public function setConsoleOutputCP(int $wCodePageID): bool;
    public function getConsoleCP(): int;
    public function getConsoleOutputCP(): int;

    // ─── Console screen buffer info ─────────────────────────────────────────

    /**
     * @param int $h handle value from {@see stdOut()}
     * @return array{cols:int, rows:int}|null
     */
    public function getConsoleScreenBufferInfo(int $h): ?array;

    // ─── File open ──────────────────────────────────────────────────────────

    /**
     * Open a Windows device (e.g. CONIN$, CONOUT$) for raw access.
     *
     * @param int $h handle value, or false on failure
     */
    public function createFile(
        string $name,
        int $dwDesiredAccess,
        int $dwShareMode,
        int $dwCreationDisposition = self::OPEN_EXISTING,
    ): int|false;

    public function closeHandle(int $h): bool;
    public function getLastError(): int;

    // ─── Ctrl handler ───────────────────────────────────────────────────────

    /**
     * Register a Ctrl-handler callback with the process.
     *
     * On Windows this calls the native `SetConsoleCtrlHandler` via FFI.
     * The `$handler` MUST be reentrant-safe — it runs on a separate OS
     * thread.  See caveat 1 in the `x/windows.md` plan.
     *
     * @param \Closure(int $dwCtrlEvent):bool $handler reentrant-safe only;
     *           write to InterruptFlags, do NOT touch Zend memory
     * @param bool $add true to register, false to unregister
     * @return bool true on success
     *
     * @throws \LogicException if FFI::dynamicFunction is not available
     */
    public function setConsoleCtrlHandler(\Closure $handler, bool $add = true): bool;

    // ─── Console input reading ───────────────────────────────────────────────

    /**
     * Wait for a handle to become ready (or timeout).
     *
     * @param int $h a Win32 handle (e.g. CONIN$)
     * @param int $timeoutMs 0 = return immediately, >0 = wait ms
     * @return int WAIT_OBJECT_0 (0) if ready, WAIT_TIMEOUT (0x102) if not,
     *             or another error code
     */
    public function waitForSingleObject(int $h, int $timeoutMs): int;

    /**
     * Peek at console input events without consuming them.
     *
     * @param int $h CONIN$ handle
     * @param list<array{type:int, dataIndex:int}> &$records filled with peeked records
     * @param int $recordSize max records to peek
     * @return int|false record count, or false on error
     */
    public function peekConsoleInput(int $h, array &$records, int $recordSize = 1): int|false;

    /**
     * Read and consume console input events.
     *
     * @param int $h CONIN$ handle
     * @param int $recordSize max records to read
     * @return list<array{type:int, dataIndex:int}>|false records read, or false
     */
    public function readConsoleInput(int $h, int $recordSize = 1): array|false;

    // ─── Wide-string helper ─────────────────────────────────────────────────

    /**
     * Convert a PHP string to a null-terminated UTF-16LE wchar_t array.
     *
     * The caller MUST free the returned pointer via {@see FFI::free()}
     * when no longer needed.
     *
     * @return \FFI\CData&(wchar_t[])&(wchar_t[$len+1])
     */
    public function toWideString(string $str): \FFI\CData;
}
