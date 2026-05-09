<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Readonly handle to an opened master PTY.
 *
 * The {@see $fd} integer is the libc file descriptor returned by
 * `posix_openpt()`. The {@see $slavePath} is the kernel-assigned path
 * to the slave end (typically `/dev/pts/N` on Linux,
 * `/dev/ttysNN` on macOS) — children attach their stdio there.
 *
 * Closing is explicit and idempotent. After {@see close()} returns,
 * subsequent calls are no-ops; the {@see $fd} value still holds its
 * original integer but no longer references a live kernel resource.
 *
 * Mirrors charmbracelet/x/xpty.UnixPty (the master half).
 */
final class Master
{
    private bool $closed = false;

    public function __construct(
        public readonly int $fd,
        public readonly string $slavePath,
    ) {}

    /**
     * True once {@see close()} has been called.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Close the master file descriptor. Idempotent.
     *
     * @throws PtyException if `close(2)` returns non-zero on the
     *                      first call. Subsequent calls swallow.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $rc = Libc::lib()->close($this->fd);
        if ($rc !== 0) {
            throw new PtyException(
                "close(master_fd={$this->fd}) failed (rc={$rc})"
            );
        }
    }
}
