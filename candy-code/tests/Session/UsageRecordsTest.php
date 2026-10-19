<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SpendLedger;
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
        self::assertContains('idx_usage_records_session', $tables, 'the rollups read one session by id; nothing scans by any other key');
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
            '$this->spendLedger()->account($this->tokenTracker, $usage, $this->usageRecordSink());',
            $chat,
        );

        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        self::assertMatchesRegularExpression(
            '/tokenTracker: \$sessionStore instanceof EnhancedSessionStore\s*\?\s*\$sessionStore->resumedTokenTracker\(\$sessionId\)/',
            $bootstrap,
        );

        // SES-1, rev-w4g MAJOR-1: the host's OWN accounting sites carry the
        // sink too — serve is SessionHost, not Chat, and an unwired site
        // persists nothing. Both shapes are pinned verbatim: one string per
        // live call into the choke.
        $host = file_get_contents(dirname(__DIR__, 2) . '/src/Host/SessionHost.php');
        foreach ([
            '$this->ledger()->account($this->spend, $msg->usage, $this->usageRecordSink());',
            '$this->ledger()->account($this->spend, $reply->usage, $this->usageRecordSink());',
        ] as $wire) {
            self::assertStringContainsString($wire, $host, 'serve accounts through the choke WITH the ledger sink');
        }
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
