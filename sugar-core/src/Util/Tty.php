<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Pty\Contract\Termios;

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

    /**
     * @param resource|null $stream  defaults to STDIN
     * @param Termios|null  $termios optional pre-built Termios; passed
     *                               through to {@see Tty\PosixBackend}
     *                               for raw-mode setup. Test seam — see
     *                               {@see \SugarCraft\Core\ProgramOptions::$termios}.
     */
    public function __construct($stream = null, ?Termios $termios = null)
    {
        $this->backend = self::backend($stream ?? STDIN, $termios);
    }

    /**
     * The single backend-selection truth (finding sugar-core-056).
     *
     * WHAT THIS USED TO BE, AND WHY IT CHANGED: there were TWO copies of the
     * decision — this class's `backend()` checked `DIRECTORY_SEPARATOR`
     * FIRST (so native Windows PHP launched from inside Git-Bash, which sets
     * MSYSTEM but is a '\\' process, got a WindowsBackend INSTANCE), while
     * `concreteBackendClass()` checked the WSL/Mintty/Cygwin env probes FIRST
     * (so the same process got PosixBackend for every STATIC call — openTty,
     * restoreLast, onResize, drainSignals). Two APIs, two truths, one
     * terminal. The env-first order is the documented intent (see "Platform
     * detection order" above), and PosixBackend::size()'s arms comment already
     * described exactly that shape ("Tty picks this backend there whenever
     * MSYSTEM is set, i.e. under Git Bash"). The duplicated arm also carried
     * a dead code path: the second separator-equality return was
     * unreachable — reaching it required NOT isWsl (arms 1/2 disagree only
     * when a probe is true) while the first arm already returned
     * WindowsBackend for every '\\' process whose isWsl was false. P2-verified
     * dead, then deleted with this rationale (sc-40).
     *
     * Both paths now derive their booleans from the environment and hand them
     * to {@see decideBackendClass()}, which carries the order law. That keeps
     * the order testable on any host: the matrix in
     * {@see \SugarCraft\Core\Tests\Util\Tty\BackendSelectionTest} drives the
     * divergent shape (native Windows + mintty) without a Windows box.
     *
     * @param resource $stream
     */
    private static function backend($stream, ?Termios $termios = null): \SugarCraft\Core\Util\Tty\Backend
    {
        $class = self::decideBackendClass(
            DIRECTORY_SEPARATOR === '\\',
            Tty\EnvDetect::isWsl(),
            Tty\EnvDetect::isMintty(),
            Tty\EnvDetect::isCygwin(),
        );

        // WindowsBackend does not take a Termios — the sugar-pty contract is
        // POSIX-only in v1.
        if ($class === Tty\WindowsBackend::class) {
            return new Tty\WindowsBackend($stream);
        }

        return new Tty\PosixBackend($stream, $termios);
    }

    /**
     * Pure backend selection over the four detection booleans (the decision
     * function behind sc-40's single-truth fix).
     *
     * Order law: every POSIX-fronted environment (WSL, Mintty/MSYS2/Git-Bash,
     * Cygwin) beats the native-Windows separator arm, because each of them
     * can wrap a '\\' PHP process while still providing the POSIX pipe the
     * PosixBackend was written for; only an unequipped native Windows process
     * takes the FFI WindowsBackend.
     *
     * @return class-string<Tty\Backend>
     */
    private static function decideBackendClass(bool $nativeWindows, bool $wsl, bool $mintty, bool $cygwin): string
    {
        if ($wsl || $mintty || $cygwin) {
            return Tty\PosixBackend::class;
        }

        if ($nativeWindows) {
            return Tty\WindowsBackend::class;
        }

        return Tty\PosixBackend::class;
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
     *
     * Delegates to the same {@see decideBackendClass()} the instance path
     * uses — that is the whole point (finding sugar-core-056): the static
     * and instance APIs can no longer disagree about which backend owns
     * this process.
     */
    private static function concreteBackendClass(): string
    {
        return self::decideBackendClass(
            DIRECTORY_SEPARATOR === '\\',
            Tty\EnvDetect::isWsl(),
            Tty\EnvDetect::isMintty(),
            Tty\EnvDetect::isCygwin(),
        );
    }
}
