<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Util\TokenTracker;

/**
 * OBS-3 (plan_comparison): the model name threaded to the durable ledger,
 * and the day×model rollups that read it back.
 *
 * Before this, every auxiliary call — titler, suggestion, goal judge,
 * auto-commit, compaction, consolidation, handoff — was stamped with the
 * CHAT backend's model, because the sink could only read the live backend.
 * The threading seam is {@see SpendLedger::account()}'s fourth parameter:
 * the settle site that knows which engine ran passes the name, the sink
 * forwards it to the row, and the rollups GROUP BY day and model. The
 * `≥`-law (SES-1) rides every fold: a group with one unpriced row is a
 * group whose dollars are a bound.
 *
 * @see SpendLedger::account()
 * @see EnhancedSessionStore::usageDayModelRollups()
 */
final class UsageModelThreadingTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        // The fold's DAY boundary is what it groups by; this suite reads both
        // SQLite's UTC date(ts,'unixepoch') and PHP's date() against the same
        // stamps, so the clock is pinned UTC (house pattern) and the day
        // arithmetic cannot disagree with the database's.
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->tempDir = sys_get_temp_dir() . '/w6d_usage_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    // ---- threading seam (account → $record($usage, $model)) ----

    public function testTheLedgerForwardsTheThreadedModelToTheSink(): void
    {
        $seen = [];
        $sink = static function (Usage $usage, ?string $model = null) use (&$seen): void {
            $seen[] = [$usage->totalTokens, $model];
        };

        SpendLedger::new()->account(new TokenTracker(), Usage::new(10, 0.5), $sink, 'summarizer-9');

        self::assertSame([[10, 'summarizer-9']], $seen, 'the name the settle site knows reaches the row sink');
    }

    public function testTheModelIsUnknownWhenTheCallerDoesNotThreadOne(): void
    {
        $seen = [];
        $sink = static function (Usage $usage, ?string $model = null) use (&$seen): void {
            $seen[] = $model;
        };

        SpendLedger::new()->account(new TokenTracker(), Usage::new(10, 0.5), $sink);

        self::assertSame([null], $seen, 'no thread, no claim — the sink resolves its own honest fallback');
    }

    public function testANullUsageCrossesNoSeamAtAll(): void
    {
        $calls = 0;
        $sink = static function () use (&$calls): void {
            ++$calls;
        };

        SpendLedger::new()->account(new TokenTracker(), null, $sink, 'summarizer-9');

        self::assertSame(0, $calls, 'the choke guards the model the same way it guards the dollars');
    }

    public function testAOneParameterSinkStillRidesTheWidenedSeam(): void
    {
        // The arity-tolerance claim is load-bearing for every mirror that has
        // not caught up: user-defined functions accept extra arguments, so the
        // serve path's old one-parameter sink must keep working untouched.
        $seen = null;
        $sink = static function (Usage $usage) use (&$seen): void {
            $seen = $usage->totalTokens;
        };

        SpendLedger::new()->account(new TokenTracker(), Usage::new(7, 0.25), $sink, 'summarizer-9');

        self::assertSame(7, $seen, 'widening the seam broke no mirror');
    }

    public function testTheThreadedNameLandsInTheRowAndTheRollupSeesIt(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $tracker = new TokenTracker();
        $sink = static function (Usage $usage, ?string $model = null) use ($store): void {
            $store->appendUsageRecord('threaded-session', $usage, $model);
        };

        SpendLedger::new()->account($tracker, Usage::new(100, 1.0), $sink, 'summarizer-9');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $stored = $pdo->query("SELECT model FROM usage_records WHERE session_id = 'threaded-session'")->fetchColumn();
        self::assertSame('summarizer-9', $stored, 'the name travels the whole seam into the durable row');

        $rollup = $store->usageModelRollups('threaded-session');
        self::assertSame('summarizer-9', $rollup[0]['model']);
    }

    // ---- day×model rollups ----

    public function testTheDayModelFoldGroupsAndSumsPerCell(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->getTimestamp() + 3600;
        $yesterday = $today - 86400;

        // Two priced calls on one model today, one unpriced yesterday, plus a
        // second model today. N=2 priced + K=1 unpriced + M=1 other model.
        $this->record($store, 'day-model', Usage::new(100, 1.5), 'alpha', $today);
        $this->record($store, 'day-model', Usage::new(200, 2.5), 'alpha', $today);
        $this->record($store, 'day-model', Usage::new(50, 0.0), 'beta', $today);
        $this->record($store, 'day-model', Usage::new(40, 0.0), 'alpha', $yesterday);
        $unpriced = Usage::new(30, 0.0, unpricedModel: 'gamma');
        $this->record($store, 'day-model', $unpriced, 'gamma', $yesterday);

        $rows = $store->usageDayModelRollups('day-model');
        $todayDate = date('Y-m-d', $today);
        $yesterdayDate = date('Y-m-d', $yesterday);

        self::assertSame([
            ['day' => $todayDate, 'model' => 'alpha', 'calls' => 2, 'totalTokens' => 300, 'costUsd' => 4.0, 'unpriced' => false],
            ['day' => $todayDate, 'model' => 'beta', 'calls' => 1, 'totalTokens' => 50, 'costUsd' => 0.0, 'unpriced' => false],
            ['day' => $yesterdayDate, 'model' => 'alpha', 'calls' => 1, 'totalTokens' => 40, 'costUsd' => 0.0, 'unpriced' => false],
            ['day' => $yesterdayDate, 'model' => 'gamma', 'calls' => 1, 'totalTokens' => 30, 'costUsd' => 0.0, 'unpriced' => true],
        ], $rows, 'newest day first, model alphabetical inside a day, sums folded per cell');
    }

    public function testOneUnpricedRowMakesItsWholeGroupABound(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $this->record($store, 'bound', Usage::new(100, 1.0), 'alpha');
        $this->record($store, 'bound', Usage::new(20, 0.0, unpricedModel: 'alpha'), 'alpha');

        $rows = $store->usageDayModelRollups('bound');
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['unpriced'], 'MAX per group: a fold with one blind row is a blind fold');
    }

    public function testANamelessRowGroupsUnderNullModelAndTheWholeViewTurnsBound(): void
    {
        // Rows written before OBS-3 threaded a name carry NULL, and the fold
        // must keep them visible under their own (null) group rather than
        // inventing a name for them.
        $store = new EnhancedSessionStore($this->dbPath);
        $this->record($store, 'legacy', Usage::new(10, 0.5), null);

        $rows = $store->usageDayModelRollups('legacy');
        self::assertSame([[
            'day' => date('Y-m-d'),
            'model' => null,
            'calls' => 1,
            'totalTokens' => 10,
            'costUsd' => 0.5,
            'unpriced' => false,
        ]], $rows);
    }

    public function testTheModelFoldOrdersByNewestActivityAndFoldsAcrossDays(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $old = time() - 3 * 86400;
        $now = time();

        $this->record($store, 'model-fold', Usage::new(1000, 10.0), 'workhorse', $old);
        $this->record($store, 'model-fold', Usage::new(300, 3.0), 'workhorse', $now);
        $this->record($store, 'model-fold', Usage::new(50, 0.2), 'freshcomer', $now);

        $rows = $store->usageModelRollups('model-fold');
        self::assertSame(['workhorse', 'freshcomer'], array_column($rows, 'model'), 'most recent activity first');
        self::assertSame(2, $rows[0]['calls']);
        self::assertSame(1300, $rows[0]['totalTokens'], 'the per-model fold spans every day the model worked');
        self::assertSame(13.0, $rows[0]['costUsd']);
    }

    public function testTheGlobalFoldSpansEverySessionAndTheScopedFoldOne(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $this->record($store, 's1', Usage::new(100, 1.0), 'alpha');
        $this->record($store, 's2', Usage::new(200, 2.0), 'alpha');

        $global = $store->usageDayModelRollups();
        self::assertSame(2, $global[0]['calls'], 'null scope folds sessions together — that is what the global view is');
        self::assertSame(300, $global[0]['totalTokens']);

        $scoped = $store->usageDayModelRollups('s1');
        self::assertSame(1, $scoped[0]['calls']);
        self::assertSame(100, $scoped[0]['totalTokens']);
    }

    public function testAnEmptyLedgerFoldsToNothingNotZeros(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        self::assertSame([], $store->usageDayModelRollups());
        self::assertSame([], $store->usageModelRollups());
    }

    public function testTheProductionWiresThreadTheAuxiliaryEnginesModel(): void
    {
        // The threading sites only fire on live settles; this shape pin is
        // the house pin, mirroring SES-1's production-wire pin. Every
        // auxiliary settle site names the engine that RAN the call: the
        // titler, suggestion and goal judge run on $titleBackend, the
        // compaction, consolidation and handoff summaries on
        // $summaryBackend — and the main turn deliberately does NOT pass a
        // name, because the chat backend's live model is already the honest
        // answer there.
        $chat = file_get_contents(dirname(__DIR__, 2) . '/src/Chat.php');

        self::assertStringContainsString(
            '$this->spendLedger()->account($this->tokenTracker, $usage, $this->usageRecordSink(), $model);',
            $chat,
            'Chat accounts through the choke WITH the threaded model',
        );
        self::assertStringContainsString(
            '$model ?? self::modelOf($this->backend),',
            $chat,
            'the sink prefers the threaded name and falls back to the chat model exactly as before',
        );
        self::assertSame(5, substr_count($chat, 'self::modelOf($this->titleBackend)'), 'titler, suggestion, goal judge, auto-commit, btw');
        self::assertSame(3, substr_count($chat, 'self::modelOf($this->summaryBackend)'), 'compaction, consolidation, handoff');
        self::assertSame(1, substr_count($chat, 'private static function modelOf'), 'one helper, shared by every site');
    }

    private function record(EnhancedSessionStore $store, string $sessionId, Usage $usage, ?string $model, ?int $ts = null): void
    {
        // appendUsageRecord stamps time() itself; the rollups are the first
        // readers that care WHEN a row landed, so every fixture row here
        // rides the raw INSERT with an explicit stamp. The store argument
        // only exists to force schema creation before the first write.
        unset($store);
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('
            INSERT INTO usage_records
                (session_id, ts, model, total_tokens, input_tokens, output_tokens,
                 cache_read_tokens, cache_creation_tokens, reasoning_tokens, cost_usd, unpriced)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sessionId,
            $ts ?? time(),
            $model,
            $usage->totalTokens,
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cacheReadTokens,
            $usage->cacheCreationTokens,
            $usage->reasoningTokens,
            $usage->costUsd,
            $usage->unpricedModel !== null ? 1 : 0,
        ]);
    }
}
