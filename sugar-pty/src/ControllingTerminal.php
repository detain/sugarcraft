<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Concerns\LibcAccess;

/**
 * Static utility for claiming a file descriptor as the calling
 * process's controlling terminal.
 *
 * After {@see claim()} the given fd is the session leader's ctty,
 * enabling the kernel to deliver job-control signals (SIGINT on
 * Ctrl+C, SIGWINCH on resize, SIGHUP when master closes) to the
 * process group connected to that fd. The SIGHUP-on-master-close
 * cascade is a ctty property: it holds only for sessions that claimed
 * one (controllingTerminal:true) — a child without a ctty never sees
 * the hangup, which is why the POSIX close paths treat the no-ctty
 * case as the leak-prone one.
 *
 * Mirrors charmbracelet/x/xpty.claimControllingTerminal.
 */
final class ControllingTerminal
{
    use LibcAccess;

    /** Linux TIOCSCTTY request code (asm-generic/ioctls.h: _IO(0x54, 14)). */
    private const TIOCSCTTY_LINUX = 0x540E;

    /** macOS TIOCSCTTY request code (_IO('t', 97) encoding). */
    private const TIOCSCTTY_DARWIN = 0x20007461;

    /**
     * FreeBSD TIOCSCTTY — _IO('t', 97) in the shared BSD `_IOC` bit
     * layout, so the wire value matches Darwin's (PTY-27).
     * UNEXERCISED until a BSD CI seat; Linux and Darwin values above
     * are measured on runners (0x540E pinned by
     * ControllingTerminalTest's live claim).
     */
    private const TIOCSCTTY_FREEBSD = 0x20007461;

    /**
     * Per-family TIOCSCTTY request number (PTY-27). The old tail was a
     * ternary: Solaris (where TIOCSCTTY is 0x40006463, a different
     * `_IO` lineage entirely) would have quietly issued the Linux or
     * macOS number. Unmapped families throw.
     *
     * @throws PtyException for unmapped families
     */
    public static function cttyRequestFor(string $family): int
    {
        return match ($family) {
            'Linux' => self::TIOCSCTTY_LINUX,
            'Darwin' => self::TIOCSCTTY_DARWIN,
            'BSD' => self::TIOCSCTTY_FREEBSD,
            default => throw new PtyException(
                Lang::t('ctty.no_request_family', ['family' => $family]),
            ),
        };
    }

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
            throw new PtyException(Lang::t('ctty.posix_only'));
        }

        $libc = self::libc();

        if ($libc->setsid() === -1) {
            // errno captured immediately — the failure surface makes
            // calls of its own that could overwrite it (PTY-32).
            throw new PtyException(Lang::t('ctty.setsid_failed', ['detail' => Libc::errnoDetail()]));
        }

        $cttyRequest = self::cttyRequestFor(\PHP_OS_FAMILY);

        // Third arg is read by the kernel as unsigned long — NOT as a
        // dereferenced pointer. Passing PHP null through FFI void *
        // renders as zero on the wire, which is EXACTLY what we want:
        // "do not steal an existing controlling terminal from another
        // session". Allocating an int and passing FFI::addr($int) with
        // $int=0 would pass a pointer-to-zero, which the kernel would
        // misinterpret. See [gotcha:ioctl-third-arg-ulong-not-pointer].
        if ($libc->ioctl($fd, $cttyRequest, null) !== 0) {
            throw new PtyException(Lang::t('ctty.ioctl_failed', ['fd' => $fd, 'detail' => Libc::errnoDetail()]));
        }
    }

    private function __construct() {}
}
