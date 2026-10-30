<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionRow;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Util\TokenTracker;

/**
 * SES-1 (plan_comparison): the durable per-session spend ledger. Before this
 * table, a session's dollars lived only in a per-process TokenTracker, so
 * `--continue` restarted `/budget` at $0.00 and no listing could show what a
 * session had ever cost. These pins own the four load-bearing shapes: the
 * schema lands on FRESH and UPGRADED databases alike; the tracker reseed
 * mirrors the session that wrote the rows; the unpriced-lower-bound law puts
 * `≥` on every dollar surface the moment one row arrives from a model with no
 * rate on file; and the append rides the single accounting choke — a call
 * that reached the tracker has a ledger row, and a call that never happened
 * fabricates neither.
 *
 * @see EnhancedSessionStore::appendUsageRecord()
 * @see SpendLedger::account()
 */
final class UsageRecordsTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_usage_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function testAFreshDatabaseCarriesTheLedgerTableAndTheCacheColumns(): void
    {
        new EnhancedSessionStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $columns = array_column($pdo->query('PRAGMA table_info(sessions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('cost_usd', $columns);
        self::assertContains('cost_unpriced', $columns);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'index')")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('usage_records', $tables);
        self::assertContains('idx_usage_records_session', $tables, 'the per-session folds read one session by id');
        self::assertContains('idx_usage_records_ts', $tables, 'SES-1-P6: the windowed folds scan the ledger by ts, so the index the old comment denied is the second key it needs');
        self::assertContains('session_tombstones', $tables, 'SES-1-P5: the delete ledger the lazy mint consults must exist before the first delete');
    }

    public function testAnUpgradedDatabaseGainsTheLedgerAndTheCacheColumns(): void
    {
        // MIGRATED_COLUMNS law: opening an old database adds the columns with
        // their defaults, and the enhanced schema lands its table beside them.
        $this->createLegacyDatabase();
        $store = new EnhancedSessionStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $columns = array_column($pdo->query('PRAGMA table_info(sessions)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('cost_usd', $columns);
        self::assertContains('cost_unpriced', $columns);
        self::assertNotFalse($pdo->query('SELECT COUNT(*) FROM usage_records'));

        self::assertNull($store->sessionCost('legacy'), 'a session predating the ledger has no recorded spend — not a measured zero');
        self::assertNull($store->usageTotals('legacy'));

        self::assertTrue($store->appendUsageRecord('legacy', Usage::new(100, 0.01)));
        self::assertEqualsWithDelta(0.01, $store->sessionCost('legacy'), 1e-9);
    }

    public function testEveryAccountedCallFoldsIntoTheLedgerOnceAndTheCacheForever(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $sink = $this->sinkFor($store, 's1');
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();

        $ledger->account($tracker, Usage::new(1500, 0.03), $sink);
        $ledger->account($tracker, Usage::new(500, 0.02), $sink);

        self::assertSame(
            ['calls' => 2, 'totalTokens' => 2000, 'costUsd' => 0.05, 'unpriced' => false],
            $store->usageTotals('s1'),
        );
        self::assertEqualsWithDelta(0.05, $store->sessionCost('s1'), 1e-9);

        $row = SessionRow::fromArray($store->getSession('s1'));
        self::assertEqualsWithDelta(0.05, $row->costUsd, 1e-9);
        self::assertFalse($row->costUnpriced);
    }

    public function testTheChokeGuardsTheLedgerWithItself(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $appends = 0;
        $sink = function (Usage $usage) use ($store, &$appends): void {
            $appends++;
            $store->appendUsageRecord('s1', $usage);
        };
        $ledger = SpendLedger::new();

        $ledger->account(new TokenTracker(), null, $sink);
        self::assertSame(0, $appends, 'an unreported call must not become a zero-dollar ledger row');

        // The sink-less shape stays callable — every pre-SES-1 test and any
        // host that has no durable store accounts through the same method.
        $tracker = new TokenTracker();
        $ledger->account($tracker, Usage::new(100, 0.01));
        self::assertEqualsWithDelta(0.01, $tracker->totalCost(), 1e-9);
        self::assertNull($store->usageTotals('s1'));
    }

    public function testUnreportedTokenBucketsRecordAsNullNotZero(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->appendUsageRecord('s1', Usage::new(250, 0.005));

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $row = $pdo->query('SELECT * FROM usage_records')->fetch(PDO::FETCH_ASSOC);

        // Usage's null-is-unreported law survives the round trip: a NULL
        // means the provider said nothing, and only `total_tokens`/`cost_usd`
        // (never optional) are NOT NULL in the DDL.
        self::assertNull($row['input_tokens']);
        self::assertNull($row['output_tokens']);
        self::assertNull($row['model'], 'a call nobody could name bills honestly enough for dollars, but the model column stays honest too');
        self::assertSame(250, (int) $row['total_tokens']);
        self::assertSame(0, (int) $row['unpriced']);
    }

    public function testTheUnpricedBitIsStickyAndMakesEveryDollarSurfaceABound(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $sink = $this->sinkFor($store, 's1');
        $ledger = SpendLedger::new();
        $tracker = new TokenTracker();

        $ledger->account($tracker, Usage::new(10, 0.0, unpricedModel: 'mystery-model'), $sink);
        $ledger->account($tracker, Usage::new(990, 0.02), $sink);

        self::assertTrue($store->usageTotals('s1')['unpriced'], 'a later priced call cannot un-blind the earlier unpriced one');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertSame(1, (int) $pdo->query('SELECT cost_unpriced FROM sessions WHERE id = "s1"')->fetchColumn());

        // The law on every dollar surface the ledger and tracker print.
        self::assertStringContainsString('Cost: ≥$0.0200', $tracker->summary());
        self::assertStringContainsString('Spend so far: ≥$0.0200 (cap $1.0000)', $ledger->statusLine($tracker, 1.0));
        self::assertStringContainsString('LOWER BOUND', $ledger->statusLine($tracker, 1.0));
        self::assertStringContainsString(
            '≥$0.0200 of the $1.0000 cap',
            $ledger->refusalNotice($tracker, 1.0, SpendLedger::crossedByPreviousTurn()),
        );

        // And priced sessions keep the exact bytes they always had.
        $clean = new TokenTracker();
        $clean->addTotalUsage(990, 0.02);
        self::assertStringContainsString('Cost: $0.0200', $clean->summary());
        self::assertStringNotContainsString('≥', $ledger->statusLine($clean, 1.0));
    }

    public function testTheReseededTrackerMirrorsTheSessionThatWroteTheRows(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $sink = $this->sinkFor($store, 's1');
        $ledger = SpendLedger::new();
        $live = new TokenTracker();

        $ledger->account($live, Usage::new(1500, 0.03), $sink);
        $ledger->account($live, Usage::new(10, 0.0, unpricedModel: 'mystery-model'), $sink);

        $reseeded = $store->resumedTokenTracker('s1');
        self::assertNotNull($reseeded);
        // Identity of MEANING, pinned as identity of output: same buckets
        // (everything accounted arrived unsplit), same lower-bound flag,
        // same printed figure — `/budget` after `--continue` cannot tell it
        // was rebuilt from rows.
        self::assertSame($live->summary(), $reseeded->summary());
        self::assertEqualsWithDelta($live->totalCost(), $reseeded->totalCost(), 1e-9);
        self::assertSame($live->unsplitTokens(), $reseeded->unsplitTokens());
        self::assertTrue($reseeded->hasUnpricedUsage());
    }

    public function testTheServeHostReseedsItsTrackerFromTheLedger(): void
    {
        // SES-1b: the serve-side half of the resume seam. `serve --continue`
        // builds a SessionHost over an existing session; before the reseed
        // its in-process tracker started at $0 while the durable cost
        // columns were right — spentUsd() and the cap checks read the
        // tracker, so the serve window lied about money the ledger held.
        $store = new EnhancedSessionStore($this->dbPath);
        $sink = $this->sinkFor($store, 's1');
        $ledger = SpendLedger::new();
        $ledger->account(new TokenTracker(), Usage::new(1500, 0.03), $sink);
        $ledger->account(new TokenTracker(), Usage::new(10, 0.0, unpricedModel: 'mystery-model'), $sink);

        $host = SessionHost::new('s1', WorkspaceContext::new(sessionStore: $store));
        self::assertEqualsWithDelta(0.03, $host->spentUsd(), 1e-9);

        // The ≥-law travels with the reseed: the unpriced row re-flags the
        // host's own tracker, so serve's dollar surfaces keep the
        // lower-bound disclosure, not a bald number.
        $spend = (new \ReflectionProperty(SessionHost::class, 'spend'))->getValue($host);
        self::assertInstanceOf(TokenTracker::class, $spend);
        self::assertTrue($spend->hasUnpricedUsage());
        self::assertStringContainsString('Spend so far: ≥$0.0300', $ledger->statusLine($spend, 1.0));

        // The other polarity of adoption: a fresh session id on the same
        // store starts at zero exactly as every host did before the table.
        $fresh = SessionHost::new('never-opened', WorkspaceContext::new(sessionStore: $store));
        self::assertSame(0.0, $fresh->spentUsd());

        // House wiring pin: the ctor consults the ledger, and no reseed
        // shape can silently regress to the plain `new TokenTracker()`.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');
        self::assertMatchesRegularExpression(
            '/\$this->spend = \$store instanceof EnhancedSessionStore\s*\?\s*\(\$store->resumedTokenTracker\(\$sessionId\) \?\? new TokenTracker\(\)\)\s*:\s*new TokenTracker\(\);/',
            $source,
        );
    }

    public function testAFreshSessionReseedsToNothingAndAnUnknownSessionWritesNothing(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        self::assertNull($store->resumedTokenTracker('never-opened'), 'the Bootstrap seam answers null so Chat keeps its zero tracker');

        // The empty id is the late-mint state Chat's sink skips; the store
        // itself refuses it too rather than orphaning rows behind a FK.
        self::assertFalse($store->appendUsageRecord('', Usage::new(1, 0.0)));
        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM usage_records')->fetchColumn());
    }

    public function testTheRowCacheRefoldsWhenTheLedgerWritesThroughItsOwnStore(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $before = $store->listSessions();
        self::assertNull($before[0]['cost_usd']);

        $store->appendUsageRecord('s1', Usage::new(100, 0.02), 'test-model');

        // Without the sessionWriteSeq bump inside addSessionSpend, this second
        // read answers from the stale memo and the picker shows yesterday's
        // sum out of the very connection that changed it.
        $after = $store->listSessions();
        self::assertSame(0.02, (float) $after[0]['cost_usd']);
    }

    public function testDeletingASessionTakesItsLedgerWithIt(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $store->appendUsageRecord('s1', Usage::new(100, 0.02));
        self::assertNotNull($store->usageTotals('s1'));

        $store->deleteSession('s1');

        self::assertNull($store->usageTotals('s1'), 'retention must not leave orphans behind the row');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM usage_records')->fetchColumn());
    }

    public function testALateSettleForADeletedSessionWritesNothingAndResurrectsNoRow(): void
    {
        // SES-1-P5: the delete is the user's last word. Before the tombstone
        // ledger, the lazy recreate inside appendUsageRecord() read
        // "no session row" as "not minted yet" — so the in-flight settle that
        // lands one tick after /session delete resurrected the session as
        // candy-code/unknown with a fresh money row. The refusal is the whole
        // fix: false out, nothing written, the id stays dead.
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $store->appendUsageRecord('s1', Usage::new(100, 0.02));
        $store->deleteSession('s1');

        self::assertFalse($store->appendUsageRecord('s1', Usage::new(50, 0.01), 'm'));

        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM usage_records')->fetchColumn());
        self::assertNull($store->session('s1'), 'the sessions row stays destroyed');
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM session_tombstones WHERE id = 's1'")->fetchColumn(),
            'the delete left its mark',
        );
    }

    public function testATranscriptSaveForADeletedSessionDoesNotResurrectTranscript(): void
    {
        // W7 wave-review MINOR (cure-W7): the SES-1-P5 gate covered
        // appendUsageRecord() alone. saveTranscript()'s sibling recreate read
        // "no session row" as "not minted yet" for a TOMBSTONED id too, so a
        // save landing one tick after the delete resurrected the session as
        // candy-code/unknown with its transcript behind it. The refusal is
        // the whole fix: nothing written, the id stays dead — the FK is
        // exactly why the refusal skips the entire save, not just the mint.
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $store->saveTranscript('s1', [Message::user('first')]);
        $store->deleteSession('s1');

        $store->saveTranscript('s1', [Message::user('still talking')]);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertNull($store->session('s1'), 'the sessions row stays destroyed');
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM session_transcripts WHERE session_id = 's1'")->fetchColumn(),
            'the save writes no transcript behind the refused mint',
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM session_tombstones WHERE id = 's1'")->fetchColumn(),
            'the delete left its mark',
        );
    }

    public function testASessionEventForADeletedSessionDoesNotResurrectEvents(): void
    {
        // The second sibling path of the same cure-W7 class. The refused
        // append answers 0 — the same "no event was written" sentinel
        // sessionEventBounds()/latestSeq() already give for a log that never
        // was; a real seq starts at 1. The polarity stands: a NEVER-minted id
        // still lazy-mints exactly as SessionEventsTableTest pins it.
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $store->appendSessionEvent('s1', 'turn.started');
        $store->deleteSession('s1');

        self::assertSame(0, $store->appendSessionEvent('s1', 'message.created', ['text' => 'still talking']));

        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertNull($store->session('s1'), 'the sessions row stays destroyed');
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM session_events WHERE session_id = 's1'")->fetchColumn(),
            'the append writes no event behind the refused mint',
        );
    }

    public function testANeverMintedIdStillLazyMintsItsRowBesideTheLedger(): void
    {
        // The polarity the tombstone must not disturb: a settle for an id
        // that was simply never minted (Chat's late-mint race) still gets its
        // row exactly as every append predating this ledger made it — the
        // refusal is reserved for ids the user DESTROYED.
        $store = new EnhancedSessionStore($this->dbPath);

        self::assertTrue($store->appendUsageRecord('never-minted', Usage::new(100, 0.02), 'test-model'));

        $row = $store->session('never-minted');
        self::assertNotNull($row);
        self::assertSame('candy-code', $row['provider']);
        self::assertSame('unknown', $row['model']);
        $pdo = new PDO('sqlite:' . $this->dbPath);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM usage_records')->fetchColumn());
    }

    public function testTheTombstoneLedgerReapsItsOwnHistoryOnTheNextDelete(): void
    {
        // 30 days is the resurrection window: past it, a settle is a clock
        // artifact or a genuinely new conversation under a reused id, and the
        // id returns to ordinary never-minted behavior. The reap rides the
        // next delete's insert — the table bounds itself, never a sweep.
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('old', 'p', 'm');
        $store->deleteSession('old');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $aged = $pdo->prepare('UPDATE session_tombstones SET deleted_at = ? WHERE id = ?');
        $aged->execute([time() - 31 * 86400, 'old']);

        $store->createSession('fresh', 'p', 'm');
        $store->deleteSession('fresh');

        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM session_tombstones WHERE id = 'old'")->fetchColumn(),
            'the entry older than the TTL is gone with the next insert',
        );
        self::assertSame(
            1,
            (int) $pdo->query("SELECT COUNT(*) FROM session_tombstones WHERE id = 'fresh'")->fetchColumn(),
        );

        self::assertTrue($store->appendUsageRecord('old', Usage::new(10, 0.001), 'm'), 'a reaped id is just an id again');
    }

    public function testTheTombstoneReadToleratesItsLedgerBeingGone(): void
    {
        // The tolerance half of the SES-1-P5 risk law. sessionTombstoned()
        // answers "nothing was ever deleted" when its table cannot be read
        // (PDOException catch), because a database no store carrying this
        // schema has ever opened — a legacy file — must behave EXACTLY as
        // every append predating the ledger did: lazy mint serves the id.
        // No other test here can ever reach that catch: the constructor's
        // CREATE TABLE IF NOT EXISTS guarantees the table exists for every
        // append run post-construction, so dropping it out from under a
        // live store is the only faithful way to visit a legacy file.
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $store->deleteSession('s1');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Row-absence leg: the mark, not some session-row residue, is what
        // refuses the mint — with the entry gone by hand (the reap law's
        // endpoint, taken directly), the id is just an id again.
        $unmark = $pdo->prepare('DELETE FROM session_tombstones WHERE id = ?');
        $unmark->execute(['s1']);
        self::assertTrue($store->appendUsageRecord('s1', Usage::new(50, 0.01), 'm'));
        self::assertNotNull($store->session('s1'), 'the unmarked id lazy-mints its row');

        // Table-absence leg: a file the tombstone read cannot even name.
        // The mark taken while the table still existed must be as invisible
        // as if it had never been — "nothing was ever deleted" is the honest
        // answer for such a file, unknown ids and marked ids alike.
        $store->createSession('s2', 'p', 'm');
        $store->deleteSession('s2');
        $pdo->exec('DROP TABLE session_tombstones');

        self::assertTrue(
            $store->appendUsageRecord('unknown', Usage::new(100, 0.02), 'test-model'),
            'the missing ledger must not fail-soft the append into a lost row',
        );
        self::assertTrue($store->appendUsageRecord('s2', Usage::new(10, 0.001), 'test-model'));

        $row = $store->session('unknown');
        self::assertNotNull($row);
        self::assertSame('candy-code', $row['provider']);
        self::assertSame('unknown', $row['model']);
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM usage_records')->fetchColumn());
    }

    public function testTheWindowedFoldScansByTheTimestampIndexItNowCarries(): void
    {
        // SES-1-P6 as the planner sees it: the OBS-3 window (`/usage all`
        // over a bounded span) filters `ts >= ?` — the exact shape
        // usageWindowClause builds — and on a ledger with history that is a
        // range scan, not the whole-table walk it was before the index.
        new EnhancedSessionStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $insert = $pdo->prepare(
            "INSERT INTO usage_records (session_id, ts, model, total_tokens, input_tokens, output_tokens,"
            . " cache_read_tokens, cache_creation_tokens, reasoning_tokens, cost_usd, unpriced)"
            . " VALUES ('s1', ?, 'm', 1, 0, 0, 0, 0, 0, 0.0, 0)"
        );
        $now = time();
        for ($back = 0; $back < 40; $back++) {
            for ($k = 0; $k < 20; $k++) {
                $insert->execute([$now - $back * 86400 - $k * 60]);
            }
        }
        $pdo->exec('ANALYZE');

        $stmt = $pdo->prepare("EXPLAIN QUERY PLAN SELECT date(ts, 'unixepoch') AS day, model, COUNT(*) FROM usage_records WHERE ts >= ? GROUP BY day, model");
        $stmt->execute([$now - 6 * 86400]);
        $plan = implode('; ', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        self::assertStringContainsString('idx_usage_records_ts', $plan, 'the window reads through the index: ' . $plan);
    }

    public function testTheRollupPairReadsOneSnapshotAcrossAnInterleavedCommit(): void
    {
        // OBS-3-P2 as SQLite executes it: while one connection's deferred
        // read transaction is open, a committed write from another connection
        // is invisible to every later read in that transaction. The pair of
        // folds answering `/usage` rides exactly such a frame — so the money
        // totals row can no longer count a call the day grid above it never saw.
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        $store->appendUsageRecord('s1', Usage::new(100, 0.01), 'm1');

        $property = new \ReflectionProperty(EnhancedSessionStore::class, 'pdo');
        $property->setAccessible(true);
        $reader = $property->getValue($store);
        self::assertInstanceOf(PDO::class, $reader);

        $reader->exec('BEGIN DEFERRED');
        $before = $store->usageModelRollups();

        $writer = new PDO('sqlite:' . $this->dbPath);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec(
            "INSERT INTO usage_records (session_id, ts, model, total_tokens, input_tokens, output_tokens,"
            . " cache_read_tokens, cache_creation_tokens, reasoning_tokens, cost_usd, unpriced)"
            . " VALUES ('s2', " . time() . ", 'm1', 999, 0, 0, 0, 0, 0, 0.5, 0)"
        );
        $writer = null;

        $after = $store->usageModelRollups();
        $reader->exec('COMMIT');

        self::assertSame($before, $after, 'the fold pair must not straddle the commit');
    }

    public function testUsageRollupsWrapsBothFoldsInOneDeferredFrame(): void
    {
        // The behavior above proves the FRAME snapshots; this shape pin proves
        // usageRollups() IS the frame — an interleaved commit cannot be
        // arranged inside a single call from a test, so the house wire for
        // "wiring only visible at runtime" is the source scan.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Session/EnhancedSessionStore.php');
        $start = strpos($source, 'public function usageRollups');
        self::assertNotFalse($start, 'the pair method exists');
        $end = strpos($source, "\n    }", $start);
        self::assertNotFalse($end);
        $slice = substr($source, $start, $end - $start);

        $begin = strpos($slice, "'BEGIN DEFERRED'");
        $days = strpos($slice, 'usageDayModelRollups(');
        $models = strpos($slice, 'usageModelRollups($sessionId');
        $sources = strpos($slice, 'usageSourceRollups($sessionId');
        $commit = strpos($slice, "'COMMIT'");
        foreach (['begin' => $begin, 'days fold' => $days, 'models fold' => $models, 'sources fold' => $sources, 'commit' => $commit] as $what => $at) {
            self::assertIsInt($at, 'usageRollups must still carry its ' . $what);
        }
        self::assertLessThan($days, $begin);
        self::assertLessThan($models, $days, 'both folds read inside the frame');
        self::assertLessThan($sources, $models, 'the attribution fold rides the same snapshot, never loose beside it');
        self::assertLessThan($commit, $sources, 'and only then does the frame close');
        self::assertSame(0, substr_count($slice, 'BEGIN IMMEDIATE'), 'reads take no write lock — /usage must not queue behind a writer');
    }

    public function testAPlainSessionStoreStillOpensAndCarriesTheCacheColumns(): void
    {
        // serve/test hosts may hold only the base store; the columns land via
        // the same migration loop, and sessionCost answers through it.
        $store = new SessionStore($this->dbPath);
        $store->createSession('s1', 'p', 'm');
        self::assertNull($store->sessionCost('s1'));

        $store->addSessionSpend('s1', 0.04, true);
        self::assertEqualsWithDelta(0.04, $store->sessionCost('s1'), 1e-9);
        $row = SessionRow::fromArray($store->getSession('s1'));
        self::assertTrue($row->costUnpriced);
    }

    public function testTheProductionWiresRideTheChokeAndTheSeam(): void
    {
        // Chat accounts THROUGH the choke with the sink attached, and the
        // launch reseeds at the chat-construction seam — both as source
        // shape, the house pin for wiring that only fires on a live settle.
        $chat = file_get_contents(dirname(__DIR__, 2) . '/src/Chat.php');
        self::assertStringContainsString(
            '$this->spendLedger()->account($this->tokenTracker, $usage, $this->usageRecordSink(), $model, $source);',
            $chat,
        );

        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        // The reseed reads its store through the incognito hoist — the
        // WELD-1030L wire renamed the local and stale-pin drift was
        // invisible until this lane re-derived it: the hoist AND the read
        // are both pinned now, so dropping either half goes red.
        self::assertMatchesRegularExpression(
            '/\$keptStore = self::incognito\(\) \? null : \$sessionStore;/',
            $bootstrap,
            'incognito launches hold no store at all',
        );
        self::assertMatchesRegularExpression(
            '/tokenTracker: \$keptStore instanceof EnhancedSessionStore\s*\?\s*\$keptStore->resumedTokenTracker\(\$sessionId\)/',
            $bootstrap,
        );

        // SES-1, rev-w4g MAJOR-1: the host's OWN accounting sites carry the
        // sink too — serve is SessionHost, not Chat, and an unwired site
        // persists nothing. Both shapes are pinned verbatim: one string per
        // live call into the choke. C-SES-8.b widened both with the source
        // label: the idle fold is a context call and the reply is the turn.
        $host = file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');
        foreach ([
            "\$this->ledger()->account(\$this->spend, \$msg->usage, \$this->usageRecordSink(), null, 'compact');",
            "\$this->ledger()->account(\$this->spend, \$reply->usage, \$this->usageRecordSink(), null, 'turn');",
        ] as $wire) {
            self::assertStringContainsString($wire, $host, 'serve accounts through the choke WITH the ledger sink');
        }
    }

    public function testEverySettleArmCarriesItsSourceLabelAtTheCallSite(): void
    {
        // C-SES-8.b: the attribution lives at the CALL SITE — an unlabeled
        // arm would land NULL and fold into the unrecorded row silently.
        // Every live accountUsage()/account() call in the settle family is
        // pinned verbatim with its label, one string per arm, so dropping a
        // label (or mislabeling an arm) goes red on the source shape.
        $chat = file_get_contents(dirname(__DIR__, 2) . '/src/Chat.php');
        $trait = file_get_contents(dirname(__DIR__, 2) . '/src/Chat/Handlers/HandlesTurnCompletion.php');
        foreach ([
            [$chat, "\$this->accountUsage(\$msg->usage, self::modelOf(\$this->titleBackend), 'goal');"],
            [$chat, "\$this->accountUsage(\$msg->message->usage, null, 'superseded');"],
            [$chat, "\$this->accountUsage(\$msg->usage, self::modelOf(\$this->summaryBackend), 'compact');"],
            [$trait, "\$this->accountUsage(\$msg->message->usage, null, 'turn');"],
            [$trait, "\$this->accountUsage(\$msg->usage, self::modelOf(\$this->titleBackend), 'aux'); // OBS-3: the btw call ran on the title engine"],
            [$trait, "\$this->accountUsage(\$msg->usage, null, 'aux');"],
            [$trait, "\$this->accountUsage(\$msg->usage, self::modelOf(\$this->summaryBackend), 'compact');"],
            [$trait, "\$this->accountUsage(\$msg->usage, self::modelOf(\$this->titleBackend), 'title');"],
        ] as $pin) {
            self::assertStringContainsString($pin[1], $pin[0]);
        }

        // The auxiliary arms share a call shape, so count them per label:
        // three 'aux' arms live in the trait (btw, auto-commit, suggestion)
        // beside the dream arm's bare-model form, and the title arm is one.
        self::assertSame(4, substr_count($trait, "'aux')"), 'four auxiliary settle arms in the trait');
        self::assertSame(1, substr_count($trait, "'title')"), 'one titler settle arm in the trait');
        $host = file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');
        foreach ([
            ['count' => 3, "usageRecordSink(), null, '"], // the three bare sink sites: compact, turn, warm
            ['count' => 3, ": null, 'aux')"],            // suggestion + auto-commit + dream (engine fallback)
            ['count' => 1, ": null, 'compact')"],        // consolidation (summary engine)
            ['count' => 1, ": null, 'goal')"],           // the judge (title engine)
        ] as $arm) {
            self::assertSame(
                $arm['count'],
                substr_count($host, $arm[0]),
                'serve settle arms carry their labels: ' . $arm[0],
            );
        }
        // Among those three, the dream arm runs on the engine the table was
        // handed — not a workspace backend — so its subject is pinned whole.
        self::assertStringContainsString(
            "\$engine instanceof EngineBackend ? \$engine->model() : null, 'aux'",
            $host,
        );
    }

    public function testTheLedgerSinksReadTheirSessionIdentityInsideTheClosure(): void
    {
        // rev-w4g MINOR-1: the sink docblock's late-mint law — the id is
        // resolved at CALL time — is invisible to behavior, because the
        // sink itself is constructed one expression before the ledger
        // invokes it, so "sink-build time" and "call time" cannot diverge
        // in a running test. This shape pin is what makes the sentence
        // load-bearing: hoist the id read above `return function` or
        // capture it in `use`, and it goes red.
        foreach ([
            ['/src/Chat.php', '$this->currentSessionId'],
            ['/src/Host/SessionHost.php', '$this->sessionId'],
        ] as [$file, $identityRead]) {
            $method = self::sinkMethodSlice(file_get_contents(dirname(__DIR__, 2) . $file));
            $built = strpos($method, 'return function');
            self::assertNotFalse($built, $file . ' must hand its sink back as a closure');
            self::assertStringContainsString(
                'use ($store): void',
                $method,
                $file . "'s sink captures the store and nothing else — an id stamped into `use` would freeze the pre-mint null",
            );
            self::assertNotFalse(
                strpos($method, $identityRead, $built),
                $file . ": {$identityRead} must be read INSIDE the closure — at call time",
            );
        }
    }

    /**
     * C-SES-8.b: the attribution columns ride the fresh CREATE, so a brand
     * new database needs no migration pass to answer `/usage sources`.
     */
    public function testAFreshLedgerCarriesTheAttributionAndDelegatedColumns(): void
    {
        new EnhancedSessionStore($this->dbPath);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $columns = array_column($pdo->query('PRAGMA table_info(usage_records)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('source', $columns, 'the settle-arm attribution the sources fold groups by');
        self::assertContains('delegated_tokens', $columns, 'the delegated prompt share every Usage already carries');
    }

    /**
     * C-SES-8.b MIGRATED_COLUMNS law, the usage_records shape: a file whose
     * ledger predates attribution gains both columns on the next open, the
     * old rows survive with honest NULLs (never an invented source), and the
     * fold groups them under that NULL beside the labelled rows.
     */
    public function testAnOldLedgerGainsTheAttributionColumnsWithoutRewritingItsHistory(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->appendUsageRecord('s1', Usage::new(100, 0.01), 'm');

        // The only faithful pre-column row: the columns the store just
        // created are dropped out from under it, leaving the old 11-column
        // shape and its row untouched.
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('ALTER TABLE usage_records DROP COLUMN delegated_tokens');
        $pdo->exec('ALTER TABLE usage_records DROP COLUMN source');
        unset($store);

        $store = new EnhancedSessionStore($this->dbPath);
        $columns = array_column($pdo->query('PRAGMA table_info(usage_records)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertContains('source', $columns, 'the guarded ALTER lands the column on the old file');
        self::assertContains('delegated_tokens', $columns);

        $old = $pdo->query('SELECT source, delegated_tokens FROM usage_records LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($old);
        self::assertNull($old['source'], 'history is not retroactively attributed');
        self::assertNull($old['delegated_tokens']);

        $store->appendUsageRecord('s1', Usage::new(100, 0.01), 'm');
        $fold = $store->usageSourceRollups('s1');
        self::assertCount(1, $fold, 'an unlabelled append folds under NULL exactly once, old row and new');
        self::assertNull($fold[0]['source']);
        self::assertSame(2, $fold[0]['calls']);
    }

    /**
     * C-SES-8.b: the fold by SOURCE — groups, sums, the delegated column
     * (COALESCEd — NULL rows contribute nothing), the sticky unpriced bit,
     * and newest-activity ordering, mirroring the model fold's laws.
     */
    public function testTheSourceFoldGroupsAttributesAndSumsTheDelegatedShare(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->appendUsageRecord('s1', Usage::new(1000, 0.01, delegatedTokens: 600), 'm', 'turn');
        $store->appendUsageRecord('s1', Usage::new(500, 0.02, delegatedTokens: 500), 'm', 'turn');
        $store->appendUsageRecord('s1', Usage::new(200, 0.005, unpricedModel: 'mystery'), 'm', 'compact');
        $store->appendUsageRecord('s2', Usage::new(50, 0.001), 'm', 'title');

        $one = $store->usageSourceRollups('s1');
        self::assertSame(['turn', 'compact'], array_column($one, 'source'), 'newest activity first, as the model fold orders');
        self::assertSame(2, $one[0]['calls']);
        self::assertSame(1500, $one[0]['totalTokens']);
        self::assertSame(1100, $one[0]['delegatedTokens'], 'the delegated share the total already carried, now kept');
        self::assertFalse($one[0]['unpriced']);
        self::assertTrue($one[1]['unpriced'], 'one blind row makes its whole group a bound');
        self::assertEqualsWithDelta(0.005, $one[1]['costUsd'], 1e-9);
        self::assertSame(0, $one[1]['delegatedTokens'], 'a NULL column COALESCEs to nothing, never to a missing figure');

        $all = $store->usageSourceRollups();
        $found = array_column($all, 'source');
        sort($found);
        $expected = ['compact', 'title', 'turn'];
        sort($expected);
        self::assertEqualsCanonicalizing($expected, $found, 'the unscoped fold spans every session the table holds');
    }

    /**
     * The usageRecordSink() method body: from its declaration to its
     * closing brace. Nested closure braces close at eight-space indent,
     * so the first four-space `    }` ends the method.
     */
    private static function sinkMethodSlice(string $source): string
    {
        $start = strpos($source, 'private function usageRecordSink');
        self::assertNotFalse($start, 'the sink method exists');
        $end = strpos($source, "\n    }", $start);
        self::assertNotFalse($end, 'the sink method closes');

        return substr($source, $start, $end - $start);
    }

    /**
     * The closure shape Chat's sink has at runtime: store append under the
     * ledger's null guard, model resolved per call.
     */
    private function sinkFor(EnhancedSessionStore $store, string $sessionId): \Closure
    {
        return static function (Usage $usage) use ($store, $sessionId): void {
            $store->appendUsageRecord($sessionId, $usage, 'test-model');
        };
    }

    private function createLegacyDatabase(): void
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
                name TEXT,
                metadata TEXT
            )
        ');
        $pdo->exec("INSERT INTO sessions (id, provider, model, name) VALUES ('legacy', 'p', 'm', 'Old work')");
    }
}
