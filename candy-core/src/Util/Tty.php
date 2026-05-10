<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Minimal portable TTY façade.
 *
 * This class acts as a facade over the platform-specific TTY backends:
 *
 * - **PosixBackend** — Linux, macOS, BSD; shell-out to `stty`.
 * - **WindowsBackend** — Native Windows PHP via FFI to kernel32.dll.
 *
 * The backend is selected at construction time based on the environment
 * (WSL, Mintty, native Windows).  All downstream callers (Renderer,
 * InputReader, Program) continue to use this class without any changes;
 * the backend swap is entirely internal.
 *
 * ## Platform detection order
 *
 * WSL must be detected before native Windows because WSL sets
 * `$WSL_INTEROP` but also runs a real Linux kernel — we want the POSIX
 * code path.  Mintty must be detected before `stream_isatty()` because
 * mintty uses pipe stdin so `isatty()` returns false even though a PTY
 * is present.
 *
 * @see \SugarCraft\Core\Util\Tty\PosixBackend
 * @see \SugarCraft\Core\Util\Tty\WindowsBackend
 * @see \SugarCraft\Core\Util\Tty\EnvDetect
 */
final class Tty
{
    // Re-export Backend SIGNAL_* constants so callers don't need to import both.
    public const SIGNAL_INTERRUPT = Tty\Backend::SIGNAL_INTERRUPT;
    public const SIGNAL_RESIZE    = Tty\Backend::SIGNAL_RESIZE;

    /** @var \SugarCraft\Core\Util\Tty\Backend */
    private \SugarCraft\Core\Util\Tty\Backend $backend;

    /** @param resource|null $stream defaults to STDIN */
    public function __construct($stream = null)
    {
        $this->backend = self::backend($stream ?? STDIN);
    }

    /**
     * Resolve the appropriate backend for the current process environment.
     *
     * Detection order:
     * 1. WSL  → PosixBackend (Linux ELF binary on Windows)
     * 2. Mintty / MSYS2 / Git-Bash → PosixBackend (PTY pipe)
     * 3. Cygwin → PosixBackend (POSIX-like environment)
     * 4. Native Windows (DIRECTORY_SEPARATOR === '\\') → WindowsBackend
     * 5. Everything else → PosixBackend
     */
    private static function backend($stream): \SugarCraft\Core\Util\Tty\Backend
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // WSL_INTEROP / WSL_DISTRO_NAME are set inside WSL even when
            // running Windows-side PHP via interop, but the PHP binary
            // itself is a Linux ELF so DIRECTORY_SEPARATOR would be '/'.
            // If we are here, DIRECTORY_SEPARATOR is '\\', so this IS
            // native Windows and we should use the WindowsBackend.
            if (!Tty\EnvDetect::isWsl()) {
                return new Tty\WindowsBackend($stream);
            }
        }

        // WSL, Mintty, MSYS2, Git-Bash, Cygwin — all use a POSIX-like PTY.
        if (Tty\EnvDetect::isWsl() || Tty\EnvDetect::isMintty() || Tty\EnvDetect::isCygwin()) {
            return new Tty\PosixBackend($stream);
        }

        // Native Windows PHP.
        if (DIRECTORY_SEPARATOR === '\\') {
            return new Tty\WindowsBackend($stream);
        }

        return new Tty\PosixBackend($stream);
    }

    public function isTty(): bool
    {
        return $this->backend->isTty();
    }

    /**
     * @return array{0:resource,1:resource}|null
     */
    public static function openTty(): ?array
    {
        $cls = self::concreteBackendClass();
        return $cls::openTty();
    }

    /** @return array{cols:int, rows:int} */
    public function size(): array
    {
        return $this->backend->size();
    }

    public function enableRawMode(): void
    {
        $this->backend->enableRawMode();
    }

    public function restore(): void
    {
        $this->backend->restore();
    }

    /**
     * Save the current terminal state on first call; restore it on the second.
     *
     * Designed for use by panic handlers that need to restore the TTY
     * from altscreen/raw mode without holding a Tty instance.
     *
     * First call: saves state (idempotent no-op if already saved).
     * Second call: restores the saved state and clears it.
     */
    public static function restoreLast(): void
    {
        $cls = self::concreteBackendClass();
        $cls::restoreLast();
    }

    public function __destruct()
    {
        $this->restore();
    }

    public static function onResize(\Closure $onResize): bool
    {
        $cls = self::concreteBackendClass();
        return $cls::onResize($onResize);
    }

    /**
     * @return int|false bitmask of dispatched signals (SIGNAL_INTERRUPT | SIGNAL_RESIZE)
     */
    public static function drainSignals(): int|false
    {
        $cls = self::concreteBackendClass();

        return $cls::drainSignals();
    }

    /**
     * Return the fully-qualified name of the concrete backend class for
     * the current process environment.
     */
    private static function concreteBackendClass(): string
    {
        // WSL, Mintty, MSYS2, Git-Bash, Cygwin — all use a POSIX-like PTY.
        if (Tty\EnvDetect::isWsl() || Tty\EnvDetect::isMintty() || Tty\EnvDetect::isCygwin()) {
            return Tty\PosixBackend::class;
        }
        // Native Windows PHP.
        if (DIRECTORY_SEPARATOR === '\\') {
            return Tty\WindowsBackend::class;
        }
        return Tty\PosixBackend::class;
    }
}
