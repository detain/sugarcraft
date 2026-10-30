<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;

/**
 * C-CTX-5.b: the ahead-of-need prefetch obeys OpenCode's skip rules, and both
 * hosts ask the same one gate — {@see CompactionService::aheadOfNeedAllowed()}.
 * A session under the named token floor never prefetches, and a summary held
 * for a model the session has since switched away from is discarded rather
 * than spliced. The by-design consequence pinned here too: a small-window
 * model whose 70% tier sits under the floor never prefetches yet still folds
 * at the 85% tier — skipping the prefetch costs latency at the fold, never the
 * fold itself.
 *
 * Mirrors the numbers of {@see SessionHostAheadOfNeedTest}: an 88,000-token
 * window puts the tiers at 61,600 / 74,800 estimated tokens; the ~62,500 band
 * rides the reminder tier UNDER the 64,000 floor, and the ~69,300 band clears
 * both. The small-window fixture uses a 60,000-token window — tiers at 42,000
 * and 51,000 — with an estimated ~46,400 band: on the reminder tier, under
 * both the blocking tier and the floor.
 *
 * Round-1 review cures pinned below: the drop arm's rule is model-only
 * (the floor governs the request alone), and the idle watch stamps its
 * model exactly like the dispatch arm, so a switch discards idle slots too.
 */
final class AheadOfNeedSkipRulesTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    /** @var list<SessionHost> hosts opted into the idle fold — their alarm must not outlive the test */
    private array $idleOptedHosts = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-skip-rules-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach ($this->idleOptedHosts as $host) {
            $timer = (new \ReflectionProperty(SessionHost::class, 'idleAutoTimer'))->getValue($host);
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }
        $this->idleOptedHosts = [];
        foreach (glob($this->dir . '/' . chr(42)) ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    /**
     * The gate's own truth table, so the two hosts' pins below cannot pass on
     * an accidentally-permanent true: the floor is a named 64,000 and the
     * model half fires only for a slot that carries one.
     */
    public function testTheGateOwnsTheFloorAndTheModelIdentity(): void
    {
        $this->assertSame(64_000, CompactionService::AHEAD_OF_NEED_TOKEN_FLOOR, 'the floor is the named constant, 64k as OpenCode');

        $this->assertTrue(CompactionService::aheadOfNeedAllowed(64_000), 'at the floor the request goes out');
        $this->assertTrue(CompactionService::aheadOfNeedAllowed(100_000), 'and above it');
        $this->assertFalse(CompactionService::aheadOfNeedAllowed(63_999), 'one under and the prefetch is skipped');
        $this->assertTrue(CompactionService::aheadOfNeedAllowed(100_000, 'm-a', 'm-a'), 'a slot held for the current model is kept');
        $this->assertFalse(CompactionService::aheadOfNeedAllowed(100_000, 'm-a', 'm-b'), 'a slot held for another model is not, however large');
        $this->assertTrue(CompactionService::aheadOfNeedAllowed(100_000, null, 'm-b'), 'an un-stamped slot predates the rule: the model half leaves it alone');
        $this->assertFalse(CompactionService::aheadOfNeedAllowed(10_000, 'm-a', 'm-a'), 'the floor still governs a same-model held slot');

        $this->assertTrue(CompactionService::aheadOfNeedModelMatches(null, 'm-b'), 'a slot that carries no model at all is left alone');
        $this->assertTrue(CompactionService::aheadOfNeedModelMatches('m-a', 'm-a'), 'the drop rule keeps a same-model slot however small the session');
        $this->assertTrue(CompactionService::aheadOfNeedModelMatches('', ''), 'an idle slot asked under the workspace default matches it');
        $this->assertFalse(CompactionService::aheadOfNeedModelMatches('m-a', 'm-b'), 'the drop rule discards a slot held for another model');
    }

    // ---------------------------------------------------------------- host side

    public function testTheHostSkipsTheRequestUnderTheTokenFloor(): void
    {
        $backend = new SkipAheadBackend(88_000, 'ok', self::summaryReply());
        $host = $this->host($backend);
        self::setHistory($host, self::floorlessBandPairs());

        $ticket = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));

        $this->assertSame(TurnTicket::STARTED, $ticket->admitted, 'the skip gates the prefetch, never the turn');
        $this->assertNull(self::hostHeld($host), 'no summary is asked under the 64,000-token floor');
        $this->assertSame(0, $backend->summaryCalls, 'the summarizer was not dialed');
    }

    public function testChatSkipsTheRequestUnderTheTokenFloor(): void
    {
        $summarizer = new SkipSummaryBackend(self::summaryReply());
        $chat = new Chat(
            history: self::floorlessBandPairs(),
            inputBuf: 'first question',
            backend: new SkipTurnBackend(88_000, 'ok'),
            summaryBackend: $summarizer,
        );

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertTrue($sent->inFlight, 'the turn went out regardless');
        $this->assertNull(self::chatHeld($sent), 'the 70% tier did not ask under the floor');
        $this->assertNull(self::backgroundMsg(self::drain($cmd)));
        $this->assertSame(0, $summarizer->calls, 'and paid for nothing');
    }

    public function testTheHostDiscardsAHeldSummaryWhenTheModelChanged(): void
    {
        // The host half of the same rule, on the injection idiom of the Chat
        // stale-fingerprint guard. The workspace backend is the synchronous
        // one so each submit runs its turn in-process and is admitted (an
        // EngineBackend turn forks and would leave the second submit queued);
        // the SESSION model identity still comes from a real EngineBackend,
        // installed exactly the way /model installs one.
        $band = self::reminderBandPairs();
        $fingerprint = CompactionService::new()->historyFingerprint(new ContextCompactor(CompactorConfig::new()), $band);
        $held = [
            'id' => 'asked-on-model-a',
            'fingerprint' => $fingerprint,
            'summaries' => self::heldRecordsFor($fingerprint),
            'safeguardHold' => null,
            'model' => 'model-a',
        ];
        $host = $this->host(new SkipAheadBackend(88_000, 'ok', self::summaryReply()));
        self::setHistory($host, $band);
        self::setHeld($host, $held);
        self::setSessionBackend($host, new EngineBackend(new SkipProvider(88_000, 'ok-a'), 'model-a'));

        $first = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));
        $this->assertSame(TurnTicket::STARTED, $first->admitted);
        $kept = self::hostHeld($host);
        $this->assertNotNull($kept, 'a prompt dispatched');
        $this->assertSame('asked-on-model-a', $kept['id'], 'the held summary is left alone while the model still matches');
        // The engine session model turns the fork machinery on, so this turn
        // settles on the loop (a synchronous AheadBackend turn would already
        // be home). Timers are armed only while the host is busy — the
        // suite's one-timer law — and cancelled the moment it returns.
        if ($host->isBusy()) {
            $loop = \React\EventLoop\Loop::get();
            $watch = null;
            $safety = $loop->addTimer(10.0, static function () use ($loop): void {
                $loop->stop();
            });
            $watch = $loop->addPeriodicTimer(0.01, static function () use ($loop, $host, &$watch): void {
                if (!$host->isBusy()) {
                    $loop->cancelTimer($watch);
                    $loop->stop();
                }
            });
            $loop->run();
            $loop->cancelTimer($watch);
            $loop->cancelTimer($safety);
        }
        $this->assertFalse($host->isBusy(), 'the model-a turn settled before the switch');

        self::setSessionBackend($host, new EngineBackend(new SkipProvider(88_000, 'ok-b'), 'model-b'));

        $second = $host->submit('x', SubmitOptions::new()->withIdempotencyKey('k2'));

        $this->assertSame(TurnTicket::STARTED, $second->admitted);
        $reasked = self::hostHeld($host);
        $this->assertNotNull($reasked, 'the dropped request was re-asked on the new model');
        $this->assertNotSame($held['id'], $reasked['id'], 'a fresh request, not the stale one carried over');
        $this->assertSame('model-b', $reasked['model'] ?? null, 'stamped with the model it was now asked under');
        $this->assertNull($reasked['summaries'], 'the model-a summaries left the slot with it');
        foreach ($host->history() as $row) {
            $this->assertStringNotContainsString(self::HELD_RECORD, $row->content, 'the stale summary was discarded, never spliced');
        }
    }

    public function testChatDiscardsAHeldSummaryWhenTheModelChanged(): void
    {
        // The held-slot half, on the injection idiom of the stale-fingerprint
        // guard: a LANDED summary asked under model-a must not ride a session
        // that has moved to model-b. Nothing is drained here on purpose — a
        // Chat dials its summarizer lazily (the SpliceTest family pins that),
        // and the turn itself forks — the slot transition is the whole rule.
        $summarizer = new SkipSummaryBackend(self::summaryReply());
        $band = self::reminderBandPairs();
        $fingerprint = CompactionService::new()->historyFingerprint(new ContextCompactor(CompactorConfig::new()), $band);
        $held = [
            'id' => 'asked-on-model-a',
            'sessionId' => null,
            'fingerprint' => $fingerprint,
            'summaries' => self::heldRecordsFor($fingerprint),
            'model' => 'model-a',
        ];
        $chat = new Chat(
            history: $band,
            inputBuf: 'first question',
            backend: new EngineBackend(new SkipProvider(88_000, 'ok-a'), 'model-a'),
            summaryBackend: $summarizer,
            backgroundSummary: $held,
        );

        [$same, $sameCmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $kept = self::chatHeld($same);
        $this->assertNotNull($kept, 'a turn dispatched');
        $this->assertSame('asked-on-model-a', $kept['id'], 'the valid slot is left alone while the model still matches');
        $this->assertSame($held['summaries'], $kept['summaries'], 'landed and carried, untouched');
        unset($sameCmd);

        $switched = $chat->withBackend(new EngineBackend(new SkipProvider(88_000, 'ok-b'), 'model-b'));
        $armed = $switched->update(new KeyMsg(KeyType::Char, 'x'))[0];
        [$next, $cmd] = $armed->update(new KeyMsg(KeyType::Enter, ''));

        $reasked = self::chatHeld($next);
        $this->assertNotNull($reasked, 'the stale summary was dropped and a new one asked');
        $this->assertNotSame($held['id'], $reasked['id'], 'the old request is gone, not carried');
        $this->assertNull($reasked['summaries'], 'the landed model-a summaries are not in the slot any more');
        $this->assertSame('model-b', $reasked['model'] ?? null, 'the fresh slot names the session model');
        $this->assertSame(0, $summarizer->calls, 'the re-request rides the returned Cmd, undialed until drained (never inside update)');
        $this->assertNotNull($cmd, 'and there is a Cmd carrying it');
    }

    public function testThePaidInFlightIdleSlotSurvivesTheSubFloorDrop(): void
    {
        // MINOR-2, round-1 review: the floor is a REQUEST rule. This slot was
        // minted by the idle watch under the 64,000-token floor of a
        // small-window session and its summarisation call is already out and
        // paid for — only the return is still pending. A landed slot is
        // spliced by the blocking fold itself before any drop arm runs; the
        // arm's decision is about the IN-FLIGHT one. The old drop half
        // re-applied the floor on the way out and threw the bought request
        // away: the return then found no slot to land in, and the request arm
        // refused to re-ask under the same floor.
        $backend = new PendingAheadBackend(60_000, 'ok');
        $host = $this->autoOnHost($backend);
        self::setHistory($host, self::floorlessBandPairs());
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampClock($host, $stamp);

        self::ringAlarm($host, $stamp);

        $held = self::hostHeld($host);
        $this->assertNotNull($held, 'the idle watch asked under the floor, as its tier rules allow');
        $this->assertNull($held['summaries'], 'the paid request is still in flight at the mint');
        $this->assertSame(1, $backend->summaryCalls, 'and it was dialed once');

        $ticket = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));

        $this->assertSame(TurnTicket::STARTED, $ticket->admitted, 'the turn ran');
        $this->assertSame(1, $backend->summaryCalls, 'the dispatch neither re-asked nor orphaned the paid request');
        $survivor = self::hostHeld($host);
        $this->assertNotNull($survivor, 'the in-flight slot survived the drop arm — the floor governs the ask, not the discard');
        $this->assertSame($held['id'], $survivor['id'], 'the same paid request, still held');

        $backend->release();

        $landed = self::hostHeld($host);
        $this->assertNotNull($landed['summaries'] ?? null, 'the paid return found its slot intact');
    }

    public function testTheIdleWatchStampsItsModelForTheSwitchRule(): void
    {
        // MINOR-1, round-1 review: the idle watch's slot must carry the model
        // its ask went out under, exactly like the dispatch arm's — otherwise
        // a model-A idle prefetch rides a session that has moved to model-B
        // and splices another model's writing into the new context. The band
        // clears the floor on an 88k window, so only the stamp half can
        // explain what the dispatch below does with the slot.
        $backend = new SkipAheadBackend(88_000, 'ok', self::summaryReply());
        $host = $this->autoOnHost($backend);
        self::setHistory($host, self::heavyBand(41_500, 4));
        self::setSessionBackend($host, new EngineBackend(new SkipProvider(88_000, 'ok-a'), 'model-a'));
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampClock($host, $stamp);

        self::ringAlarm($host, $stamp);

        $held = self::hostHeld($host);
        $this->assertNotNull($held, 'the over-tier idle watch asked');
        $this->assertSame('model-a', $held['model'] ?? 'ABSENT', 'the idle slot names the model the ask went out under');

        self::setSessionBackend($host, new EngineBackend(new SkipProvider(88_000, 'ok-b'), 'model-b'));
        $ticket = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));

        $this->assertSame(TurnTicket::STARTED, $ticket->admitted, 'the model-B turn ran');
        $after = self::hostHeld($host);
        $this->assertTrue(
            $after === null || ($after['model'] ?? null) === 'model-b',
            'the stamped idle slot left the latch at the switch — carried over under model-B is the failure mode',
        );
        foreach ($host->history() as $row) {
            $this->assertStringNotContainsString('asked: ahead-of-need record 1', $row->content, 'the model-A idle records were discarded, never spliced');
        }
    }

    public function testTheSmallWindowModelNeverPrefetchesButStillFoldsOnTheHost(): void
    {
        $backend = new SkipAheadBackend(60_000, str_repeat('z', 60_000), self::summaryReply());
        $host = $this->host($backend);
        self::setHistory($host, self::smallWindowBandPairs());

        $ticket = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));

        $this->assertSame(TurnTicket::STARTED, $ticket->admitted, 'the turn ran');
        $this->assertNull(self::hostHeld($host), 'its 70% tier sits under the floor: nothing is prefetched');
        $this->assertSame(0, $backend->summaryCalls);

        $host->submit('x', SubmitOptions::new()->withIdempotencyKey('k2'));

        $text = self::agentText($host->history());
        $this->assertStringContainsString('[summary]', $text, 'the 85% tier still folds — with no prefetch held, on the local heuristic');
        $this->assertSame(0, $backend->summaryCalls, 'the summarizer was never dialed ahead of need, and the fold needed it not');
        $this->assertNull(self::hostHeld($host), 'nothing was held and nothing is left held');
    }

    public function testTheSmallWindowModelNeverPrefetchesButStillFoldsOnChat(): void
    {
        $summarizer = new SkipSummaryBackend(self::summaryReply());
        $chat = new Chat(
            history: self::smallWindowBandPairs(),
            inputBuf: 'first question',
            backend: new SkipTurnBackend(60_000, str_repeat('z', 60_000)),
            summaryBackend: $summarizer,
        );

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull(self::chatHeld($sent), 'the reminder tier sits under the floor: no prefetch');
        $settled = self::feed($sent, self::drain($cmd));
        $this->assertFalse($settled->inFlight, 'the first turn settled with its large reply');
        $this->assertSame(0, $summarizer->calls, 'and nothing was ever asked ahead of need');

        $armed = $settled->update(new KeyMsg(KeyType::Char, 'x'))[0];
        [$parked, $parkCmd] = $armed->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull(self::latch($parked), 'the 85% tier folds: it parks and pays for the summary at need');
        $this->assertNull(self::chatHeld($parked), 'while the held slot stays empty, as it always was');

        $folded = self::feed($parked, self::drain($parkCmd));
        $this->assertSame(1, $summarizer->calls, 'exactly the fold asked for a summary, never an ahead-of-need one');
        $this->assertStringContainsString('[summary]', self::agentText($folded->history), 'and the fold landed its lines');
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * 13 exchanges: three of ~20,750 estimated tokens and ten trivial ones, for
     * ~62,500 — on the reminder tier of an 88,000-token window, under the
     * ahead-of-need token floor.
     *
     * @return list<Message>
     */
    private static function floorlessBandPairs(): array
    {
        return self::heavyBand(41_500, 3);
    }

    /**
     * 13 exchanges: three of ~23,000 estimated tokens and ten trivial ones, for
     * ~69,300 — past both the reminder tier and the floor.
     *
     * @return list<Message>
     */
    private static function reminderBandPairs(): array
    {
        return self::heavyBand(46_000, 3);
    }

    /**
     * 12 exchanges: two of ~23,000 estimated tokens and ten trivial ones, for
     * ~46,400 — on the reminder tier of a 60,000-token window (42,000 / 51,000),
     * under its 85% tier and under the floor.
     *
     * @return list<Message>
     */
    private static function smallWindowBandPairs(): array
    {
        return self::heavyBand(46_000, 2);
    }

    /**
     * @return list<Message>
     */
    private static function heavyBand(int $chars, int $pairs): array
    {
        $history = [];
        for ($i = 0; $i < $pairs; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), $chars));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), $chars));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    /** One record per offered exchange, and a state block. */
    private const HELD_RECORD = 'a held record that must not land';

    /** A ready summary map holding one record per key of $fingerprint. */
    private static function heldRecordsFor(HistoryFingerprint $fingerprint): array
    {
        $summaries = [];
        foreach ($fingerprint->exchangeKeys() as $key) {
            $summaries[$key] = self::HELD_RECORD;
        }

        return [...$summaries, StateSummaryTemplate::SUMMARY_KEY => StateSummaryTemplate::new()->render()];
    }

    private static function summaryReply(): string
    {
        $records = [];
        for ($i = 1; $i <= 6; $i++) {
            $records[] = "{$i}.\nasked: ahead-of-need record {$i}";
        }

        return implode("\n", $records)
            . "\n<session-state>\n## Goal\nkeep the router fast\n</session-state>";
    }

    private function host(Backend $backend): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend, hooks: null),
            customCommands: [],
        );
    }

    /** A host that opted into the idle fold — the watch only runs for this config. */
    private function autoOnHost(Backend $backend): SessionHost
    {
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend, hooks: null),
            customCommands: [],
            compactorConfig: CompactorConfig::new()->withIdleAuto(),
        );
        $this->idleOptedHosts[] = $host;

        return $host;
    }

    /** Set the accepted-prompt clock the one-shot re-derives its idle anchor from. */
    private static function stampClock(SessionHost $host, \DateTimeImmutable $at): void
    {
        (new \ReflectionProperty(SessionHost::class, 'lastPromptAt'))->setValue($host, $at);
    }

    /** Run the idle watch body at once — the suite never sleeps for real. */
    private static function ringAlarm(SessionHost $host, \DateTimeImmutable $markedAt): void
    {
        (new \ReflectionMethod(SessionHost::class, 'onIdleAutoCompaction'))->invoke($host, $markedAt);
    }

    /** @param list<Message> $history */
    private static function setHistory(SessionHost $host, array $history): void
    {
        (new \ReflectionProperty(SessionHost::class, 'history'))->setValue($host, $history);
    }

    /** The session's own backend slot — what a `/model` switch rewrites. */
    /** @param array<string, mixed>|null $held */
    private static function setHeld(SessionHost $host, ?array $held): void
    {
        (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->setValue($host, $held);
    }

    private static function setSessionBackend(SessionHost $host, Backend $backend): void
    {
        (new \ReflectionProperty(SessionHost::class, 'sessionBackend'))->setValue($host, $backend);
    }

    /** @return null|array{id:string,fingerprint:HistoryFingerprint,summaries:?array<string,string>,model?:string} */
    private static function hostHeld(SessionHost $host): ?array
    {
        return (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->getValue($host);
    }

    /** @return array{id: string, sessionId: ?string, fingerprint: HistoryFingerprint, summaries: ?array<string, string>}|null */
    private static function chatHeld(Chat $chat): ?array
    {
        return (new \ReflectionProperty(Chat::class, 'backgroundSummary'))->getValue($chat);
    }

    private static function latch(Chat $chat): ?string
    {
        return (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat);
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

    /** @param list<Message> $history */
    private static function agentText(array $history): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, Message::agentVisible($history)));
    }
}

/** The conversation backend: a fixed window and a fixed reply, counting turns. */
final class SkipTurnBackend implements Backend, ReportsContextWindow
{
    public int $calls = 0;

    public function __construct(private readonly int $window, private readonly string $reply)
    {
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }
}

/** The summary backend: a fixed reply, counting its calls. */
final class SkipSummaryBackend implements Backend, SummarisesWithCache
{
    public int $calls = 0;

    public function __construct(private readonly string $reply)
    {
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant($this->reply)->withUsage(Usage::new(100, 0.0));
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(100, 0.0)));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(100, 0.0)));
    }
}

/** The one backend the host shares for turns and summarization. */
final class SkipAheadBackend implements Backend, ReportsContextWindow, SummarisesWithCache
{
    public int $turnCalls = 0;

    public int $summaryCalls = 0;

    public function __construct(
        private readonly int $window,
        private readonly string $reply,
        private readonly string $summaryReply,
    ) {
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->turnCalls++;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->turnCalls++;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->summaryCalls++;

        return \React\Promise\resolve(Message::assistant($this->summaryReply)->withUsage(Usage::new(100, 0.0)));
    }
}

/**
 * A host backend whose turn answers at once but whose summarisation hands
 * back a promise that only settles when the test rings it — so an already
 * billed ahead-of-need request can be caught in flight by the drop arm.
 */
final class PendingAheadBackend implements Backend, ReportsContextWindow, SummarisesWithCache
{
    public int $turnCalls = 0;

    public int $summaryCalls = 0;

    /** @var (callable(Message): void)|null */
    private $summaryResolver = null;

    public function __construct(
        private readonly int $window,
        private readonly string $reply,
    ) {
    }

    /** Deliver the paid-for summarisation — nothing lands until this is rung. */
    public function release(): void
    {
        $resolve = $this->summaryResolver;
        $this->summaryResolver = null;
        if ($resolve !== null) {
            $resolve(Message::assistant(
                "1.\nasked: the in-flight record that must land"
                    . "\n<session-state>\n## Goal\nkeep the router fast\n</session-state>",
            )->withUsage(Usage::new(100, 0.0)));
        }
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->turnCalls++;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->turnCalls++;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->summaryCalls++;

        return new \React\Promise\Promise(function (callable $resolve): void {
            $this->summaryResolver = $resolve;
        });
    }
}

/** A provider double on which a real EngineBackend can run: window, fixed reply, call counter. */
final class SkipProvider implements ProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly int $window, private readonly string $reply)
    {
    }

    public function name(): string
    {
        return 'skip-rules-' . $this->reply;
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsDocuments(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function costPer1kTokens(string $model, string $direction): ?float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $this->calls++;

        return new CompleteResponse(content: $this->reply);
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield $this->complete($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}
