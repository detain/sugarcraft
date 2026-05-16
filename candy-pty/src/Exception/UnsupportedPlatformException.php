<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Exception;

use SugarCraft\Pty\PtyException;

/**
 * Raised when {@see \SugarCraft\Pty\PtySystemFactory::default()} can
 * not return a PTY system for the current host — currently this is
 * Windows, where the v1 candy-pty backend is POSIX-only.
 *
 * Windows ConPTY support is reserved for a v2 sidecar implementation;
 * the exception message points users at the issue tracker so they can
 * upvote / request the work without needing to read the source.
 *
 * Extends {@see PtyException} so callers that already catch the
 * generic candy-pty error type don't miss it.
 */
final class UnsupportedPlatformException extends PtyException
{
    public static function forPosixOnly(string $detectedPlatform): self
    {
        return new self(\sprintf(
            'candy-pty is POSIX-only in v1 (detected: %s). '
            . 'Windows ConPTY is reserved for v2 sidecar; '
            . 'track / request progress at https://github.com/detain/sugarcraft/issues',
            $detectedPlatform,
        ));
    }
}
