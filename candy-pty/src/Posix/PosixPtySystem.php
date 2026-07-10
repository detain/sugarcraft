<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Concerns\LibcAccess;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;

/**
 * @see creack/pty.Open()
 * @see portable-pty.PtySystem
 */
final class PosixPtySystem implements PtySystem
{
    use LibcAccess;

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
     * On Darwin, `openpty()` is attempted first as a single-call alternative
     * to the `posix_openpt + grantpt + unlockpt + ptsname_r` quartet. If it
     * returns -1 the quartet is used as fallback.
     *
     * @param int $cols Terminal column count (default 80).
     * @param int $rows Terminal row count (default 24).
     * @return PtyPair
     * @see creack/pty.Open()
     * @see portable-pty.PtySystem
     */
    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $libc = self::libc();

        $masterFd = $this->openPtyMaster($libc);
        if ($masterFd < 0) {
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.posix_openpt_failed', [
                    'rc'    => $masterFd,
                    'errno' => \SugarCraft\Pty\Libc::errnoDetail(),
                ])
            );
        }

        // FD_CLOEXEC: proc_open wires fds 0-2 via descriptor spec; every
        // other parent fd inherits across fork+exec. Without this the
        // child holds an open reference to the master, the kernel's
        // master-side refcount never drops to 0 on parent close, and
        // tty_hangup() never fires (no SIGHUP for the session leader).
        // A failed set (rc === -1) is the EXACT bug this line exists to
        // prevent, so abort loudly (close + throw) rather than silently
        // handing back a master that will never hang up its child.
        $cloexecRc = $libc->fcntl($masterFd, self::F_SETFD, self::FD_CLOEXEC);
        if ($cloexecRc === -1) {
            $libc->close($masterFd);
        }
        self::requireCloexec($cloexecRc, $masterFd);

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
            $slaveFd = $libc->open($slavePath, \SugarCraft\Pty\TermiosFactory::O_RDWR | \SugarCraft\Pty\TermiosFactory::oNoCtty());
            if ($slaveFd >= 0) {
                // Same fork+exec leak as the master fd above — the
                // anchor only exists to keep the kernel slave-count ≥ 1
                // in the PARENT, so it must not survive into the child.
                // Unlike the master this is best-effort: a non-cloexec
                // anchor leaking into children is WORSE than no anchor,
                // so if the flag can't be set, drop the fd and skip the
                // anchor entirely. The macOS resize below is already
                // best-effort, so a missing anchor is safe.
                if ($libc->fcntl($slaveFd, self::F_SETFD, self::FD_CLOEXEC) === -1) {
                    $libc->close($slaveFd);
                    @\trigger_error(
                        'candy-pty: FD_CLOEXEC on the macOS anchor slave fd failed; '
                        . 'skipping the winsize anchor (resize remains best-effort).',
                        \E_USER_WARNING,
                    );
                } else {
                    $master->attachAnchorSlaveFd($slaveFd);
                }
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
     * Assert that an `fcntl(F_SETFD, FD_CLOEXEC)` call succeeded.
     *
     * `fcntl` returns -1 on failure. Leaving the master fd inheritable
     * is the exact bug the FD_CLOEXEC line exists to prevent — the child
     * inherits the master, the kernel's master-side refcount never drops
     * to 0, and `tty_hangup()`/SIGHUP never fires — so a failed set must
     * abort `open()` rather than continue silently. The CALLER is
     * responsible for closing `$fd` before invoking this guard.
     *
     * Extracted as a pure guard so it is unit-testable without the libc
     * singleton (`LibcAccess::libc()` has no injection seam to force
     * rc === -1 from a full `open()` round-trip).
     *
     * @throws \SugarCraft\Pty\PtyException when $rc === -1
     */
    private static function requireCloexec(int $rc, int $fd): void
    {
        if ($rc === -1) {
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.cloexec_failed', ['fd' => $fd])
            );
        }
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

    /**
     * Open the master PTY fd.
     *
     * On Darwin, `openpty()` is attempted first as a single-call alternative.
     * If it returns -1 (not available or failed), falls back to `posix_openpt`.
     */
    private function openPtyMaster(\FFI $libc): int
    {
        if (\PHP_OS_FAMILY === 'Darwin') {
            $masterFdPtr = $libc->new('int[1]');
            $slaveFdPtr = $libc->new('int[1]');

            $rc = $libc->openpty(
                \FFI::addr($masterFdPtr[0]),
                \FFI::addr($slaveFdPtr[0]),
                null,
                null,
                null,
            );

            // Discard the slave fd — we only need the master for the
            // parent; the child gets its stdio wired via proc_open.
            if ($rc === 0) {
                $libc->close($slaveFdPtr[0]);
                return $masterFdPtr[0];
            }
            // fall through to quartet on -1
        }

        return $libc->posix_openpt(\SugarCraft\Pty\TermiosFactory::O_RDWR | \SugarCraft\Pty\TermiosFactory::oNoCtty());
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
