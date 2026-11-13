<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Backup;

use SugarCraft\CodeTime\Store;

/**
 * Rotating backup manager for JSONL data files.
 * Archives (copies) files whose mtime has aged past the keepDays threshold.
 * Scope is the per-day `*.jsonl` files; a SQLite database file living in the
 * same tree is NOT archived — the CLI says so out loud, because a byte copy of
 * a live database is a torn snapshot at best. The crash-safe remedy for sqlite
 * users is `sqlite3 <db> ".backup <dest>"` or `VACUUM INTO '<dest>'`.
 *
 * Everything this class copies lands owner-private (0700 directory, 0600
 * file), because an archive of the day files IS the privacy-sensitive payload:
 * the README promises the path map is never world-readable, and a sibling
 * directory at the umask default would break that promise with one cron tick.
 */
final class AutoBackup
{
    /** @param list<string> $dirs directories to back up */
    public function __construct(
        private readonly string $backupDir,
        private readonly array $dirs = [],
    ) {}

    /**
     * Archive data files older than keepDays (copy-only — nothing is ever
     * deleted from the data directory; the "rotating" in the class name is
     * an archive cadence, not a retention sweeper).
     *
     * The backup directory is created lazily, only when there is something to
     * put in it: a no-op run that still mkdirs leaves an empty sibling behind.
     *
     * @param int $keepDays number of days to keep
     * @return int number of files copied
     *
     * @throws \RuntimeException if the backup directory cannot be created, a
     *                          file copy fails, or an archived copy cannot be
     *                          made owner-private — a backup must never fail
     *                          silently (silent failure is undetected data
     *                          loss; silent looseness is a privacy leak).
     */
    public function rotate(int $keepDays = 30): int
    {
        $cutoff = time() - ($keepDays * 86400);

        // Collect first, create second: an empty archive run must not mint a
        // directory, and a failed creation must never read as "0 copied".
        $pending = [];
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.jsonl') ?: [];
            foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime === false) {
                    continue;
                }
                if ($mtime < $cutoff) {
                    $pending[] = [
                        $file,
                        $this->backupDir . '/' . basename($dir) . '_' . basename($file) . '.' . date('Y-m-d', $mtime),
                    ];
                }
            }
        }

        if ($pending === []) {
            return 0;
        }

        // 0700 under a narrowed umask, before any payload exists. Store owns
        // the law; the label keeps a failure honest about which directory.
        Store::ensurePrivateDir($this->backupDir, 'backup directory');

        $count = 0;
        foreach ($pending as [$file, $dest]) {
            if (is_file($dest)) {
                continue;
            }
            // Throw on a failed copy rather than silently skipping it:
            // a swallowed copy failure means an aged data file is being
            // rotated out with no archived copy — data loss.
            // CT-S20: a write-protected destination already throws below;
            // @ keeps copy()'s raw PHP Warning from doubling the owned line.
            if (!@copy($file, $dest)) {
                throw new \RuntimeException("candy-codetime: failed to copy {$file} to {$dest}");
            }
            // copy() creates the destination from the process umask, NOT from
            // the source mode (measured: a 0600 source lands 0644 under umask
            // 022) — so tightening is a separate, mandatory step.
            Store::ensurePrivateFile($dest, 'archived heartbeat file');
            ++$count;
        }

        return $count;
    }

    /**
     * @return list<string> list of backup files
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }
        return glob($this->backupDir . '/*') ?: [];
    }
}
