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

    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $libc = \SugarCraft\Pty\Libc::lib();

        $masterFd = $libc->posix_openpt(self::O_RDWR | self::oNoCtty());
        if ($masterFd < 0) {
            throw new \SugarCraft\Pty\PtyException(
                \SugarCraft\Pty\Lang::t('open.posix_openpt_failed', ['rc' => $masterFd])
            );
        }

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

        // Apply the requested initial size NOW, before any caller
        // materializes the PHP stream wrapper for the master fd. On
        // macOS xnu the TIOCSWINSZ ioctl is rejected on a master fd
        // whose PHP stream wrapper has already taken ownership — the
        // raw libc ioctl path only works on the freshly-opened
        // descriptor. Linux ptmx is lenient about this; macOS is not.
        try {
            $master->resize($cols, $rows);
        } catch (\SugarCraft\Pty\PtyException) {
            // Best-effort: a fresh PTY should always accept TIOCSWINSZ;
            // if it doesn't, fall through and let later callers retry
            // when they really need a specific size.
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
