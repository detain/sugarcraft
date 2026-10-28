<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Import;

use PDO;
use SugarCraft\Code\Support\SqliteConnect;

/**
 * The side table that remembers what was imported from where, opened on the
 * session database the way {@see \SugarCraft\Code\Share\ShareStore} opens
 * its own table — same file, same WAL, same restrictive umask dance, and
 * the same fail-safe law: a store opened read-only keeps the shape it has,
 * every reader falls to null and every writer is a no-op rather than a
 * crash.
 *
 * One row per source key. The importer consults it BEFORE minting a session
 * so a re-import of a grown transcript appends only its delta into the
 * session the first import created, instead of duplicating the history.
 */
final class SessionImportLedger
{
    private const SCHEMA = 'CREATE TABLE IF NOT EXISTS session_imports (
        source_key TEXT PRIMARY KEY,
        session_id TEXT NOT NULL,
        items INTEGER NOT NULL,
        digest TEXT NOT NULL,
        imported_at INTEGER NOT NULL
    )';

    private function __construct(
        private readonly string $dbPath,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * A ledger over the session database at $dbPath. The file is created (or
     * reused) exactly as ShareStore::atPath() does, so opening the ledger of
     * an existing store never re-permissions anything and a fresh one is
     * never briefly world-readable.
     */
    public static function atPath(string $dbPath): self
    {
        $existed = is_file($dbPath);
        $previousUmask = umask(0077);
        try {
            $pdo = SqliteConnect::open($dbPath);
            $pdo->exec('PRAGMA journal_mode=WAL');
        } finally {
            umask($previousUmask);
        }
        if (!$existed && is_file($dbPath)) {
            @chmod($dbPath, 0600);
        }

        $ledger = new self($dbPath, $pdo);
        try {
            $ledger->ensureSchema();
        } catch (\PDOException) {
            // A read-only database answers lookup()/remember() from the
            // catch arms below for its whole life; the import itself still
            // proceeds, just without delta memory.
        }

        return $ledger;
    }

    public function path(): string
    {
        return $this->dbPath;
    }

    /**
     * The stored row for one source key, or null when nothing was imported
     * from it (or the table cannot be read).
     *
     * @return null|array{session_id: string, items: int, digest: string}
     */
    public function lookup(string $sourceKey): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT session_id, items, digest FROM session_imports WHERE source_key = ?'
            );
            $stmt->execute([$sourceKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (\PDOException) {
            return null;
        }

        if (!\is_array($row)) {
            return null;
        }

        return [
            'session_id' => (string) $row['session_id'],
            'items' => (int) $row['items'],
            'digest' => (string) $row['digest'],
        ];
    }

    /**
     * Record (or refresh) the import of $sourceKey into $sessionId.
     */
    public function remember(string $sourceKey, string $sessionId, int $items, string $digest): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO session_imports (source_key, session_id, items, digest, imported_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$sourceKey, $sessionId, $items, $digest, time()]);
        } catch (\PDOException) {
            // Fail-safe: a read-only store cannot remember. The next import
            // of the same source re-creates its session rather than
            // appending blind — duplication beats corruption here.
        }
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(self::SCHEMA);
    }
}
