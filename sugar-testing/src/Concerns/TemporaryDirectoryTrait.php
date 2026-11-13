<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Concerns;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Testing\Lang;

/**
 * Provides temporary directory setup and cleanup for tests.
 *
 * Use this trait in test classes that need isolated temp directories.
 * The trait OWNS the `$tmpDir` property — do NOT re-declare it in the
 * consuming class (PHP fails with "already declared"). Read `$this->tmpDir`
 * after {@see setUpTemporaryDirectory()}.
 *
 * Each setup call mints a fresh, process-unique directory (pid + a random
 * component), so two test classes sharing a suffix — or a class rerun in
 * the same process — never collide on one directory, and teardown never
 * needs to "recover" a sibling class's state.
 *
 * @mixin TestCase
 */
trait TemporaryDirectoryTrait
{
    private string $tmpDir = '';

    /**
     * Override to return a label for the temp directory name.
     * E.g. 'assertions', 'tape', 'golden'. It is a prefix only — the
     * directory stays unique regardless.
     */
    protected function getTempDirSuffix(): string
    {
        return 'test';
    }

    /**
     * Create the private scratch directory at $this->tmpDir (mode 0700).
     *
     * Call from the consuming class's setUp().
     */
    protected function setUpTemporaryDirectory(): void
    {
        $suffix = $this->getTempDirSuffix();
        $base   = sys_get_temp_dir() . '/sugar-testing-' . $suffix . '-' . getmypid()
            . '-' . bin2hex(random_bytes(4));

        // '@' + is_dir rescue keeps the concurrent-creation shape warning-free.
        @mkdir($base, 0700, true);
        if (!is_dir($base)) {
            throw new RuntimeException(Lang::t('concerns.scratch_create_failed', ['path' => $base]));
        }

        $this->tmpDir = $base;
    }

    /**
     * Delete the scratch directory, never following symlinks out of it.
     *
     * Call from the consuming class's tearDown(). A planted symlink is
     * unlinked as a link — its target stays untouched. If the recorded
     * path is (or sits through a link outside) the system temp dir, the
     * wipe refuses and raises instead of deleting an innocent tree.
     */
    protected function tearDownTemporaryDirectory(): void
    {
        $dir = $this->tmpDir;
        $this->tmpDir = '';

        if ($dir === '') {
            return;
        }

        if (is_link($dir)) {
            throw new RuntimeException(Lang::t('concerns.link_detected', ['path' => $dir]));
        }

        if (!is_dir($dir)) {
            return; // already gone; nothing owed
        }

        $tempRoot = realpath(sys_get_temp_dir());
        $realDir  = realpath($dir);
        if ($tempRoot === false || $realDir === false || !str_starts_with($realDir, $tempRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(Lang::t('concerns.outside_temp', [
                'path' => $dir,
                'root' => $tempRoot ?: 'the system temp directory',
            ]));
        }

        self::removeTreeContents($realDir);
        rmdir($realDir);
    }

    /**
     * lstat-safe recursive delete: links unlink, dirs recurse, files unlink.
     */
    private static function removeTreeContents(string $dir): void
    {
        $handle = opendir($dir);
        if ($handle === false) {
            throw new RuntimeException(Lang::t('concerns.opendir_failed', ['path' => $dir]));
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $dir . '/' . $entry;

                // Order matters: is_link first, so a link-to-directory is
                // never descended into (is_dir would happily follow it).
                if (is_link($path)) {
                    unlink($path);

                    continue;
                }

                if (is_dir($path)) {
                    self::removeTreeContents($path);
                    rmdir($path);

                    continue;
                }

                unlink($path);
            }
        } finally {
            closedir($handle);
        }
    }
}
