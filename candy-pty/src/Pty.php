<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

/**
 * Facade for opening a master/slave PTY pair and (in later PRs)
 * spawning a child wired to it.
 *
 * PR1 surface: {@see open()} only — the rest of the public API
 * (`spawn`, `read`, `write`, `resize`, `setBlocking`) lands in
 * follow-up PRs.
 *
 * Mirrors charmbracelet/x/xpty.Open() semantics for Linux/macOS.
 *
 * @see https://github.com/charmbracelet/x/tree/main/xpty
 */
final class Pty
{
    /**
     * `O_RDWR` flag — value identical on Linux and macOS.
     */
    private const O_RDWR = 0x0002;

    /**
     * Open a fresh PTY pair and return a {@see Master} handle.
     *
     * Steps (mirrors `posix_openpt` + `grantpt` + `unlockpt` + `ptsname_r`):
     *
     * 1. `posix_openpt(O_RDWR | O_NOCTTY)` allocates a master.
     * 2. `grantpt()` adjusts the slave's permissions for the calling user.
     * 3. `unlockpt()` clears the slave's lock so a child may open it.
     * 4. `ptsname_r()` reads the kernel-assigned slave path into a buffer.
     *
     * Any failure throws {@see PtyException} after closing the master
     * fd if step 1 succeeded — callers never receive a half-open Master.
     */
    public static function open(): Master
    {
        $libc = Libc::lib();

        $masterFd = $libc->posix_openpt(self::O_RDWR | self::oNoCtty());
        if ($masterFd < 0) {
            throw new PtyException(Lang::t('open.posix_openpt_failed', ['rc' => $masterFd]));
        }

        if ($libc->grantpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.grantpt_failed', ['fd' => $masterFd]));
        }

        if ($libc->unlockpt($masterFd) !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.unlockpt_failed', ['fd' => $masterFd]));
        }

        $slavePath = self::readPtsName($libc, $masterFd);

        return new Master($masterFd, $slavePath);
    }

    /**
     * Platform-specific `O_NOCTTY` value. Linux: `0400` (0o400 = 256).
     * macOS: `0x20000`.
     */
    private static function oNoCtty(): int
    {
        return PHP_OS_FAMILY === 'Darwin' ? 0x20000 : 0o400;
    }

    /**
     * Read the slave PTY path via `ptsname_r` into a 256-byte buffer.
     *
     * 256 bytes is the de-facto cap — Linux assigns `/dev/pts/<N>`
     * (≤ 14 chars), macOS `/dev/ttysNNN` (≤ 13 chars). 256 leaves
     * generous headroom without forcing a re-call loop.
     */
    private static function readPtsName(\FFI $libc, int $masterFd): string
    {
        $buf = $libc->new('char[256]');
        $rc = $libc->ptsname_r($masterFd, $buf, 256);
        if ($rc !== 0) {
            $libc->close($masterFd);
            throw new PtyException(Lang::t('open.ptsname_failed', ['fd' => $masterFd]));
        }

        return \FFI::string($buf);
    }

    private function __construct() {}
}
