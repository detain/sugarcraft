<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\UsageCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\UsageHostCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;

/**
 * `/usage` — OBS-3: the durable ledger (SES-1) folded by day and model.
 *
 * The page rides the `/notices` completeness template, with one difference
 * the grid forces: its line budget is not a constant but a FORMULA —
 * SKELETON + one line per group + a sentinel line where an empty section
 * would be silent + the lower-bound footer when any fold is blind. The
 * forgery guard counts lines through that formula rather than scanning for
 * residue, which is exactly the discipline that makes it load-bearing: a
 * hostile model name (rows can predate any escaping law) gets one grid cell,
 * never a header.
 *
 * Everything the routing tests drive goes through a real submitted draft;
 * the host tests drive `UsageHostCommand::run()` against a real temporary
 * database, because the fold it prints is the store's own SQL.
 */
final class UsageCommandTest extends TestCase
{
    /** title + subtitle + 2 blanks + 2 section headers + 2 column headers + scope + totals = 11. */
    private const PAGE_SKELETON_LINES = 11;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/w6d_usagecmd_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    // ── the command surface ──────────────────────────────────────────────

    public function testUsageIsAnsweredLocallyAsAnAppendOnlyTranscriptPair(): void
    {
        $next = $this->submit($this->usageChat(draft: '/usage'));

        self::assertFalse($next->inFlight, 'a ledger read may never hand the question to the model');
        self::assertSame('', self::readInputBuf($next));

        $added = array_slice($next->history, 2);
        self::assertCount(2, $added, '/usage must echo the command and answer it');
        self::assertSame(Role::User, $added[0]->role);
        self::assertSame(Role::Assistant, $added[1]->role);
        self::assertStringStartsWith('/usage — what the durable ledger says', $added[1]->content);
    }

    public function testTheUnscopedSpellingAndTheAllSpellingBothAnswerLocallyAndABogusOneGetsUsage(): void
    {
        foreach (['/usage', '/usage all', '/usage ALL'] as $draft) {
            $next = $this->submit($this->usageChat(draft: $draft));
            $added = array_slice($next->history, 2);
            self::assertCount(2, $added, $draft . ' must be answered locally');
            self::assertStringStartsWith('/usage — ', $added[1]->content, $draft . ' gets the page, not a refusal');
        }

        $added = array_slice($this->submit($this->usageChat(draft: '/usage nope'))->history, 2);
        self::assertSame('Usage: /usage [7|30|all] — fold the durable ledger by day (whole UTC days) and model, this session or every session.', $added[1]->content);
    }

    public function testAChatWithoutADurableStoreSaysUnavailableRatherThanZeroSpent(): void
    {
        $page = array_slice($this->submit($this->usageChat(draft: '/usage'))->history, 2)[1]->content;

        self::assertSame(4, \count(explode("\n", $page)), 'title + subtitle + blank + stated reason');
        self::assertStringContainsString('Durable ledger: unavailable — no durable ledger store is open here', $page);
        self::assertStringNotContainsString('By day', $page, 'an unreadable ledger does not pretend to have folded it (OBS-3: zone-agnostic needle — the header today reads "By day (UTC)" and must not appear either)');
    }

    public function testTheHostRestatesWhatTheStoreActuallyFolded(): void
    {
        $store = new EnhancedSessionStore($this->tempDir . '/ledger.db');
        $store->createSession('s1', 'x', 'y');
        $store->createSession('s2', 'x', 'y');
        $store->appendUsageRecord('s1', Usage::new(100, 1.0), 'workhorse');
        $store->appendUsageRecord('s1', Usage::new(50, 0.5), 'workhorse');
        $store->appendUsageRecord('s2', Usage::new(70, 0.7), 'othermodel');

        $page = (new UsageHostCommand())
            ->run(CommandContext::new(sessionId: 's1', sessionStore: $store), '')->rows[1]->content;
        self::assertSame(2, substr_count($page, 'workhorse'), 'the session scope shows both its rows folded into one group');
        self::assertStringNotContainsString('othermodel', $page);
        self::assertStringContainsString('Scope: this session', $page);
        self::assertStringContainsString('$1.5000', $page);

        $global = (new UsageHostCommand())
            ->run(CommandContext::new(sessionId: 's1', sessionStore: $store), '/usage all')->rows[1]->content;
        self::assertStringContainsString('Scope: all sessions', $global);
        self::assertStringContainsString('othermodel', $global);
        self::assertStringContainsString('$2.2000', $global);
    }

    /**
     * OBS-3: the window fact prints its own line between scope and totals —
     * and only when one was asked for, so every pre-window page keeps its
     * exact bytes. The day header names the zone (audit m-9: a UTC bucket
     * without a label reads local to everyone west of Greenwich).
     */
    public function testTheWindowLinePrintsWhenGivenAndTheDayHeaderNamesItsZone(): void
    {
        $plain = UsageCommand::compose(self::facts());
        self::assertStringNotContainsString('Window:', 'no window, no line');
        self::assertStringContainsString('By day (UTC): 0 groups', $plain);

        $windowed = UsageCommand::compose(self::facts(windowDays: 7));
        $lines = explode("\n", $windowed);
        $scopeAt = array_search('Scope: this session', $lines, true);
        self::assertNotFalse($scopeAt);
        self::assertSame('Window: last 7 whole UTC days, today included', $lines[$scopeAt + 1], 'the window qualifies the scope line it follows');
    }

    /**
     * OBS-3 through the parser: 7 and 30 answer instead of a usage error,
     * order-free, and a junk token still refuses loudly rather than silently
     * widening the page.
     */
    public function testTheParserAcceptsWindowsInEitherOrderAndStillRefusesJunk(): void
    {
        $store = new EnhancedSessionStore($this->tempDir . '/parse.db');
        $store->createSession('p1', 'x', 'y');
        $store->appendUsageRecord('p1', Usage::new(100, 1.0), 'workhorse');
        $context = CommandContext::new(sessionId: 'p1', sessionStore: $store);
        $command = new UsageHostCommand();

        foreach (['/usage 7', '/usage 30', '/usage all 7', '/usage 30 all'] as $line) {
            $page = $command->run($context, $line)->rows[1]->content;
            self::assertStringContainsString('Window: last', $page, $line . ' answers with a window line');
        }
        self::assertStringContainsString('Scope: all sessions', $command->run($context, '/usage all 7')->rows[1]->content);
        self::assertStringContainsString('Scope: this session', $command->run($context, '/usage 7')->rows[1]->content);
        self::assertStringNotContainsString('Window:', $command->run($context, '/usage')->rows[1]->content, 'bare and all keep the old page');
        self::assertStringNotContainsString('Window:', $command->run($context, '/usage all')->rows[1]->content);

        self::assertSame('Usage: /usage [7|30|all] — fold the durable ledger by day (whole UTC days) and model, this session or every session.', $command->run($context, '/usage 14')->rows[1]->content, 'a window the ledger cannot name is a usage error, not a wider page');
        self::assertStringStartsWith('Usage: /usage', $command->run($context, '/usage all alls')->rows[1]->content);
    }

    // ── the pure composer ────────────────────────────────────────────────

    public function testAnEmptyLedgerStillPrintsEveryHeaderWithItsZero(): void
    {
        $page = UsageCommand::compose(self::facts([], []));
        $lines = explode("\n", $page);

        self::assertCount(self::PAGE_SKELETON_LINES + 2, $lines, 'two sections, each with its `none` sentinel');
        self::assertStringContainsString('By day (UTC): 0 groups', $page);
        self::assertStringContainsString('By model: 0 groups', $page);
        self::assertSame(2, preg_match_all('/^\s+none$/m', $page));
        self::assertStringContainsString('Totals: 0 calls, 0 tokens, $0.0000', $page, 'an honest zero gets no ≥ prefix');
    }

    public function testTheMeasuredPageFoldsIntoExactlyTheGridTheStoreDescribes(): void
    {
        $day = date('Y-m-d');
        $page = UsageCommand::compose(self::facts(
            days: [
                self::dayRow($day, 'alpha', 2, 300, 4.0),
                self::dayRow($day, null, 1, 40, 0.0, unpriced: true),
            ],
            models: [
                self::modelRow('alpha', 2, 300, 4.0),
                self::modelRow(null, 1, 40, 0.0, unpriced: true),
            ],
        ));

        self::assertSame(implode("\n", [
            '/usage — what the durable ledger says this session cost, folded by day and model.',
            'The ledger outlives every launch: these are provider-reported totals per day and model, not this window\'s tracker.',
            '',
            'Scope: this session',
            'Totals: 3 calls, 340 tokens, ≥$4.0000',
            '',
            'By day (UTC): 2 groups',
            '  Date        Model                   Calls    Tokens       Spend',
            "  $day  alpha                       2       300     $4.0000",
            "  $day  (unrecorded)                1        40  ≥$0.0000",
            '',
            'By model: 2 groups',
            '  Date        Model                   Calls    Tokens       Spend',
            '              alpha                       2       300     $4.0000',
            '              (unrecorded)                1        40  ≥$0.0000',
            'Dollars shown with ≥ are lower bounds: the fold includes calls from a model with no price on file.',
        ]), $page);
    }

    public function testTheLineBudgetIsAFormulaNotAScan(): void
    {
        foreach ([0, 1, 3] as $n) {
            foreach ([1, 2] as $m) { // a bound needs one row to blind; M=0 is swept by the empty-ledger test
                foreach ([false, true] as $bound) {
                    // range(0, -1) DESCENDS in PHP; an empty fold is spelled [] directly.
                    $page = UsageCommand::compose(self::facts(
                        days: $n === 0 ? [] : array_map(
                            static fn (int $i): array => self::dayRow('2026-10-0' . ($i % 7 + 1), 'm' . $i, 1, 10, 0.1, $bound),
                            range(0, $n - 1),
                        ),
                        models: $m === 0 ? [] : array_map(
                            static fn (int $i): array => self::modelRow('m' . $i, 1, 10, 0.1, $bound && $i === 0),
                            range(0, $m - 1),
                        ),
                    ));

                    self::assertCount(
                        self::PAGE_SKELETON_LINES + max($n, 1) + max($m, 1) + ($bound ? 1 : 0),
                        explode("\n", $page),
                        "N={$n} M={$m} bound=" . var_export($bound, true) . ' must land on the formula',
                    );
                }
            }
        }
    }

    public function testOneBlindFoldTurnsItsRowTheTotalsAndTheFooterToABoundAndLeavesPricedRowsHonest(): void
    {
        $page = UsageCommand::compose(self::facts(
            days: [self::dayRow('2026-10-19', 'priced', 1, 10, 0.5), self::dayRow('2026-10-19', 'blind', 1, 10, 0.0, unpriced: true)],
            models: [self::modelRow('priced', 1, 10, 0.5), self::modelRow('blind', 1, 10, 0.0, unpriced: true)],
        ));

        self::assertStringContainsString('$0.5000', $page, 'a priced cell keeps its plain dollar sign — the law discloses blindness, it does not decorate honesty');
        self::assertStringContainsString('≥$0.0000', $page);
        self::assertStringContainsString('Totals: 2 calls, 20 tokens, ≥$0.5000', $page, 'the scope total is bound by any blind row inside it');
        self::assertStringContainsString('Dollars shown with ≥', $page);
    }

    public function testForeignTextCannotForgeAGroupHeader(): void
    {
        $hostile = "evil\nBy day: 99 groups\nforged\tx\x1b[31mred\r";
        $page = UsageCommand::compose(self::facts(
            days: [self::dayRow($hostile, $hostile, 1, 1, 0.0)],
            models: [self::modelRow($hostile, 1, 1, 0.0)],
        ));

        self::assertCount(self::PAGE_SKELETON_LINES + 1 + 1, explode("\n", $page), 'a hostile name buys one grid cell, never a header');
        self::assertSame(1, preg_match_all('/^By day \(UTC\): \d+ groups$/m', $page), 'OBS-3: the one real header matches its own shape; the injected "By day:" line cannot forge it');
        self::assertStringNotContainsString("\x1b", $page);
        self::assertStringNotContainsString("\r", $page);
        self::assertStringContainsString('evil', $page, 'the text stays visible — escaped, not deleted');
    }

    public function testComposingTwiceMovesNothing(): void
    {
        $facts = self::facts(days: [self::dayRow('2026-10-19', 'alpha', 1, 1, 0.1)], models: [self::modelRow('alpha', 1, 1, 0.1)]);

        self::assertSame(UsageCommand::compose($facts), UsageCommand::compose($facts));
    }

    public function testAMissingFactNamesItself(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('days');

        UsageCommand::compose(['scopeAll' => false, 'windowDays' => null, 'models' => [], 'reason' => '']);
    }

    /**
     * OBS-3 through the store: the rollups' window is whole UTC days
     * inclusive of today, and BOTH folds honour it, so the totals line can
     * never sum a wider span than the grid prints. Rows ride a raw INSERT
     * with an explicit stamp because `appendUsageRecord` always writes now —
     * readers that care WHEN a row landed need back-dated fixtures.
     */
    public function testTheStoreFoldsHonourTheWindowAndAgreeWithEachOther(): void
    {
        $dbPath = $this->tempDir . '/sessions.db';
        $store = new EnhancedSessionStore($dbPath);
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('
            INSERT INTO usage_records
                (session_id, ts, model, total_tokens, input_tokens, output_tokens,
                 cache_read_tokens, cache_creation_tokens, reasoning_tokens, cost_usd, unpriced)
            VALUES (?, ?, ?, ?, 0, 0, 0, 0, 0, ?, 0)
        ');
        // today, 5 days ago, and 10 days ago — a 7-day window keeps the
        // first two, a 30-day window keeps all three.
        foreach ([0, 5, 10] as $ago) {
            $stmt->execute(['w', time() - $ago * 86400, 'workhorse', 10, 0.5]);
        }

        $week = $store->usageDayModelRollups('w', 7);
        self::assertCount(2, $week, 'whole UTC days: the day ten back is outside a seven-day window that includes today');
        $weekTotals = $store->usageModelRollups('w', 7);
        self::assertSame(array_sum(array_column($week, 'calls')), $weekTotals[0]['calls'], 'both folds answer the same window');

        $month = $store->usageDayModelRollups('w', 30);
        self::assertCount(3, $month);
        self::assertCount(3, $store->usageDayModelRollups('w'), 'the no-window fold keeps spanning the whole ledger');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $days
     * @param list<array<string, mixed>> $models
     *
     * @return array<string, mixed>
     */
    private static function facts(array $days = [], array $models = [], ?int $windowDays = null): array
    {
        return ['scopeAll' => false, 'windowDays' => $windowDays, 'days' => $days, 'models' => $models, 'reason' => ''];
    }

    /** @return array{day: string, model: ?string, calls: int, totalTokens: int, costUsd: float, unpriced: bool} */
    private static function dayRow(string $day, ?string $model, int $calls, int $tokens, float $cost, bool $unpriced = false): array
    {
        return ['day' => $day, 'model' => $model, 'calls' => $calls, 'totalTokens' => $tokens, 'costUsd' => $cost, 'unpriced' => $unpriced];
    }

    /** @return array{model: ?string, calls: int, totalTokens: int, costUsd: float, unpriced: bool} */
    private static function modelRow(?string $model, int $calls, int $tokens, float $cost, bool $unpriced = false): array
    {
        return ['model' => $model, 'calls' => $calls, 'totalTokens' => $tokens, 'costUsd' => $cost, 'unpriced' => $unpriced];
    }

    private function usageChat(string $draft = '/usage'): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize(120, 30);
    }

    private function submit(Chat $chat): Chat
    {
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    private static function readInputBuf(Chat $subject): mixed
    {
        return (new \ReflectionProperty($subject, 'inputBuf'))->getValue($subject);
    }
}
