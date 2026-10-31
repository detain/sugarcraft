<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Exception;

use SugarCraft\Pty\PtyException;

/**
 * Raised when {@see \SugarCraft\Pty\PtySystemFactory::new()} can
 * not return a PTY system for the current host — currently this is
 * Windows, where the v1 sugar-pty backend is POSIX-only.
 *
 * Windows ConPTY support is reserved for a v2 sidecar implementation;
 * the exception message points users at the issue tracker so they can
 * upvote / request the work without needing to read the source.
 *
 * Extends {@see PtyException} so callers that already catch the
 * generic sugar-pty error type don't miss it.
 */
final class UnsupportedPlatformException extends PtyException
{
    public static function forPosixOnly(string $detectedPlatform): self
    {
        return new self(\sprintf(
            'sugar-pty is POSIX-only in v1 (detected: %s). '
            . 'Windows ConPTY is reserved for v2 sidecar; '
            . 'track / request progress at https://github.com/detain/sugarcraft/issues',
            $detectedPlatform,
        ));
    }

    /**
     * Raised when a backend that is not yet implemented is selected
     * via `SUGARCRAFT_PTY_BACKEND`.
     */
    public static function forDeferredBackend(string $backend): self
    {
        return new self(\sprintf(
            'SUGARCRAFT_PTY_BACKEND=%s is not implemented in v1; '
            . 'this backend is reserved for a future release. '
            . 'Use SUGARCRAFT_PTY_BACKEND=posix-ffi (default) for now.',
            $backend,
        ));
    }

    /**
     * Raised when the Windows ConPTY backend is asked for on a host where it
     * cannot work, carrying the reason the availability probe found.
     *
     * ## Why the reason is in the message and not in a code
     *
     * There are four distinct ways to land here — not Windows, PHP below the
     * FFI floor this package requires on Windows, ext-ffi absent, or a Windows
     * old enough to have no `CreatePseudoConsole` export (pre-1809) — and the
     * four have four different fixes. "Windows ConPTY is not available" tells a
     * user none of them, and the upgrade-one-thing reflex sends someone with a
     * missing extension to upgrade PHP. {@see \SugarCraft\Pty\Windows\ConPtyApi::supported()}
     * already knows which one it is; this factory is where that knowledge becomes
     * a sentence.
     *
     * The wording deliberately does NOT point at the issue tracker the way
     * {@see forPosixOnly()} does: by the time this fires, the ConPTY primitives
     * exist in this package, and the remaining work is a platform binding the
     * caller's own host is missing, not a feature request.
     */
    public static function forConPtyUnavailable(string $reason): self
    {
        return new self(\sprintf(
            'the Windows ConPTY backend is not available on this host: %s. '
            . 'ConPTY needs Windows 10 1809 or newer, PHP 8.4 or newer with the ffi '
            . 'extension, and it is not selected by SUGARCRAFT_PTY_BACKEND yet.',
            $reason,
        ));
    }
}
