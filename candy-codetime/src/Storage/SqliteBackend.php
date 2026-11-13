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
    /**
     * Milliseconds a writer waits for another connection's lock before
     * failing. SQLite's default is 0, and the file's stated model is several
     * concurrent appenders (editor plugin + CLI + cron) sharing one db — with
     * no timeout every overlap surfaces as "database is locked".
     */
    private const BUSY_TIMEOUT_MS = 5000;

    private \SQLite3 $db;

    public function __construct(string $dbPath)
    {
        $this->db = new \SQLite3($dbPath);
        if (!$this->db->busyTimeout(self::BUSY_TIMEOUT_MS)) {
            throw new \RuntimeException(
                "candy-codetime: cannot set the busy timeout on database file {$dbPath}",
            );
        }
        // WAL removes the writer-blocks-reader class outright: the file's
        // model is several appenders (editor plugin + CLI + cron) sharing it
        // while a dashboard reads it, and under the rollback journal every
        // commit locked all readers out until it finished.
        @$this->db->exec('PRAGMA journal_mode = WAL');
        // WAL's companion, armed ONLY on a confirmed migration. exec() is not
        // a receipt: a refused migration (read-only filesystem, locking-
        // protocol clash) answers with the OLD mode, and synchronous=NORMAL
        // on a ROLLBACK journal is the corruption-prone combination — commits
        // acknowledge before the journal lands. Read the mode back; when it
        // is not 'wal', synchronous stays at SQLite's safe FULL default,
        // which is exactly what is correct for the journal in use.
        if (strtolower((string) @$this->db->querySingle('PRAGMA journal_mode')) === 'wal') {
            // FULL buys nothing under WAL: heartbeat telemetry that loses its
            // last transaction to a power cut costs one minute of history,
            // and every bin call-site already fail-softs a Throwable.
            @$this->db->exec('PRAGMA synchronous = NORMAL');
        }
        // A negative cache_size is kibibytes: a 64,000 KiB (62.5 MiB) page
        // cache keeps the range scans over idx_heartbeats_time in memory even
        // at full history. The database itself stays far below that, so this
        // is headroom, not pressure.
        @$this->db->exec('PRAGMA cache_size = -64000');
        // No PRAGMA foreign_keys: heartbeats and milestones are standalone
        // tables, so enabling it would be dead configuration implying
        // relations the schema declares nowhere.
        @$this->db->exec('CREATE TABLE IF NOT EXISTS heartbeats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            time INTEGER NOT NULL,
            project TEXT NOT NULL,
            language TEXT NOT NULL,
            file TEXT NOT NULL,
            duration INTEGER NOT NULL DEFAULT 60,
            tags TEXT NOT NULL DEFAULT \'[]\'
        )');
        @$this->db->exec('CREATE TABLE IF NOT EXISTS milestones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            time INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT \'\'
        )');
        @$this->db->exec('CREATE INDEX IF NOT EXISTS idx_heartbeats_time ON heartbeats(time)');

        // CT-S20: the execs above answered a corrupt or foreign .db with a
        // 7-line `PHP Warning: SQLite3::exec(): ... on line NN` stack and no
        // verdict. @ keeps PHP's diagnostic out of the user's terminal —
        // every return is still a checked shape — and this probe turns the
        // aftermath into ONE owned line at open time, where the alternative
        // was a "successful" backend whose first real query died later.
        if (@$this->db->querySingle('SELECT count(*) FROM heartbeats') === false) {
            throw new \RuntimeException(
                "candy-codetime: {$dbPath} is not a usable candy-codetime database: "
                . $this->db->lastErrorMsg(),
            );
        }

        // privacy-first: the db holds paths as supplied and project names just like
        // the JSONL files, so it gets the same owner-private treatment Store
        // gives them (tighten-if-loose, throw if that fails). Under WAL the
        // -wal/-shm sidecars are no longer transient: the -wal carries
        // committed heartbeats until checkpointed, so the sweep (which runs
        // after the journal_mode pragma, and after the CREATE execs that
        // first materialise the sidecars) covers $dbPath-wal and
        // $dbPath-shm too. A sidecar absent at open is skipped; one found
        // loose is tightened, and a failed tighten throws exactly like the
        // main file's does.
        $mode = @fileperms($dbPath);
        if ($mode !== false && ($mode & 0o077) !== 0 && !@chmod($dbPath, 0o600)) {
            throw new \RuntimeException(
                "candy-codetime: cannot make database file {$dbPath} owner-private",
            );
        }
        foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $sidecar) {
            $sidecarMode = @fileperms($sidecar);
            if ($sidecarMode !== false && ($sidecarMode & 0o077) !== 0 && !@chmod($sidecar, 0o600)) {
                throw new \RuntimeException(
                    "candy-codetime: cannot make database sidecar {$sidecar} owner-private",
                );
            }
        }
    }

    public function insert(Heartbeat $hb): void
    {
        // One law at both doors: the file lane refuses invalid UTF-8 through
        // json_encode's JSON_THROW_ON_ERROR, so the sqlite bind legs must not
        // quietly swallow the same bytes — a stored poison turns every later
        // `export json` into a fatal (JsonExporter cannot encode it).
        foreach ([$hb->project, $hb->language, $hb->file] as $text) {
            if (!\mb_check_encoding($text, 'UTF-8')) {
                throw new \RuntimeException('candy-codetime: heartbeat text is not valid UTF-8, refusing to store it');
            }
        }

        try {
            $tags = json_encode($hb->tags, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'candy-codetime: heartbeat tags could not be encoded: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $stmt = @$this->db->prepare('INSERT INTO heartbeats (time, project, language, file, duration, tags) VALUES (:time, :project, :language, :file, :duration, :tags)');
        if ($stmt === false) {
            throw new \RuntimeException('candy-codetime: sqlite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':time', $hb->time);
        $stmt->bindValue(':project', $hb->project);
        $stmt->bindValue(':language', $hb->language);
        $stmt->bindValue(':file', $hb->file);
        $stmt->bindValue(':duration', $hb->duration);
        $stmt->bindValue(':tags', $tags);
        $result = @$stmt->execute();
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
        $stmt = @$this->db->prepare('SELECT * FROM heartbeats WHERE time >= :from AND time <= :to ORDER BY time ASC');
        if ($stmt === false) {
            throw new \RuntimeException('candy-codetime: sqlite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':from', $fromTime);
        $stmt->bindValue(':to', $toTime);
        $result = @$stmt->execute();
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
        $stmt = @$this->db->prepare('INSERT INTO milestones (name, time, description) VALUES (:name, :time, :desc)');
        if ($stmt === false) {
            throw new \RuntimeException('candy-codetime: sqlite prepare failed: ' . $this->db->lastErrorMsg());
        }
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':time', $time);
        $stmt->bindValue(':desc', $description);
        $result = @$stmt->execute();
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
        $result = @$this->db->query('SELECT name, time, description FROM milestones ORDER BY time ASC');
        if ($result === false) {
            throw new \RuntimeException('candy-codetime: sqlite milestone query failed: ' . $this->db->lastErrorMsg());
        }
        $rows = [];
        try {
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                // fromArray(), not the constructor: the stored row is
                // hand-editable data and its time leg must be clamped.
                $rows[] = Milestone::fromArray($row);
            }
        } finally {
            $result->finalize();
        }
        return $rows;
    }
}
