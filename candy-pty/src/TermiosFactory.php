<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\Termios;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Pty\Posix\SttyTermios;

/**
 * Factory that opens a Termios for the given fd.
 *
 * Tries PosixTermios (FFI) first. On Throwable or when
 * SUGARCRAFT_TERMIOS=stty is set, falls back to SttyTermios.
 *
 * Logs once via error_log when falling back.
 *
 * @see portable-pty.Termios
 */
final class TermiosFactory
{
    private const PREFERRED = 'PosixTermios';
    private const FALLBACK = 'SttyTermios';

    private static bool $loggedFallback = false;

    /** `O_RDWR` flag — value is identical on Linux and macOS. */
    public const O_RDWR = 0x0002;

    private function __construct() {}

    /**
     * Platform-specific `O_NOCTTY` flag for `posix_openpt()` / `open()`.
     *
     * Linux: 0o400 (octal), macOS: 0x20000 (hex).
     * This flag prevents the PTY from becoming the process's controlling
     * terminal when `setsid()` + `TIOCSCTTY` are called explicitly via
     * `ControllingTerminal::claim()`.
     *
     * @see ControllingTerminal::claim()
     */
    public static function oNoCtty(): int
    {
        return \PHP_OS_FAMILY === 'Darwin' ? 0x20000 : 0o400;
    }

    /**
     * Open a Termios for the given fd.
     *
     * Tries PosixTermios (FFI) first. On Throwable or when
     * SUGARCRAFT_TERMIOS=stty is set, falls back to SttyTermios.
     *
     * Logs at info level once when falling back.
     */
    public static function open(int $fd): Termios
    {
        if (\getenv('SUGARCRAFT_TERMIOS') === 'stty') {
            return new SttyTermios($fd);
        }

        try {
            return new PosixTermios($fd);
        } catch (\Throwable) {
            if (!self::$loggedFallback) {
                \error_log('[TermiosFactory] ext-ffi unavailable or failed, using stty fallback');
                self::$loggedFallback = true;
            }
            return new SttyTermios($fd);
        }
    }

    /**
     * Return which backend is in use for the given fd.
     *
     * Returns 'PosixTermios' or 'SttyTermios'.
     */
    public static function which(int $fd): string
    {
        if (\getenv('SUGARCRAFT_TERMIOS') === 'stty') {
            return self::FALLBACK;
        }

        try {
            new PosixTermios($fd);
            return self::PREFERRED;
        } catch (\Throwable) {
            return self::FALLBACK;
        }
    }
}
