<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\HistoryCompactedMsg;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Util\TokenTracker;

/**
 * CTX-5: the opt-in idle auto-compaction fold (`compaction.idleAuto`, default
 * off). The fold rides the parked tier's machinery — same backend choice,
 * same PreCompact `auto` trigger, same breaker — but lands as an automatic
 * rewrite of the transcript with NO turn dispatched, because nobody sent one.
 *
 * These pins cover the three things the machinery does not already pin:
 * the WATCH (gates that arm/disarm the tick), the TICK'S VERDICT (silent for
 * every "not now", request out only for all-yes), and the LANDING (folds in
 * place, releases the latch on every early out, degrades HOLD, refuses to
 * rewrite under a live turn or a stale fingerprint).
 */
final class IdleAutoCompactionTest extends TestCase
{
    private const RECORD = 'a record the idle fold wrote';

    private const FOLDED_MARK = 'Compacted during the idle';

    /** ~80k estimated tokens: over the 85% tier of an 88,000-token window. */
    private static function bigIdlePairs(): array
    {
        $pairs = [];
        for ($i = 0; $i < 4; $i++) {
            $pairs[] = Message::user(str_repeat(chr(97 + $i), 51_000));
            $pairs[] = Message::assistant(str_repeat(chr(111 + $i), 51_000));
        }
        for ($i = 0; $i < 8; $i++) {
            $pairs[] = Message::user("late question {$i}");
            $pairs[] = Message::assistant("late answer {$i}");
        }

        return $pairs;
    }

    /** Over the tier, but everything sits in the verbatim tail: the heuristic
     * has no leading run to condense, so a fold there buys nothing. */
    private static function tailHeavyIdlePairs(): array
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = Message::user(str_repeat(chr(113 + $i), 240_000));
            $rows[] = Message::assistant(str_repeat(chr(119 + $i), 240_000));
        }

        return $rows;
    }

    /** Over the tier with a condensible leading run AND a verbatim tail that
     * alone outweighs the tier: the fold lands, but it cannot clear the tier,
     * so the breaker measures a refill that did not deliver a turn. */
    private static function breakerIdlePairs(): array
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = Message::user(str_repeat(chr(97 + $i), 51_000));
            $rows[] = Message::assistant(str_repeat(chr(111 + $i), 51_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $rows[] = Message::user('tail question ' . $i . ': ' . str_repeat(chr(117 + $i % 6), 16_000));
            $rows[] = Message::assistant('tail answer ' . $i . ': ' . str_repeat(chr(105 + $i % 6), 16_000));
        }

        return $rows;
    }

    /** @param list<Message> $history */
    private static function idleFingerprint(array $history): HistoryFingerprint
    {
        return CompactionService::new()->historyFingerprint(new ContextCompactor(CompactorConfig::new()), $history);
    }

    /** One record per exchange key of $fingerprint. */
    private static function idleRecords(HistoryFingerprint $fingerprint): array
    {
        $records = [];
        foreach ($fingerprint->exchangeKeys() as $key) {
            $records[$key] = self::RECORD;
        }

        return [...$records, StateSummaryTemplate::SUMMARY_KEY => StateSummaryTemplate::new()->render()];
    }

    private function watchingConfig(): CompactorConfig
    {
        return CompactorConfig::new()->withIdleAuto(true);
    }

    /**
     * @param list<Message> $history
     * @param array{id: string, fingerprint: HistoryFingerprint, sessionId: ?string}|null $latch
     */
    private function chat(
        array $history,
        ?CompactorConfig $config = null,
        ?\DateTimeImmutable $idleSince = null,
        ?array $latch = null,
        ?TokenTracker $tracker = null,
        ?float $cap = null,
        bool $inFlight = false,
        bool $readOnly = false,
        ?string $pending = null,
        int $refills = 0,
        ?IdleSummarySpy &$summarizer = null,
    ): Chat {
        $summarizer = new IdleSummarySpy("1.\nidle model record");

        return new Chat(
            history: $history,
            backend: new IdleTurnSpy(),
            compactorConfig: $config ?? $this->watchingConfig(),
            lastActivityAt: $idleSince,
            inFlight: $inFlight,
            readOnlySession: $readOnly,
            maxCostUsd: $cap,
            tokenTracker: $tracker,
            summaryBackend: $summarizer,
            pendingCompactionId: $pending,
            pendingIdleCompaction: $latch,
            consecutiveRefillCompactions: $refills,
        );
    }

    /** @return ?array{id:string,fingerprint:HistoryFingerprint,sessionId:?string} */
    private static function latchOf(Chat $chat): ?array
    {
        return (new \ReflectionProperty(Chat::class, 'pendingIdleCompaction'))->getValue($chat);
    }

    /** Agent-visible content bytes: compaction HIDES rows rather than
     * deleting them (roadmap 1.B-3), so the raw count is the wrong measure —
     * the wire the model actually reads is the right one.
     *
     * @param list<Message> $history
     */
    private static function idleWireSize(array $history): int
    {
        $bytes = 0;
        foreach (Message::agentVisible($history) as $message) {
            $bytes += strlen($message->content);
        }

        return $bytes;
    }

    private static function mentions(array $history, string $text): bool
    {
        foreach ($history as $message) {
            if (str_contains($message->content, $text)) {
                return true;
            }
        }

        return false;
    }

    // ── the watch ─────────────────────────────────────────────────────

    public function testTheDefaultConfigNeverWatchesAndNeverFolds(): void
    {
        $summarizer = null;
        $chat = $this->chat(
            self::bigIdlePairs(),
            config: CompactorConfig::new(), // idleAuto defaults false
            idleSince: new \DateTimeImmutable('-2 hours'),
            summarizer: $summarizer,
        );

        $this->assertFalse($chat->shouldWatchIdleCompaction());

        [$after, $cmd] = $chat->update(new \SugarCraft\Code\IdleCompactTickMsg());
        $this->assertSame($chat, $after, 'a tick into an opted-out session changes nothing');
        $this->assertNull($cmd);
        $this->assertSame(0, $summarizer->calls);
    }

    public function testTheWatchGateRefusesEveryMachineLevelReason(): void
    {
        $idle = new \DateTimeImmutable('-2 hours');
        $latch = ['id' => 'out', 'fingerprint' => self::idleFingerprint(self::bigIdlePairs()), 'sessionId' => null];

        $cases = [
            'opt-out' => ['config' => CompactorConfig::new()],
            'tier off' => ['config' => $this->watchingConfig()->withMode(CompactorConfig::MODE_OFF)],
            'no window' => ['config' => $this->watchingConfig()->withIdleOfferSeconds(0)],
            'read-only' => ['readOnly' => true],
            'compact out' => ['pending' => 'manual'],
            'fold out' => ['latch' => $latch],
            'breaker tripped' => ['refills' => IdleCompactionPolicy::REFILL_LIMIT],
        ];

        foreach ($cases as $label => $args) {
            $chat = $this->chat(self::bigIdlePairs(), ...$args + ['idleSince' => $idle]);
            $this->assertFalse($chat->shouldWatchIdleCompaction(), $label);
        }

        $this->assertTrue($this->chat(self::bigIdlePairs(), idleSince: $idle)->shouldWatchIdleCompaction(), 'control: all clear');
    }

    public function testTheAppArmsTheTickOnlyWhileTheChatWouldWatch(): void
    {
        $watching = $this->chat(self::bigIdlePairs(), idleSince: new \DateTimeImmutable('-2 hours'));
        [$app] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($watching)
            ->update(new WindowSizeMsg(120, 40));

        $this->assertTrue($app->subscriptions()?->has(App::IDLE_COMPACT_SUBSCRIPTION) ?? false);

        $off = $this->chat(self::bigIdlePairs(), config: CompactorConfig::new(), idleSince: new \DateTimeImmutable('-2 hours'));
        [$quietApp] = App::new($this->createMock(ProviderInterface::class), 'm')
            ->withChat($off)
            ->update(new WindowSizeMsg(120, 40));

        // subscriptions() is legitimately NULL when nothing is declared — that
        // is the quiet shape too, so the pin is "not armed", not "has() false".
        $this->assertNotSame(true, $quietApp->subscriptions()?->has(App::IDLE_COMPACT_SUBSCRIPTION), 'an opted-out chat carries no idle tick');
    }

    // ── the tick's verdict ────────────────────────────────────────────

    public function testATickOverTierAndIdleSendsTheUnattendedRequest(): void
    {
        $chat = $this->chat(self::bigIdlePairs(), idleSince: new \DateTimeImmutable('-2 hours'), summarizer: $summarizer);

        [$next, $cmd] = $chat->update(new \SugarCraft\Code\IdleCompactTickMsg());

        $this->assertNotNull($cmd, 'the request rides a Cmd like every other summarization');
        $this->assertSame(0, $summarizer->calls, 'the tick builds the request; executing it is the runner\'s');
        $latch = self::latchOf($next);
        $this->assertNotNull($latch);
        $this->assertNotSame('', $latch['id']);
        $this->assertTrue(
            $latch['fingerprint']->matches(self::idleFingerprint($chat->history)),
            'the latch carries the fingerprint of the history the summaries were asked against',
        );
        $this->assertFalse($next->shouldWatchIdleCompaction(), 'a fold out disarms the watch — one request at a time');
    }

    public function testTheTickStaysSilentForEveryNotNow(): void
    {
        $tick = new \SugarCraft\Code\IdleCompactTickMsg();

        // Under tier (short transcript), however long the idle.
        $short = $this->chat([Message::user('hi'), Message::assistant('hello')], idleSince: new \DateTimeImmutable('-7 days'));
        [$after, $cmd] = $short->update($tick);
        $this->assertSame($short, $after);
        $this->assertNull($cmd);

        // Over tier but just active: the clock is the AND-side, never a reason alone.
        $busy = $this->chat(self::bigIdlePairs(), idleSince: new \DateTimeImmutable('-5 minutes'));
        [$after, $cmd] = $busy->update($tick);
        $this->assertNull($cmd, 'five minutes into a 3600-second window: no request');
        $this->assertNull(self::latchOf($after));

        // Input in the box: the user is about to be back.
        $typed = $this->chat(self::bigIdlePairs(), idleSince: new \DateTimeImmutable('-2 hours'));
        $withInput = (new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($typed, ['inputBuf' => 'half a thought']);
        [$after, $cmd] = $withInput->update($tick);
        $this->assertNull($cmd, 'a draft in the box silences this minute without disarming the watch');
        $this->assertTrue($after->shouldWatchIdleCompaction(), 'transient silences never disarm');
    }

    public function testTheHeuristicModeFoldsOnTheSpotWithoutAskingTheModel(): void
    {
        $chat = $this->chat(
            self::bigIdlePairs(),
            config: $this->watchingConfig()->withMode(CompactorConfig::MODE_HEURISTIC),
            idleSince: new \DateTimeImmutable('-2 hours'),
            summarizer: $summarizer,
        );

        [$next, $cmd] = $chat->update(new \SugarCraft\Code\IdleCompactTickMsg());

        $this->assertSame(0, $summarizer->calls, 'heuristic mode never pays for a round-trip');
        $this->assertTrue(self::mentions($next->history, self::FOLDED_MARK), 'the free fold landed immediately');
        $this->assertNull(self::latchOf($next), 'nothing is outstanding');
        $this->assertLessThan(self::idleWireSize($chat->history), self::idleWireSize($next->history), 'the agent-visible wire actually shrank');
    }

    public function testTheSpendCapFoldsHeuristicallyAndNeverTheModel(): void
    {
        $spent = new TokenTracker();
        $spent->addTotalUsage(100, 0.06);
        $chat = $this->chat(
            self::bigIdlePairs(),
            idleSince: new \DateTimeImmutable('-2 hours'),
            cap: 0.05,
            tracker: $spent,
            summarizer: $summarizer,
        );

        [$next, $cmd] = $chat->update(new \SugarCraft\Code\IdleCompactTickMsg());

        $this->assertSame(0, $summarizer->calls, 'a cap-breached session cannot buy the model\'s opinion');
        $this->assertTrue(self::mentions($next->history, self::FOLDED_MARK), 'the free fold still ran');
        $this->assertNull(self::latchOf($next));
    }

    public function testAFoldThatBuysNothingStaysQuietAndSelfLimits(): void
    {
        $spent = new TokenTracker();
        $spent->addTotalUsage(100, 0.06);
        $chat = $this->chat(
            self::tailHeavyIdlePairs(),
            idleSince: new \DateTimeImmutable('-2 hours'),
            cap: 0.05,
            tracker: $spent,
        );

        // The over-tier test passes (it is what routes into the fold), but the
        // heuristic has no leading run to condense — nothing lands, no row.
        $before = $chat->history;
        [$next, $cmd] = $chat->update(new \SugarCraft\Code\IdleCompactTickMsg());

        $this->assertFalse(self::mentions($next->history, self::FOLDED_MARK), 'a silent zero-savings attempt');
        $this->assertSame($before, $next->history);
        $this->assertNull(self::latchOf($next));
    }

    // ── the landing ───────────────────────────────────────────────────

    public function testTheLandingFoldsInPlaceAndDispatchesNoTurn(): void
    {
        $history = self::bigIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => null]);

        [$next, $cmd] = $chat->update(new HistoryCompactedMsg('ic-1', self::idleRecords($fingerprint), fingerprint: $fingerprint));

        $this->assertTrue(self::mentions($next->history, self::RECORD), 'the summaries landed');
        $this->assertTrue(self::mentions($next->history, self::FOLDED_MARK), 'the transcript says the idle did it');
        $folded = $next->history[count($next->history) - 1];
        $this->assertTrue($folded->uiOnly, 'the announcement is for the human, not the model');
        $this->assertNull(self::latchOf($next));
        $this->assertFalse($next->inFlight, 'nobody sent anything — no turn started');
        $this->assertLessThan(self::idleWireSize($history), self::idleWireSize($next->history), 'the leading run was condensed');
        // $cmd is the postCompactCmd closure: the /compact report hook, never a turn.
        // Running it here would need the app runner; its presence is pinned by the
        // parked route's identical postCompactCmd already.
    }

    public function testALandingUnderALiveTurnReleasesQuietly(): void
    {
        $history = self::bigIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => null], inFlight: true);

        [$next] = $chat->update(new HistoryCompactedMsg('ic-1', self::idleRecords($fingerprint), fingerprint: $fingerprint));

        $this->assertSame($chat->history, $next->history, 'no rewrite under a live stream');
        $this->assertNull(self::latchOf($next), 'the latch releases — the watch may re-arm');
    }

    public function testALandingWhoseFingerprintWentStaleIsDiscarded(): void
    {
        $history = self::bigIdlePairs();
        $askedAgainst = $history;
        $askedAgainst[0] = Message::user('what the transcript said when the fold asked');
        $stale = self::idleFingerprint($askedAgainst);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $stale, 'sessionId' => null]);

        [$next] = $chat->update(new HistoryCompactedMsg('ic-1', self::idleRecords($stale), fingerprint: $stale));

        $this->assertFalse(self::mentions($next->history, self::RECORD), 'summaries of a history that no longer leads never land');
        $this->assertNull(self::latchOf($next));
    }

    public function testALandingFromAnotherSessionReleasesTheLatch(): void
    {
        $history = self::bigIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => 'left-behind']);

        [$next] = $chat->update(new HistoryCompactedMsg('ic-1', self::idleRecords($fingerprint), fingerprint: $fingerprint));

        $this->assertSame($chat->history, $next->history);
        $this->assertNull(self::latchOf($next));
    }

    public function testABlockedLandingWritesOneNoticeAndFoldsNothing(): void
    {
        $history = self::bigIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => null]);

        [$next] = $chat->update(new HistoryCompactedMsg('ic-1', blockedBy: 'a PreCompact hook', fingerprint: $fingerprint));

        $this->assertTrue(self::mentions($next->history, 'PreCompact'), 'the user learns the hook stopped it');
        $this->assertFalse(self::mentions($next->history, self::FOLDED_MARK), 'nothing was folded');
        $this->assertCount(count($history) + 1, $next->history);
        $this->assertNull(self::latchOf($next));
    }

    public function testAHoldVerdictDegradesToTheHeuristicFoldWithTheAdvisory(): void
    {
        $history = self::bigIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => null]);

        [$next] = $chat->update(new HistoryCompactedMsg(
            'ic-1',
            ['k-whatever' => 'model text that must not land'],
            safeguardHold: 'collapsed state block',
            fingerprint: $fingerprint,
        ));

        $this->assertFalse(self::mentions($next->history, 'model text that must not land'), 'the HOLD text never enters the transcript');
        $this->assertTrue(self::mentions($next->history, self::FOLDED_MARK), 'the fold degraded instead of refusing');
        $this->assertNull(self::latchOf($next));
        $this->assertLessThan(self::idleWireSize($history), self::idleWireSize($next->history), 'the heuristic condensed the leading run');
    }

    public function testTheLandingMeasuresTheBreakerWithoutCountingATurn(): void
    {
        $history = self::breakerIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, latch: ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => null]);

        [$next] = $chat->update(new HistoryCompactedMsg('ic-1', self::idleRecords($fingerprint), fingerprint: $fingerprint));

        $refills = (new \ReflectionProperty(Chat::class, 'consecutiveRefillCompactions'))->getValue($next);
        // The tail exchanges are verbatim-protected and alone outweigh the 85%
        // tier, so the fold — however real — leaves the transcript over tier:
        // a refill that did NOT clear it with no turn sent. The run extends.
        $this->assertTrue(self::mentions($next->history, self::FOLDED_MARK), 'control: the fold did land');
        $this->assertSame(1, $refills, 'turnSent: false — a futile idle fold extends the breaker run');
    }

    public function testASupersededTickRequestStillRoutesToTheCompactLatch(): void
    {
        // Control pin: an idle fold is OUT, /compact parks on the same
        // transcript, and the IDLE message shape must not be applied by the
        // compact route nor vice versa. Here a landing for an unknown id is
        // dropped entirely once no latches reference it.
        $history = self::bigIdlePairs();
        $fingerprint = self::idleFingerprint($history);
        $chat = $this->chat($history, pending: 'manual');

        [$next] = $chat->update(new HistoryCompactedMsg('ic-ghost', self::idleRecords($fingerprint), fingerprint: $fingerprint));

        $this->assertFalse(self::mentions($next->history, self::RECORD), 'a landing for an id nothing asked is dropped');
        $this->assertSame('manual', (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat));
    }

    public function testTheTickDoesNotRaceAnAheadOfNeedRequest(): void
    {
        // Both watches want the same transcript rewritten; the membership
        // gate keeps the idle tick from arming while a background summary is
        // held, and the dispatch gate (pinned here at the Chat level) keeps
        // the idle fold from asking while the tick's own latch is out.
        $chat = $this->chat(self::bigIdlePairs(), idleSince: new \DateTimeImmutable('-2 hours'));
        [$folded] = $chat->update(new \SugarCraft\Code\IdleCompactTickMsg());
        $this->assertNotNull(self::latchOf($folded), 'control: the fold is out');

        [, $second] = $folded->update(new \SugarCraft\Code\IdleCompactTickMsg());
        $this->assertNull($second, 'a second tick while the first is out asks nobody');
    }
}

/** The conversation backend for idle fixtures: fixed window, canned reply. */
final class IdleTurnSpy implements Backend, ReportsContextWindow
{
    public function contextWindow(): int
    {
        return 88_000;
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        return Message::assistant('idle reply');
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        return \React\Promise\resolve(Message::assistant('idle reply'));
    }
}

/** A summarisation backend counting its calls. */
final class IdleSummarySpy implements Backend
{
    public int $calls = 0;

    public function __construct(private readonly string $reply)
    {
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }
}
