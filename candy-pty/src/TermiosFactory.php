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
 * Every fallback is logged via error_log, numbered with a per-process
 * count and carrying the reason PosixTermios failed.
 *
 * @see portable-pty.Termios
 */
final class TermiosFactory
{
    private const PREFERRED = 'PosixTermios';
    private const FALLBACK = 'SttyTermios';

    /**
     * FFI→stty fallbacks taken in this process. It replaced a log-once
     * boolean: in a long-running process (an FPM worker, a REPL) only the
     * FIRST fallback was ever logged, so a mid-session degradation was
     * silent. Each fallback is now logged with its ordinal, which keeps the
     * events distinguishable without a per-call flood guard -- the factory
     * runs once per opened termios, not per byte.
     */
    private static int $fallbackCount = 0;

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
     * Every fallback is logged (see {@see fallbackCount()}); the forced
     * `SUGARCRAFT_TERMIOS=stty` path is a choice, not a fallback, and is
     * neither logged nor counted.
     */
    public static function open(int $fd): Termios
    {
        if (\getenv('SUGARCRAFT_TERMIOS') === 'stty') {
            return new SttyTermios($fd);
        }

        try {
            return new PosixTermios($fd);
        } catch (\Throwable $e) {
            self::$fallbackCount++;
            \error_log(\sprintf(
                '[TermiosFactory] PosixTermios unavailable for fd=%d (fallback #%d in this process): %s; using stty fallback',
                $fd,
                self::$fallbackCount,
                $e->getMessage(),
            ));
            return new SttyTermios($fd);
        }
    }

    /**
     * How many FFI→stty fallbacks {@see open()} has taken in this process.
     * A diagnostic for long-running hosts: a non-zero, growing value means
     * termios is degrading mid-session, not merely at startup.
     */
    public static function fallbackCount(): int
    {
        return self::$fallbackCount;
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
