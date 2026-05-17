<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

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

        $ws = Libc::lib()->new('unsigned short[' . self::WINSIZE_FIELDS . ']');
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
        return Libc::lib()->new('unsigned short[' . self::WINSIZE_FIELDS . ']');
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

        $libc = Libc::lib();
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
     * failure. Uses the non-variadic POSIX 2024 `tcsetwinsize` on
     * Darwin (where the real `ioctl` is variadic and the arm64 ABI
     * mismatch causes our fixed-arg cdef to send the struct pointer
     * to the wrong register); falls back to `ioctl(TIOCSWINSZ)` on
     * Linux. On Darwin, if both fail, a final `stty -f /dev/fd/<fd>`
     * shell fallback is attempted before returning the error code.
     * Centralised here so both legacy `Pty::resize()` and
     * `PosixMasterPty::resize()` get the fix transparently.
     *
     * @see SttyTermios::sttyArgs() for the Darwin `-f` flag convention
     */
    public static function setSizeViaLibc(\FFI $libc, int $fd, \FFI\CData $ws): int
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            try {
                return $libc->tcsetwinsize($fd, $ws);
            } catch (\Throwable) {
                // Fall through to ioctl if tcsetwinsize is missing
                // on the host (pre-macOS-13 libSystem).
            }
        }
        $rc = $libc->ioctl($fd, self::setRequest(), $ws);

        if ($rc !== 0 && \PHP_OS_FAMILY === 'Darwin') {
            // Darwin final fallback: stty(1) on the fd.
            $sttyRc = self::sttySetSize($fd, (int) $ws[0], (int) $ws[1]);
            if ($sttyRc === 0) {
                return 0;
            }
        }

        return $rc;
    }

    /**
     * Shell-out to stty(1) to set the terminal size on Darwin.
     *
     * Uses `stty -f /dev/fd/<fd> rows <rows> cols <cols>` per the
     * macOS convention (note `-f`, not Linux's `-F`).
     *
     * @see SttyTermios::runStty() for the same pattern
     */
    private static function sttySetSize(int $fd, int $rows, int $cols): int
    {
        $cmd = ['stty', '-f', '/dev/fd/' . $fd, 'rows', (string) $rows, 'cols', (string) $cols];
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $desc, $pipes);

        \fclose($pipes[0]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return \proc_close($proc);
    }

    /**
     * Read the winsize from `$fd` into the provided buffer. Returns
     * 0 on success, libc's rc on failure. Same Darwin-vs-Linux split
     * as {@see setSizeViaLibc()}.
     */
    public static function getSizeViaLibc(\FFI $libc, int $fd, \FFI\CData $ws): int
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            try {
                return $libc->tcgetwinsize($fd, $ws);
            } catch (\Throwable) {
            }
        }
        return $libc->ioctl($fd, self::getRequest(), $ws);
    }

    private function __construct() {}
}
