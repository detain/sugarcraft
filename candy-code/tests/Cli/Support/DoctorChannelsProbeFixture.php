<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Filesystem scaffolding for the channel doctor rows under test.
 *
 * The doctor legs themselves never write; every fixture lands under a
 * fresh temp directory so each row can be judged deterministically, and
 * the mode helpers read back the applied permission bits so a host that
 * would silently ignore a chmod shows up as skipped, never as a lie.
 *
 * The is_readable refusal branch is deliberately NOT fixture-tested:
 * the suite runs as root on the devbox, and root reads a mode-0000
 * file happily, so no honest fixture exists for it. The branch is
 * carried as defense-in-depth and mutation-noted in the lane report.
 */
final class DoctorChannelsProbeFixture
{
    /** A private temp directory that only this test run owns. */
    public static function makeChannelProbeDir(): string
    {
        $dir = \sys_get_temp_dir() . '/doctor-' . \uniqid((string) \getmypid(), true);
        \mkdir($dir, 0700);

        return $dir;
    }

    /** Lay one file down, optionally at a strict mode, and return its path. */
    public static function writeProbeFile(string $dir, string $name, string $body, ?int $mode = null): string
    {
        $path = $dir . '/' . $name;
        \file_put_contents($path, $body);
        if (null !== $mode) {
            \chmod($path, $mode);
        }
        \clearstatcache(true, $path);

        return $path;
    }

    /** True when the on-disk permission bits are exactly what was asked for. */
    public static function probeFileModeIs(string $path, int $expected): bool
    {
        \clearstatcache(true, $path);

        return (\fileperms($path) & 0777) === $expected;
    }

    /**
     * A bundle directory holding one channel entrypoint file. The test
     * constructs the Bundle itself, so only the entry file's existence,
     * containment, and mode matter to the loader mirrors here.
     */
    public static function makeChannelProbeBundle(string $parent, string $name): string
    {
        $dir = $parent . '/' . $name;
        \mkdir($dir, 0755);
        self::writeProbeFile($dir, 'channel.php', "<?php\n", 0644);

        return $dir;
    }

    /** Remove a fixture directory and everything under it. */
    public static function purgeProbeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($dir);
    }
}
