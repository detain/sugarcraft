<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionQuery;
use SugarCraft\Code\Session\SessionSearchIndex;
use SugarCraft\Code\Session\SessionStore;

/**
 * SES-2 (plan_comparison §SES-2): the FTS5 side table behind session body
 * search, at its own level — the anchor/FTS pair, the diff-by-hash sync,
 * bm25 ranking with a snippet, the cascade and garbage paths, and the latch
 * that makes every one of those a no-op on a SQLite built without FTS5.
 *
 * C-SES-2 adds the build-marker family: the marker seeds a fresh process's
 * trust (the second store over a built database sweeps nothing), the sweep
 * runs inside the store's transaction, a lock contention degrades the search
 * without poisoning the vouch, and a version mismatch pays exactly one
 * rebuild before settling.
 *
 * The store-level half of the same law (a search that answers through
 * `withBodySearch`, and a LIKE path byte-unchanged when the latch is set)
 * lives in SessionSearchFtsTest and SessionSearchUnavailableTest.
 *
 * @see SessionSearchIndex
 */
final class SessionSearchIndexTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_ftsix_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/search.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SessionSearchIndex::observeSyncsForTesting(null);
        // Known names only: no directory walker here, so this file stays out
        // of the tree-wide walkers' rosters.
        foreach (['search.db', 'search.db-wal', 'search.db-shm', 'busy.db', 'busy.db-journal'] as $name) {
            @unlink($this->tempDir . '/' . $name);
        }
        @rmdir($this->tempDir);
    }

    private function baseStore(): SessionStore
    {
        return new SessionStore($this->dbPath);
    }

    /** @param list<string> $bodies */
    private function saveChat(EnhancedSessionStore $store, string $id, array $bodies): void
    {
        $store->createSession($id, 'p', 'm');
        $messages = [];
        foreach ($bodies as $i => $body) {
            $messages[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'content' => $body];
        }
        $store->saveTranscript($id, $messages);
    }

    /**
     * The store's own "am I inside a transaction" signal: the depth counter
     * `SessionStore::immediateTransaction()` guards nesting on. PDO's
     * `inTransaction()` cannot see a raw BEGIN on this PHP build — the very
     * hazard that counter exists for — so reading the real counter is the
     * load-bearing observation, not a detour around the brief's wording.
     */
    private function storeTransactionDepth(EnhancedSessionStore $store): int
    {
        $inner = (new \ReflectionProperty(EnhancedSessionStore::class, 'sessionStore'))->getValue($store);

        return (int) (new \ReflectionProperty(SessionStore::class, 'transactionDepth'))->getValue($inner);
    }

    /** Anchor rows as identity, not counts: a sweep moves the ids a diff keeps. */
    private function anchorRows(string $dbPath): array
    {
        return $this->barePdo($dbPath)
            ->query('SELECT id, session_id, message_id, hash FROM session_message_rows ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{version: int, dirty: int}|null */
    private function readMarkerRow(string $dbPath): ?array
    {
        $pdo = $this->barePdo($dbPath);

        $row = $pdo->query('SELECT version, dirty FROM session_search_meta WHERE rowid = 1')
            ->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? ['version' => (int) $row['version'], 'dirty' => (int) $row['dirty']]
            : null;
    }

    /**
     * A test-owned connection that FAILS LOUD and FAST: pdo_sqlite's default
     * busy timeout is 60 s of silent-mode sleeping, and a contention test
     * must measure "who waits", not watch a wall-clock nap. Production never
     * sees either: the real index rides the session store's own connection,
     * which SqliteConnect pins to a 5 s `PRAGMA busy_timeout`.
     */
    private function barePdo(string $dbPath): PDO
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 1);

        return $pdo;
    }

    public function testOpeningTheIndexLandsTheTablesAndAsksForTheFirstSweep(): void
    {
        $store = $this->baseStore();
        $index = SessionSearchIndex::create($store->getPdo());

        self::assertTrue($index->available());
        self::assertTrue($index->needsRebuild(), 'no marker on disk — a process cannot trust a table it has never seen built');

        $tables = $store->getPdo()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('session_message_rows', $tables);
        self::assertContains('session_messages_fts', $tables);
        self::assertContains('session_search_meta', $tables, 'the build marker lives beside the tables it vouches for');
    }

    public function testARankedSearchAnswersBestHitFirstWithASnippet(): void
    {
        $store = $this->baseStore();
        $store->createSession('light', 'p', 'm');
        $store->createSession('heavy', 'p', 'm');
        $index = SessionSearchIndex::create($store->getPdo());

        $index->syncMessages('light', ['m1' => 'the kubernetes cluster rolled once']);
        $index->syncMessages('heavy', [
            'm1' => 'kubernetes everywhere: kubernetes operators babysit kubernetes pods while kubernetes scales',
            'm2' => 'unrelated prose about pasta',
        ]);
        $index->markBuilt();
        self::assertFalse($index->needsRebuild());

        $ranked = $index->search('kubernetes');
        self::assertNotNull($ranked);
        self::assertSame(['heavy', 'light'], array_column($ranked ?? [], 'session_id'), 'bm25 puts the denser session first');
        self::assertStringContainsString('[kubernetes]', $ranked[0]['snippet'] ?? '');
    }

    public function testAQueryWithoutIndexableWordsCannotBeAnswered(): void
    {
        $store = $this->baseStore();
        $index = SessionSearchIndex::create($store->getPdo());

        self::assertNull($index->search('!!! ??? ...'), 'punctuation carries no prefix term — the caller must hear "cannot answer", not "nothing matched"');
        self::assertSame([], $index->search('zzzqqqxyz') ?? [], 'a real word that matches nothing is a real, empty, answer');
    }

    public function testAChangedBodyIsReindexedAndAVanishedOneDropsOut(): void
    {
        $store = $this->baseStore();
        $store->createSession('s1', 'p', 'm');
        $index = SessionSearchIndex::create($store->getPdo());
        $stable = 'the kubernetes cluster rolled overnight';

        $index->syncMessages('s1', ['m1' => $stable, 'm2' => 'a note about redis failover']);
        self::assertCount(1, $index->search('redis') ?? []);

        $index->syncMessages('s1', ['m1' => $stable, 'm2' => 'a note about memcached', 'm3' => 'brand new about etcd']);
        self::assertSame([], $index->search('redis') ?? [], 'an edited message leaves its old words unsearchable');
        self::assertCount(1, $index->search('memcached') ?? []);
        self::assertCount(1, $index->search('etcd') ?? []);
        self::assertCount(1, $index->search('kubernetes') ?? [], 'the unchanged message keeps its row — the diff is by hash, not by timestamp');

        $index->syncMessages('s1', ['m1' => $stable]);
        self::assertSame([], $index->search('memcached') ?? [], 'a rewind that drops a row drops its text too');
        self::assertSame([], $index->search('etcd') ?? []);
    }

    public function testAReSaveOfUnchangedBodiesMovesNotOneRowIndexRow(): void
    {
        $store = $this->baseStore();
        $store->createSession('s1', 'p', 'm');
        $index = SessionSearchIndex::create($store->getPdo());
        $bodies = ['m1' => 'the kubernetes cluster rolled overnight', 'm2' => 'a note about redis failover'];
        $index->syncMessages('s1', $bodies);

        $anchor = static fn(): array => $store->getPdo()
            ->query('SELECT id, message_id, hash FROM session_message_rows ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        $totalChanges = static fn(): int => (int) $store->getPdo()->query('SELECT total_changes()')->fetchColumn();

        $rowsBefore = $anchor();
        $changesBefore = $totalChanges();

        // Same set, and the same set in shuffled key order: the diff is by
        // hash, so neither re-save may touch a row. Row-identity snapshots
        // cannot see a churn bug (a delete-and-reinsert keeps id and hash),
        // which is why total_changes() is the load-bearing assert — it
        // counts every INSERT/UPDATE/DELETE the connection performed.
        $index->syncMessages('s1', $bodies);
        $index->syncMessages('s1', array_reverse($bodies, true));

        self::assertSame($rowsBefore, $anchor());
        self::assertSame($changesBefore, $totalChanges(), 'an unchanged re-save performed zero row movements on the connection');
        self::assertCount(1, $index->search('kubernetes') ?? [], 'the untouched rows still answer');
    }

    public function testForgettingASessionRemovesItsTextWithoutTouchingOthers(): void
    {
        $store = $this->baseStore();
        $store->createSession('gone', 'p', 'm');
        $store->createSession('kept', 'p', 'm');
        $index = SessionSearchIndex::create($store->getPdo());
        $index->syncMessages('gone', ['m1' => 'ephemeral contraband prose']);
        $index->syncMessages('kept', ['m1' => 'ephemeral contraband prose too']);

        $index->forgetSessions(['gone']);
        $ranked = $index->search('contraband') ?? [];
        self::assertSame(['kept'], array_column($ranked, 'session_id'));

        $index->collectGarbage();
        self::assertSame(0, (int) $store->getPdo()
            ->query('SELECT COUNT(*) FROM session_messages_fts WHERE rowid NOT IN (SELECT id FROM session_message_rows)')
            ->fetchColumn());
    }

    public function testTheAnchorRowsLeaveByTheForeignKeyCascadeWithTheirSession(): void
    {
        $store = $this->baseStore();
        $store->createSession('s1', 'p', 'm');
        $index = SessionSearchIndex::create($store->getPdo());
        $index->syncMessages('s1', ['m1' => 'cascade bound prose about walrus teeth']);

        $store->deleteSession('s1');

        // SessionStore opens the connection with foreign_keys ON, so the
        // cascade is live exactly as it is for session_events. The FTS rows
        // are not cascaded (virtual tables take no FK) which is why reads
        // inner-join the anchor: with it gone, the text is unanswerable even
        // before forgetSessions or the garbage pass name the session.
        self::assertSame(0, (int) $store->getPdo()
            ->query('SELECT COUNT(*) FROM session_message_rows')->fetchColumn());
        self::assertSame([], $index->search('walrus') ?? []);
    }

    public function testOnlyTheFirstWindowOfARunawayBodyIsIndexed(): void
    {
        $store = $this->baseStore();
        $store->createSession('s1', 'p', 'm');
        $index = SessionSearchIndex::create($store->getPdo());

        $head = 'leadword here';
        $tail = 'tailword past the window';
        $index->syncMessages('s1', ['m1' => $head . ' ' . str_repeat('midword ', 4096) . $tail]);

        self::assertCount(1, $index->search('leadword') ?? []);
        self::assertCount(1, $index->search('midword') ?? [], 'the window still holds deep into the body');
        self::assertSame([], $index->search('tailword') ?? [], 'past ' . SessionSearchIndex::MAX_BODY_BYTES . ' bytes the rest of the paste is the transcript problem, not the index problem');
    }

    public function testASecondStoreOverAPopulatedBuiltDatabaseDoesZeroSweep(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $this->saveChat($store, 's1', ['the kubernetes cluster rolled once']);
        $this->saveChat($store, 's2', ['a note about redis failover']);

        // The per-save diff already left anchor rows behind; no marker exists,
        // so the first body search must sweep — clearAll-and-reindex moves the
        // row identities, the fingerprint that separates a sweep from a diff.
        $beforeSweep = $this->anchorRows($this->dbPath);
        self::assertNotSame([], $beforeSweep, 'the per-save sync indexed both bodies already');

        $sweepSyncs = [];
        SessionSearchIndex::observeSyncsForTesting(static function () use (&$sweepSyncs): void {
            $sweepSyncs[] = true;
        });
        $hits = $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('kubernetes'));
        SessionSearchIndex::observeSyncsForTesting(null);

        self::assertSame(['s1'], array_map(static fn($row): string => $row->id, $hits));
        self::assertCount(2, $sweepSyncs, 'the unvouched first search sweeps both sessions');
        $afterSweep = $this->anchorRows($this->dbPath);
        self::assertNotSame($beforeSweep, $afterSweep, 'a sweep rebuilds from scratch — ids move where a diff would hold them');

        // The whole point of C-SES-2: the next process over the same database
        // reads the marker and does not sweep at all.
        $second = new EnhancedSessionStore($this->dbPath);
        $secondSyncs = [];
        SessionSearchIndex::observeSyncsForTesting(static function () use (&$secondSyncs): void {
            $secondSyncs[] = true;
        });
        $hitsAgain = $second->listSessionsFiltered((SessionQuery::new())->withBodySearch('kubernetes'));
        SessionSearchIndex::observeSyncsForTesting(null);

        self::assertSame(['s1'], array_map(static fn($row): string => $row->id, $hitsAgain));
        self::assertSame([], $secondSyncs, 'the marker vouches — the second process runs no sweep sync');
        self::assertSame($afterSweep, $this->anchorRows($this->dbPath), 'not one anchor row moved: the row identities stand as the first process left them');
    }

    public function testTheRebuildSweepRunsInsideATransaction(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $this->saveChat($store, 's1', ['the kubernetes cluster rolled once']);
        $this->saveChat($store, 's2', ['a note about redis failover']);

        // Armed only across the search: every sync that fires here belongs to
        // the rebuild sweep, and each one must find the store mid-transaction.
        $depths = [];
        SessionSearchIndex::observeSyncsForTesting(function () use (&$depths, $store): void {
            $depths[] = $this->storeTransactionDepth($store);
        });
        try {
            $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('redis'));
        } finally {
            SessionSearchIndex::observeSyncsForTesting(null);
        }

        self::assertSame([1, 1], $depths, 'the sweep runs chunked inside the store transaction — one WAL commit per chunk, not per session');
        self::assertSame(0, $this->storeTransactionDepth($store), 'and the store released it afterwards');
    }

    public function testALockedSearchDegradesWithoutTouchingTheBuildTrust(): void
    {
        // A stand-alone connection pair on its own file: the real index rides
        // the store's WAL connection, but the contention being pinned here is
        // the rollback-journal refusal a transient external writer produces.
        // barePdo's 1 s timeout keeps the refusal instant for the test — the
        // raw PDO default is 60 s of busy-sleep, which is exactly the wall the
        // BUSY carve-out exists to prevent, and what made an early draft of
        // this test look hung.
        $busyPath = $this->tempDir . '/busy.db';
        $pdo = $this->barePdo($busyPath);
        $index = SessionSearchIndex::create($pdo);
        $index->syncMessages('s1', ['m1' => 'the kubernetes cluster rolled once']);
        $index->markBuilt();
        self::assertFalse($index->needsRebuild());

        $blocker = $this->barePdo($busyPath);
        $blocker->exec('BEGIN EXCLUSIVE');
        try {
            self::assertNull($index->search('kubernetes'), 'a locked read answers with the LIKE degrade');
            self::assertFalse($index->needsRebuild(), 'the lock refused an answer, it did not corrupt a table');
        } finally {
            $blocker->exec('ROLLBACK');
            unset($blocker);
        }
        self::assertSame(0, $this->readMarkerRow($busyPath)['dirty'] ?? -1, 'the vouch survives on disk: BUSY is not a build failure');
        self::assertCount(1, $index->search('kubernetes') ?? [], 'once the lock clears the same index answers again, unrebuilt');

        // The discriminating contrast: a real read failure is a build
        // problem, and must still mark the index suspect.
        $pdo->exec('DROP TABLE session_messages_fts');
        self::assertNull($index->search('kubernetes'));
        self::assertTrue($index->needsRebuild(), 'a genuine failure still dirties — the BUSY carve-out is not a blanket silence');
    }

    public function testAFailedSyncRecordsTheDirtyFlagOnDiskForTheNextProcess(): void
    {
        $busyPath = $this->tempDir . '/busy.db';
        $pdo = $this->barePdo($busyPath);
        $index = SessionSearchIndex::create($pdo);
        $index->syncMessages('s1', ['m1' => 'the kubernetes cluster rolled once']);
        $index->markBuilt();
        self::assertSame(0, $this->readMarkerRow($busyPath)['dirty'] ?? -1);

        // Break the table underneath a sync: the sync swallows the failure
        // (a search index is never worth failing a save over) and records it.
        $pdo->exec('DROP TABLE session_message_rows');
        $index->syncMessages('s1', ['m2' => 'a note about redis failover']);

        self::assertTrue($index->needsRebuild());
        self::assertSame(1, $this->readMarkerRow($busyPath)['dirty'] ?? -1, 'the flag is durable, not just in-process');

        $reopened = SessionSearchIndex::create($this->barePdo($busyPath));
        self::assertTrue($reopened->needsRebuild(), 'the next process reads the recorded failure and asks for the sweep');
    }

    public function testAVersionMismatchForcesExactlyOneRebuild(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $this->saveChat($store, 's1', ['the kubernetes cluster rolled once']);
        $store->listSessionsFiltered((SessionQuery::new())->withBodySearch('kubernetes'));

        $marker = $this->readMarkerRow($this->dbPath);
        self::assertIsArray($marker, 'a completed sweep leaves the vouch on disk');
        self::assertSame(SessionSearchIndex::INDEX_VERSION, $marker['version'] ?? -1);

        // Age the marker past the shape this class reads: the honest signal
        // of a table whose meaning changed underneath the database.
        $stale = SessionSearchIndex::INDEX_VERSION + 1;
        $this->barePdo($this->dbPath)->exec('UPDATE session_search_meta SET version = ' . $stale . ' WHERE rowid = 1');

        $second = new EnhancedSessionStore($this->dbPath);
        $secondSyncs = 0;
        SessionSearchIndex::observeSyncsForTesting(static function () use (&$secondSyncs): void {
            ++$secondSyncs;
        });
        try {
            $hits = $second->listSessionsFiltered((SessionQuery::new())->withBodySearch('kubernetes'));
        } finally {
            SessionSearchIndex::observeSyncsForTesting(null);
        }
        self::assertSame(['s1'], array_map(static fn($row): string => $row->id, $hits));
        self::assertSame(1, $secondSyncs, 'a marker from another version pays exactly one sweep');
        self::assertSame(SessionSearchIndex::INDEX_VERSION, $this->readMarkerRow($this->dbPath)['version'] ?? -1, 'and re-issues the vouch for this one');

        $third = new EnhancedSessionStore($this->dbPath);
        $thirdSyncs = 0;
        SessionSearchIndex::observeSyncsForTesting(static function () use (&$thirdSyncs): void {
            ++$thirdSyncs;
        });
        try {
            $third->listSessionsFiltered((SessionQuery::new())->withBodySearch('kubernetes'));
        } finally {
            SessionSearchIndex::observeSyncsForTesting(null);
        }
        self::assertSame(0, $thirdSyncs, 'the refreshed marker settles it — no repeat');
    }

    public function testALatchedIndexCreatesNothingAndAnswersNothing(): void
    {
        $store = $this->baseStore();
        $store->createSession('s1', 'p', 'm');

        $index = SessionSearchIndex::create($store->getPdo(), 'no_such_fts_module_probe');

        self::assertFalse($index->available());
        self::assertNull($index->search('anything'));
        self::assertFalse($index->needsRebuild(), 'there is no build a latch could be waiting on');
        $index->syncMessages('s1', ['m1' => 'ignored']);
        $index->forgetSessions(['s1']);
        $index->collectGarbage();
        $index->clearAll();
        $index->markBuilt();

        self::assertSame(0, (int) $store->getPdo()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('session_message_rows', 'session_messages_fts', 'session_search_meta')")
            ->fetchColumn(), 'the degrade-not-fail promise is schema-visible: an FTS5-less database looks exactly like one from before SES-2');
    }
}
