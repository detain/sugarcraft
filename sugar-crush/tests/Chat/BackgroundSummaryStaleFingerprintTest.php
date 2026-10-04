<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Compaction\HistoryFingerprint;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * Roadmap 2.10, the guard half: a summary fetched ahead of need is spliced in
 * ONLY while its {@see HistoryFingerprint} still matches the history — the
 * exchanges it summarised still the leading run of those condensed now, under
 * the same prior summaries, in the same session. Anything else falls back to
 * the parked route the 85% tier always had, and a stale summary is never
 * written into the transcript.
 */
final class BackgroundSummaryStaleFingerprintTest extends TestCase
{
    private const RECORD = 'a held record that must not land';

    /** ~78,300 estimated tokens: over the 85% tier of an 88,000-token window. */
    private static function overTierPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 52_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 52_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    /** ~62,500 estimated tokens: in the 70%–85% band. */
    private static function reminderBandPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 41_500));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 41_500));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    /** @param list<Message> $history */
    private static function fingerprintOf(array $history): HistoryFingerprint
    {
        return CompactionService::new()->historyFingerprint(new ContextCompactor(CompactorConfig::new()), $history);
    }

    /** A ready summary map holding one record per key of $fingerprint. */
    private static function recordsFor(HistoryFingerprint $fingerprint): array
    {
        $summaries = [];
        foreach ($fingerprint->exchangeKeys() as $key) {
            $summaries[$key] = self::RECORD;
        }

        return [...$summaries, StateSummaryTemplate::SUMMARY_KEY => StateSummaryTemplate::new()->render()];
    }

    /**
     * @param list<Message> $history
     * @param array{id: string, sessionId: ?string, fingerprint: HistoryFingerprint, summaries: ?array<string, string>}|null $held
     */
    private function chat(array $history, ?array $held, ?TokenTracker $tracker = null, ?StaleFingerprintSummaryBackend &$summarizer = null): Chat
    {
        $summarizer = new StaleFingerprintSummaryBackend("1.\nasked: parked record");

        return new Chat(
            history: $history,
            inputBuf: 'the prompt',
            backend: new StaleFingerprintTurnBackend(88_000, 'ok'),
            tokenTracker: $tracker,
            summaryBackend: $summarizer,
            backgroundSummary: $held,
        );
    }

    private static function held(Chat $chat): ?array
    {
        return (new \ReflectionProperty(Chat::class, 'backgroundSummary'))->getValue($chat);
    }

    private static function latch(Chat $chat): ?string
    {
        return (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private static function mentions(array $history, string $text): bool
    {
        foreach ($history as $m) {
            if (str_contains($m->content, $text)) {
                return true;
            }
        }

        return false;
    }

    // ── the fingerprint ───────────────────────────────────────────────

    public function testAFingerprintMatchesItselfAndAnyHistoryItIsTheLeadingRunOf(): void
    {
        $exchanges = [['key' => 'k1'], ['key' => 'k2']];
        $fingerprint = HistoryFingerprint::of($exchanges, ['prior']);

        $this->assertTrue($fingerprint->matches(HistoryFingerprint::of($exchanges, ['prior'])));
        $this->assertTrue(
            $fingerprint->matches(HistoryFingerprint::of([...$exchanges, ['key' => 'k3']], ['prior'])),
            'exchanges that slid out of the tail since extend it at the end',
        );
        $this->assertSame(['k1', 'k2'], $fingerprint->exchangeKeys());
        $this->assertSame($fingerprint->digest(), HistoryFingerprint::of($exchanges, ['prior'])->digest());
    }

    public function testAFingerprintDoesNotMatchAnotherRunOtherPriorsOrNothing(): void
    {
        $fingerprint = HistoryFingerprint::of([['key' => 'k1'], ['key' => 'k2']], []);

        $this->assertFalse($fingerprint->matches(HistoryFingerprint::of([['key' => 'k2'], ['key' => 'k1']], [])), 'order counts');
        $this->assertFalse($fingerprint->matches(HistoryFingerprint::of([['key' => 'k1']], [])), 'a shorter run lost an exchange');
        $this->assertFalse($fingerprint->matches(HistoryFingerprint::of([['key' => 'k0'], ['key' => 'k1'], ['key' => 'k2']], [])), 'not the LEADING run');
        $this->assertFalse($fingerprint->matches(HistoryFingerprint::of([['key' => 'k1'], ['key' => 'k2']], ['a compaction landed'])), 'the prior summaries changed');
        $this->assertNotSame($fingerprint->digest(), HistoryFingerprint::of([['key' => 'k1'], ['key' => 'k2']], ['x'])->digest());

        $empty = HistoryFingerprint::of([], []);
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->matches($empty), 'a summary of nothing is never spliced');
    }

    public function testTheHistoryFingerprintChangesWhenAnEarlyExchangeDoes(): void
    {
        $history = self::overTierPairs();
        $rewound = $history;
        $rewound[0] = Message::user('a different first request');

        $this->assertFalse(self::fingerprintOf($history)->matches(self::fingerprintOf($rewound)));
        $this->assertTrue(self::fingerprintOf($history)->matches(self::fingerprintOf([...$history, Message::user('more'), Message::assistant('yes')])));
    }

    // ── the 85% tier ──────────────────────────────────────────────────

    public function testAMatchingReadySummaryIsSplicedWithoutParking(): void
    {
        $history = self::overTierPairs();
        $fingerprint = self::fingerprintOf($history);
        $chat = $this->chat($history, ['id' => 'bg', 'sessionId' => null, 'fingerprint' => $fingerprint, 'summaries' => self::recordsFor($fingerprint)], null, $summarizer);

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull(self::latch($next), 'control: a matching summary is spliced, nothing parks');
        $this->assertTrue(self::mentions(Message::agentVisible($next->history), self::RECORD));
        $this->assertSame(0, $summarizer->calls);
    }

    public function testAStaleFingerprintIsDiscardedAndTheTierParksAsBefore(): void
    {
        $history = self::overTierPairs();
        $other = $history;
        $other[0] = Message::user('what the history said when the summary was asked for');
        $stale = self::fingerprintOf($other);
        $chat = $this->chat($history, ['id' => 'bg', 'sessionId' => null, 'fingerprint' => $stale, 'summaries' => self::recordsFor($stale)]);

        [$parked, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull(self::latch($parked), 'the prompt is parked behind a fresh summarization');
        $this->assertNotNull($cmd);
        $this->assertFalse(self::mentions($parked->history, self::RECORD), 'the stale summary never reaches the transcript');
    }

    public function testASummaryHeldForAnotherSessionIsNeverSpliced(): void
    {
        $history = self::overTierPairs();
        $fingerprint = self::fingerprintOf($history);
        $chat = $this->chat($history, ['id' => 'bg', 'sessionId' => 'some-other-session', 'fingerprint' => $fingerprint, 'summaries' => self::recordsFor($fingerprint)]);

        [$parked] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull(self::latch($parked));
        $this->assertFalse(self::mentions($parked->history, self::RECORD));
    }

    public function testAFailedOrStillOutSummaryFallsBackToParking(): void
    {
        $history = self::overTierPairs();
        $fingerprint = self::fingerprintOf($history);

        foreach (['failed' => [], 'still out' => null] as $label => $summaries) {
            $chat = $this->chat($history, ['id' => 'bg', 'sessionId' => null, 'fingerprint' => $fingerprint, 'summaries' => $summaries]);
            [$parked] = $chat->update(new KeyMsg(KeyType::Enter, ''));
            $this->assertNotNull(self::latch($parked), "{$label}: parks as before");
        }
    }

    // ── the 70% tier ──────────────────────────────────────────────────

    public function testAReadySummaryThatNoLongerMatchesIsReplacedByAFreshRequest(): void
    {
        $history = self::reminderBandPairs();
        $other = $history;
        $other[0] = Message::user('rewound away');
        $stale = self::fingerprintOf($other);
        $chat = $this->chat($history, ['id' => 'old', 'sessionId' => null, 'fingerprint' => $stale, 'summaries' => self::recordsFor($stale)]);

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $held = self::held($sent);
        $this->assertNotNull($held);
        $this->assertNotSame('old', $held['id'], 'a fresh request replaced the stale summary');
        $this->assertNull($held['summaries']);
    }

    public function testAFailedSummaryThatStillMatchesIsNotReaskedThisCycle(): void
    {
        $history = self::reminderBandPairs();
        $fingerprint = self::fingerprintOf([...$history, Message::user('the prompt')]);
        $failed = ['id' => 'failed', 'sessionId' => null, 'fingerprint' => $fingerprint, 'summaries' => []];
        $chat = $this->chat($history, $failed, null, $summarizer);

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('failed', self::held($sent)['id'] ?? null, 'held, so a failing summariser is asked once per cycle');
        $this->assertSame(0, $summarizer->calls);
    }

    public function testBelowTheReminderTierALandedSummaryIsDropped(): void
    {
        $history = [Message::user('hi'), Message::assistant('hello')];
        $fingerprint = HistoryFingerprint::of([['key' => 'k']], []);
        $chat = $this->chat($history, ['id' => 'bg', 'sessionId' => null, 'fingerprint' => $fingerprint, 'summaries' => ['k' => 'x']]);

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull(self::held($sent));
    }

    // ── the landing ───────────────────────────────────────────────────

    public function testASupersededLandingIsDroppedButStillBilled(): void
    {
        $tracker = new TokenTracker();
        $fingerprint = HistoryFingerprint::of([['key' => 'k']], []);
        $held = ['id' => 'current', 'sessionId' => null, 'fingerprint' => $fingerprint, 'summaries' => null];
        $chat = $this->chat(self::reminderBandPairs(), $held, $tracker);

        [$after] = $chat->update(new HistoryCompactedMsg(
            'superseded',
            ['k' => 'x'],
            usage: Usage::new(1_000, 0.25),
            fingerprint: $fingerprint,
            background: true,
        ));

        $this->assertSame($held, self::held($after), 'the summary still out is untouched');
        $this->assertEqualsWithDelta(0.25, $tracker->totalCost(), 1e-9, 'the call was made on the user\'s key all the same');
    }

    public function testALandingForASessionSwitchedAwayFromIsDropped(): void
    {
        $fingerprint = HistoryFingerprint::of([['key' => 'k']], []);
        $chat = $this->chat(self::reminderBandPairs(), ['id' => 'bg', 'sessionId' => 'left-behind', 'fingerprint' => $fingerprint, 'summaries' => null]);

        [$after] = $chat->update(new HistoryCompactedMsg('bg', ['k' => 'x'], fingerprint: $fingerprint, background: true));

        $this->assertNull(self::held($after));
    }

    public function testAnUnusableLandingIsHeldAsEmpty(): void
    {
        $fingerprint = HistoryFingerprint::of([['key' => 'k']], []);
        $held = ['id' => 'bg', 'sessionId' => null, 'fingerprint' => $fingerprint, 'summaries' => null];

        foreach ([
            'error' => new HistoryCompactedMsg('bg', [], 'provider down', fingerprint: $fingerprint, background: true),
            'blocked' => new HistoryCompactedMsg('bg', blockedBy: 'no', fingerprint: $fingerprint, background: true),
        ] as $label => $msg) {
            [$after] = $this->chat(self::reminderBandPairs(), $held)->update($msg);
            $this->assertSame([], self::held($after)['summaries'] ?? null, $label);
            $this->assertFalse(self::mentions($after->history, 'provider down'), "{$label}: nothing written to the transcript");
        }
    }
}

/** The conversation backend: a fixed window, answering every turn with "ok". */
final class StaleFingerprintTurnBackend implements Backend, ReportsContextWindow
{
    public function __construct(private readonly int $window, private readonly string $reply)
    {
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        return \React\Promise\resolve(Message::assistant($this->reply));
    }
}

/** A tool-less summary backend counting its calls. */
final class StaleFingerprintSummaryBackend implements Backend
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
