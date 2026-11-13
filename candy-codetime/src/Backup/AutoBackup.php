<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Backup;

use SugarCraft\CodeTime\Store;

/**
 * Rotating backup manager for JSONL data files.
 * Archives (copies) files whose mtime has aged past the keepDays threshold.
 * Scope of the copy-only rotate() is the per-day `*.jsonl` files; a byte copy
 * of a live SQLite database would be a torn snapshot at best, so the database
 * gets its own door instead: archiveSqliteDatabase() runs VACUUM INTO, which
 * snapshots consistently from a live connection (the CLI's plain `backup`
 * still refuses to copy the .db and says why).
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
     * Crash-safe archive of the SQLite database via VACUUM INTO.
     *
     * Unlike copy() over a .db file — which races WAL sidecars and captures
     * whatever torn state the -wal happens to hold — VACUUM INTO reads the
     * database through a consistent snapshot and writes a standalone file
     * (no sidecars at the destination). The live database is not touched,
     * so this is archive-only: nothing is pruned, vacuumed in place, or
     * trimmed. The copy lands owner-private like every other archived
     * payload, and an existing destination second is never overwritten.
     *
     * @return string the archived file's path
     *
     * @throws \RuntimeException if sqlite3 is unavailable, the source is
     *                           missing, or the archive cannot be made.
     */
    public function archiveSqliteDatabase(string $dbPath): string
    {
        if (!extension_loaded('sqlite3')) {
            throw new \RuntimeException('candy-codetime: backup --sqlite needs the sqlite3 PHP extension, which is not loaded in this PHP');
        }
        if (!is_file($dbPath)) {
            throw new \RuntimeException("candy-codetime: no sqlite database at {$dbPath} — nothing to archive");
        }

        Store::ensurePrivateDir($this->backupDir, 'backup directory');
        $dest = $this->backupDir . '/candy-codetime-' . date('Y-m-d-His') . '.db';

        try {
            $db = new \SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
        } catch (\Throwable $error) {
            throw new \RuntimeException('candy-codetime: could not open ' . $dbPath . ' for archiving: ' . $error->getMessage());
        }

        // @ on the exec legs: a failure already answers through lastErrorMsg
        // in the owned line below; PHP's duplicate warning stays out of the
        // user's terminal (same law as SqliteBackend's ctor).
        $stmt = @$db->prepare('VACUUM INTO ?');
        if ($stmt === false) {
            $message = $db->lastErrorMsg();
            $db->close();
            throw new \RuntimeException('candy-codetime: this SQLite build cannot run VACUUM INTO: ' . $message);
        }
        $stmt->bindValue(1, $dest, SQLITE3_TEXT);
        if (@$stmt->execute() === false) {
            $message = $db->lastErrorMsg();
            $db->close();
            throw new \RuntimeException("candy-codetime: could not archive {$dbPath} to {$dest}: " . $message);
        }
        $db->close();

        Store::ensurePrivateFile($dest, 'archived database');

        return $dest;
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
