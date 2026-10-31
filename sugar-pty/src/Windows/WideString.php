<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\PtyException;

/**
 * UTF-8 <-> UTF-16LE conversion for the Win32 wide (`…W`) API family.
 *
 * ## Why this is its own type
 *
 * Every Windows entry point sugar-pty needs on the ConPTY path is the `W`
 * variant, and `W` means `wchar_t *` — which on Windows is UTF-16LE, always
 * two bytes per code unit, and never carries a byte-order mark. PHP strings
 * are byte buffers holding UTF-8, so each wide call has a conversion on either
 * side of it. Inlining that conversion at each call site would repeat the same
 * four failure questions twelve times over (does mbstring add a BOM, what
 * happens to a trailing half unit, which encoding is validated first), so they
 * are answered once, here.
 *
 * ## What is load-bearing about the shape
 *
 *  - {@see fromUtf8()} emits NO terminator. Callers that need a C string append
 *    {@see NUL_UNIT} — {@see terminate()} does both steps, and the split exists
 *    because `CreateProcessW`'s `lpCommandLine` is terminated while an
 *    environment block's per-entry terminator is followed by a second NUL that
 *    belongs to the block, not to the string (see {@see EnvBlock}).
 *  - Malformed UTF-8 is refused rather than replaced. mbstring's converter
 *    substitutes or drops invalid sequences silently; handing the OS a mangled
 *    path or command line is worse than not handing it one at all, so the
 *    encoding is checked first and a violation throws.
 *  - Lone surrogates are refused for the same reason: they are valid UTF-16
 *    code units and invalid Unicode, and {@see toUtf8()} cannot name a UTF-8
 *    string for one.
 *
 * Mirrors the `wchar_t` handling in sugar-core's
 * {@see \SugarCraft\Core\Util\Tty\Kernel32::toWideString()}, which builds a
 * `CData(wchar_t[])` for calls that need an owned buffer; this class is the
 * plain-string half of the same job and does not touch FFI.
 */
final class WideString
{
    /**
     * A single UTF-16LE NUL code unit — the terminator a `…W` parameter wants.
     *
     * Spelled as a two-byte literal, not as `chr(0)`, because one byte is HALF
     * a code unit and a caller who writes `"\0"` produces a string Windows
     * reads as U+0000 followed by one stray byte.
     */
    public const NUL_UNIT = "\0\0";

    /**
     * Transcode a UTF-8 string to UTF-16LE, without a terminator.
     *
     * @throws PtyException if `$utf8` is not valid UTF-8
     */
    public static function fromUtf8(string $utf8): string
    {
        if ($utf8 === '') {
            return '';
        }

        if (!\mb_check_encoding($utf8, 'UTF-8')) {
            throw new PtyException(\sprintf(
                'cannot encode a path or command line as UTF-16LE: the value is not valid UTF-8 (%d bytes)',
                \strlen($utf8),
            ));
        }

        $wide = \mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');

        // mb_convert_encoding returns false only on an unsupported encoding
        // name; 'UTF-16LE' is one this lib requires and pins. Refusing here
        // keeps a future PHP build without it from silently handing callers a
        // '' where a converted string was promised.
        if ($wide === false) {
            throw new PtyException('PHP cannot convert to UTF-16LE on this build');
        }

        return $wide;
    }

    /**
     * Transcode to UTF-16LE and append the NUL code unit `…W` parameters expect.
     */
    public static function terminate(string $utf8): string
    {
        return self::fromUtf8($utf8) . self::NUL_UNIT;
    }

    /**
     * Transcode UTF-16LE back to UTF-8, dropping one trailing NUL unit if present.
     *
     * The trailing-NUL case is not a courtesy: {@see fromUtf8()} never adds one,
     * but every length a wide API reports back (a console read, a `GetFullPathNameW`
     * buffer) is measured in code units that frequently include the terminator.
     * Only ONE unit is removed — a genuinely empty-but-terminated value arrives as
     * two units and must come back as `''`, while a value that ends in a NUL the
     * caller put there keeps it.
     *
     * @throws PtyException on an odd byte length (half a code unit — a truncated
     *         buffer) or on a lone surrogate (valid UTF-16, not valid Unicode)
     */
    public static function toUtf8(string $utf16le): string
    {
        if (($len = \strlen($utf16le)) % 2 !== 0) {
            throw new PtyException(\sprintf(
                'cannot decode UTF-16LE: %d bytes is not a whole number of 2-byte code units',
                $len,
            ));
        }

        if ($len >= 2 && \substr($utf16le, -2) === self::NUL_UNIT) {
            $utf16le = \substr($utf16le, 0, -2);
        }

        if ($utf16le === '') {
            return '';
        }

        if (self::hasLoneSurrogate($utf16le)) {
            throw new PtyException('cannot decode UTF-16LE: an unpaired surrogate code unit cannot name a UTF-8 string');
        }

        $utf8 = \mb_convert_encoding($utf16le, 'UTF-8', 'UTF-16LE');

        if ($utf8 === false) {
            throw new PtyException('PHP cannot convert from UTF-16LE on this build');
        }

        return $utf8;
    }

    /**
     * Number of UTF-16 code units the wide form of `$utf8` occupies.
     *
     * This is what `lstrlenW` counts and what `CreateProcessW`'s command-line
     * buffer sizing works in — NOT what `mb_strlen()` reports: an astral
     * character (anything above U+FFFF, an emoji among them) is one character
     * and TWO code units. A buffer sized by character count is one unit short
     * for every emoji in the string, which is the classic off-by-two of this
     * API family.
     */
    public static function codeUnits(string $utf8): int
    {
        // strlen(utf16le)/2 is the unit count; fromUtf8() has already refused
        // anything that is not valid UTF-8, so the length is trustworthy.
        return \intdiv(\strlen(self::fromUtf8($utf8)), 2);
    }

    /**
     * Byte length of the wide form of `$utf8` (two per code unit).
     */
    public static function byteLength(string $utf8): int
    {
        return self::codeUnits($utf8) * 2;
    }

    /**
     * True when `$utf16le` contains a surrogate not followed/preceded by its pair.
     *
     * Written as a code-unit walk rather than a regular expression because the
     * test is on 16-bit units, and PCRE over a raw byte string sees the second
     * byte of one unit and the first of the next as its own characters.
     */
    private static function hasLoneSurrogate(string $utf16le): bool
    {
        $units = \str_split($utf16le, 2);
        $count = \count($units);

        for ($i = 0; $i < $count; $i++) {
            $unit = \unpack('v', $units[$i])[1];

            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                $next = $units[$i + 1] ?? null;

                if ($next === null) {
                    return true;
                }

                $followed = \unpack('v', $next)[1];

                if ($followed < 0xDC00 || $followed > 0xDFFF) {
                    return true;
                }

                $i++; // The pair is consumed together.
            } elseif ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                return true; // A trailing surrogate with no lead before it.
            }
        }

        return false;
    }

    private function __construct() {}
}
