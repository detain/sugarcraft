<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Static utility for claiming a file descriptor as the calling
 * process's controlling terminal.
 *
 * After {@see claim()} the given fd is the session leader's ctty,
 * enabling the kernel to deliver job-control signals (SIGINT on
 * Ctrl+C, SIGWINCH on resize, SIGHUP when master closes) to the
 * process group connected to that fd.
 *
 * Mirrors charmbracelet/x/xpty.claimControllingTerminal.
 */
final class ControllingTerminal
{
    /** Linux TIOCSCTTY request code (defined in sys/ioctl.h). */
    private const TIOCSCTTY_LINUX = 0x540E;

    /** macOS TIOCSCTTY request code (_IOWR('t', 1, int) encoding). */
    private const TIOCSCTTY_DARWIN = 0x20007461;

    /**
     * Claim the given file descriptor as the controlling terminal of
     * a new session.
     *
     * Calls {@see Libc::lib()->setsid()} to create a new session with
     * the calling process as leader, then calls
     * {@see Libc::lib()->ioctl()} with TIOCSCTTY to assign the fd as
     * the session's controlling terminal.
     *
     * @param int $fd  open file descriptor (typically the slave PTY fd)
     * @throws PtyException if setsid() or ioctl(TIOCSCTTY) fails
     */
    public static function claim(int $fd): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new PtyException('ControllingTerminal is POSIX-only.');
        }

        $libc = Libc::lib();

        if ($libc->setsid() === -1) {
            throw new PtyException(
                'ControllingTerminal::claim setsid() failed (already a session leader?)',
            );
        }

        $tioCSctty = PHP_OS_FAMILY === 'Darwin'
            ? self::TIOCSCTTY_DARWIN
            : self::TIOCSCTTY_LINUX;

        // Third arg is read by the kernel as unsigned long; passing
        // PHP null renders as 0 which means "don't steal an existing
        // ctty from another session".  See [gotcha:ioctl-third-arg-ulong-not-pointer].
        if ($libc->ioctl($fd, $tioCSctty, null) !== 0) {
            throw new PtyException(
                "ControllingTerminal::claim ioctl({$fd}, TIOCSCTTY, 0) failed",
            );
        }
    }

    private function __construct() {}
}
