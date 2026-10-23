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
        // (docs-review-1023 n4). Files and directories we did not create are
        // left untouched.
        $fileCreated = !\is_file($dbPath);
        $this->db = new \SQLite3($dbPath);
        if ($dirCreated) {
            @\chmod($dir, 0700);
        }
        if ($fileCreated) {
            @\chmod($dbPath, 0600);
        }
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->init();
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
        $row = $r->fetchArray();
        return (int) ($row[0] ?? 0);
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM history WHERE ts < :before'
        );
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
