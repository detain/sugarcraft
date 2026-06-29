<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\History;

use SugarCraft\Query\Admin\StatusSnapshot;

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
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new \RuntimeException("Cannot create history directory: {$dir}");
            }
        }

        $this->db = new \SQLite3($dbPath);
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
