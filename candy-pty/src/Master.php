<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Readonly view of an opened master PTY: the libc file descriptor
 * returned by `posix_openpt()` plus the kernel-assigned slave path
 * (`/dev/pts/N` on Linux, `/dev/ttysNN` on macOS) that children
 * attach their stdio to.
 *
 * Lifecycle (`close`, validity checks) lives on the owning {@see Pty}
 * instance — `Master` is a snapshot value, never the source of truth
 * for "is this fd still open?". Mirrors charmbracelet/x/xpty.UnixPty's
 * master half.
 */
final class Master
{
    public function __construct(
        public readonly int $fd,
        public readonly string $slavePath,
    ) {}
}
