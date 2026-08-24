<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Environment detection for MSYS2 / Mintty / Git-Bash / WSL.
 *
 * These environments run PHP under a Unix-like layer on Windows but
 * expose a POSIX API via MSYS2.  Detecting them allows the Tty façade
 * to dispatch to the correct backend:
 *
 * - WSL (Windows Subsystem for Linux): Linux ELF binary, use POSIX backend.
 * - Mintty / MSYS2 / Git-Bash: Unix-likePTY pipe, use POSIX backend.
 * - Native Windows PHP: no POSIX, use WindowsBackend.
 *
 * Detection order matters (see {@see \SugarCraft\Core\Util\Tty::backend()}):
 *
 * 1. WSL must be checked before native Windows (WSL_INTEROP is set even
 *    when /proc/sys/kernel/osrelease doesn't mention "microsoft").
 * 2. Mintty env vars must be checked before `stream_isatty()` — mintty
 *    uses pipe stdin so `isatty(fileno(stdin))` returns false.
 *
 * @see https://www.msys2.org/docs/environment/
 * @see https://github.com/microsoft/WSL/issues/4235
 */
final class EnvDetect
{
    /**
     * True when PHP is running inside WSL (Linux ELF binary on Windows).
     *
     * Detection heuristics (checked in order):
     * - `$WSL_INTEROP` env var (most reliable — set by WSL itself).
     * - `/proc/sys/kernel/osrelease` contains `microsoft` (WSL1).
     * - `/proc/sys/kernel/osrelease` contains `WSL`   (WSL2).
     */
    public static function isWsl(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // WSL_INTEROP is set by WSL for every process it launches.
        if (getenv('WSL_INTEROP') !== false) {
            return $cached = true;
        }

        // WSL2 sets WSL_ENV: WSL_INTEROP,WSL_DISTRO_NAME,WSL_HOSTU
        if (getenv('WSL_DISTRO_NAME') !== false) {
            return $cached = true;
        }

        // Fallback: check /proc/sys/kernel/osrelease for WSL signatures.
        $release = @file_get_contents('/proc/sys/kernel/osrelease');
        if (is_string($release)) {
            if (stripos($release, 'microsoft') !== false
                || stripos($release, 'WSL') !== false
            ) {
                return $cached = true;
            }
        }

        return $cached = false;
    }

    /**
     * True when PHP is running under Mintty, MSYS2, or Git-Bash.
     *
     * Mintty sets one or more of these env vars:
     * - `MSYSTEM` — set by MSYS2 / MinGW environments.
     * - `MINTTY_SHORTCUT` — exclusively set by mintty launcher.
     * - `TERM_PROGRAM=mintty` — recognised by many tools as a mintty session.
     *
     * We also check `OSTYPE=cygwin` for legacy Cygwin environments (these
     * also use a Unix-like PTY pipe and should use the POSIX backend).
     */
    public static function isMintty(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // MSYSTEM is the primary env var set by MSYS2 / MinGW / Git-Bash.
        if (getenv('MSYSTEM') !== false) {
            return $cached = true;
        }
        if (getenv('MINTTY_SHORTCUT') !== false) {
            return $cached = true;
        }
        if (getenv('TERM_PROGRAM') === 'mintty') {
            return $cached = true;
        }

        return $cached = false;
    }

    /**
     * True when running inside a Cygwin environment.
     *
     * Cygwin sets `OSTYPE=cygwin`.  Like Mintty, Cygwin provides a
     * POSIX-like environment with `/dev/tty` and `stty` available.
     */
    public static function isCygwin(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        return $cached = getenv('OSTYPE') === 'cygwin';
    }

    /**
     * True when the native Windows PHP process is attached to a
     * console (not redirected / piped).
     *
     * On native Windows PHP `stream_isatty()` on descriptor 0 is the
     * primary check, and {@see WindowsBackend::isTty()} additionally
     * validates that the console handle is not `INVALID_HANDLE_VALUE`
     * via {@see Kernel32}.
     *
     * ## DORMANT ON PURPOSE — do not wire it, and do not delete it
     *
     * WHAT THIS DOC-BLOCK USED TO SAY: "The Tty façade also validates
     * the console handle …", written in a voice that reads as though the
     * façade calls this method on its way there.
     *
     * WHAT IS TRUE NOW: it has no caller anywhere.  Verified by symbol
     * across this package's `src`, and across `src`/`bin` of every
     * sibling reachable from `sugar-crush`'s vendor closure: the only
     * occurrence of the name is this declaration.  {@see \SugarCraft\Core\Util\Tty::backend()}
     * and `Tty::concreteBackendClass()` reach {@see isWsl()},
     * {@see isMintty()} and {@see isCygwin()} out of this same class and
     * never this one, and {@see WindowsBackend::isTty()} performs its own
     * `stream_isatty($this->stream)`.
     *
     * WHY IT IS AN INTENTIONAL SEAM RATHER THAN A MISSING CALL, measured
     * against the tree rather than reasoned about: the single place it
     * would slot into is `WindowsBackend::isTty()`, and that method asks
     * about `$this->stream` — the stream the backend was CONSTRUCTED
     * with, via `WindowsBackend::__construct($stream, $kernel32)`.  This
     * method hard-codes the process-wide standard input.  Wiring it would
     * silently discard the injected stream, which is the seam that
     * constructor exists to provide and which the backend's own tests
     * drive.  So the correct native-Windows console probe is not "call
     * this from isTty()"; it is "give this a stream parameter first" — a
     * signature change nobody has needed, because no Windows runner
     * executes these suites (`scripts/affected-libs.php` decides that).
     * The method stays as the declaration of intent for whoever takes the
     * Windows console path up.
     *
     * WHY THE GUARD BELOW IS NOT DECORATION: this is a member of the
     * closed-descriptor-0 family documented on {@see \SugarCraft\Core\Util\TtyDetect}.
     * Unguarded, this body THROWS rather than answering when descriptor 0
     * has been closed — measured, PHP 8.3.6, `TypeError: stream_isatty():
     * supplied resource is not a valid stream resource`, which the `@`
     * operator cannot suppress because it is thrown rather than raised.
     * Guarding a dormant method is the cheap half of the no-removal rule:
     * whoever wires it later inherits a method that cannot introduce a
     * throw, instead of one that can.  The `defined()` half covers the
     * non-CLI SAPIs where the constant is never created at all — this
     * method is the Windows-console probe, and a native Windows PHP
     * process is not guaranteed to be running the CLI SAPI.
     */
    public static function isConsoleStdin(): bool
    {
        if (!\defined('STDIN') || !\is_resource(\STDIN)) {
            return false;
        }

        return stream_isatty(\STDIN);
    }
}
