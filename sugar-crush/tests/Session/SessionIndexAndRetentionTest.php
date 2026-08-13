<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\SessionStore;

/**
 * crush_code.md Phase 0 item 12 — `listSessions()`'s ORDER BY must be served
 * by an index (on old databases too), the per-frame query must stop
 * re-running, and `pruneSessions()` must be reachable with a retention that
 * cannot eat something a user meant to keep: not a named session, not the
 * session about to be resumed, not everything at once because the window
 * overflowed, and not a session inside the window because the cutoff was
 * computed in local time against UTC timestamps.
 *
 * @see SessionStore::initSchema()
 * @see SessionStore::pruneSessions()
 */
final class SessionIndexAndRetentionTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_session_idx_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    // =========================================================================
    // Index
    // =========================================================================

    /**
     * The point of the index is that SQLite stops sorting, so that — not the
     * mere existence of an index name — is what gets asserted. A DESC index
     * would still leave "USE TEMP B-TREE FOR RIGHT PART OF ORDER BY" behind
     * because the rowid tiebreak would come back ASC; this catches that
     * regression as well as the no-index one.
     */
    public function testListSessionsOrderByIsServedByTheIndexWithNoSort(): void
    {
        $store = new SessionStore($this->dbPath);
        for ($i = 0; $i < 50; $i++) {
            $store->createSession("s{$i}", 'p', 'm');
        }

        $plan = $this->queryPlan($this->dbPath);

        $this->assertStringContainsString('idx_sessions_updated_at', $plan);
        $this->assertStringNotContainsStringIgnoringCase(
            'TEMP B-TREE',
            $plan,
            "SQLite is still sorting listSessions() by hand:\n{$plan}",
        );
    }

    /**
     * A database created before the index existed has to gain it on the next
     * open, not only on a freshly created file. Built here from the literal
     * old CREATE TABLE so the test does not depend on the current schema.
     */
    public function testAnExistingPreIndexDatabaseGainsTheIndexOnOpen(): void
    {
        $this->createOldSchemaDatabase(50);

        $before = $this->queryPlan($this->dbPath);
        $this->assertStringContainsString('SCAN sessions', $before);
        $this->assertStringContainsStringIgnoringCase('TEMP B-TREE', $before);

        // Opening it is the migration.
        $store = new SessionStore($this->dbPath);

        $after = $this->queryPlan($this->dbPath);
        $this->assertStringContainsString('idx_sessions_updated_at', $after);
        $this->assertStringNotContainsStringIgnoringCase('TEMP B-TREE', $after);

        // And the pre-existing rows are all still there and still ordered.
        $rows = $store->listSessions(50);
        $this->assertCount(50, $rows);
        // old-0 was written with the newest updated_at.
        $this->assertSame('old-0', $rows[0]['id']);
    }

    /** Re-opening an already-migrated database must not throw or duplicate. */
    public function testTheIndexMigrationIsIdempotentAcrossRepeatedOpens(): void
    {
        $this->createOldSchemaDatabase(3);

        new SessionStore($this->dbPath);
        new SessionStore($this->dbPath);
        $third = new SessionStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $count = (int) $pdo
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_sessions_updated_at'")
            ->fetchColumn();

        $this->assertSame(1, $count);
        $this->assertCount(3, $third->listSessions());
    }

    // =========================================================================
    // Per-frame query caching
    // =========================================================================

    /**
     * renderSessionTabStrip() calls listSessions() once per frame. Repeated
     * calls with nothing written in between must not re-run the statement.
     */
    public function testRepeatedListSessionsCallsDoNotReRunTheQuery(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('a', 'p', 'm');
        $store->createSession('b', 'p', 'm');

        $first = $store->listSessions();
        $this->assertSame(1, $store->sessionListQueries());

        // One second of rendering at 60fps.
        for ($frame = 0; $frame < 60; $frame++) {
            $this->assertSame($first, $store->listSessions());
        }

        $this->assertSame(
            1,
            $store->sessionListQueries(),
            'Sixty render frames re-ran the sessions query; the per-frame query is still live.',
        );
    }

    /** Different limits are memoised separately rather than colliding. */
    public function testEachLimitIsMemoisedOnItsOwn(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('a', 'p', 'm');
        $store->createSession('b', 'p', 'm');

        $this->assertCount(2, $store->listSessions(20));
        $this->assertCount(1, $store->listSessions(1));
        $this->assertCount(2, $store->listSessions(20));
        $this->assertCount(1, $store->listSessions(1));

        $this->assertSame(2, $store->sessionListQueries());
    }

    /** A write through this store has to be visible on the very next read. */
    public function testAWriteThroughTheStoreInvalidatesTheCachedList(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('a', 'p', 'm');
        $this->assertCount(1, $store->listSessions());

        $store->createSession('b', 'p', 'm');
        $this->assertCount(2, $store->listSessions());

        $store->renameSession('b', 'renamed');
        $this->assertSame('renamed', $store->listSessions()[0]['name']);

        $store->deleteSession('b');
        $this->assertCount(1, $store->listSessions());
    }

    /**
     * And a write through a DIFFERENT connection to the same file — a second
     * sugar-crush process, or the raw PDO handles other tests open — must be
     * picked up too.
     */
    public function testAWriteFromAnotherConnectionInvalidatesTheCachedList(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('a', 'p', 'm');
        $this->assertCount(1, $store->listSessions());

        $other = new SessionStore($this->dbPath);
        $other->createSession('b', 'p', 'm');

        $this->assertCount(2, $store->listSessions());
    }

    // =========================================================================
    // Retention
    // =========================================================================

    public function testPruneSessionsKeepsANamedSessionHoweverOldItIs(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('kept', 'p', 'm', null, 'the bisect notes');
        $store->createSession('dropped', 'p', 'm');
        $this->ageSessions('2020-01-01 00:00:00');

        $pruned = $store->pruneSessions(30);

        $this->assertSame(1, $pruned);
        $this->assertNotNull($store->getSession('kept'));
        $this->assertNull($store->getSession('dropped'));
    }

    /** An empty-string name is not a name — it is what a blank column holds. */
    public function testPruneSessionsTreatsAnEmptyNameAsUnnamed(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('blank', 'p', 'm', null, '');
        $this->ageSessions('2020-01-01 00:00:00');

        $this->assertSame(1, $store->pruneSessions(30));
        $this->assertNull($store->getSession('blank'));
    }

    /** A named session's messages and tool calls survive with it. */
    public function testPruneSessionsLeavesANamedSessionsTranscriptAlone(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('kept', 'p', 'm', null, 'keep me');
        $messageId = $store->addMessage('kept', ['role' => 'user', 'content' => 'still here']);
        $store->addToolCall('kept', $messageId, ['name' => 'bash', 'arguments' => ['cmd' => 'ls']]);
        $this->ageSessions('2020-01-01 00:00:00');

        $store->pruneSessions(30);

        $this->assertCount(1, $store->getMessages('kept'));
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM tool_calls')->fetchColumn());
    }

    /** Sessions inside the retention window are untouched. */
    public function testPruneSessionsKeepsAnythingInsideTheWindow(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('recent', 'p', 'm');
        $this->ageSessions(gmdate('Y-m-d H:i:s', strtotime('-10 days')));

        $this->assertSame(0, $store->pruneSessions(30));
        $this->assertNotNull($store->getSession('recent'));
    }

    /**
     * The session the caller is about to resume survives at any age. The
     * "unnamed" test is weak — auto-titling fires once per session, needs a
     * title backend and fails silently — so the row `seedSession()` picked is
     * exempted explicitly rather than trusted to have a name.
     */
    public function testPruneSessionsNeverTouchesTheSessionTheCallerIsResuming(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('resuming', 'p', 'm');
        $store->createSession('abandoned', 'p', 'm');
        $this->ageSessions('2020-01-01 00:00:00');

        $pruned = $store->pruneSessions(30, 'resuming');

        $this->assertSame(1, $pruned);
        $this->assertNotNull($store->getSession('resuming'));
        $this->assertNull($store->getSession('abandoned'));
    }

    /**
     * Deleting a conversation silently is the part that is not defensible, so
     * the caller is handed what went — ids and how much transcript each one
     * held — rather than a bare count it can only discard.
     */
    public function testPruneSessionsReportsWhatItDeleted(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('gone', 'p', 'm');
        $store->addMessage('gone', ['role' => 'user', 'content' => 'a']);
        $store->addMessage('gone', ['role' => 'assistant', 'content' => 'b']);
        $store->createSession('kept', 'p', 'm', null, 'named');
        $this->ageSessions('2020-01-01 00:00:00');

        $store->pruneSessions(30);
        $report = $store->pruneReport();

        $this->assertCount(1, $report);
        $this->assertSame('gone', $report[0]['id']);
        $this->assertSame(2, $report[0]['messages']);
        $this->assertSame('2020-01-01 00:00:00', $report[0]['updated_at']);
    }

    /** A run that deletes nothing reports nothing, rather than last run's rows. */
    public function testThePruneReportIsResetByEveryRun(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('gone', 'p', 'm');
        $this->ageSessions('2020-01-01 00:00:00');

        $store->pruneSessions(30);
        $this->assertCount(1, $store->pruneReport());

        $store->pruneSessions(30);
        $this->assertSame([], $store->pruneReport());
    }

    /**
     * `updated_at` is written by SQLite's `CURRENT_TIMESTAMP`, which is UTC,
     * so the cutoff has to be UTC too. A `date()` cutoff east of UTC is up to
     * 14 hours early — at a one-day retention that is a 58% error, and the
     * session deleted is one the user touched inside the window.
     *
     * @dataProvider timezonesEitherSideOfUtc
     */
    public function testTheCutoffIsUtcWhateverTheProcessTimezoneIs(string $timezone): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $store = new SessionStore($this->dbPath);
            $store->createSession('inside', 'p', 'm');
            // 23 hours old in UTC: inside a 1-day window everywhere, but a
            // local-time cutoff in Kiritimati (UTC+14) would put it outside.
            $this->ageSessions(gmdate('Y-m-d H:i:s', time() - 23 * 3600));

            $this->assertSame(0, $store->pruneSessions(1), "A 23-hour-old session was pruned at 1-day retention in {$timezone}.");
            $this->assertNotNull($store->getSession('inside'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function timezonesEitherSideOfUtc(): array
    {
        return [
            'far east'  => ['Pacific/Kiritimati'],
            'east'      => ['Asia/Tokyo'],
            'utc'       => ['UTC'],
            'west'      => ['America/Los_Angeles'],
        ];
    }

    /**
     * `ctype_digit()` accepts a 20-digit number, `(int)` saturates it to
     * `PHP_INT_MAX`, and `strtotime("-PHP_INT_MAX days")` overflows to a
     * cutoff in the FUTURE — at which point "older than the cutoff" matches
     * every session in the table.
     *
     * @dataProvider overflowingRetentionDays
     */
    public function testAnAbsurdRetentionValueDeletesNothingRatherThanEverything(int $daysOld): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('survivor', 'p', 'm');
        $store->createSession('old', 'p', 'm');
        $this->ageSessions('2020-01-01 00:00:00');

        $pruned = $store->pruneSessions($daysOld);

        $this->assertSame(0, $pruned, 'An out-of-range retention window deleted rows.');
        $this->assertNotNull($store->getSession('survivor'));
        $this->assertNotNull($store->getSession('old'));
    }

    /** @return array<string, array{0: int}> */
    public static function overflowingRetentionDays(): array
    {
        return [
            'php int max' => [PHP_INT_MAX],
            'saturated'   => [(int) '99999999999999999999'],
            'astronomical' => [intdiv(PHP_INT_MAX, 2)],
            'zero'        => [0],
            'negative'    => [-7],
        ];
    }

    /** The clamp is what makes the overflow unreachable, so state it. */
    public function testRetentionIsClampedToASaneUpperBound(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('ancient', 'p', 'm');
        $this->ageSessions('1990-01-01 00:00:00');

        // 36500 days ≈ 100 years: even a session dated 1990 is inside it.
        $this->assertSame(0, $store->pruneSessions(SessionStore::MAX_RETENTION_DAYS));
        $this->assertNotNull($store->getSession('ancient'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a database with the exact `sessions` schema SessionStore shipped
     * before item 12 — no index, and no `name` column either, so the older of
     * the two migrations is exercised alongside the new one.
     */
    private function createOldSchemaDatabase(int $rows): void
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE sessions (
                id TEXT PRIMARY KEY,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                system_prompt TEXT,
                metadata TEXT
            )
        ');
        $insert = $pdo->prepare('INSERT INTO sessions (id, updated_at, provider, model) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < $rows; $i++) {
            $insert->execute(["old-{$i}", date('Y-m-d H:i:s', strtotime("-{$i} minutes")), 'p', 'm']);
        }
    }

    /**
     * EXPLAIN the statement `listSessions()` ACTUALLY prepares.
     *
     * Deliberately not a copy of the SQL: an earlier version of this file kept
     * its own transcription with `LIMIT 20` spliced in, and mutating the
     * production `ORDER BY` to an unindexable-but-equivalent
     * `ORDER BY datetime(updated_at) DESC` left every test in the file green
     * while the real plan fell back to `SCAN sessions` + `USE TEMP B-TREE`.
     * The statement text now comes from the class under test, so that
     * mutation fails here.
     */
    private function queryPlan(string $dbPath): string
    {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('EXPLAIN QUERY PLAN ' . SessionStore::LIST_SESSIONS_SQL);
        $stmt->execute([20]);

        $details = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details[] = (string) $row['detail'];
        }

        return implode("\n", $details);
    }

    private function ageSessions(string $timestamp): void
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE sessions SET updated_at = ?')->execute([$timestamp]);
    }
}
