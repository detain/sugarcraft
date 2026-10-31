<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * Host-INDEPENDENT quoting of one argument for a POSIX shell.
 *
 * WHY THIS EXISTS (C-TUI-6.a). Every bash-script builder in this package
 * quoted its payload with `escapeshellarg()` — a function whose answer
 * depends on the HOST: PHP's POSIX arm wraps the string in single quotes and
 * escapes embedded ones exactly like this class does, but its Windows arm
 * wraps in DOUBLE quotes and blanks every character the Windows shell
 * cannot carry (`%`, `!`, `"`). The receiver of these strings is always
 * bash — Git for Windows ships one — and to bash a double-quoted
 * `%PATH% !x` is not a literal, it is a corruption: cmd.exe's expansion
 * syntax survives into the script as those exact bytes, history expansion
 * eats the `!`, and the `"` boundaries let the payload escape its own
 * argument. The bug is invisible while the PHP process runs on Linux, which
 * is how a Windows tool path grew up leaning on it.
 *
 * This class carries the POSIX single-quote rule with no host read at all:
 * wrap in `'`, rewrite each embedded `'` as `'\\''` (end quote, one escaped
 * literal quote, reopen quote). On a POSIX host the output is byte-identical
 * to `escapeshellarg()` — pinned case by case in the test file, corpus
 * including `'`, `%`, `!`, `"`, tabs, newlines and multi-byte text — so
 * adopting it costs the existing Linux/macOS behaviour nothing. On Windows
 * the same bytes stay the literal text bash will see, which is the whole
 * point: `$0` of a `-c` script quoted this way cannot expand, redirect or
 * inject whatever it contains.
 *
 * NUL bytes are refused with a ValueError, mirroring PHP 8.3's own
 * `escapeshellarg()` behavior on them (measured: it throws, it does not
 * strip): a NUL inside a shell argument has no meaning past the point it
 * appears for the exec boundary, so a builder that received one is holding
 * corrupted input, and silently truncating there would turn a loud failure
 * into a quietly different command.
 */
final class PosixShellQuote
{
    /**
     * Quote $argument as ONE literal word for a POSIX shell (bash, sh).
     *
     * Byte-identical to `escapeshellarg()` on a POSIX host for every input
     * it accepts; refuses NUL exactly where it refuses NUL.
     *
     * @throws \ValueError when $argument contains a NUL byte
     */
    public static function quote(string $argument): string
    {
        if (\str_contains($argument, "\0")) {
            throw new \ValueError('PosixShellQuote::quote(): Argument #1 ($argument) must not contain any null bytes');
        }

        return "'" . \str_replace("'", "'\\''", $argument) . "'";
    }
}
