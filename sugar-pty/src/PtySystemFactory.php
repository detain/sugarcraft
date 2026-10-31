<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Exception\UnsupportedPlatformException;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Windows\ConPtySystem;
use SugarCraft\Pty\Windows\Kernel32ConPty;

/**
 * Resolves a host-appropriate {@see PtySystem} implementation. Lets
 * application code stay DI-friendly without hard-coding the backend
 * at the call site.
 *
 * Linux / macOS / BSD / Solaris → {@see PosixPtySystem}.
 * Windows → {@see ConPtySystem} (needs Windows 10 1809+ and PHP 8.4+
 * with the ffi extension; the availability refusal arrives at
 * `$system->open()`, not here).
 *
 * The factory is dependency-free — no FFI handles allocated, no
 * `/dev/ptmx` probe — so constructing it is cheap enough to call from
 * tests and bootstrappers alike. The actual PTY syscalls only run
 * when a caller invokes `$system->open()`. On Windows the ConPTY FFI
 * load is likewise deferred into `open()`, which is why this file may
 * name {@see Kernel32ConPty} only through its static `supported()`
 * probe (which gates on the OS family before touching FFI at all).
 *
 * Backend selection is controlled by `SUGARCRAFT_PTY_BACKEND`:
 * `posix-ffi` (default on POSIX), `conpty` (forces the Windows ConPTY
 * backend; on a non-Windows host it throws
 * {@see UnsupportedPlatformException} naming the probe's reason),
 * `sidecar` / `pecl` (declared but not implemented in v1 — selecting
 * either throws {@see UnsupportedPlatformException}), `auto` (same as
 * unset / default behaviour).
 *
 * Mirrors charmbracelet/x/xpty.Open()'s platform-resolution layer. 

 */
final class PtySystemFactory
{
    private const VALID_BACKENDS = ['posix-ffi', 'conpty', 'sidecar', 'pecl', 'auto'];

    /**
     * Default factory entry point. Respects `SUGARCRAFT_PTY_BACKEND`
     * when set, otherwise falls back to platform-appropriate behaviour.
     *
     * @throws UnsupportedPlatformException when an unreleased backend
     *     (`sidecar`, `pecl`) is selected, or `conpty` is selected on
     *     a host the ConPTY probe refuses.
     * @throws \InvalidArgumentException    on an unrecognised value.
     */
    public static function new(): PtySystem
    {
        $backend = \getenv('SUGARCRAFT_PTY_BACKEND');

        if ($backend === false || $backend === '' || $backend === 'auto') {
            return self::forPlatform(\PHP_OS_FAMILY);
        }

        if ($backend === 'posix-ffi') {
            return self::forPlatform(\PHP_OS_FAMILY);
        }

        if ($backend === 'conpty') {
            if (\PHP_OS_FAMILY === 'Windows') {
                return new ConPtySystem();
            }

            $reason = Kernel32ConPty::supported()
                ?? 'the availability probe found no reason, yet this host is not Windows';

            throw UnsupportedPlatformException::forConPtyUnavailable($reason);
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
     * Former name of {@see new()}, kept so existing callers (sugar-ssh-server's
     * InProcessTransport among them) keep resolving. The project's root
     * factory is `::new()`; `::default()` is reserved against.
     *
     * @deprecated use {@see new()}
     * @throws UnsupportedPlatformException
     * @throws \InvalidArgumentException
     */
    public static function default(): PtySystem
    {
        return self::new();
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
            'Windows' => new ConPtySystem(),
            default => throw UnsupportedPlatformException::forPosixOnly($platform),
        };
    }

    private function __construct() {}
}
