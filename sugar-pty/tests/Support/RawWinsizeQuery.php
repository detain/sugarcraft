<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use SugarCraft\Pty\Libc;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\SizeIoctl;

/**
 * Test-side winsize read (PTY-40).
 *
 * `SizeIoctl::query()` was deleted: no src consumer ever called it, and
 * its bare RuntimeException broke the package contract of PtyException
 * surfaces. Production code composes the same answer through
 * `getSizeViaLibc()` + `unpack()` (`PosixMasterPty::size()`), so tests
 * that need a raw-fd query compose it the same way here — through the
 * @internal doors, with the package's own exception type.
 */
trait RawWinsizeQuery
{
    /**
     * @return array{rows:int, cols:int, xpix:int, ypix:int}
     * @throws PtyException if the ioctl refuses (non-tty included)
     */
    private static function queryWinsize(int $fd): array
    {
        $libc = Libc::lib();
        $ws = SizeIoctl::emptyBuffer();
        $rc = SizeIoctl::getSizeViaLibc($libc, $fd, $ws);
        if ($rc !== 0) {
            throw new PtyException('TIOCGWINSZ ioctl failed on fd ' . $fd . ' with rc=' . $rc);
        }

        return SizeIoctl::unpack($ws);
    }
}
