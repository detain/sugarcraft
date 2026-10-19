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
        self::assertSame('Usage: /usage [all] — fold the durable ledger by day and model, this session or every session.', $added[1]->content);
    }

    public function testAChatWithoutADurableStoreSaysUnavailableRatherThanZeroSpent(): void
    {
        $page = array_slice($this->submit($this->usageChat(draft: '/usage'))->history, 2)[1]->content;

        self::assertSame(4, \count(explode("\n", $page)), 'title + subtitle + blank + stated reason');
        self::assertStringContainsString('Durable ledger: unavailable — no durable ledger store is open here', $page);
        self::assertStringNotContainsString('By day:', $page, 'an unreadable ledger does not pretend to have folded it');
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

    // ── the pure composer ────────────────────────────────────────────────

    public function testAnEmptyLedgerStillPrintsEveryHeaderWithItsZero(): void
    {
        $page = UsageCommand::compose(self::facts([], []));
        $lines = explode("\n", $page);

        self::assertCount(self::PAGE_SKELETON_LINES + 2, $lines, 'two sections, each with its `none` sentinel');
        self::assertStringContainsString('By day: 0 groups', $page);
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
            'By day: 2 groups',
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
        self::assertSame(1, preg_match_all('/^By day: \d+ groups$/m', $page));
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

        UsageCommand::compose(['scopeAll' => false, 'models' => [], 'reason' => '']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $days
     * @param list<array<string, mixed>> $models
     *
     * @return array<string, mixed>
     */
    private static function facts(array $days = [], array $models = []): array
    {
        return ['scopeAll' => false, 'days' => $days, 'models' => $models, 'reason' => ''];
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
