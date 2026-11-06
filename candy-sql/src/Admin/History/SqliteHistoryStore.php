<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\History;

use SugarCraft\Sql\Admin\StatusSnapshot;

/**
 * SQLite-backed HistoryStore.
 *
 * Schema:
 *   CREATE TABLE history (
 *     id         INTEGER PRIMARY KEY,
 *     ts         REAL    NOT NULL,
 *     recorded_at TEXT   NOT NULL,
 *     variables  TEXT    NOT NULL
 *   );
 *   CREATE INDEX IF NOT EXISTS idx_history_ts ON history(ts);
 *
 * Uses WAL mode and a busy timeout for safe concurrent access.
 */
final class SqliteHistoryStore implements HistoryStoreInterface
{
    private \SQLite3 $db;

    public function __construct(private readonly string $dbPath)
    {
        $dir = \dirname($dbPath);
        $dirCreated = false;
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0700, true) && !\is_dir($dir)) {
                throw new \RuntimeException("Cannot create history directory: {$dir}");
            }
            $dirCreated = true;
        }

        // History rows carry server variable names and values - keep the
        // store readable only by its owner. mkdir()'s mode is umask-filtered
        // and SQLite creates the database file with the default 0644, so
        // re-assert the modes on anything this constructor just created
        // (review n4). Files and directories we did not create are
        // left untouched.
        $fileCreated = !\is_file($dbPath);
        $this->db = new \SQLite3($dbPath);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode = WAL');
        // WAL companion pragma (owner ruling): NORMAL keeps commits durable
        // into the WAL itself — only the checkpoint window could be lost —
        // trading the per-commit fsync of FULL for speed on a store that is a
        // ~1-INSERT/3s poll snapshot log, fail-soft by design, whose rows are
        // transient server-variable shapes rather than irreplaceable data.
        // This one is ours to choose: unlike journal_mode it persists nowhere
        // on disk (per-connection, like busy_timeout), and it creates no new
        // sidecar files, so the mode-settling sweep below is unaffected.
        $this->db->exec('PRAGMA synchronous = NORMAL');

        // Modes are settled AFTER the WAL pragma (D-M9, 2026-11-03): WAL
        // creates the -wal and -shm sidecars, and a chmod that ran before
        // the pragma could never see them - the main file went private
        // while its write-ahead twin stayed world-readable at the umask.
        // @-suppression is gone too: a chmod that fails means another
        // uid owns the store, and silently continuing to write server
        // variables through it is the opposite of the fail-loud law.
        if ($dirCreated) {
            self::setPrivateMode($dir, 0700, created: true);
        }
        self::setPrivateMode($dbPath, 0600, created: $fileCreated);
        foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $sidecar) {
            if (\is_file($sidecar)) {
                self::setPrivateMode($sidecar, 0600, created: true);
            }
        }

        $this->init();
    }

    /**
     * Force a private mode on the history store's own files.
     *
     * A file this constructor created must land exactly on $mode; a
     * pre-existing file is only TIGHTENED (group/world bits cleared), never
     * loosened - the store may hold server-variable values that were made
     * readable by an earlier, less careful build.
     */
    private static function setPrivateMode(string $path, int $mode, bool $created): void
    {
        \clearstatcache(true, $path);
        $perms = \fileperms($path);
        if ($perms === false) {
            return; // vanished between open and mode-set - SQLite holds its own handle
        }
        $current = $perms & 0777;
        if (!$created && ($current & 0077) === 0) {
            return; // already private - leave stranger files exactly as found
        }
        $target = $created ? $mode : ($current & ~0077);
        if ($current === $target) {
            return;
        }
        if (!\chmod($path, $target)) {
            throw new \RuntimeException(
                "Cannot secure history store file: {$path} (mode " . \substr(\sprintf('%o', $target), -4) . ')'
            );
        }
    }

    private function init(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS history (
                id         INTEGER PRIMARY KEY,
                ts         REAL    NOT NULL,
                recorded_at TEXT   NOT NULL,
                variables  TEXT    NOT NULL
            )
        ');

        $this->db->exec('
            CREATE INDEX IF NOT EXISTS idx_history_ts
            ON history(ts)
        ');
    }

    public function save(StatusSnapshot $snapshot): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO history (ts, recorded_at, variables)
             VALUES (:ts, :recorded_at, :variables)'
        );
        // Corrupt-store defense (D-M11): prepare() answers false for a file
        // SQLite cannot read as a database; the old code died inside
        // bindValue() on null, and an un-rejected promise in the admin poll
        // bricked live refresh for the whole session. Fail with a sentence.
        if ($stmt === false) {
            throw new \RuntimeException('History store is unusable: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':ts', $snapshot->ts, \SQLITE3_FLOAT);
        $stmt->bindValue(':recorded_at', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':variables', \json_encode($snapshot->variables), \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return array<StatusSnapshot>
     */
    public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        // Use format('U.u') to preserve sub-second precision; binding as TEXT
        // allows SQLite to compare the stored REAL (float) column correctly.
        $sinceEpoch = $since->format('U.u');
        $untilEpoch = $until->format('U.u');

        $stmt = $this->db->prepare(
            'SELECT ts, variables FROM history
             WHERE ts >= :since AND ts <= :until
             ORDER BY ts ASC'
        );
        if ($stmt === false) {
            throw new \RuntimeException('History store is unusable: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':since', $sinceEpoch, \SQLITE3_TEXT);
        $stmt->bindValue(':until', $untilEpoch, \SQLITE3_TEXT);

        $result = $stmt->execute();
        $snapshots = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $variables = \json_decode((string) $row['variables'], true);
            if (!\is_array($variables)) {
                continue;
            }
            $snapshots[] = new StatusSnapshot($variables, (float) $row['ts']);
        }
        $stmt->close();

        return $snapshots;
    }

    public function count(): int
    {
        $r = $this->db->query('SELECT COUNT(*) FROM history');
        if ($r === false) {
            throw new \RuntimeException('History store is unusable: ' . $this->db->lastErrorMsg());
        }
        $row = $r->fetchArray();
        return (int) ($row[0] ?? 0);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM history WHERE ts < :before'
        );
        if ($stmt === false) {
            throw new \RuntimeException('History store is unusable: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':before', $before->format('U.u'), \SQLITE3_TEXT);
        $stmt->execute();
        $changes = $this->db->changes();
        $stmt->close();

        return $changes;
    }

    public function close(): void
    {
        $this->db->close();
    }
}
