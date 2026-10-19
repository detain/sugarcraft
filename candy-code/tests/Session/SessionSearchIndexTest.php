<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\SessionSearchIndex;
use SugarCraft\Code\Session\SessionStore;

/**
 * SES-2 (plan_comparison §SES-2): the FTS5 side table behind session body
 * search, at its own level — the anchor/FTS pair, the diff-by-hash sync,
 * bm25 ranking with a snippet, the cascade and garbage paths, and the latch
 * that makes every one of those a no-op on a SQLite built without FTS5.
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
        // Known names only: no directory walker here, so this file stays out
        // of the tree-wide walkers' rosters.
        foreach (['search.db', 'search.db-wal', 'search.db-shm'] as $name) {
            @unlink($this->tempDir . '/' . $name);
        }
        @rmdir($this->tempDir);
    }

    private function baseStore(): SessionStore
    {
        return new SessionStore($this->dbPath);
    }

    public function testOpeningTheIndexLandsBothTablesAndAsksForTheFirstSweep(): void
    {
        $store = $this->baseStore();
        $index = SessionSearchIndex::create($store->getPdo());

        self::assertTrue($index->available());
        self::assertTrue($index->needsRebuild(), 'a fresh process has not read a transcript yet — the first body search pays the sweep');

        $tables = $store->getPdo()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('session_message_rows', $tables);
        self::assertContains('session_messages_fts', $tables);
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
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('session_message_rows', 'session_messages_fts')")
            ->fetchColumn(), 'the degrade-not-fail promise is schema-visible: an FTS5-less database looks exactly like one from before SES-2');
    }
}
