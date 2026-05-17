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
 * Backend selection is controlled by `SUGARCRAFT_PTY_BACKEND`:
 * `posix-ffi` (default on POSIX), `sidecar` / `pecl` (deferred to
 * phase 12, throw now), `auto` (same as unset / default behaviour).
 *
 * Mirrors charmbracelet/x/xpty.Open()'s platform-resolution layer.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P4.1)
 */
final class PtySystemFactory
{
    private const VALID_BACKENDS = ['posix-ffi', 'sidecar', 'pecl', 'auto'];

    /**
     * Default factory entry point. Respects `SUGARCRAFT_PTY_BACKEND`
     * when set, otherwise falls back to platform-appropriate behaviour.
     *
     * @throws UnsupportedPlatformException on Windows or when an
     *     unreleased backend (`sidecar`, `pecl`) is selected.
     * @throws \InvalidArgumentException    on an unrecognised value.
     */
    public static function default(): PtySystem
    {
        $backend = \getenv('SUGARCRAFT_PTY_BACKEND');

        if ($backend === false || $backend === '' || $backend === 'auto') {
            return self::forPlatform(\PHP_OS_FAMILY);
        }

        if ($backend === 'posix-ffi') {
            return self::forPlatform(\PHP_OS_FAMILY);
        }

        if ($backend === 'sidecar' || $backend === 'pecl') {
            throw UnsupportedPlatformException::forDeferredBackend($backend);
        }

        throw new \InvalidArgumentException(\sprintf(
            'Unknown SUGARCRAFT_PTY_BACKEND value %s. Recognised values: %s.',
            \var_export($backend, true),
            \implode(', ', self::VALID_BACKENDS),
        ));
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
