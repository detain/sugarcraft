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
     * Archive day files older than keepDays (copy-only — nothing is ever
     * deleted from the data directory; the "rotating" in the class name is
     * an archive cadence, not a retention sweeper).
     *
     * Destination names key on the LOGICAL DAY embedded in the source file
     * name (mtime-day fallback for names that carry no day), never on the
     * age at the moment of archiving: a backfilled old day file re-archives
     * into the SAME destination it already owns and refreshes it, instead of
     * minting diverging copies of one day. The directory slug carries a short
     * digest of the real path, so two same-basename roots can never quietly
     * shadow each other's archive. (These names differ from the first
     * release's mtime-keyed ones: stale-format archives are not recognised,
     * so their days are re-copied once under the canonical name — deleting
     * the superseded stale files after that is safe, the append-only sources
     * still hold every byte the copies did.)
     *
     * A present destination is not a reason to skip: the day files are
     * append-only, so a source larger than its archived copy is a strict
     * superset of it, and gets re-copied.
     *
     * Copies run through a temporary file plus rename, under a non-blocking
     * exclusive lock inside the backup directory, so overlapping runs can
     * never interleave into one destination. Lock and directory exist only
     * when there is work: a no-op run must not mkdir, and a failed creation
     * must never read as "0 copied".
     *
     * @param int $keepDays number of days to keep
     * @return int number of archived copies written (new and refreshed)
     *
     * @throws \RuntimeException if the backup directory or lock file cannot
     *                          be created, another backup run holds the lock,
     *                          two distinct sources map to one destination, a
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
            sort($files);
            foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime === false || $mtime >= $cutoff) {
                    continue;
                }
                $dest = $this->destination($dir, $file, $mtime);
                $owner = $pending[$dest] ?? null;
                if ($owner !== null && $owner !== $file) {
                    throw new \RuntimeException(
                        'candy-codetime: ' . $owner . ' and ' . $file
                        . ' both archive to ' . basename($dest)
                        . ' — refusing to guess which one owns it',
                    );
                }
                $pending[$dest] = $file;
            }
        }

        // An existing archive satisfies its source only when it is at least
        // as large and at least as new: append-only means growth never
        // retracts, so a bigger or later source is a superset of the copy.
        $todo = [];
        foreach ($pending as $dest => $file) {
            if (!self::archiveSatisfies($dest, $file)) {
                $todo[] = [$file, $dest];
            }
        }

        if ($todo === []) {
            return 0;
        }

        // 0700 under a narrowed umask, before the lock or any payload exists.
        // Store owns the law; the label keeps a failure honest about which
        // directory.
        Store::ensurePrivateDir($this->backupDir, 'backup directory');

        $lockPath = $this->backupDir . '/.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('candy-codetime: could not open the backup lock file at ' . $lockPath);
        }
        Store::ensurePrivateFile($lockPath, 'backup lock file');
        if (@flock($lock, LOCK_EX | LOCK_NB) === false) {
            fclose($lock);
            throw new \RuntimeException(
                'candy-codetime: another backup run is already writing to ' . $this->backupDir
                . ' — refusing to overlap',
            );
        }

        $count = 0;
        try {
            foreach ($todo as [$file, $dest]) {
                // Copy to a temp file and rename into place: a crash mid-copy
                // leaves the previous archive intact and at most an orphan
                // .tmp behind, and no reader ever sees a half-written
                // destination. @ keeps copy()'s raw PHP Warning off an owned
                // line (CT-S20 law).
                $tmp = $dest . '.tmp';
                if (!@copy($file, $tmp)) {
                    @unlink($tmp);
                    throw new \RuntimeException("candy-codetime: failed to copy {$file} to {$dest}");
                }
                // copy() creates the destination from the process umask, NOT
                // from the source mode (measured: a 0600 source lands 0644
                // under umask 022) — tightening is a separate, mandatory
                // step, and it runs before the rename so the canonical name
                // never exists in a loose state.
                Store::ensurePrivateFile($tmp, 'archived heartbeat file');
                if (is_file($dest) && !@unlink($dest)) {
                    @unlink($tmp);
                    throw new \RuntimeException("candy-codetime: could not replace the stale archive at {$dest}");
                }
                if (!@rename($tmp, $dest)) {
                    @unlink($tmp);
                    throw new \RuntimeException("candy-codetime: could not move the archived copy into place at {$dest}");
                }
                ++$count;
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $count;
    }

    /**
     * The canonical archive destination for a source day file: directory
     * identity (basename plus a short digest of the real path, so two
     * same-basename roots can never collide) and the logical day embedded in
     * the file name — with the mtime day only as fallback for names that
     * carry no day. Keying on the day rather than the age-at-archive is what
     * lets a backfill refresh its existing copy instead of orphaning it.
     */
    private function destination(string $dir, string $file, int $mtime): string
    {
        $day = basename($file, '.jsonl');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            $day = date('Y-m-d', $mtime);
        }

        $root = realpath($dir) ?: $dir;

        return $this->backupDir . '/' . basename($dir) . '_' . substr(hash('sha256', $root), 0, 8) . '_' . $day . '.jsonl';
    }

    /**
     * Whether an existing archive already carries everything its source has:
     * at least as large and at least as new (append-only day files grow and
     * never shrink, so size is the superset predicate and mtime the
     * tie-breaker for touch-only changes).
     */
    private static function archiveSatisfies(string $dest, string $file): bool
    {
        if (!is_file($dest)) {
            return false;
        }

        $srcSize = @filesize($file);
        $dstSize = @filesize($dest);
        $srcMtime = @filemtime($file);
        $dstMtime = @filemtime($dest);
        if ($srcSize === false || $dstSize === false || $srcMtime === false || $dstMtime === false) {
            return false;
        }

        return $srcSize <= $dstSize && $srcMtime <= $dstMtime;
    }

    /**
     * Crash-safe archive    /**
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
        $all = glob($this->backupDir . '/*') ?: [];

        // The run-lock and any orphaned temp file are machinery, not
        // archives — listBackups() answers "what can I restore?".
        return array_values(array_filter(
            $all,
            static fn(string $path): bool => !str_starts_with(basename($path), '.')
                && !str_ends_with($path, '.tmp'),
        ));
    }
}
