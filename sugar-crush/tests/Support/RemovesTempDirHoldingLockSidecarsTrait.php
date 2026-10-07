<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Teardown disposal for a temp directory the test lets
 * {@see \SugarCraft\Core\Util\AtomicJsonFile} write into.
 *
 * WHY. Every atomic write takes an exclusive flock on a stable sidecar named
 * `.<basename>.lock` beside the target, and candy-core 96e8fce92 deliberately
 * NEVER unlinks it — removing a lock races a writer blocked on the old inode
 * into a new one. A teardown that sweeps with `glob($dir . '/*')` therefore
 * misses the dot-prefixed sidecar (and any stray `.<name>.tmp.<hex>` payload
 * left by a failed publish), and the following `rmdir` fires a
 * "Directory not empty" PHP warning — the 18-warning shape the sharded runs
 * surfaced on the McpAuthStore/OAuth suites under repo-root cwd.
 *
 * So the sweep reads the directory whole: scandir lists dot-entries too, every
 * plain file goes, and only then does the directory itself. Nothing is
 * silenced with `@` — if a subdirectory ever appears, the plain `rmdir` says
 * so loudly.
 */
trait RemovesTempDirHoldingLockSidecarsTrait
{
    /**
     * Delete every entry of a flat temp directory — lock sidecars included —
     * then the directory itself. A missing directory is already the goal.
     */
    protected static function removeTempDirEvenWithLockSidecars(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
