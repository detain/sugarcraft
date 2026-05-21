<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Storage;

use SugarCraft\Tick\Heartbeat;

/**
 * SQLite-backed heartbeat + milestone store.
 * Provides an alternative to the JSONL store with query capabilities.
 */
final class SqliteBackend
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
    }

    public function insert(Heartbeat $hb): void
    {
        $stmt = $this->db->prepare('INSERT INTO heartbeats (time, project, language, file, duration, tags) VALUES (:time, :project, :language, :file, :duration, :tags)');
        $stmt->bindValue(':time', $hb->time);
        $stmt->bindValue(':project', $hb->project);
        $stmt->bindValue(':language', $hb->language);
        $stmt->bindValue(':file', $hb->file);
        $stmt->bindValue(':duration', $hb->duration);
        $stmt->bindValue(':tags', json_encode($hb->tags));
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return list<Heartbeat>
     */
    public function query(int $fromTime, int $toTime): array
    {
        $stmt = $this->db->prepare('SELECT * FROM heartbeats WHERE time >= :from AND time <= :to ORDER BY time ASC');
        $stmt->bindValue(':from', $fromTime);
        $stmt->bindValue(':to', $toTime);
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $row['tags'] = json_decode((string) $row['tags'], true) ?: [];
            $rows[] = Heartbeat::fromArray($row);
        }
        return $rows;
    }

    public function insertMilestone(string $name, int $time, string $description = ''): void
    {
        $stmt = $this->db->prepare('INSERT INTO milestones (name, time, description) VALUES (:name, :time, :desc)');
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':time', $time);
        $stmt->bindValue(':desc', $description);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @return list<array{name: string, time: int, description: string}>
     */
    public function milestones(): array
    {
        $result = $this->db->query('SELECT name, time, description FROM milestones ORDER BY time ASC');
        $rows = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $rows[] = [
                'name' => (string) $row['name'],
                'time' => (int) $row['time'],
                'description' => (string) $row['description'],
            ];
        }
        return $rows;
    }
}
