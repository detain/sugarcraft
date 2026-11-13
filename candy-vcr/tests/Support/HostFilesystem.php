<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

/**
 * Host-filesystem capabilities the safety tests lean on, asked of the real
 * host instead of assumed.
 *
 * - POSIX permission bits: Windows has none. fileperms() reads 0666 for any
 *   writable file and chmod() only toggles the read-only attribute, so an
 *   "owner-only 0600" or "read-only directory" pin has nothing to measure
 *   there. Those legs skip on Windows; everything else in the test runs.
 * - Symlinks: Windows can create them only with the privilege (admin, or
 *   Developer Mode). CI runners have it, many desktops do not, so a symlink
 *   leg skips only when planting the link actually fails.
 */
trait HostFilesystem
{
    protected static function hostHasPosixModes(): bool
    {
        return \PHP_OS_FAMILY !== 'Windows';
    }

    protected function requirePosixModes(): void
    {
        if (!self::hostHasPosixModes()) {
            $this->markTestSkipped('Windows has no POSIX permission bits: fileperms() reads 0666 and chmod() only sets read-only.');
        }
    }

    protected function plantSymlinkOrSkip(string $target, string $link): void
    {
        if (!@\symlink($target, $link)) {
            $this->markTestSkipped('This host cannot create symlinks (Windows without the symlink privilege or Developer Mode).');
        }
        $this->assertTrue(\is_link($link));
    }
}
