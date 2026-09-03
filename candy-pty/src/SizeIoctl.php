<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Concerns\LibcAccess;

/**
 * Platform-aware `TIOCSWINSZ` / `TIOCGWINSZ` request constants and
 * a packer / unpacker for the kernel `winsize` struct.
 *
 * @see creack/pty.GetsizeFull
 *
 * The struct is identical on both supported platforms — four
 * little-endian unsigned shorts laid out as
 * `[ws_row, ws_col, ws_xpixel, ws_ypixel]` — but the ioctl request
 * numbers diverge:
 *
 * | Constant      | Linux    | macOS         |
 * |---------------|----------|---------------|
 * | `TIOCSWINSZ`  | `0x5414` | `0x80087467`  |
 * | `TIOCGWINSZ`  | `0x5413` | `0x40087468`  |
 *
 * Linux's compact numbers come from the type-2 ioctl encoding;
 * macOS uses the full BSD `_IOR` / `_IOW` macro encoding which packs
 * direction, type, group, and number into the top bits.
 *
 * Mirrors charmbracelet/x/xpty.UnixPty.SetSize / Size on Go.
 */
final class SizeIoctl
{
    use LibcAccess;

    /** Linux TIOCSWINSZ — set window size. */
    public const LINUX_TIOCSWINSZ = 0x5414;

    /** Linux TIOCGWINSZ — get window size. */
    public const LINUX_TIOCGWINSZ = 0x5413;

    /** macOS TIOCSWINSZ — set window size. */
    public const DARWIN_TIOCSWINSZ = 0x80087467;

    /** macOS TIOCGWINSZ — get window size. */
    public const DARWIN_TIOCGWINSZ = 0x40087468;

    /** Number of unsigned-short fields in a `struct winsize` (rows, cols, xpix, ypix). */
    public const WINSIZE_FIELDS = 4;

    /**
     * Return the platform's `TIOCSWINSZ` request number.
     */
    public static function setRequest(): int
    {
        return PHP_OS_FAMILY === 'Darwin' ? self::DARWIN_TIOCSWINSZ : self::LINUX_TIOCSWINSZ;
    }

    /**
     * Return the platform's `TIOCGWINSZ` request number.
     */
    public static function getRequest(): int
    {
        return PHP_OS_FAMILY === 'Darwin' ? self::DARWIN_TIOCGWINSZ : self::LINUX_TIOCGWINSZ;
    }

    /**
     * Allocate and populate a `struct winsize` FFI buffer.
     *
     * Pixel dimensions default to 0 — the kernel never queries them
     * for terminal-size-aware programs (tput, ncurses, etc.).
     */
    public static function pack(int $rows, int $cols, int $xpix = 0, int $ypix = 0): \FFI\CData
    {
        if ($rows < 0 || $cols < 0 || $xpix < 0 || $ypix < 0) {
            throw new \InvalidArgumentException(
                "winsize fields must be non-negative; got rows={$rows} cols={$cols} xpix={$xpix} ypix={$ypix}"
            );
        }

        $ws = self::libc()->new('unsigned short[' . self::WINSIZE_FIELDS . ']');
        $ws[0] = $rows;
        $ws[1] = $cols;
        $ws[2] = $xpix;
        $ws[3] = $ypix;
        return $ws;
    }

    /**
     * Allocate an empty `struct winsize` buffer suitable for handing
     * to `ioctl(TIOCGWINSZ)`.
     */
    public static function emptyBuffer(): \FFI\CData
    {
        return self::libc()->new('unsigned short[' . self::WINSIZE_FIELDS . ']');
    }

    /**
     * Read `[rows, cols, xpix, ypix]` back out of a winsize buffer.
     *
     * @return array{rows:int, cols:int, xpix:int, ypix:int}
     */
    public static function unpack(\FFI\CData $ws): array
    {
        return [
            'rows' => (int) $ws[0],
            'cols' => (int) $ws[1],
            'xpix' => (int) $ws[2],
            'ypix' => (int) $ws[3],
        ];
    }

    /**
     * Query the terminal size for the given fd via TIOCGWINSZ.
     *
     * On Darwin a failed ioctl is transparently re-read through
     * stty(1) — see {@see getSizeViaLibc()} for the arm64 ABI
     * evidence. Callers get the same shape either way.
     *
     * @param int $fd a file descriptor that refers to a TTY
     * @return array{cols:int, rows:int, xpix:int, ypix:int}
     * @throws \RuntimeException if fd is not a TTY or ioctl fails
     * @see creack/pty.GetsizeFull
     */
    public static function query(int $fd): array
    {
        if (!\function_exists('posix_isatty') || !\posix_isatty($fd)) {
            throw new \RuntimeException('Cannot query size of non-tty fd');
        }

        $libc = self::libc();
        $ws = self::emptyBuffer();
        $rc = self::getSizeViaLibc($libc, $fd, $ws);
        if ($rc !== 0) {
            throw new \RuntimeException(
                'TIOCGWINSZ ioctl failed on fd ' . $fd . ' with rc=' . $rc
            );
        }
        return self::unpack($ws);
    }

    /**
     * Apply a winsize to `$fd`. Returns 0 on success, libc's rc on
     * failure. Linux uses `ioctl(TIOCSWINSZ)`. Darwin tries the same
     * but falls back to `stty -f /dev/fd/<alias>` (fd `dup()`ed first —
     * see {@see withDupAlias()}) because the real libc
     * `ioctl` is variadic and arm64 puts varargs on the stack while
     * fixed args sit in `x0`–`x7` — our fixed-arg cdef pushes the
     * winsize pointer to the wrong register and the kernel returns
     * -1. POSIX 2024 `tcsetwinsize` would solve this cleanly but
     * macOS 15 libSystem doesn't ship it yet (verified PR #475 CI:
     * `Failed resolving C function 'tcsetwinsize'`). The same frame
     * mismatch was since MEASURED in the GET direction too — see
     * {@see getSizeViaLibc()} before assuming any winsize-bearing
     * ioctl can succeed straight through the cdef on arm64 Darwin.
     *
     * Centralised here so both legacy `Pty::resize()` and
     * `PosixMasterPty::resize()` get the fix transparently.
     *
     * @see SttyTermios::sttyArgs() for the Darwin `-f` flag convention
     */
    public static function setSizeViaLibc(\FFI $libc, int $fd, \FFI\CData $ws): int
    {
        $rc = $libc->ioctl($fd, self::setRequest(), $ws);

        if ($rc !== 0 && \PHP_OS_FAMILY === 'Darwin') {
            $sttyRc = self::sttySetSize($libc, $fd, (int) $ws[0], (int) $ws[1]);
            if ($sttyRc === 0) {
                return 0;
            }
        }

        return $rc;
    }

    /**
     * Run `$work` against a `dup()`ed ALIAS of `$fd`, closing the alias
     * when `$work` returns, and return `$work`'s result — or null when
     * the dup itself failed (the work must never run against a number
     * that names nothing).
     *
     * ## WHY THE CHILD GETS AN ALIAS AND NEVER THE RAW FD
     *
     * `PosixPtySystem::openPtyMaster()` marks the pty master
     * `FD_CLOEXEC` unconditionally on BOTH platforms
     * (`Posix/PosixPtySystem.php:70` — it must not survive
     * `proc_open`), and an `FD_CLOEXEC` fd is gone from the child's
     * descriptor table across `exec()`. A child asked to open
     * `/dev/fd/<raw fd>` therefore finds nothing; `dup(2)` returns a
     * fresh descriptor whose close-on-exec flag is cleared (POSIX
     * guarantees), so the alias survives into stty(1) while the
     * original's inheritance contract stays untouched.
     *
     * Kept as the ONE `dup`/`close` site in this file, shared by both
     * stty fallbacks — the descriptor-argument census in candy-core
     * (`DescriptorSinkArgumentCensusTest`) judges each call-site
     * spelling once per file, so a second same-spelled pair would land
     * as unjudged ` #2` keys; one shared alias helper keeps the
     * lifetime managed in exactly one place.
     *
     * @template T
     *
     * @param  \FFI           $libc the same handle the failed ioctl used
     * @param  callable(int):T $work receives the alias fd
     * @return T|null
     */
    private static function withDupAlias(\FFI $libc, int $fd, callable $work): mixed
    {
        $dup = $libc->dup($fd);
        if ($dup < 0) {
            return null;
        }

        try {
            return $work($dup);
        } finally {
            $libc->close($dup);
        }
    }

    /**
     * Shell-out to stty(1) to set the terminal size on Darwin.
     *
     * Uses `stty -f /dev/fd/<alias> rows <rows> cols <cols>` per the
     * macOS convention (note `-f`, not Linux's `-F`). The descriptor is
     * the `dup()` alias, NOT the raw fd: the master is FD_CLOEXEC'd
     * (`Posix/PosixPtySystem.php:70`), so before the alias existed this
     * fallback was silently dead for exactly the resize path it was
     * built for — the exec'd stty could not open the name it was
     * handed and exited non-zero on every call. The open-time resize
     * hides that failure (best-effort `try { resize } catch
     * (PtyException)` in `PosixPtySystem.php:126`), so only LATER
     * explicit resizes surfaced it, as a plain dead no-op rather than
     * an error. See {@see withDupAlias()} for the full WHY.
     *
     * Multiple rapid resizes (e.g. from a fast SIGWINCH forwarding loop)
     * are rate-limited: if the same (fd, rows, cols) triple was set
     * within the last 20 ms, the stty subprocess is skipped. This
     * prevents a burst of resize events from spawning a dozen stty
     * processes that mostly race each other.
     *
     * Fails closed: a dup or spawn failure returns non-zero and
     * {@see setSizeViaLibc()} surfaces the original ioctl rc unchanged
     * — a genuinely broken fd cannot be laundered into a success here.
     *
     * @see SttyTermios::runStty() for the same spawn pattern
     */
    private static function sttySetSize(\FFI $libc, int $fd, int $rows, int $cols): int
    {
        // Rate-limit: skip redundant stty calls within 20 ms window.
        // Uses a static cache per (fd, rows, cols) triple.
        static $lastCall = null;
        $now = \hrtime(true);
        if ($lastCall !== null
            && $lastCall['fd'] === $fd
            && $lastCall['rows'] === $rows
            && $lastCall['cols'] === $cols
            && ($now - $lastCall['when']) < 20_000_000  // 20 ms in nanoseconds
        ) {
            return 0;  // skip — recent identical call already applied
        }
        $lastCall = ['fd' => $fd, 'rows' => $rows, 'cols' => $cols, 'when' => $now];

        $rc = self::withDupAlias($libc, $fd, static function (int $dup) use ($rows, $cols): int {
            $cmd = ['stty', '-f', '/dev/fd/' . $dup, 'rows', (string) $rows, 'cols', (string) $cols];
            $desc = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = \proc_open($cmd, $desc, $pipes);

            if (!\is_resource($proc)) {
                return -1;
            }

            \fclose($pipes[0]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);

            return \proc_close($proc);
        });

        return $rc ?? -1;
    }

    /**
     * Read the winsize from `$fd` into the provided buffer. Returns
     * 0 on success, libc's rc on failure. Linux routes straight
     * through `ioctl(TIOCGWINSZ)`. Darwin tries the same and falls
     * back to `stty(1)` — the mirror of {@see setSizeViaLibc()}.
     *
     * ## WHY THE READ DIRECTION NOW NEEDS THE SAME FALLBACK AS THE WRITE
     *
     * This method used to cite an empirical claim ([gotcha:
     * ioctl-read-vs-write-variadic]) that only the WRITE direction
     * suffers the fixed-arg-cdef-vs-variadic-ABI mismatch on arm64
     * Darwin and that `TIOCGWINSZ` reads came back fine. That claim is
     * now DISPROVED by measurement: CI run 33796495350, job
     * 100785492531 (macos-15-arm64, PHP 8.3.33) failed with
     * `TIOCGWINSZ ioctl failed on fd 8 with rc=-1` on a slave
     * descriptor that `posix_isatty()` had just accepted. The register
     * frame our fixed-arg cdef emits does not survive the kernel's
     * variadic `ioctl(int, unsigned long, ...)` wrapper in EITHER
     * direction on that platform; every Linux/x86-64 lane and the
     * Intel hosts pass because there the third argument lands where
     * the callee reads it regardless of the frame flavour.
     *
     * Why the fallback rather than a better cdef:
     * - Retyping `void *arg` as `struct winsize *arg` changes nothing —
     *   pointee type does not move a pointer argument; the mismatch
     *   lives in the fixed-vs-variadic call frame, not in the C type.
     * - `tcgetwinsize(2)` would be the clean non-variadic answer, but
     *   macOS 15 libSystem does not ship it and `FFI::cdef()` resolves
     *   every declared symbol eagerly — the whole libc handle would
     *   fail to load. See [gotcha:macos-tcsetwinsize-missing]
     *   (PR #475 CI, same evidence as `tcsetwinsize`).
     *
     * Unlike the SET fallback's 20 ms rate limit this read is NOT
     * cached: a size query is ground truth refreshed on every SIGWINCH
     * and a read after a resize must observe the NEW geometry (pinned
     * by `SizeIoctlExtendedTest::testSetSizeViaLibcRoundTrip`). The
     * subprocess therefore only ever runs where the answer used to be
     * an exception.
     *
     * @param \FFI $libc the same handle the ioctl is attempted with
     * @param \FFI\CData $ws a 4-field winsize buffer (`emptyBuffer()`
     *                       shape); the stty fallback fills rows/cols
     *                       and zeroes the two pixel fields (stty does
     *                       not report them; `pack()`'s doc-block
     *                       records that size-aware programs never
     *                       read them)
     */
    public static function getSizeViaLibc(\FFI $libc, int $fd, \FFI\CData $ws): int
    {
        $rc = $libc->ioctl($fd, self::getRequest(), $ws);

        if ($rc !== 0 && \PHP_OS_FAMILY === 'Darwin') {
            if (self::sttyGetSize($libc, $fd, $ws)) {
                return 0;
            }
        }

        return $rc;
    }

    /**
     * Shell-out to stty(1) to read the terminal size on Darwin.
     *
     * Uses `stty -f /dev/fd/<fd> -a`; macOS' stty prints the geometry
     * in BSD word order — `speed <b> baud; <rows> rows;` then
     * `<cols> columns;` — which {@see parseSttySize()} pins.
     *
     * The descriptor reaches the child through {@see withDupAlias()} —
     * the pty master is FD_CLOEXEC'd, so the raw fd would name nothing
     * in the child's table; that helper is the shared, single-alias
     * implementation for both stty fallback directions.
     *
     * Fails closed: every error path (dup, spawn, exit status,
     * unparsable reading) returns false and leaves `$ws` untouched, so
     * callers surface the ioctl's own rc exactly as before this
     * fallback existed — a genuinely broken fd cannot be laundered
     * into a success here.
     *
     * @see sttySetSize() for the same `/dev/fd` convention in the write
     *      direction, sharing the same alias helper
     */
    private static function sttyGetSize(\FFI $libc, int $fd, \FFI\CData $ws): bool
    {
        $reading = self::withDupAlias($libc, $fd, static function (int $dup): ?string {
            $cmd = ['stty', '-f', '/dev/fd/' . $dup, '-a'];
            $desc = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = \proc_open($cmd, $desc, $pipes);

            if (!\is_resource($proc)) {
                return null;
            }

            \fclose($pipes[0]);
            // stdout fully read BEFORE proc_close: stty -a is a few
            // hundred bytes, far under the pipe buffer, so the read
            // cannot deadlock against a not-yet-reaped child.
            $reading = (string) \stream_get_contents($pipes[1]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);

            if (\proc_close($proc) !== 0) {
                return null;
            }

            return $reading;
        });

        return $reading !== null && self::parseSttySize($reading, $ws);
    }

    /**
     * Parse a BSD stty(1) `-a` reading's geometry into a winsize
     * buffer. True when both numbers were present, false — with the
     * buffer untouched — otherwise.
     *
     * Kept separate from {@see sttyGetSize()} so the macOS transcript
     * shape is pinned on any host, and anchored to the BSD word order
     * (`43 rows`, not GNU's `rows 43`): a GNU-shaped reading must NOT
     * parse, so the Darwin lane can never be silently satisfied by a
     * GNU `stty` on PATH.
     */
    private static function parseSttySize(string $reading, \FFI\CData $ws): bool
    {
        if (\preg_match('/(\d+) rows/', $reading, $rows) !== 1
            || \preg_match('/(\d+) columns/', $reading, $cols) !== 1
        ) {
            return false;
        }

        $ws[0] = (int) $rows[1];
        $ws[1] = (int) $cols[1];
        // stty(1) reports no pixel geometry. Zeros match pack()'s
        // stance that size-aware programs never read these fields —
        // and overwrite anything the caller's buffer held before.
        $ws[2] = 0;
        $ws[3] = 0;

        return true;
    }

    private function __construct() {}
}
