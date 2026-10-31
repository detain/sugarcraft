<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

/**
 * The Win32 surface sugar-pty's ConPTY backend needs, as a contract.
 *
 * ## Why an interface at all
 *
 * Everything below is a Windows call, and this package's test suite runs on
 * Linux and macOS. Declaring the surface as a contract — rather than as a class
 * whose FFI calls happen to be unreachable off-Windows — is what lets a test
 * double answer the same messages and a PTY session be driven, end to end,
 * against a fake kernel32 on a machine that has no kernel32. It is the same
 * shape sugar-core uses for its own console bindings
 * ({@see \SugarCraft\Core\Util\Tty\Kernel32Interface} beside
 * {@see \SugarCraft\Core\Util\Tty\Kernel32}), including the part that makes it
 * work: handles are plain `int`.
 *
 * ## Handle representation
 *
 * A `HANDLE`, `HPCON` and friends are pointer-sized opaque values. They are
 * carried here as PHP `int`, never as `\FFI\CData`: a CData is only meaningful
 * inside the FFI scope that made it, it cannot be constructed by a double, and
 * comparing two of them for identity is an exercise. On a real host the int is
 * the pointer address; in a test it is a small counter. {@see \FFI} casts are
 * confined to {@see Kernel32ConPty}, which is the only type in this namespace
 * that knows C at all.
 *
 * The cost of that choice is that `-1` has to mean two things in one type —
 * `INVALID_HANDLE_VALUE` is `(HANDLE)-1` while a failed `WaitForSingleObject`
 * returns the DWORD `0xFFFFFFFF`. {@see INVALID_HANDLE_VALUE} and
 * {@see WAIT_FAILED} are therefore spelled differently on purpose, and every
 * method that can return one says which.
 *
 * ## Scope of this step
 *
 * This contract and its FFI implementation are the primitives only. Nothing
 * here satisfies sugar-pty's {@see \SugarCraft\Pty\Contract\PtySystem} or
 * {@see \SugarCraft\Pty\Contract\Process} yet — that adapter layer is the
 * follow-up step, and until it lands this namespace is unreachable from
 * `PtySystemFactory` on any host. {@see supported()} is the door it will open.
 *
 * Mirrors the Windows console-API subset charmbracelet/x/xpty needs, which
 * itself follows the ConPTY sample at
 * https://learn.microsoft.com/en-us/windows/console/creating-a-pseudoconsole-session
 */
interface ConPtyApi
{
    // ─── Availability ───────────────────────────────────────────────────────

    /**
     * Lowest PHP that may bind FFI to Win32 in this monorepo.
     *
     * AGENTS.md: "PHP 8.3+ (8.4+ for Windows FFI)". The version gate is not
     * ceremony — 8.3's FFI has the `ffi.enable`/Zeney round-trip behaviours that
     * 8.4 changed on Windows builds, and the package that ships this file is
     * consumed by apps running on either.
     */
    public const MIN_PHP_VERSION_ID = 80400;

    // ─── Pseudo-console / process-attribute constants ────────────────────────

    /**
     * `PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE` — `UpdateProcThreadAttribute`'s
     * attribute id for attaching a ConPTY to a process being created.
     *
     * `MAKEPROCATTRIBUTENUMBER(22, 2)`. Hard-coded rather than computed because
     * the macro is a header-only construct with no runtime counterpart, and a
     * helper that derives it from two magic numbers explains less than this.
     */
    public const PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE = 0x00020016;

    /** `EXTENDED_STARTUPINFO_PRESENT` — required whenever `STARTUPINFOEXW` is passed. */
    public const EXTENDED_STARTUPINFO_PRESENT = 0x00080000;

    /** `CREATE_UNICODE_ENVIRONMENT` — pairs with {@see EnvBlock::encode()}. */
    public const CREATE_UNICODE_ENVIRONMENT = 0x00040000;

    /** `CREATE_NEW_PROCESS_GROUP` — keeps the child off this process's Ctrl+C line. */
    public const CREATE_NEW_PROCESS_GROUP = 0x00000200;

    /** `CREATE_NO_WINDOW` — a ConPTY child must not get its own console window. */
    public const CREATE_NO_WINDOW = 0x08000000;

    /** `STARTF_USESTDHANDLES` — honour `hStdInput`/`hStdOutput`/`hStdError`. */
    public const STARTF_USESTDHANDLES = 0x00000100;

    /** `STARTF_USESHOWWINDOW` — honour `wShowWindow`. */
    public const STARTF_USESHOWWINDOW = 0x00000001;

    /** `SW_HIDE`. */
    public const SW_HIDE = 0;

    // ─── Wait / exit-code constants ─────────────────────────────────────────

    /** `WAIT_OBJECT_0`. */
    public const WAIT_OBJECT_0 = 0x00000000;

    /** `WAIT_TIMEOUT`. */
    public const WAIT_TIMEOUT = 0x00000102;

    /**
     * `WAIT_FAILED`, as the DWORD this API returns — not {@see INVALID_HANDLE_VALUE}.
     *
     * The two are the same bit pattern read through two different types. Keeping
     * both spellings is what makes `if ($rc === self::WAIT_FAILED)` mean a failed
     * wait instead of a bad handle.
     */
    public const WAIT_FAILED = 0xFFFFFFFF;

    /** `INFINITE` for {@see waitForSingleObject()} — a DWORD, so 0xFFFFFFFF. */
    public const INFINITE = 0xFFFFFFFF;

    /** `STILL_ACTIVE` — what `GetExitCodeProcess` reports while the process runs. */
    public const STILL_ACTIVE = 259;

    /** `(HANDLE)-1`, the failure sentinel of `CreateFile`/`CreatePipe`-family calls. */
    public const INVALID_HANDLE_VALUE = -1;

    /** `S_OK`. `CreatePseudoConsole` and `ResizePseudoConsole` are `HRESULT`-returning. */
    public const S_OK = 0;

    // ─── Job-object constants ───────────────────────────────────────────────

    /** `JobObjectExtendedLimitInformation`, the class carrying `LimitFlags`. */
    public const JOB_OBJECT_EXTENDED_LIMIT_INFORMATION = 9;

    /** `JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`. */
    public const JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x00002000;

    // ─── Lifetime ───────────────────────────────────────────────────────────

    /**
     * The live `\FFI` handle, for a caller that must reach C directly.
     */
    public function ffi(): \FFI;

    /**
     * A process-wide instance bound to the host's kernel32.
     *
     * @throws \SugarCraft\Pty\Exception\UnsupportedPlatformException when
     *         {@see supported()} answers non-null
     */
    public static function self(): self;

    /**
     * Why ConPTY is unusable here, or null when it is usable.
     *
     * A probe with NO side effects: it does not create a pseudo-console, does not
     * allocate a console, does not change a codepage, and does not write anywhere.
     * It answers four questions and stops:
     *
     *  1. Is this Windows? (`PHP_OS_FAMILY`)
     *  2. Is PHP ≥ 8.4? ({@see MIN_PHP_VERSION_ID})
     *  3. Is ext-ffi loaded?
     *  4. Does kernel32 export `CreatePseudoConsole`? — the Windows 10 1809
     *     floor. Answered by ATTEMPTING the binding and catching the resolution
     *     failure, because PHP's FFI has no symbol-listing call, and because
     *     binding a function is not calling one: nothing runs.
     *
     * The reason string is what {@see \SugarCraft\Pty\Exception\UnsupportedPlatformException::forConPtyUnavailable()}
     * is built from, so a user sees *which* of the four failed rather than
     * "Windows support is not available".
     */
    public static function supported(): ?string;

    // ─── Pseudo-console ─────────────────────────────────────────────────────

    /**
     * `CreatePseudoConsole` — the ConPTY itself, wired between two pipe ends.
     *
     * `$input` is the write end of the parent→child pipe, `$output` the read end
     * of the child→parent pipe; ConPTY reads keystrokes from `$input` and writes
     * screen output to `$output`, which is the direction inversion that trips up
     * every first reading of this API.
     *
     * @return int HPCON handle
     * @throws \SugarCraft\Pty\PtyException on a non-`S_OK` HRESULT
     */
    public function createPseudoConsole(int $width, int $height, int $input, int $output): int;

    /**
     * `ResizePseudoConsole` — propagates a terminal resize into the child.
     *
     * @throws \SugarCraft\Pty\PtyException on a non-`S_OK` HRESULT
     */
    public function resizePseudoConsole(int $pseudoConsole, int $width, int $height): void;

    /**
     * `ClosePseudoConsole`.
     *
     * Closing it does NOT close the two pipe handles it was given — Windows
     * leaves that to the owner, and a backend that forgets leaks one pair per
     * session.
     */
    public function closePseudoConsole(int $pseudoConsole): void;

    // ─── Pipes ──────────────────────────────────────────────────────────────

    /**
     * `CreatePipe` — an anonymous parent/child pipe pair.
     *
     * @param int $bufferSize 0 lets the OS pick
     * @return array{read:int, write:int}
     * @throws \SugarCraft\Pty\PtyException when the call fails
     */
    public function createPipe(int $bufferSize = 0): array;

    /**
     * `PeekNamedPipe` — how much is readable without consuming it.
     *
     * This is the primitive that makes a ConPTY read loop possible: `ReadFile` on
     * the pseudo-console's output handle blocks, and the POSIX-side pump has no
     * `select()` over a Windows handle, so a loop has to ask before reading.
     *
     * @return array{available:int,leftThisMessage:int}|null null when the pipe is
     *         gone (the child exited and its end closed), which `ReadFile` would
     *         otherwise report as a successful zero-byte read forever
     */
    public function peekNamedPipe(int $handle): ?array;

    /**
     * `ReadFile` — read up to `$bytes` from a pipe handle.
     *
     * @return string|false the bytes read (`''` at end-of-file), or false on a
     *                      failed read
     */
    public function readFile(int $handle, int $bytes): string|false;

    /**
     * `WriteFile` — write a whole buffer to a pipe handle.
     *
     * @return int|false bytes written, or false on a failed write. A short write
     *                   is returned as-is rather than retried: the caller owns
     *                   whether that means a full pipe or a dead child.
     */
    public function writeFile(int $handle, string $bytes): int|false;

    // ─── Process creation ───────────────────────────────────────────────────

    /**
     * `InitializeProcThreadAttributeList` — allocate an attribute list.
     *
     * The list is an opaque OS-sized blob: the real call is made twice, once with
     * a null buffer to learn the size and once to initialise it. This method does
     * both and hands back an id for the buffer the implementation retains, so no
     * caller has to keep a `CData` alive — which is the entire difference between
     * this and a raw binding, and the reason a double can implement it.
     *
     * @return int opaque list id for {@see setPseudoConsoleAttribute()} and
     *             {@see createProcess()}
     * @throws \SugarCraft\Pty\PtyException on failure
     */
    public function initializeAttributeList(int $attributeCount): int;

    /**
     * `UpdateProcThreadAttribute` with
     * {@see PROC_THREAD_ATTRIBUTE_PSEUDOCONSOLE}.
     *
     * The value Windows wants is the HPCON itself passed as a pointer-sized
     * value, not the address of a variable holding it — the inversion that makes
     * a copied ConPTY sample segfault instead.
     *
     * @throws \SugarCraft\Pty\PtyException on failure
     */
    public function setPseudoConsoleAttribute(int $attributeList, int $pseudoConsole): void;

    /**
     * `DeleteProcThreadAttributeList` and release the retained buffer.
     */
    public function deleteAttributeList(int $attributeList): void;

    /**
     * `CreateProcessW` with an extended startup info carrying `$attributeList`.
     *
     * The ConPTY is attached through that list, which is why the
     * {@see EXTENDED_STARTUPINFO_PRESENT} flag is added here rather than taken
     * from the caller: without it, `CreateProcessW` reads the startup struct as a
     * plain `STARTUPINFO` and ignores the attribute list, and the child comes up
     * with no console at all — a failure that looks like a hang, not like a
     * missing flag.
     *
     * @param string                    $applicationName program path, or '' to let
     *                                                   the command line supply it
     * @param string                    $commandLine     built by {@see CommandLine::fromArgv()}
     * @param int                       $attributeList   id from
     *                                                   {@see initializeAttributeList()}
     * @param array<string, string>     $environment     encoded via {@see EnvBlock};
     *                                                   an empty array inherits this process's block
     * @param int                       $creationFlags   additional `CREATE_*` bits
     * @param int|null                  $job             a {@see createJobObject()} id to
     *                                                   place the child in, giving
     *                                                   tree containment
     * @return array{process:int, processId:int} the process handle and pid; the
     *         primary thread handle is closed by this call
     * @throws \SugarCraft\Pty\PtyException when the call fails, carrying the
     *         win32 error code — never a silently absent child
     */
    public function createProcess(
        string $applicationName,
        string $commandLine,
        int $attributeList,
        array $environment,
        string $workingDirectory = '',
        int $creationFlags = 0,
        ?int $job = null,
    ): array;

    // ─── Process state ──────────────────────────────────────────────────────

    /**
     * `WaitForSingleObject`.
     *
     * @param int $timeoutMs {@see INFINITE} waits forever
     * @return int {@see WAIT_OBJECT_0}, {@see WAIT_TIMEOUT}, or
     *             {@see WAIT_FAILED}
     */
    public function waitForSingleObject(int $handle, int $timeoutMs): int;

    /**
     * `GetExitCodeProcess`.
     *
     * @return int the exit code, or {@see STILL_ACTIVE} while the process runs
     * @throws \SugarCraft\Pty\PtyException on a failed query
     */
    public function getExitCodeProcess(int $handle): int;

    /**
     * `CloseHandle`.
     */
    public function closeHandle(int $handle): bool;

    // ─── Job object (tree containment) ──────────────────────────────────────

    /**
     * `CreateJobObjectW` with {@see JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE} already
     * set through `SetInformationJobObject`.
     *
     * The two calls are fused because the limit is the only reason sugar-pty uses
     * a job at all, and a job created without it is a container that does not
     * contain: the child survives the parent and keeps the ConPTY's pipe end
     * alive. Handing out a half-built job object would be a trap shaped like a
     * primitive.
     *
     * @return int opaque job id
     * @throws \SugarCraft\Pty\PtyException if either call fails, after closing
     *         whatever was created
     */
    public function createJob(): int;

    /**
     * `AssignProcessToJobObject`.
     *
     * @throws \SugarCraft\Pty\PtyException on failure
     */
    public function assignProcessToJob(int $job, int $process): void;

    /**
     * `TerminateJobObject` — kill the tree in one call.
     *
     * This is why the job exists rather than a `taskkill /T /F` round trip: the
     * whole tree dies whether or not the child spawned grandchildren, and with
     * `KILL_ON_JOB_CLOSE` a parent that dies uncleaned gets the same result from
     * the handle closing under it.
     *
     * @throws \SugarCraft\Pty\PtyException on failure
     */
    public function terminateJob(int $job, int $exitCode = 0): void;

    /**
     * Close the job handle. With the kill-on-close limit in place this is what
     * reaps an unreaped tree.
     */
    public function closeJob(int $job): bool;
}
