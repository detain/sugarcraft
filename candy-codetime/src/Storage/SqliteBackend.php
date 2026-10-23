<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Storage;

use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Milestone;

/**
 * SQLite-backed heartbeat + milestone store.
 * Provides an alternative to the JSONL store with query capabilities, and
 * satisfies the same {@see HeartbeatReader} contract, so `dashboard` can read
 * it exactly like `push`/`export`/`gaps` already do.
 */
final class SqliteBackend implements HeartbeatReader
{
    private \SQLite3 $db;

    public function __construct(string $dbPath)
    {
        $this->db = new \SQLite3($dbPath);
        $this->db->exec('CREATE TABLE IF NOT EXISTS heartbeats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            time INTEGER NOT NULL,
            project TEXT NOT NULL,
            language TEXT NOT NULL,
            file TEXT NOT NULL,
            duration INTEGER NOT NULL DEFAULT 60,
            tags TEXT NOT NULL DEFAULT \'[]\'
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS milestones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            time INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT \'\'
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_heartbeats_time ON heartbeats(time)');

        // privacy-first: the db holds absolute paths and project names just like
        // the JSONL files, so it gets the same owner-private treatment Store
        // gives them (tighten-if-loose, throw if that fails). The -wal/-journal
        // side files are transient under the default rollback journal mode and
        // inherit the same 0700 data directory.
        $mode = @fileperms($dbPath);
        if ($mode !== false && ($mode & 0o077) !== 0 && !@chmod($dbPath, 0o600)) {
            throw new \RuntimeException(
                "candy-codetime: cannot make database file {$dbPath} owner-private",
            );
        }
    }

    public function insert(Heartbeat $hb): void
    {
        try {
            $tags = json_encode($hb->tags, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'candy-codetime: heartbeat tags could not be encoded: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $stmt = $this->db->prepare('INSERT INTO heartbeats (time, project, language, file, duration, tags) VALUES (:time, :project, :language, :file, :duration, :tags)');
        if ($stmt === false) {
            throw new \RuntimeException('candy-codetime: sqlite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':time', $hb->time);
        $stmt->bindValue(':project', $hb->project);
        $stmt->bindValue(':language', $hb->language);
        $stmt->bindValue(':file', $hb->file);
        $stmt->bindValue(':duration', $hb->duration);
        $stmt->bindValue(':tags', $tags);
        $result = $stmt->execute();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException('candy-codetime: sqlite heartbeat insert failed: ' . $this->db->lastErrorMsg());
        }
        // Finalize the result BEFORE closing its statement — closing first
        // invalidates the result object (finalize() then errors).
        $result->finalize();
        $stmt->close();
    }

    /**
     * @return list<Heartbeat>
     */
    public function query(int $fromTime, int $toTime): array
    {
        $stmt = $this->db->prepare('SELECT * FROM heartbeats WHERE time >= :from AND time <= :to ORDER BY time ASC');
        if ($stmt === false) {
            throw new \RuntimeException('candy-codetime: sqlite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':from', $fromTime);
        $stmt->bindValue(':to', $toTime);
        $result = $stmt->execute();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException('candy-codetime: sqlite heartbeat query failed: ' . $this->db->lastErrorMsg());
        }
        $rows = [];
        try {
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                $row['tags'] = json_decode((string) $row['tags'], true) ?: [];
                $rows[] = Heartbeat::fromArray($row);
            }
        } finally {
            // Statements and results are closed on every path — a leaked cursor
            // outlives the object and holds the db file.
            $result->finalize();
            $stmt->close();
        }
        return $rows;
    }

    /**
     * Day-range read shaped for the shared {@see HeartbeatReader} contract, so
     * the dashboard and any range consumer can take either storage unchanged.
     * The bounds are whole days: from-day 00:00 through to-day 23:59:59.
     *
     * @return list<Heartbeat>
     */
    public function loadRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->query(
            (int) $from->setTime(0, 0)->getTimestamp(),
            (int) $to->setTime(23, 59, 59)->getTimestamp(),
        );
    }

    /**
     * No cached reads exist over the live database, so there is nothing to
     * drop — the honest no-op the {@see HeartbeatReader} contract expects
     * (same method Store uses to re-read its day files after `r`).
     */
    public function invalidate(): void
    {
    }

    public function insertMilestone(string $name, int $time, string $description = ''): void
    {
        $stmt = $this->db->prepare('INSERT INTO milestones (name, time, description) VALUES (:name, :time, :desc)');
        if ($stmt === false) {
            throw new \RuntimeException('candy-codetime: sqlite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':time', $time);
        $stmt->bindValue(':desc', $description);
        $result = $stmt->execute();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException('candy-codetime: sqlite milestone insert failed: ' . $this->db->lastErrorMsg());
        }
        $result->finalize();
        $stmt->close();
    }

    /**
     * @return list<Milestone>
     */
    public function milestones(): array
    {
        $result = $this->db->query('SELECT name, time, description FROM milestones ORDER BY time ASC');
        if ($result === false) {
            throw new \RuntimeException('candy-codetime: sqlite milestone query failed: ' . $this->db->lastErrorMsg());
        }
        $rows = [];
        try {
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                $rows[] = new Milestone(
                    (string) $row['name'],
                    (int) $row['time'],
                    (string) $row['description'],
                );
            }
        } finally {
            $result->finalize();
        }
        return $rows;
    }
}
