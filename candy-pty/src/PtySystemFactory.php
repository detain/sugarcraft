<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\Posix\PosixPtySystem;

/**
 * Resolves a host-appropriate {@see PtySystem} implementation. Lets
 * application code stay DI-friendly without hard-coding the POSIX
 * backend at the call site.
 *
 * Linux / macOS → {@see PosixPtySystem}.
 * Windows       → {@see UnsupportedPlatformException} (v2 ConPTY).
 *
 * The factory is dependency-free — no FFI handles allocated, no
 * `/dev/ptmx` probe — so constructing it is cheap enough to call from
 * tests and bootstrappers alike. The actual PTY syscalls only run
 * when a caller invokes `$system->open()`.
 *
 * Mirrors charmbracelet/x/xpty.Open()'s platform-resolution layer.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P4.1)
 */
final class PtySystemFactory
{
    /**
     * Default factory entry point. Pick the implementation that
     * matches `PHP_OS_FAMILY`; throw {@see UnsupportedPlatformException}
     * on platforms where no backend exists.
     *
     * @throws UnsupportedPlatformException on Windows.
     */
    public static function default(): PtySystem
    {
        return self::forPlatform(\PHP_OS_FAMILY);
    }

    /**
     * Explicit-platform variant for tests: returns the implementation
     * the factory would pick for the given `$platform` string (matches
     * PHP's `PHP_OS_FAMILY` values: 'Linux', 'Darwin', 'BSD', 'Solaris',
     * 'Windows', 'Unknown').
     */
    public static function forPlatform(string $platform): PtySystem
    {
        return match ($platform) {
            'Linux', 'Darwin', 'BSD', 'Solaris' => new PosixPtySystem(),
            default => throw UnsupportedPlatformException::forPosixOnly($platform),
        };
    }

    private function __construct() {}
}
