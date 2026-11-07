<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session\Gc;

use PDO;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Workspace\ShadowGarbageCollector;

/**
 * Read-only orphan census for `gc analyze` (gc-cli-1106 B2).
 *
 * REPORT-ONLY by law: every number here defines B3's cleanup target
 * surface (count + bytes per kind); this class never deletes, never
 * VACUUMs, never unlinks. It reads through the handle GcInventory opened
 * (`mode=ro`) and reuses GcSessionSizes' scanners plus B1's flock probe —
 * one idiom per shape, no second copies.
 *
 * The tombstone grace law mirrors SessionStore's retention bookkeeping:
 * a deleted session leaves a session_tombstones row that lives
 * TOMBSTONE_TTL_SECONDS so late-arriving child rows still find their
 * parent's grave. Inside that window the parent is NOT-YET-ORPHANED and
 * its events/usage/import rows must not be accused; past the window the
 * tombstone itself becomes reportable residue (B3's to reap — SessionStore
 * reaps it only on the next insert, which a read-only diagnostic may not
 * trigger).
 *
 * The shadow-dir age gate borrows ShadowGarbageCollector's own interval
 * vocabulary: a dir nobody claims YET may be mid-checkpoint, so only dirs
 * older than one collection interval count as abandoned.
 *
 * B3 rider (gc purge): every accusation also has a TARGET face —
 * {@see orphanJournalPaths()}, {@see orphanRowTarget()},
 * {@see abandonedShadowDirectories()}, {@see listStaleLockPaths()},
 * {@see expiredTombstoneTarget()} — so purge deletes exactly what the
 * census counts, never a re-derived second rule set. The target faces are
 * still read-only; the DELETE statements live in the caller.
 */
final class GcOrphanCensus
{
    /**
     * Mirrors SessionStore's private TOMBSTONE_TTL_SECONDS (30 * 86400):
     * the deletion-grace window inside which a missing parent is excused.
     */
    public const TOMBSTONE_TTL_SECONDS = 2_592_000;

    public function __construct(
        private readonly PDO $connection,
        private readonly string $configDir,
    ) {
    }

    /**
     * @return array<string,array{count:int,bytes:int}>
     */
    public function census(int $now): array
    {
        return [
            'journals' => $this->orphanJournals($now),
            'events' => $this->orphanRows('session_events', $now),
            'usage_records' => $this->orphanRows('usage_records', $now),
            'session_imports' => $this->orphanRows('session_imports', $now),
            'stale_locks' => $this->staleLocks(),
            'stale_shares' => $this->staleShares($now),
            'abandoned_shadows' => $this->abandonedShadows($now),
            'expired_tombstones' => $this->expiredTombstones($now),
        ];
    }

    /**
     * Transcript journals whose session id is neither live nor inside the
     * tombstone grace window. Non-session-shaped files are never accused
     * (scanJournals' id law), and the whole scan refuses to run without
     * the sessions table (nothing to disprove).
     *
     * @return array{count:int,bytes:int}
     */
    public function orphanJournals(int $now): array
    {
        $accused = $this->orphanJournalPaths($now);
        $count = count($accused);
        $bytes = 0;

        foreach ($accused as $paths) {
            foreach ($paths as $path) {
                $bytes += (int) filesize($path);
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * The SAME accusation as {@see orphanJournals()}, as targets: accused
     * session id => its journal paths. `gc purge --clean-orphan-journals`
     * unlinks through this list, so the census and the cleanup can never
     * disagree about which file is an orphan (gc-cli-1106 B3).
     *
     * @return array<string,list<string>>
     */
    public function orphanJournalPaths(int $now): array
    {
        $journals = GcSessionSizes::scanJournalPaths($this->configDir);

        if ($journals === [] || !GcInventory::tablePresent($this->connection, 'sessions')) {
            return [];
        }

        $claimed = $this->claimedIds($now);
        $accused = [];

        foreach ($journals as $id => $paths) {
            if (isset($claimed[$id])) {
                continue;
            }

            $accused[$id] = $paths;
        }

        return $accused;
    }

    /**
     * Rows in a session-bearing table whose owner is gone without grace:
     * NULL owners count (they can never fold to anyone), owners present
     * in `sessions` are clean, and in-grace tombstones excuse the row.
     *
     * @return array{count:int,bytes:int}
     */
    private function orphanRows(string $table, int $now): array
    {
        $target = $this->orphanRowTarget($table, $now);

        if ($target === null) {
            return ['count' => 0, 'bytes' => 0];
        }

        $columns = array_values(array_intersect(
            GcInventory::columnsOf($this->connection, $table),
            GcInventory::TABLE_ROSTERS[$table],
        ));
        $terms = array_map(
            static fn (string $column): string => 'COALESCE(SUM(LENGTH("' . $column . '")), 0)',
            $columns,
        );

        $statement = $this->connection->prepare(
            'SELECT COUNT(*), ' . implode(' + ', $terms)
            . ' FROM "' . $table . '" WHERE ' . $target[0],
        );
        $statement->execute($target[1]);
        $row = $statement->fetch(PDO::FETCH_NUM);

        if ($row === false) {
            return ['count' => 0, 'bytes' => 0];
        }

        return ['count' => (int) $row[0], 'bytes' => (int) $row[1]];
    }

    /**
     * The accusation predicate for one orphan row family, with the params
     * that complete it: `gc purge --clean-orphan-events`, `--clean-orphan-usage`
     * and `--clean-imports` DELETE through the SAME fragment the census
     * counts with, so cleanup can never reap a row the diagnostic did not
     * accuse (gc-cli-1106 B3 single-source law). Returns null when the
     * family is not accusable at all (missing table, missing session_id,
     * or no sessions table to disprove against).
     *
     * @return null|array{0:string,1:list<int|string>}
     */
    public function orphanRowTarget(string $table, int $now): ?array
    {
        if (!GcInventory::tablePresent($this->connection, $table)) {
            return null;
        }

        $columns = array_values(array_intersect(
            GcInventory::columnsOf($this->connection, $table),
            GcInventory::TABLE_ROSTERS[$table],
        ));

        if ($columns === [] || !in_array('session_id', $columns, true)) {
            return null;
        }

        if (!GcInventory::tablePresent($this->connection, 'sessions')) {
            return null;
        }

        $parameters = [$now - self::TOMBSTONE_TTL_SECONDS];
        $grace = '';
        $tombstoneGrave = 'session_tombstones';

        if (
            GcInventory::tablePresent($this->connection, $tombstoneGrave)
            && in_array('deleted_at', GcInventory::columnsOf($this->connection, $tombstoneGrave), true)
        ) {
            $grace = ' AND "session_id" NOT IN (SELECT "id" FROM "' . $tombstoneGrave . '" WHERE "deleted_at" >= ?)';
        }

        return [
            '("session_id" IS NULL'
            . ' OR ("session_id" NOT IN (SELECT "id" FROM "sessions")'
            . $grace . '))',
            $grace === '' ? [] : $parameters,
        ];
    }

    /**
     * Lock inodes no flock holder answers to. The probe itself is B1's —
     * GcInventory::countLocks — this is only its stale complement.
     *
     * @return array{count:int,bytes:int}
     */
    private function staleLocks(): array
    {
        $directory = $this->configDir . '/' . SessionLock::DIRECTORY;

        if (!is_dir($directory) || is_link($directory)) {
            return ['count' => 0, 'bytes' => 0];
        }

        $locks = GcInventory::countLocks($directory);

        return [
            'count' => $locks['files'] - $locks['live'],
            'bytes' => $locks['stale_bytes'],
        ];
    }

    /**
     * The stale half as paths, from the SAME sweep {@see staleLocks()}
     * counts through: `gc purge --clean-locks` deletes exactly these,
     * re-probing each inode immediately before its unlink (a lock that
     * gains a holder mid-run is somebody's live session and must survive).
     *
     * @return list<string>
     */
    public function listStaleLockPaths(): array
    {
        $directory = $this->configDir . '/' . SessionLock::DIRECTORY;

        if (!is_dir($directory) || is_link($directory)) {
            return [];
        }

        return GcInventory::staleLockPaths($directory);
    }

    /**
     * Share rows that can never serve again: revoked or past expiry.
     * Bytes are the html payload under the shared roster accounting.
     *
     * @return array{count:int,bytes:int}
     */
    private function staleShares(int $now): array
    {
        $table = 'shares';

        if (!GcInventory::tablePresent($this->connection, $table)) {
            return ['count' => 0, 'bytes' => 0];
        }

        $columns = array_values(array_intersect(
            GcInventory::columnsOf($this->connection, $table),
            GcInventory::TABLE_ROSTERS[$table],
        ));
        $live = GcInventory::columnsOf($this->connection, $table);
        $conditions = [];

        if (in_array('revoked_at', $live, true)) {
            $conditions[] = '"revoked_at" IS NOT NULL';
        }

        if (in_array('expires_at', $live, true)) {
            $conditions[] = '("expires_at" IS NOT NULL AND "expires_at" <= ?)';
        }

        if ($conditions === [] || $columns === []) {
            return ['count' => 0, 'bytes' => 0];
        }

        $terms = array_map(
            static fn (string $column): string => 'COALESCE(SUM(LENGTH("' . $column . '")), 0)',
            $columns,
        );
        $usesExpiry = in_array('expires_at', $live, true);
        $statement = $this->connection->prepare(
            'SELECT COUNT(*), ' . implode(' + ', $terms)
            . ' FROM "' . $table . '" WHERE (' . implode(' OR ', $conditions) . ')',
        );
        $statement->execute($usesExpiry ? [$now] : []);
        $row = $statement->fetch(PDO::FETCH_NUM);

        if ($row === false) {
            return ['count' => 0, 'bytes' => 0];
        }

        return ['count' => (int) $row[0], 'bytes' => (int) $row[1]];
    }

    /**
     * Shadow dirs whose sha1 matches no live session's cwd hash AND whose
     * mtime predates one ShadowGarbageCollector interval — young unknowns
     * may be mid-checkpoint and stay unaccused.
     *
     * @return array{count:int,bytes:int}
     */
    private function abandonedShadows(int $now): array
    {
        $accused = $this->abandonedShadowDirectories($now);
        $count = count($accused);
        $bytes = 0;

        foreach ($accused as $dir) {
            $bytes += $dir['bytes'];
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * The accusation above as targets: hash => {bytes, mtime, path}. `gc
     * purge --clean-shadows` deletes exactly these directories — same
     * claim-law (a dir any live cwd hashes to is owned; a young unknown
     * may be mid-checkpoint), one walk, no second copy (gc-cli-1106 B3).
     *
     * @return array<string,array{bytes:int,mtime:int,path:string}>
     */
    public function abandonedShadowDirectories(int $now): array
    {
        $directories = GcSessionSizes::scanShadowDirectories($this->configDir);

        if ($directories === [] || !GcInventory::tablePresent($this->connection, 'sessions')) {
            return [];
        }

        $columns = GcInventory::columnsOf($this->connection, 'sessions');

        if (!in_array('cwd', $columns, true)) {
            return [];
        }

        $statement = $this->connection->prepare('SELECT DISTINCT "cwd" FROM "sessions" WHERE "cwd" IS NOT NULL AND "cwd" <> \'\'');
        $statement->execute();
        $claimed = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $cwd) {
            $claimed[GcSessionSizes::hashForCwd((string) $cwd)] = true;
        }

        $accused = [];

        foreach ($directories as $hash => $dir) {
            if (isset($claimed[$hash])) {
                continue;
            }

            if ($dir['mtime'] > $now - ShadowGarbageCollector::INTERVAL_SECONDS) {
                continue;
            }

            $accused[$hash] = $dir + [
                'path' => $this->configDir . '/' . GcSessionSizes::SHADOW_DIRECTORY_NAME . '/' . $hash,
            ];
        }

        return $accused;
    }

    /**
     * Tombstones past their grace window: the grave itself is now residue.
     * Counted here so B3 sees the reap target; never reaped by a read.
     *
     * @return array{count:int,bytes:int}
     */
    private function expiredTombstones(int $now): array
    {
        $target = $this->expiredTombstoneTarget($now);

        if ($target === null) {
            return ['count' => 0, 'bytes' => 0];
        }

        $statement = $this->connection->prepare(
            'SELECT COUNT(*), COALESCE(SUM(LENGTH("id")), 0) FROM "session_tombstones" WHERE ' . $target[0],
        );
        $statement->execute($target[1]);
        $row = $statement->fetch(PDO::FETCH_NUM);

        if ($row === false) {
            return ['count' => 0, 'bytes' => 0];
        }

        return ['count' => (int) $row[0], 'bytes' => (int) $row[1]];
    }

    /**
     * The expired-grave predicate with its params, shared by the count
     * above and `gc purge --clean-expired-tombstones` below — SessionStore
     * only reaps these opportunistically on its next write, so the census
     * names the reap and purge performs it through the same fragment.
     *
     * @return null|array{0:string,1:list<int|string>}
     */
    public function expiredTombstoneTarget(int $now): ?array
    {
        $table = 'session_tombstones';

        if (!GcInventory::tablePresent($this->connection, $table)) {
            return null;
        }

        $live = GcInventory::columnsOf($this->connection, $table);

        if (!in_array('id', $live, true) || !in_array('deleted_at', $live, true)) {
            return null;
        }

        return ['"deleted_at" < ?', [$now - self::TOMBSTONE_TTL_SECONDS]];
    }

    /**
     * Ids a row may still lawfully point at: every live session plus every
     * in-grace tombstone.
     *
     * @return array<string,true>
     */
    private function claimedIds(int $now): array
    {
        $statement = $this->connection->prepare('SELECT "id" FROM "sessions"');
        $statement->execute();
        $claimed = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $claimed[(string) $id] = true;
        }

        $grave = 'session_tombstones';

        if (
            GcInventory::tablePresent($this->connection, $grave)
            && in_array('deleted_at', GcInventory::columnsOf($this->connection, $grave), true)
        ) {
            $statement = $this->connection->prepare(
                'SELECT "id" FROM "' . $grave . '" WHERE "deleted_at" >= ?',
            );
            $statement->execute([$now - self::TOMBSTONE_TTL_SECONDS]);

            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $claimed[(string) $id] = true;
            }
        }

        return $claimed;
    }
}
