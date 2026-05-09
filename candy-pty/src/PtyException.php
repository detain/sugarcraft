<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Raised when a PTY syscall fails or the host environment cannot
 * support PTY allocation (Windows, sandboxed macOS, restricted
 * `/dev/ptmx`, etc.).
 */
final class PtyException extends \RuntimeException
{
}
