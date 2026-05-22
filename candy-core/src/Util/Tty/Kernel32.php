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
 * The {@see setConsoleCtrlHandler()} callback runs on a **separate OS
 * thread**.  The Zend VM is **not reentrant**.  The handler MUST:
 *
 * - Write only to process-shared memory (e.g. a single `\FFI\CData(uint32_t)`
 *   allocated via `$this->ffi()->new()`, NOT PHP strings/arrays/zvals).
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
 * mode and subsequent non-Unicode output breaks.  The {@see WindowsBackend}
 * registers a defensive shutdown function to handle this.
 *
 * @see https://docs.microsoft.com/en-us/windows/console/setconsolecp
 */
final class Kernel32 implements Kernel32Interface
{
    /** Lazily-loaded FFI runtime. */
    private static ?\FFI $ffi = null;

    /**
     * Return a new Kernel32 instance.
     *
     * FFI is shared per-process via a static variable; each new instance
     * still references the same cached FFI object.
     */
    public static function self(): Kernel32Interface
    {
        return new self();
    }

    /**
     * Return the cached FFI instance, loading the kernel32 cdef on first call.
     *
     * The FFI definition is deferred to first call to avoid loading the
     * .h headers at startup.  The definition is cached per-process.
     */
    public function ffi(): \FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }

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

DWORD WaitForSingleObject(HANDLE hHandle, DWORD dwMilliseconds);

BOOL PeekConsoleInputW(HANDLE hConsoleInput, void *lpBuffer,
                       DWORD nLength, DWORD *lpNumberOfEventsRead);
BOOL ReadConsoleInputW(HANDLE hConsoleInput, void *lpBuffer,
                       DWORD nLength, DWORD *lpNumberOfEventsRead);

typedef struct _INPUT_RECORD {
    unsigned short EventType;
    unsigned char  EventData[6];
} INPUT_RECORD;

enum {
    KEY_EVENT = 0x0001,
    WINDOW_BUFFER_SIZE_EVENT = 0x0004,
};

enum {
    WAIT_TIMEOUT   = 0x00000102,
    WAIT_OBJECT_0  = 0x00000000,
};
CPROTO
            ,
            'kernel32.dll'
        );

        return self::$ffi;
    }

    // ─── Handle accessors ────────────────────────────────────────────────────

    /**
     * @return int HANDLE as an integer (pointer address cast to int)
     */
    public function getStdHandle(int $nStdHandle): int
    {
        $stdHandle = $this->ffi()->new('unsigned long');
        \FFI::memcpy($stdHandle, [$nStdHandle], 4);
        $h = $this->ffi()->GetStdHandle($stdHandle);

        return (int) \FFI::cast('intptr_t', $h)->cdata;
    }

    // ─── Console input reading ───────────────────────────────────────────────

    /**
     * Wait for a handle to become signalled (or timeout).
     *
     * Used to poll CONIN$ without busy-waiting.  A timeout of 0 returns
     * immediately if the handle is not signalled.
     *
     * @param int $h handle to wait on
     * @param int $timeoutMs milliseconds to wait (0 = return immediately)
     * @return int WAIT_TIMEOUT (0x102) if not signalled within timeout,
     *             WAIT_OBJECT_0 (0x0000) if signalled, or error code
     */
    public function waitForSingleObject(int $h, int $timeoutMs): int
    {
        $handle = \FFI::cast('void*', \FFI::new('intptr_t'));
        $handle->cdata = $h;

        return (int) $this->ffi()->WaitForSingleObject($handle, $timeoutMs);
    }

    /**
     * Peek at console input events without consuming them.
     *
     * @param int $h CONIN$ handle from {@see openTty()} or {@see stdIn()}
     * @param list<array{type:int, data:nonempty-string}> $records buffer to fill
     * @param int $recordSize max number of records to read
     * @return int|false number of records peeked, or false on error
     */
    public function peekConsoleInput(int $h, array &$records, int $recordSize = 1): int|false
    {
        $handle = \FFI::cast('void*', \FFI::new('intptr_t'));
        $handle->cdata = $h;

        $buf = $this->ffi()->new("unsigned char[8][{$recordSize}]");
        $count = $this->ffi()->new('unsigned long');

        $ok = $this->ffi()->PeekConsoleInputW(
            $handle,
            \FFI::addr($buf),
            $recordSize,
            \FFI::addr($count),
        );

        if (!$ok) {
            return false;
        }

        $records = [];
        for ($i = 0; $i < $count->cdata; $i++) {
            $type = \FFI::cast('unsigned short*', \FFI::ptr($buf, $i))
                ->cdata;
            $records[] = ['type' => $type, 'index' => $i];
        }

        return (int) $count->cdata;
    }

    /**
     * Read and consume console input events.
     *
     * @param int $h CONIN$ handle from {@see openTty()} or {@see stdIn()}
     * @param int $recordSize max number of records to read
     * @return list<array{type:int, dataIndex:int}>|false records read, or false on error
     */
    public function readConsoleInput(int $h, int $recordSize = 1): array|false
    {
        $handle = \FFI::cast('void*', \FFI::new('intptr_t'));
        $handle->cdata = $h;

        $buf   = $this->ffi()->new("unsigned char[8][{$recordSize}]");
        $count = $this->ffi()->new('unsigned long');

        $ok = $this->ffi()->ReadConsoleInputW(
            $handle,
            \FFI::addr($buf),
            $recordSize,
            \FFI::addr($count),
        );

        if (!$ok) {
            return false;
        }

        $records = [];
        for ($i = 0; $i < $count->cdata; $i++) {
            $type = \FFI::cast('unsigned short*', \FFI::ptr($buf, $i))
                ->cdata;
            $records[] = ['type' => $type, 'index' => $i];
        }

        return $records;
    }

    public function stdIn(): int
    {
        return $this->getStdHandle(self::STD_INPUT_HANDLE);
    }

    public function stdOut(): int
    {
        return $this->getStdHandle(self::STD_OUTPUT_HANDLE);
    }

    public function stdErr(): int
    {
        return $this->getStdHandle(self::STD_ERROR_HANDLE);
    }

    // ─── Console mode ────────────────────────────────────────────────────────

    public function getConsoleMode(int $h): int|false
    {
        $mode = $this->ffi()->new('unsigned long');
        $ok   = $this->ffi()->GetConsoleMode(
            \FFI::cast('void*', \FFI::new('intptr_t')),
            \FFI::addr($mode),
        );

        return $ok ? $mode->cdata : false;
    }

    public function setConsoleMode(int $h, int $dwMode): bool
    {
        $handle = \FFI::cast('void*', \FFI::new('intptr_t'));
        $handle->cdata = $h;

        return (bool) $this->ffi()->SetConsoleMode($handle, $dwMode);
    }

    // ─── Codepage ────────────────────────────────────────────────────────────

    public function setConsoleCP(int $wCodePageID): bool
    {
        return (bool) $this->ffi()->SetConsoleCP($wCodePageID);
    }

    public function setConsoleOutputCP(int $wCodePageID): bool
    {
        return (bool) $this->ffi()->SetConsoleOutputCP($wCodePageID);
    }

    public function getConsoleCP(): int
    {
        return $this->ffi()->GetConsoleCP();
    }

    public function getConsoleOutputCP(): int
    {
        return $this->ffi()->GetConsoleOutputCP();
    }

    // ─── Console screen buffer info ──────────────────────────────────────────

    /**
     * @param int $h handle value from {@see stdOut()}
     * @return array{cols:int, rows:int}|null
     */
    public function getConsoleScreenBufferInfo(int $h): ?array
    {
        $info = $this->ffi()->new('unsigned char[24]');
        $ok   = $this->ffi()->GetConsoleScreenBufferInfo(
            \FFI::cast('void*', \FFI::new('intptr_t')),
            \FFI::addr($info),
        );

        if (!$ok) {
            return null;
        }

        // Extract window bounds (all values are signed shorts; cast to int).
        $left   = (int) \FFI::cast('short*', \FFI::ptr($info, 4))->cdata;
        $right  = (int) \FFI::cast('short*', \FFI::ptr($info, 8))->cdata;
        $top    = (int) \FFI::cast('short*', \FFI::ptr($info, 6))->cdata;
        $bottom = (int) \FFI::cast('short*', \FFI::ptr($info, 10))->cdata;

        // Handle -1 (unset by ConHost) with sensible floor defaults.
        $left   = $left   < 0 ? 0 : $left;
        $right  = $right  < 0 ? 79 : $right;
        $top    = $top    < 0 ? 0 : $top;
        $bottom = $bottom < 0 ? 23 : $bottom;

        return [
            'cols' => $right - $left + 1,
            'rows' => $bottom - $top + 1,
        ];
    }

    // ─── File open ───────────────────────────────────────────────────────────

    public function createFile(
        string $name,
        int $dwDesiredAccess,
        int $dwShareMode,
        int $dwCreationDisposition = self::OPEN_EXISTING,
    ): int|false {
        $nameW = $this->toWideString($name);

        $h = $this->ffi()->CreateFileW(
            $nameW,
            $dwDesiredAccess,
            $dwShareMode,
            \FFI::NULL,
            $dwCreationDisposition,
            0,
            \FFI::NULL,
        );

        $ptrVal = (int) \FFI::cast('intptr_t', $h)->cdata;

        return ($ptrVal === -1 || $ptrVal === 0) ? false : $ptrVal;
    }

    public function closeHandle(int $h): bool
    {
        $handle = \FFI::cast('void*', \FFI::new('intptr_t'));
        $handle->cdata = $h;

        return (bool) $this->ffi()->CloseHandle($handle);
    }

    public function getLastError(): int
    {
        return (int) $this->ffi()->GetLastError();
    }

    // ─── Ctrl handler ────────────────────────────────────────────────────────

    /**
     * Register a Ctrl-handler callback with the process.
     *
     * The `$handler` MUST be reentrant-safe and write only to
     * process-shared memory.  See the class docblock and caveat 1 in
     * the `x/windows.md` plan for thread-safety requirements.
     *
     * **Requires PHP FFI with `FFI::dynamicFunction`** (PHP 8.4+ FFI).
     * On builds without it, this method returns `false` and emits a
     * warning.  The interrupt flag can still be set via
     * {@see InterruptFlags} for testing.
     *
     * @param \Closure(int $dwCtrlEvent):bool $handler reentrant-safe only
     * @return bool true on success, false if registration failed or dynamicFunction unavailable
     */
    public function setConsoleCtrlHandler(\Closure $handler, bool $add = true): bool
    {
        if (!\method_exists(\FFI::class, 'dynamicFunction')) {
            @trigger_error(
                'Kernel32::setConsoleCtrlHandler requires PHP FFI with '
                    . 'FFI::dynamicFunction (PHP 8.4+ FFI). Interrupt handling '
                    . 'is disabled on this build.',
                \E_USER_WARNING,
            );

            return false;
        }

        $cHandler = \FFI::cast(
            'void (*)(unsigned long)',
            \FFI::dynamicFunction($handler),
        );

        return (bool) $this->ffi()->SetConsoleCtrlHandler(
            \FFI::cast('void*', $cHandler),
            $add ? 1 : 0,
        );
    }

    // ─── Wide-string helper ──────────────────────────────────────────────────

    /**
     * Convert a PHP string to a null-terminated UTF-16LE wchar_t array.
     *
     * The caller MUST free the returned pointer via {@see FFI::free()}
     * when no longer needed.
     */
    public function toWideString(string $str): \FFI\CData
    {
        $len = \mb_strlen($str, 'UTF-8');
        $w   = $this->lib()->new("unsigned short[{$len} + 1]");

        for ($i = 0; $i < $len; $i++) {
            $w[$i] = \mb_ord(\mb_substr($str, $i, 1, 'UTF-8'), 'UTF-8');
        }
        $w[$len] = 0;

        return $w;
    }

    /**
     * Alias of {@see ffi()} for internal use.
     */
    private function lib(): \FFI
    {
        return $this->ffi();
    }
}
