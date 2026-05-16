<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Raised when a PTY syscall fails or the host environment cannot
 * support PTY allocation (Windows, sandboxed macOS, restricted
 * `/dev/ptmx`, etc.).
 *
 * Subclassable so callers can catch the generic `PtyException` and
 * still get specific subtypes (e.g. {@see Exception\UnsupportedPlatformException}).
 */
class PtyException extends \RuntimeException
{
}
