<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;

/**
 * @see creack/pty.Open()
 * @see portable-pty.PtySystem
 */
final class PosixPtySystem implements PtySystem
{
    /** `O_RDWR` flag — value identical on Linux and macOS. */
    private const O_RDWR = 0x0002;

    /** `F_SETFD` — fcntl cmd to set the fd flags. Linux + macOS = 2. */
    private const F_SETFD = 2;

    /** `FD_CLOEXEC` — close-on-exec fd flag. Linux + macOS = 1. */
    private const FD_CLOEXEC = 1;

    /**
     * Open a new PTY pair and resize the master to the requested dimensions.
     *
     * The master fd is created with `FD_CLOEXEC` set so it is closed on
     * any subsequent `proc_open` in the child — without this flag the child
     * inherits the master fd, keeping the kernel's master-side refcount > 0
     * after the parent closes the master, preventing `tty_hangup()` from
     * firing (no SIGHUP for the session leader).
     *
     * On macOS an anchor slave fd is also opened and held for the master's
     * lifetime to prevent the kernel from zeroing the PTY winsize between
     * this call and the first `proc_open` that wires the child's stdio.
     *
     * @param int $cols Terminal column count (default 80).
     * @param int $rows Terminal row count (default 24).
     * @return PtyPair
     * @see creack/pty.Open()
     * @see portable-pty.PtySystem
     */
    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $libc = \SugarCraft\Pty\Libc::lib();

        $masterFd = $libc->posix_openpt(self::O_RDWR | self::oNoCtty());
        if ($masterFd < 0) {
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.posix_openpt_failed', ['rc' => $masterFd])
            );
        }

        // FD_CLOEXEC: proc_open wires fds 0-2 via descriptor spec; every
        // other parent fd inherits across fork+exec. Without this the
        // child holds an open reference to the master, the kernel's
        // master-side refcount never drops to 0 on parent close, and
        // tty_hangup() never fires (no SIGHUP for the session leader).
        $libc->fcntl($masterFd, self::F_SETFD, self::FD_CLOEXEC);

        if ($libc->grantpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.grantpt_failed', ['fd' => $masterFd])
            );
        }

        if ($libc->unlockpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.unlockpt_failed', ['fd' => $masterFd])
            );
        }

        $slavePath = self::readPtsName($libc, $masterFd);

        $master = new PosixMasterPty($masterFd, $slavePath);

        // macOS xnu requires the slave end to be open for the kernel
        // to honor TIOCSWINSZ ioctls AND it zeros the winsize again
        // whenever the slave count drops to 0. Open an anchor slave
        // fd here and HOLD it for the master's lifetime — that keeps
        // the kernel-side slave count ≥ 1 across the gap between
        // open() and the first proc_open() that opens the child's
        // slave fds, so the resize sticks. Closed in close(). Linux
        // ptmx doesn't need this AND the open()/close() round-trip
        // would change empty-PTY read semantics, so it's Darwin-only.
        if (\PHP_OS_FAMILY === 'Darwin') {
            $slaveFd = $libc->open($slavePath, self::O_RDWR | self::oNoCtty());
            if ($slaveFd >= 0) {
                // Same fork+exec leak as the master fd above — the
                // anchor only exists to keep the kernel slave-count ≥ 1
                // in the PARENT, so it must not survive into the child.
                $libc->fcntl($slaveFd, self::F_SETFD, self::FD_CLOEXEC);
                $master->attachAnchorSlaveFd($slaveFd);
            }
            try {
                $master->resize($cols, $rows);
            } catch (\SugarCraft\Pty\PtyException) {
                // Fall through; later resize calls (e.g. SlavePty::spawn
                // with controllingTerminal:true) get another chance.
            }
        }

        return new PosixPtyPair($master, $slavePath);
    }

    /**
     * @return array<string, bool>
     * @see creack/pty.Open()
     */
    public function capabilities(): array
    {
        return [
            'pty' => true,
            'termios' => true,
            'signal' => true,
        ];
    }

    /** Platform-specific `O_NOCTTY`: Linux 0o400, macOS 0x20000. */
    private static function oNoCtty(): int
    {
        return PHP_OS_FAMILY === 'Darwin' ? 0x20000 : 0o400;
    }

    /**
     * Read the slave PTY path via `ptsname_r` into a 256-byte buffer.
     */
    private static function readPtsName(\FFI $libc, int $masterFd): string
    {
        $buf = $libc->new('char[256]');
        $rc = $libc->ptsname_r($masterFd, $buf, 256);
        if ($rc !== 0) {
            $libc->close($masterFd);
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.ptsname_failed', ['fd' => $masterFd])
            );
        }
        return \FFI::string($buf);
    }
}
