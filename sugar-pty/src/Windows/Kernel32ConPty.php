<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\PtyException;

/**
 * FFI binding of the {@see ConPtyApi} surface to `kernel32.dll`.
 *
 * This is the only type in sugar-pty that speaks C, and the only one that is
 * ever loaded on Windows. Everything it hands back is an `int` — see
 * {@see ConPtyApi}'s handle-representation note for why that is the contract and
 * not a convenience.
 *
 * ## Why DWORD is `unsigned int` and not `unsigned long`
 *
 * PHP's FFI parser is the C compiler's own, so it uses the HOST data model. This
 * box is Linux x86-64 (LP64), where `unsigned long` is EIGHT bytes; Windows
 * x64 is LLP64, where `DWORD` is FOUR. A cdef that spells `DWORD` as
 * `unsigned long` therefore has a struct layout that only matches the real
 * Windows one when it actually runs there — and this file's struct layouts are
 * checked by a test that runs on Linux.
 *
 * `unsigned int` is four bytes under both models, so declaring `DWORD` that way
 * makes the layout this file describes identical on every platform, and the
 * size assertions in `tests/Windows/Kernel32ConPtyTest.php` are then genuine
 * evidence about the Windows shape rather than a measurement of the host's.
 * sugar-core's older console binding
 * ({@see \SugarCraft\Core\Util\Tty\Kernel32}) spells it `unsigned long` and is
 * therefore unparseable-off-Windows-by-construction; that is reported as a
 * finding rather than copied forward.
 *
 * ## Why wide parameters are `const void *`
 *
 * PHP's FFI has no `wchar_t` — measured: `FFI::cdef("wchar_t c;")` throws
 * `Undefined C type "wchar_t"` on PHP 8.3.6. Declaring the `…W` string
 * parameters as `const void *` and feeding them the UTF-16LE bytes from
 * {@see WideString::terminate()} sidesteps the missing typedef entirely, and a
 * pointer to a `char[]` buffer is the same thing at the ABI level that a
 * `wchar_t *` is.
 *
 * ## Why the attribute list and the job are ids, not values
 *
 * Both are owned buffers whose lifetime must outlast the call that named them.
 * The list is additionally an ALIGNED buffer: kernelbase lays the attribute
 * blob's internal pointers out on `MEMORY_ALLOCATION_ALIGNMENT` (16 bytes on
 * x64) and fails `UpdateProcThreadAttribute` on a lesser-aligned one WITHOUT
 * calling `SetLastError` — that no-error FALSE is exactly the "win32 error 0"
 * the Windows 8.4 CI leg reported. MSDN's sample builds the blob with
 * `malloc()`, whose CRT contract answers that alignment; `FFI::new()` would
 * route through PHP's emalloc, which only guarantees 8, so the list comes from
 * `HeapAlloc` of the process heap instead — and its memory is not GC-managed
 * at all. What {@see $attributeLists} / {@see $jobs} retain and hand back an
 * int for is the pointer (a wrapper kept so the matching `delete*` frees the
 * heap block exactly once), and it is what lets the test double in
 * `sugar-pty/tests/Windows/FakeConPtyApi.php` implement the same interface
 * without owning a single byte of C memory. The
 * same shape of problem, and the same answer, as sugar-core's
 * `$ctrlHandlerKeepAlive` roster.
 */
final class Kernel32ConPty implements ConPtyApi
{
    /** The DLL every symbol below is resolved from. */
    public const LIBRARY = 'kernel32.dll';

    /**
     * Type declarations only — no function prototypes.
     *
     * Split from {@see FUNCTIONS_CDEF} because a declaration block with a
     * prototype in it cannot be parsed without a library to resolve it against
     * (measured: `FFI::cdef("HANDLE f(void);", null)` throws
     * "Failed resolving C function"). Keeping the types separate is what lets
     * the struct layouts be size-checked on a Linux host, which is the only host
     * this repo's CI has for this file until the Windows pool runs it.
     */
    private const TYPES_CDEF = <<<'CDEF'
typedef unsigned int  DWORD;
typedef unsigned int  UINT;
typedef int           BOOL;
typedef unsigned short WORD;
typedef long long     LONGLONG;
typedef size_t        SIZE_T;
typedef size_t        ULONG_PTR;

typedef struct { short X; short Y; } COORD;

typedef struct _STARTUPINFOW {
    DWORD  cb;
    void  *lpReserved;
    void  *lpDesktop;
    void  *lpTitle;
    DWORD  dwX;
    DWORD  dwY;
    DWORD  dwXSize;
    DWORD  dwYSize;
    DWORD  dwXCountChars;
    DWORD  dwYCountChars;
    DWORD  dwFillAttribute;
    DWORD  dwFlags;
    WORD   wShowWindow;
    WORD   cbReserved2;
    void  *lpReserved2;
    /*
     * Handles are opaque pointer-SIZED values. They are modeled intptr_t
     * here, exactly as every handle parameter in the function declarations
     * below already is: on LLP64 an intptr_t and a void * occupy the
     * identical 8-byte slot, so Microsoft's offsets and sizes are untouched
     * (the layout pins in tests/Windows/Kernel32ConPtyTest.php prove it),
     * and reads become plain scalar conversions — see createProcess().
     */
    intptr_t hStdInput;
    intptr_t hStdOutput;
    intptr_t hStdError;
} STARTUPINFOW;

typedef struct {
    STARTUPINFOW StartupInfo;
    void        *lpAttributeList;
} STARTUPINFOEXW;

typedef struct {
    /* HANDLEs again: pointer-sized, integer-modeled — see STARTUPINFOW. */
    intptr_t hProcess;
    intptr_t hThread;
    DWORD    dwProcessId;
    DWORD    dwThreadId;
} PROCESS_INFORMATION;

typedef struct {
    LONGLONG PerProcessUserTimeLimit;
    LONGLONG PerJobUserTimeLimit;
    DWORD    LimitFlags;
    SIZE_T   MaximumWorkingSetSize;
    SIZE_T   MinimumWorkingSetSize;
    DWORD    ActiveProcessLimit;
    ULONG_PTR Affinity;
    DWORD    PriorityClass;
    DWORD    SchedulingClass;
} JOBOBJECT_BASIC_LIMIT_INFORMATION;

typedef struct {
    LONGLONG ReadOperationCount;
    LONGLONG WriteOperationCount;
    LONGLONG OtherOperationCount;
    LONGLONG ReadTransferCount;
    LONGLONG WriteTransferCount;
    LONGLONG OtherTransferCount;
} IO_COUNTERS;

typedef struct {
    JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
    IO_COUNTERS                       IoInfo;
    SIZE_T                            ProcessMemoryLimit;
    SIZE_T                            JobMemoryLimit;
    SIZE_T                            PeakProcessMemoryUsed;
    SIZE_T                            PeakJobMemoryUsed;
} JOBOBJECT_EXTENDED_LIMIT_INFORMATION;
CDEF;

    /**
     * Function prototypes. `HANDLE`, `HPCON` and `DWORD_PTR` parameters are
     * spelled `intptr_t` — pointer-sized on both Windows data models, so the
     * ABI is unchanged and an int can be passed without a CData round trip.
     */
    private const FUNCTIONS_CDEF = <<<'CDEF'
int      CreatePseudoConsole(COORD size, intptr_t hInput, intptr_t hOutput, DWORD dwFlags, intptr_t *phPC);
int      ResizePseudoConsole(intptr_t hPC, COORD size);
void     ClosePseudoConsole(intptr_t hPC);

BOOL CreatePipe(intptr_t *hReadPipe, intptr_t *hWritePipe, void *lpPipeAttributes, DWORD nSize);
BOOL PeekNamedPipe(intptr_t hNamedPipe, void *lpBuffer, DWORD nBufferSize, DWORD *lpBytesRead, DWORD *lpTotalBytesAvail, DWORD *lpBytesLeftThisMessage);
BOOL ReadFile(intptr_t hFile, void *lpBuffer, DWORD nNumberOfBytesToRead, DWORD *lpNumberOfBytesRead, void *lpOverlapped);
BOOL WriteFile(intptr_t hFile, const void *lpBuffer, DWORD nNumberOfBytesToWrite, DWORD *lpNumberOfBytesWritten, void *lpOverlapped);

BOOL InitializeProcThreadAttributeList(void *lpAttributeList, DWORD dwAttributeCount, DWORD dwFlags, SIZE_T *lpSize);
BOOL UpdateProcThreadAttribute(void *lpAttributeList, DWORD dwFlags, SIZE_T Attribute, void *lpValue, SIZE_T cbSize, void *lpPreviousValue, SIZE_T *lpReturnSize);
void DeleteProcThreadAttributeList(void *lpAttributeList);

intptr_t GetProcessHeap(void);
void *HeapAlloc(intptr_t hHeap, DWORD dwFlags, SIZE_T dwBytes);
BOOL HeapFree(intptr_t hHeap, DWORD dwFlags, void *lpMem);

BOOL CreateProcessW(const void *lpApplicationName, const void *lpCommandLine, void *lpProcessAttributes, void *lpThreadAttributes, BOOL bInheritHandles, DWORD dwCreationFlags, const void *lpEnvironment, const void *lpCurrentDirectory, STARTUPINFOEXW *lpStartupInfo, PROCESS_INFORMATION *lpProcessInformation);

DWORD WaitForSingleObject(intptr_t hHandle, DWORD dwMilliseconds);
BOOL  GetExitCodeProcess(intptr_t hProcess, DWORD *lpExitCode);
BOOL  CloseHandle(intptr_t hObject);
DWORD GetLastError(void);

intptr_t CreateJobObjectW(void *lpJobAttributes, const void *lpName);
BOOL SetInformationJobObject(intptr_t hJob, int JobObjectInfoClass, void *lpJobObjectInfo, DWORD cbJobObjectInfoLength);
BOOL AssignProcessToJobObject(intptr_t hJob, intptr_t hProcess);
BOOL TerminateJobObject(intptr_t hJob, UINT uExitCode);
CDEF;

    /**
     * The export probe: the real `CreatePseudoConsole` prototype and nothing
     * else, resolved against kernel32 without calling it.
     *
     * `UINT` is declared inline because this block must stand alone.
     */
    private const PROBE_CDEF = <<<'CDEF'
typedef unsigned int DWORD;
typedef unsigned int UINT;
typedef struct { short X; short Y; } COORD;
int CreatePseudoConsole(COORD size, intptr_t hInput, intptr_t hOutput, DWORD dwFlags, intptr_t *phPC);
CDEF;

    /** Process-wide FFI handle, loaded once — same shape as {@see \SugarCraft\Pty\Libc}. */
    private static ?\FFI $ffi = null;

    /** Process-wide instance behind {@see self()}. */
    private static ?self $instance = null;

    /**
     * Live attribute-list blobs, id => heap-backed `void *` CData.
     *
     * A `PROC_THREAD_ATTRIBUTE_LIST` is dereferenced by `CreateProcessW` long
     * after the call that built it; the heap block outlives every PHP variable,
     * and the wrapper is retained so {@see deleteAttributeList()} frees it
     * exactly once.
     */
    private array $attributeLists = [];

    /** Live job objects, id => `array{handle:int, buffer:?\FFI\CData}`. */
    private array $jobs = [];

    /** The process heap handle, resolved once by {@see heap()}. */
    private ?int $processHeap = null;

    /** Monotonic id source for {@see $attributeLists} / {@see $jobs}. */
    private int $nextId = 1;

    /**
     * The full cdef: types then prototypes.
     *
     * Public for the same reason {@see \SugarCraft\Pty\Libc::cdef()} is — the
     * declaration is data a guard can read without loading FFI or touching a DLL.
     */
    public static function cdef(): string
    {
        return self::TYPES_CDEF . "\n" . self::FUNCTIONS_CDEF;
    }

    /**
     * The types half of {@see cdef()} — parseable with no library, so its
     * struct layouts can be measured off-Windows.
     */
    public static function typesCdef(): string
    {
        return self::TYPES_CDEF;
    }

    /**
     * The prototypes half of {@see cdef()} — resolvable only against
     * {@see LIBRARY}.
     */
    public static function functionsCdef(): string
    {
        return self::FUNCTIONS_CDEF;
    }

    public static function self(): ConPtyApi
    {
        $reason = self::supported();

        if ($reason !== null) {
            throw UnsupportedPlatformException::forConPtyUnavailable($reason);
        }

        return self::$instance ??= new self();
    }

    /**
     * A process-wide binding, WITHOUT the availability gate {@see self()}
     * applies.
     *
     * The pair exists because the two callers differ: a factory asks "can this
     * run here" and must refuse loudly, while a test that is about to assert
     * something about the binding itself must not be refused by its own host.
     */
    public static function unsafe(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @see ConPtyApi::supported()
     */
    public static function supported(): ?string
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return \sprintf('this host is %s, and ConPTY is a Windows API', \PHP_OS_FAMILY);
        }

        if (\PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            return \sprintf(
                'this PHP is %s; FFI binding to the Windows API in this package requires %s or newer',
                \PHP_VERSION,
                '8.4',
            );
        }

        if (!\extension_loaded('ffi')) {
            return 'the ffi extension is not loaded, and ConPTY is reached through FFI';
        }

        try {
            // Binding is not calling: nothing here runs CreatePseudoConsole, so
            // the probe keeps its "no side effects" promise. A missing symbol is
            // an FFI\Exception out of the resolution pass.
            \FFI::cdef(self::PROBE_CDEF, self::LIBRARY);
        } catch (\FFI\Exception $e) {
            return \sprintf(
                'kernel32.dll does not export CreatePseudoConsole (%s); ConPTY needs Windows 10 1809 or newer',
                $e->getMessage(),
            );
        }

        return null;
    }

    public function ffi(): \FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }

        try {
            self::$ffi = \FFI::cdef(self::cdef(), self::LIBRARY);
        } catch (\FFI\Exception $e) {
            throw new PtyException(
                \sprintf('cannot bind %s for ConPTY: %s', self::LIBRARY, $e->getMessage()),
                0,
                $e,
            );
        }

        return self::$ffi;
    }

    // ─── Pseudo-console ─────────────────────────────────────────────────────

    public function createPseudoConsole(int $width, int $height, int $input, int $output): int
    {
        $ffi = $this->ffi();
        $size = $ffi->new('COORD');
        $size->X = $width;
        $size->Y = $height;

        $out = $ffi->new('intptr_t');
        $hresult = $ffi->CreatePseudoConsole($size, $input, $output, 0, \FFI::addr($out));

        if ((int) $hresult !== self::S_OK) {
            throw new PtyException(\sprintf(
                'CreatePseudoConsole(%dx%d) failed with HRESULT 0x%08X (win32 error %d)',
                $width,
                $height,
                $hresult & 0xFFFFFFFF,
                $this->lastError(),
            ));
        }

        return (int) $out->cdata;
    }

    public function resizePseudoConsole(int $pseudoConsole, int $width, int $height): void
    {
        $ffi = $this->ffi();
        $size = $ffi->new('COORD');
        $size->X = $width;
        $size->Y = $height;

        // ResizePseudoConsole is documented HRESULT, and PHP's FFI honours the
        // declaration, so the return value here is the COORD-passed-by-value
        // ABI on x64 (the struct is passed in registers alongside it). The
        // Windows sample ignores the return; this checks it and throws.
        $hresult = $ffi->ResizePseudoConsole($pseudoConsole, $size);

        if ((int) $hresult !== self::S_OK) {
            throw new PtyException(\sprintf(
                'ResizePseudoConsole(%dx%d) failed with HRESULT 0x%08X',
                $width,
                $height,
                $hresult & 0xFFFFFFFF,
            ));
        }
    }

    public function closePseudoConsole(int $pseudoConsole): void
    {
        // Deliberately unchecked. ClosePseudoConsole has no usable failure
        // signal for a handle this process already considers closed, and every
        // caller is on a teardown path where an exception would skip the pipe
        // closes that follow it.
        $this->ffi()->ClosePseudoConsole($pseudoConsole);
    }

    // ─── Pipes ──────────────────────────────────────────────────────────────

    public function createPipe(int $bufferSize = 0): array
    {
        $ffi = $this->ffi();
        $read = $ffi->new('intptr_t');
        $write = $ffi->new('intptr_t');

        // lpSecurityAttributes NULL gives a non-inheritable pipe. That is right
        // for ConPTY: the child never sees these ends — it gets the pseudo-
        // console through the attribute list — so inheriting them would only
        // keep the parent's EOF signal alive in a grandchild.
        $ok = $ffi->CreatePipe(\FFI::addr($read), \FFI::addr($write), null, $bufferSize);

        if (!$ok) {
            throw new PtyException(\sprintf(
                'CreatePipe(size=%d) failed with win32 error %d',
                $bufferSize,
                $this->lastError(),
            ));
        }

        // Scalar out-params from FFI::new() are readable ONLY through ->cdata:
        // [0] dimension access is reserved for pointer/array CData and throws
        // "Cannot use object of type FFI\CData as array" on every other shape.
        // This exact line was the Windows 8.4 CI error (open() died here before
        // any refusal or live round-trip could run); the accessor is pinned by
        // testEveryScalarOutParamReadGoesThroughCdataNotADimension.
        return ['read' => (int) $read->cdata, 'write' => (int) $write->cdata];
    }

    public function peekNamedPipe(int $handle): ?array
    {
        $ffi = $this->ffi();
        $avail = $ffi->new('DWORD');
        $left = $ffi->new('DWORD');

        // The documented "how much is there" form: lpBuffer NULL, and then
        // lpBytesRead MUST also be NULL (a non-null one alongside a null buffer
        // is the shape the API rejects). Two out-params remain: the bytes readable
        // now, and what is left of the message currently being written — a ConPTY
        // output stream is not message-oriented in practice, so the second is
        // informational, and it is carried rather than dropped because a caller
        // that sees available == 0 with left > 0 knows the write is mid-flight.
        $ok = $ffi->PeekNamedPipe($handle, null, 0, null, \FFI::addr($avail), \FFI::addr($left));

        if (!$ok) {
            // Any failure is reported as "gone". In practice the code that
            // matters here is ERROR_BROKEN_PIPE (109) and ERROR_INVALID_HANDLE
            // (6), both of which mean the read loop should stop; a caller that
            // wants the distinction reads the error through lastError() before
            // the next call, which is why this returns null rather than throwing
            // inside a loop that would have to catch its own teardown.
            return null;
        }

        return [
            'available' => (int) $avail->cdata,
            'leftThisMessage' => (int) $left->cdata,
        ];
    }

    public function readFile(int $handle, int $bytes): string|false
    {
        if ($bytes <= 0) {
            return '';
        }

        $ffi = $this->ffi();
        $buffer = $ffi->new(\sprintf('unsigned char[%d]', $bytes));
        $read = $ffi->new('DWORD');

        $ok = $ffi->ReadFile($handle, \FFI::addr($buffer), $bytes, \FFI::addr($read), null);

        if (!$ok) {
            return false;
        }

        $count = (int) $read->cdata;

        if ($count === 0) {
            return ''; // End of pipe; the caller learns closure from peekNamedPipe().
        }

        return \FFI::string($buffer, $count);
    }

    public function writeFile(int $handle, string $bytes): int|false
    {
        $length = \strlen($bytes);

        if ($length === 0) {
            return 0;
        }

        $ffi = $this->ffi();
        // The buffer must outlive the call and cannot be a PHP string cast
        // directly: FFI needs an addressable C array.
        $buffer = $ffi->new(\sprintf('unsigned char[%d]', $length));
        \FFI::memcpy($buffer, $bytes, $length);

        $written = $ffi->new('DWORD');
        $ok = $ffi->WriteFile($handle, \FFI::addr($buffer), $length, \FFI::addr($written), null);

        if (!$ok) {
            return false;
        }

        return (int) $written->cdata;
    }

    // ─── Process creation ───────────────────────────────────────────────────

    public function initializeAttributeList(int $attributeCount): int
    {
        $ffi = $this->ffi();
        $size = $ffi->new('SIZE_T');

        // Call one: size query. The documented answer for a null list is FALSE
        // with ERROR_INSUFFICIENT_BUFFER and the size filled in — so a TRUE here
        // would mean the call did not do what its contract says, and that is
        // worth refusing rather than accepting.
        $ffi->InitializeProcThreadAttributeList(null, $attributeCount, 0, \FFI::addr($size));
        $queryError = $this->lastError();

        if ((int) $size->cdata <= 0) {
            throw new PtyException(\sprintf(
                'InitializeProcThreadAttributeList(%d) reported no size (win32 error %d)',
                $attributeCount,
                $queryError,
            ));
        }

        $bytes = (int) $size->cdata;
        // HeapAlloc, not FFI::new: kernelbase reads and writes the blob's
        // internal pointers on MEMORY_ALLOCATION_ALIGNMENT (16 bytes on x64),
        // and a lesser-aligned list makes UpdateProcThreadAttribute answer
        // FALSE with GetLastError still 0 — the "win32 error 0" CI signature.
        // The CRT malloc the MSDN sample uses carries that alignment by
        // contract; emalloc behind FFI::new() only guarantees 8.
        $list = $ffi->HeapAlloc($this->heap(), 0, $bytes);

        if ($list === null) {
            throw new PtyException(\sprintf(
                'HeapAlloc(%d bytes) for the attribute list failed with win32 error %d',
                $bytes,
                $this->lastError(),
            ));
        }

        $size2 = $ffi->new('SIZE_T');
        $size2->cdata = $bytes;

        $ok = $ffi->InitializeProcThreadAttributeList(
            $list,
            $attributeCount,
            0,
            \FFI::addr($size2),
        );

        if (!$ok) {
            $ffi->HeapFree($this->heap(), 0, $list);

            throw new PtyException(\sprintf(
                'InitializeProcThreadAttributeList(%d, %d bytes) failed with win32 error %d',
                $attributeCount,
                $bytes,
                $this->lastError(),
            ));
        }

        $id = $this->nextId++;
        // Retained: CreateProcessW dereferences this buffer later, and the
        // wrapper is what deleteAttributeList() frees the heap block through.
        $this->attributeLists[$id] = $list;

        return $id;
    }

    public function setPseudoConsoleAttribute(int $attributeList, int $pseudoConsole): void
    {
        if (!isset($this->attributeLists[$attributeList])) {
            throw new PtyException(\sprintf(
                'attribute list id %d is not live; it was never created or was already deleted',
                $attributeList,
            ));
        }

        $ffi = $this->ffi();
        $size = $ffi->new('SIZE_T');
        $list = $this->attributeLists[$attributeList];
        $cbSize = \FFI::sizeof($ffi->new('intptr_t'));

        // Shape A — the handle passed BY VALUE, as the MSDN CreateProcess
        // sample spells it (lpValue carries the HPCON itself).
        $ok = $ffi->UpdateProcThreadAttribute(
            $list,
            0,
            self::PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE,
            $ffi->cast('void *', $pseudoConsole),
            $cbSize,
            null,
            \FFI::addr($size),
        );
        // Capture the code BEFORE any other call can touch it — the
        // diagnosis must name the error Update actually left behind.
        $byValueError = $this->lastError();

        if (!$ok) {
            // Shape B — a POINTER TO the HPCON slot. kernelbase's path for
            // PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE has shipped in both
            // spellings across Windows builds (copy-the-handle vs
            // dereference-lpValue); a build that dereferences a bare handle
            // faults internally and answers FALSE without calling
            // SetLastError — exactly the "win32 error 0" signature this leg
            // carried at CI run 42955157728 even after the allocator fix.
            // Both shapes are attempted per call; whichever the build accepts
            // wins, and a pointer stored in an already-populated list entry
            // is harmless to the other shape (an overwrite, not a conflict).
            $slot = $ffi->new('intptr_t');
            $slot->cdata = $pseudoConsole;
            $ok = $ffi->UpdateProcThreadAttribute(
                $list,
                0,
                self::PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE,
                \FFI::addr($slot),
                $cbSize,
                null,
                \FFI::addr($size),
            );
        }

        if (!$ok) {
            $byPtrError = $this->lastError();

            // The spawn is already dying here, so the refusal path spends its
            // one roundtrip on forensics the kernel can answer: which of the
            // two sides is actually dead. blobAddr is the list's real address
            // — an (int) cast of a void* CData yields the wrapper's stored
            // handle, not the pointer, which is why an early run reported the
            // blob as 0x1. The first word inside the blob is kernelbase's own
            // bookkeeping; reading it says whether the list we initialized is
            // still the list we allocated. ResizePseudoConsole is the cheap
            // oracle for the pseudo-console handle: S_OK means the handle both
            // attach shapes were refused is ALIVE, which indicts the attribute
            // list; any other answer means the handle went stale upstream in
            // ConPtySystem and Update honestly rejected it.
            $blobAddr = (int) \FFI::cast('intptr_t', $list)->cdata;
            $magicWord = $ffi->new('SIZE_T');
            \FFI::memcpy(\FFI::addr($magicWord), $list, \FFI::sizeof($magicWord));
            $coord = $ffi->new('COORD');
            $coord->X = 80;
            $coord->Y = 24;
            $probeResult = (int) $ffi->ResizePseudoConsole($pseudoConsole, $coord);
            $probeError = $this->lastError();

            throw new PtyException(\sprintf(
                'UpdateProcThreadAttribute(PSEUDOCONSOLE) failed with win32 error %d [byPtr err=%d, blobAddr=0x%016X, align=%d, magic=0x%016X, hpcn=0x%016X, probeHr=0x%08X, probeErr=%d, cbSize=%d]',
                $byValueError,
                $byPtrError,
                $blobAddr,
                $blobAddr % 16,
                (int) $magicWord->cdata,
                $pseudoConsole,
                $probeResult,
                $probeError,
                $cbSize,
            ));
        }
    }

    public function deleteAttributeList(int $attributeList): void
    {
        $list = $this->attributeLists[$attributeList] ?? null;

        if ($list === null) {
            return; // Already gone; teardown is not an error.
        }

        unset($this->attributeLists[$attributeList]);
        $ffi = $this->ffi();
        $ffi->DeleteProcThreadAttributeList($list);
        $ffi->HeapFree($this->heap(), 0, $list);
    }

    public function createProcess(
        string $applicationName,
        string $commandLine,
        int $attributeList,
        array $environment,
        string $workingDirectory = '',
        int $creationFlags = 0,
        ?int $job = null,
    ): array {
        $list = $this->attributeLists[$attributeList] ?? null;

        if ($list === null) {
            throw new PtyException(\sprintf(
                'createProcess needs a live attribute list id; %d is not one, so the child would '
                . 'have started with no pseudo-console and appeared to hang',
                $attributeList,
            ));
        }

        if ($commandLine === '' && $applicationName === '') {
            throw new PtyException('createProcess needs an application name or a command line');
        }

        $ffi = $this->ffi();

        // CreateProcessW WRITES into lpCommandLine (it is LPWCH, not LPCWCH), and
        // the CRT's own parser expects to be allowed to. A read-only buffer is
        // the classic first attempt and is not what the signature wants.
        $cmdBuffer = $this->wideBuffer($commandLine);
        $appBuffer = $this->wideBuffer($applicationName);
        $cwdBuffer = $this->wideBuffer($workingDirectory);

        $startup = $ffi->new('STARTUPINFOEXW');
        // cb is the size of the OUTER struct, not the inner one: the
        // EXTENDED_STARTUPINFO_PRESENT contract on MSDN's CreateProcessW page
        // states the lpStartupInfo parameter "points to a STARTUPINFOEXW
        // structure", and the STARTUPINFOEXW page states its StartupInfo.cb
        // "must be set to sizeof(STARTUPINFOEXW)" — here 112, not the inner
        // STARTUPINFOW's 104. CreateProcessW validates cb against the flag.
        $startup->StartupInfo->cb = \FFI::sizeof($startup);
        $startup->lpAttributeList = $list;
        // wShowWindow + the flag that makes it mean anything: a ConPTY child left
        // with the default show-state opens a console window on a desktop.
        $startup->StartupInfo->wShowWindow = self::SW_HIDE;
        $startup->StartupInfo->dwFlags = self::STARTF_USESHOWWINDOW;

        $info = $ffi->new('PROCESS_INFORMATION');

        // The environment block, if the caller supplied a map. Encoding it here
        // rather than taking pre-encoded bytes is what keeps the shape and its
        // flag from ever disagreeing — a UTF-16 block handed over without
        // CREATE_UNICODE_ENVIRONMENT arrives as ANSI shredded by NULs, and a
        // caller assembling both halves by hand is how that bug is born.
        $flags = $creationFlags | self::EXTENDED_STARTUPINFO_PRESENT;
        $envBuffer = null;

        if ($environment !== []) {
            $encoded = EnvBlock::encode($environment);
            $envBuffer = $this->byteBuffer($encoded);
            $flags |= self::CREATE_UNICODE_ENVIRONMENT;
        }

        $ok = $ffi->CreateProcessW(
            $applicationName === '' ? null : \FFI::addr($appBuffer),
            \FFI::addr($cmdBuffer),
            null,
            null,
            0, // bInheritHandles: the pipes are not passed by inheritance (see createPipe()).
            $flags,
            $envBuffer === null ? null : \FFI::addr($envBuffer),
            $workingDirectory === '' ? null : \FFI::addr($cwdBuffer),
            \FFI::addr($startup),
            \FFI::addr($info),
        );

        if (!$ok) {
            throw new PtyException(\sprintf(
                'CreateProcessW failed for "%s" with win32 error %d',
                $commandLine !== '' ? $commandLine : $applicationName,
                $this->lastError(),
            ));
        }

        // MAJOR-2, cured at the declaration: `(int)` applied to a POINTER-typed
        // FFI\CData answers garbage AND emits "Object of class FFI\CData could
        // not be converted to int", which this lib's failOnWarning would turn
        // into a Windows-leg red. The sugar-core Kernel32 idiom
        // `(int) \FFI::cast('intptr_t', $h)->cdata` was tried and is NOT
        // transferable: measured on PHP 8.3.6, casting an integer-typed result
        // out of a void * source RE-ANCHORS the CData at the pointer's value,
        // so `->cdata` DEREFERENCES the handle as memory — a slot holding
        // 0x1234 SIGSEGVs the read (exit 139). sugar-core gets away with it
        // only because there the source is a pointer at real FFI-owned storage
        // the cast re-reads; a kernel handle from CreateProcessW is not
        // dereferenceable memory on any host. Declaring the slots intptr_t
        // (same 8-byte LLP64 layout) makes this a silent, exact scalar read.
        $process = (int) $info->hProcess;
        $thread = (int) $info->hThread;

        // CreateProcessW hands back two handles and a PTY backend needs one of
        // them. The primary thread handle is closed HERE rather than returned,
        // because a caller that is not told it exists will certainly leak it, and
        // a process whose thread handle is still open cannot be waited on for
        // free anywhere else.
        $this->closeHandle($thread);

        if ($job !== null) {
            $this->assignProcessToJob($job, $process);
        }

        return ['process' => $process, 'processId' => (int) $info->dwProcessId];
    }

    // ─── Process state ──────────────────────────────────────────────────────

    public function waitForSingleObject(int $handle, int $timeoutMs): int
    {
        return (int) $this->ffi()->WaitForSingleObject($handle, $timeoutMs);
    }

    public function getExitCodeProcess(int $handle): int
    {
        $ffi = $this->ffi();
        $code = $ffi->new('DWORD');

        if (!$ffi->GetExitCodeProcess($handle, \FFI::addr($code))) {
            throw new PtyException(\sprintf(
                'GetExitCodeProcess failed with win32 error %d',
                $this->lastError(),
            ));
        }

        return (int) $code->cdata;
    }

    public function closeHandle(int $handle): bool
    {
        // 0 and -1 are what "no handle" arrives as from the paths above;
        // CloseHandle on either would fail and report a spurious win32 error.
        if ($handle === 0 || $handle === self::INVALID_HANDLE_VALUE) {
            return false;
        }

        return (bool) $this->ffi()->CloseHandle($handle);
    }

    /**
     * The last win32 error, for a caller that needs to distinguish
     * ERROR_BROKEN_PIPE from ERROR_INVALID_HANDLE after a false return.
     */
    public function lastError(): int
    {
        return (int) $this->ffi()->GetLastError();
    }

    /**
     * The process heap handle, resolved once — the allocator whose alignment
     * contract the attribute list requires; see {@see initializeAttributeList()}.
     */
    private function heap(): int
    {
        if ($this->processHeap === null) {
            $handle = (int) $this->ffi()->GetProcessHeap();

            if ($handle === 0) {
                throw new PtyException(\sprintf(
                    'GetProcessHeap failed with win32 error %d',
                    $this->lastError(),
                ));
            }

            $this->processHeap = $handle;
        }

        return $this->processHeap;
    }

    // ─── Job object ─────────────────────────────────────────────────────────

    public function createJob(): int
    {
        $ffi = $this->ffi();
        // A NULL name makes the job private to this process: no other session on
        // the host can open it by name and terminate what is inside.
        $handle = (int) $ffi->CreateJobObjectW(null, null);

        if ($handle === 0) {
            throw new PtyException(\sprintf(
                'CreateJobObjectW failed with win32 error %d',
                $this->lastError(),
            ));
        }

        $info = $ffi->new('JOBOBJECT_EXTENDED_LIMIT_INFORMATION');
        $info->BasicLimitInformation->LimitFlags = self::JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;

        $ok = $ffi->SetInformationJobObject(
            $handle,
            self::JOB_OBJECT_EXTENDED_LIMIT_INFORMATION,
            \FFI::addr($info),
            \FFI::sizeof($info),
        );

        if (!$ok) {
            // Half-built job: close it rather than hand back a handle whose
            // process tree would survive us.
            $this->closeHandle($handle);

            throw new PtyException(\sprintf(
                'SetInformationJobObject(KILL_ON_JOB_CLOSE) failed with win32 error %d',
                $this->lastError(),
            ));
        }

        $id = $this->nextId++;
        // The CData is retained only so the id->handle map has a single owner
        // and closeJob() cannot be called twice for the same live handle.
        $this->jobs[$id] = ['handle' => $handle, 'buffer' => $info];

        return $id;
    }

    public function assignProcessToJob(int $job, int $process): void
    {
        $this->guardJob($job, __METHOD__);

        if (!$this->ffi()->AssignProcessToJobObject($this->jobs[$job]['handle'], $process)) {
            throw new PtyException(\sprintf(
                'AssignProcessToJobObject failed with win32 error %d; the child would run outside '
                . 'the job and survive its termination',
                $this->lastError(),
            ));
        }
    }

    public function terminateJob(int $job, int $exitCode = 0): void
    {
        $this->guardJob($job, __METHOD__);

        if (!$this->ffi()->TerminateJobObject($this->jobs[$job]['handle'], $exitCode)) {
            throw new PtyException(\sprintf(
                'TerminateJobObject failed with win32 error %d',
                $this->lastError(),
            ));
        }
    }

    public function closeJob(int $job): bool
    {
        $entry = $this->jobs[$job] ?? null;

        if ($entry === null) {
            return false;
        }

        unset($this->jobs[$job]);

        // Closing the handle is what the KILL_ON_JOB_CLOSE limit fires on, so for
        // a tree that was never explicitly terminated this call IS the reaper.
        return $this->closeHandle($entry['handle']);
    }

    private function guardJob(int $job, string $from): void
    {
        if (!isset($this->jobs[$job])) {
            throw new PtyException(\sprintf(
                '%s: job id %d is not live; it was never created or was already closed',
                $from,
                $job,
            ));
        }
    }

    /**
     * A writable `char[]` holding `$utf8` as UTF-16LE plus its NUL unit.
     */
    private function wideBuffer(string $utf8): \FFI\CData
    {
        return $this->byteBuffer(WideString::terminate($utf8));
    }

    /**
     * A `char[]` copy of raw bytes, sized so it can be written to in place.
     */
    private function byteBuffer(string $bytes): \FFI\CData
    {
        $ffi = $this->ffi();
        // +2 so a zero-length value still has a real, addressable one-unit
        // terminator; FFI::new("char[0]") is not a thing.
        $buffer = $ffi->new(\sprintf('unsigned char[%d]', \strlen($bytes) + 2));
        \FFI::memcpy($buffer, $bytes, \strlen($bytes));
        $buffer[\strlen($bytes)] = 0;
        $buffer[\strlen($bytes) + 1] = 0;

        return $buffer;
    }
}
