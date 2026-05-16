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

    private function __construct() {}
}
