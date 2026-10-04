<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Backend\SummarisesWithCache;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Compaction\HistoryFingerprint;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.10: ahead-of-need summarisation. When the 70% reminder tier fires,
 * the turn goes out AND the summaries the 85% tier will need are requested in
 * the background; when the 85% tier then fires, those summaries are spliced in
 * and the prompt is sent at once instead of being parked behind a
 * summarization round-trip.
 *
 * Driven through the real `Chat::update()` with an 88,000-token window
 * (tiers at 61,600 / 74,800 / 83,600 estimated tokens).
 */
final class BackgroundSummarySpliceTest extends TestCase
{
    /**
     * 13 exchanges: three of ~20,750 estimated tokens and ten trivial ones, for
     * ~62,500 — over the 70% tier, under the 85% one.
     *
     * @return list<Message>
     */
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

    /** One record per offered exchange, and a state block. */
    private static function summaryReply(): string
    {
        $records = [];
        for ($i = 1; $i <= 6; $i++) {
            $records[] = "{$i}.\nasked: ahead-of-need record {$i}";
        }

        return implode("\n", $records)
            . "\n<session-state>\n## Goal\nkeep the router fast\n</session-state>";
    }

    private function chat(SpliceSummaryBackend $summarizer, string $reply = 'ok', ?HookManager $hooks = null): Chat
    {
        return new Chat(
            history: self::reminderBandPairs(),
            inputBuf: 'first question',
            backend: new SpliceTurnBackend(88_000, $reply),
            summaryBackend: $summarizer,
            hooks: $hooks,
        );
    }

    /** @return list<Msg> every Msg the Cmd produces, batches exploded, promises settled */
    private static function drain(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $out = $cmd();
        if ($out instanceof BatchMsg) {
            $msgs = [];
            foreach ($out->cmds as $inner) {
                array_push($msgs, ...self::drain($inner));
            }

            return $msgs;
        }
        if ($out instanceof AsyncCmd) {
            $resolved = null;
            $out->promise->then(static function ($msg) use (&$resolved): void {
                $resolved = $msg;
            });

            return $resolved instanceof Msg ? [$resolved] : [];
        }

        return $out instanceof Msg ? [$out] : [];
    }

    /** @param list<Msg> $msgs */
    private static function backgroundMsg(array $msgs): ?HistoryCompactedMsg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof HistoryCompactedMsg && $msg->background) {
                return $msg;
            }
        }

        return null;
    }

    /** @param list<Msg> $msgs */
    private static function feed(Chat $chat, array $msgs, bool $backgroundFirst = false): Chat
    {
        usort($msgs, static fn (Msg $a, Msg $b): int => $backgroundFirst
            ? (int) ($b instanceof HistoryCompactedMsg) <=> (int) ($a instanceof HistoryCompactedMsg)
            : (int) ($a instanceof HistoryCompactedMsg) <=> (int) ($b instanceof HistoryCompactedMsg));
        foreach ($msgs as $msg) {
            [$chat] = $chat->update($msg);
        }

        return $chat;
    }

    /** @return array{id: string, sessionId: ?string, fingerprint: HistoryFingerprint, summaries: ?array<string, string>}|null */
    private static function held(Chat $chat): ?array
    {
        return (new \ReflectionProperty(Chat::class, 'backgroundSummary'))->getValue($chat);
    }

    private static function latch(Chat $chat): ?string
    {
        return (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private static function agentText(array $history): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, Message::agentVisible($history)));
    }

    public function testTheReminderTierRequestsTheSummaryBesideTheTurnAndRewritesNothing(): void
    {
        $summarizer = new SpliceSummaryBackend(self::summaryReply());
        $chat = $this->chat($summarizer);

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertTrue($sent->inFlight, 'the turn went out at once');
        $this->assertNull(self::latch($sent), 'nothing is parked');
        $held = self::held($sent);
        $this->assertNotNull($held, 'the 70% tier asked for the summaries ahead of need');
        $this->assertNull($held['summaries'], 'in flight until the Cmd lands');
        $this->assertCount(4, $held['fingerprint']->exchangeKeys(), 'the three heavy exchanges and q0 leave the verbatim tail');
        $this->assertSame(0, $summarizer->calls, 'never inside update()');
        $this->assertStringNotContainsString('[summary]', self::agentText($sent->history), 'nothing rewritten');

        $msgs = self::drain($cmd);
        $background = self::backgroundMsg($msgs);
        $this->assertNotNull($background, 'the summary rides the turn\'s batch');
        $this->assertSame(1, $summarizer->calls);
        $this->assertSame($held['id'], $background->compactionId);
        $this->assertNull($background->parkedSubmission, 'no turn waits on it');
        $this->assertSame(
            array_map(static fn (Message $m): string => $m->content, self::reminderBandPairs()),
            array_map(static fn (Message $m): string => $m->content, Message::agentVisible($summarizer->seen)),
            'the conversation as it stood before this turn: the prompt dispatched beside it is neither summarised nor answered',
        );

        $landed = self::feed($sent, $msgs, backgroundFirst: true);
        $ready = self::held($landed);
        $this->assertNotNull($ready);
        $this->assertArrayHasKey(\SugarCraft\Crush\Context\Compaction\StateSummaryTemplate::SUMMARY_KEY, $ready['summaries']);
        $this->assertCount(5, $ready['summaries'], 'four records and the state block');
        $this->assertStringNotContainsString('[summary]', self::agentText($landed->history), 'landing applies nothing');
    }

    public function testTheEightyFiveTierSplicesAReadySummaryInsteadOfParking(): void
    {
        $summarizer = new SpliceSummaryBackend(self::summaryReply());
        // A large reply pushes the next submit past the 85% tier.
        $chat = $this->chat($summarizer, str_repeat('z', 60_000));

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $settled = self::feed($sent, self::drain($cmd));
        $this->assertFalse($settled->inFlight, 'turn one settled');
        $this->assertIsArray(self::held($settled)['summaries'] ?? null, 'the background summary landed');

        $main = (new \ReflectionProperty(Chat::class, 'backend'))->getValue($settled);
        $this->assertInstanceOf(SpliceTurnBackend::class, $main);
        $turnsBefore = $main->calls;

        $settled = $settled->update(new KeyMsg(KeyType::Char, 'x'))[0];
        [$next, $nextCmd] = $settled->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull(self::latch($next), 'the 85% tier did not park');
        $this->assertTrue($next->inFlight, 'the prompt went out immediately');
        $this->assertNull(self::held($next), 'the summary is used up');
        $text = self::agentText($next->history);
        $this->assertStringContainsString('ahead-of-need record 1', $text, 'the model\'s records were spliced in');
        $this->assertStringContainsString('keep the router fast', $text, 'and its state block');
        $this->assertStringNotContainsString(str_repeat('a', 1_000), $text, 'the heavy exchanges were condensed');
        $this->assertSame('x', self::lastUser($next->history), 'the prompt itself, once');

        self::drain($nextCmd);
        $this->assertSame($turnsBefore + 1, $main->calls, 'exactly one turn dispatched');
        $this->assertSame(1, $summarizer->calls, 'no second summarization was paid for');
        $this->assertStringContainsString('ahead-of-need record 2', self::agentText($main->lastHistory), 'the provider read the summaries');
    }

    public function testASummaryStillOutWhenTheTierFiresFallsBackToParking(): void
    {
        $summarizer = new SpliceSummaryBackend(self::summaryReply());
        $chat = $this->chat($summarizer, str_repeat('z', 60_000));

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $msgs = array_values(array_filter(self::drain($cmd), static fn (Msg $m): bool => !$m instanceof HistoryCompactedMsg));
        $settled = self::feed($sent, $msgs);
        $this->assertNull(self::held($settled)['summaries'], 'the background summary has not landed');

        $settled = $settled->update(new KeyMsg(KeyType::Char, 'x'))[0];
        [$parked] = $settled->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull(self::latch($parked), 'the tier parks exactly as before');
    }

    public function testBelowTheReminderTierNothingIsRequested(): void
    {
        $summarizer = new SpliceSummaryBackend(self::summaryReply());
        $chat = new Chat(
            history: [Message::user('hi'), Message::assistant('hello')],
            inputBuf: 'next',
            backend: new SpliceTurnBackend(88_000, 'ok'),
            summaryBackend: $summarizer,
        );

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull(self::held($sent));
        $this->assertNull(self::backgroundMsg(self::drain($cmd)));
        $this->assertSame(0, $summarizer->calls);
    }

    public function testNoSummaryBackendMeansNoBackgroundRequest(): void
    {
        $chat = new Chat(
            history: self::reminderBandPairs(),
            inputBuf: 'first question',
            backend: new SpliceTurnBackend(88_000, 'ok'),
        );

        [$sent] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertTrue($sent->inFlight);
        $this->assertNull(self::held($sent));
    }

    public function testTheBackgroundRequestRunsThePreCompactChainAsAutoAndTheSpliceFiresPostCompact(): void
    {
        $fired = new \ArrayObject();
        $registry = new HookRegistry();
        foreach ([HookEvent::PreCompact, HookEvent::PostCompact] as $event) {
            $registry->register(new SpliceRecordingHook($event, $fired));
        }
        $summarizer = new SpliceSummaryBackend(self::summaryReply());
        $chat = $this->chat($summarizer, str_repeat('z', 60_000), new HookManager($registry));

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(0, $fired, 'the chain never runs inside update()');
        $settled = self::feed($sent, self::drain($cmd));

        $pre = array_values(array_filter((array) $fired, static fn (array $f): bool => $f['event'] === 'PreCompact'));
        $this->assertCount(1, $pre);
        $this->assertSame('auto', json_decode($pre[0]['context']->toolInput, true)['trigger']);

        $settled = $settled->update(new KeyMsg(KeyType::Char, 'x'))[0];
        [, $nextCmd] = $settled->update(new KeyMsg(KeyType::Enter, ''));
        self::drain($nextCmd);

        $post = array_values(array_filter((array) $fired, static fn (array $f): bool => $f['event'] === 'PostCompact'));
        $this->assertCount(1, $post, 'the spliced compaction is reported to PostCompact');
        $this->assertSame('auto', json_decode($post[0]['context']->toolInput, true)['trigger']);
        $this->assertCount(1, $pre, 'and PreCompact is not asked a second time');
    }

    /** @param list<Message> $history */
    private static function lastUser(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role === Role::User) {
                return $history[$i]->content;
            }
        }

        return '';
    }
}

/** The conversation backend: a fixed window and a fixed reply, recording each turn. */
final class SpliceTurnBackend implements Backend, ReportsContextWindow
{
    public int $calls = 0;

    /** @var list<Message> */
    public array $lastHistory = [];

    public function __construct(private readonly int $window, private readonly string $reply)
    {
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;
        $this->lastHistory = $history;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;
        $this->lastHistory = $history;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }
}

/**
 * The summary backend: a fixed reply, counting its calls and keeping what it was
 * sent. It takes the cache-reusing route (roadmap 2.4-2), as the launch's does.
 */
final class SpliceSummaryBackend implements Backend, SummarisesWithCache
{
    public int $calls = 0;

    /** @var list<Message> */
    public array $seen = [];

    public function __construct(private readonly string $reply)
    {
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;
        $this->seen = $history;

        return Message::assistant($this->reply)->withUsage(Usage::new(100, 0.0));
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;
        $this->seen = $history;

        return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(100, 0.0)));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->calls++;
        $this->seen = $history;

        return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(100, 0.0)));
    }
}

/** Records every compaction hook it is asked, and allows. */
final class SpliceRecordingHook implements HookInterface
{
    public function __construct(private readonly HookEvent $event, private readonly \ArrayObject $fired)
    {
    }

    public function name(): string
    {
        return 'splice-recorder-' . $this->event->value;
    }

    public function event(): HookEvent
    {
        return $this->event;
    }

    public function matcher(): string
    {
        return '';
    }

    public function execute(HookContext $context): HookResult
    {
        $this->fired[] = ['event' => $this->event->value, 'context' => $context];

        return HookResult::allow();
    }
}
