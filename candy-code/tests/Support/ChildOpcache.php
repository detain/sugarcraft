<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

/**
 * `-d` arguments that let a spawned `php` child reuse the scripts an earlier
 * child of the same test process already compiled.
 *
 * WHY. A CLI `php` keeps opcache in shared memory that dies with the process,
 * so every `bin/candy-code` child this suite spawns compiles the ~430 files a
 * launch loads from nothing. Measured on the dev box (PHP 8.3, 2026-11-21,
 * empty TMPDIR): an offline `-p` run 830 ms -> 500 ms once the cache is warm,
 * an unknown-flag run 146 ms -> 112 ms, and BinCandyCodeDispatchTest alone
 * 50 s -> 36 s. The spawn-heavy files are a few hundred seconds of the
 * suite's total work, and at K=32 total work is what sets the wall.
 *
 * WHAT KEEPS IT FROM SERVING STALE CODE:
 * - One directory per test process, made on first use and removed when that
 *   process exits, so nothing compiled in one run is served to the next.
 *   A forked child exiting does not remove its parent's directory.
 * - The temp directories are blacklisted. Opcache revalidates by mtime in
 *   whole seconds, so a fixture script rewritten at the same path with the
 *   same mtime (a test that resets it, or a host that sets
 *   `opcache.file_update_protection=0`) could otherwise come back as its
 *   previous body. Only the checkout's own files (src/, vendor/, bin/) get
 *   cached, and those do not change during a run.
 * - `opcache.enable_cli=1`, because PHP's default is 0, which would make the
 *   file cache a silent no-op on a runner that keeps the default. A build
 *   without opcache ignores all three settings.
 *
 * Opt-in per spawn site. A child whose test reads the child's own ini, argv
 * or compile behavior should not take these.
 */
final class ChildOpcache
{
    private static ?string $dir = null;

    private static bool $unavailable = false;

    /**
     * The `-d` pairs to put between PHP_BINARY and the script, or an empty
     * list when the cache directory cannot be made (the child then compiles
     * as before).
     *
     * @return list<string>
     */
    public static function iniArgs(): array
    {
        $dir = self::dir();
        if ($dir === null) {
            return [];
        }

        return [
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.file_cache=' . $dir,
            '-d', 'opcache.blacklist_filename=' . $dir . '/blacklist.txt',
        ];
    }

    /**
     * {@see iniArgs()} shell-escaped, for a spawn site that builds a string
     * command. Empty, or ends without a trailing space.
     */
    public static function iniArgsForShell(): string
    {
        return \implode(' ', \array_map('escapeshellarg', self::iniArgs()));
    }

    private static function dir(): ?string
    {
        if (self::$dir !== null || self::$unavailable) {
            return self::$dir;
        }

        $temp = \rtrim(\sys_get_temp_dir(), '/');
        $dir = $temp . '/candy_code_child_opcache_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        if (!@\mkdir($dir, 0700)) {
            self::$unavailable = true;

            return null;
        }

        // Prefix entries: every path under each temp directory is left out of
        // the cache. TMPDIR is listed too because the bootstrap points it at a
        // sandbox the children write their fixtures into.
        $prefixes = [$temp . '/'];
        $tmpdir = \getenv('TMPDIR');
        if (\is_string($tmpdir) && $tmpdir !== '' && \rtrim($tmpdir, '/') !== $temp) {
            $prefixes[] = \rtrim($tmpdir, '/') . '/';
        }
        if (@\file_put_contents($dir . '/blacklist.txt', \implode("\n", $prefixes) . "\n") === false) {
            self::remove($dir);
            self::$unavailable = true;

            return null;
        }

        $owner = \getmypid();
        \register_shutdown_function(static function () use ($dir, $owner): void {
            if (\getmypid() === $owner) {
                self::remove($dir);
            }
        });

        return self::$dir = $dir;
    }

    private static function remove(string $dir): void
    {
        if (!\is_dir($dir) || \is_link($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            ($entry->isDir() && !$entry->isLink()) ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
        }
        @\rmdir($dir);
    }
}
