<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;

/**
 * CTX-5: the served host folds while nobody is watching — opt-in, and only
 * through the machinery that already exists.
 *
 * {@see SessionHost::armIdleAutoCompaction()} replaces the periodic TUI tick
 * with the two clocks this host has — the instant a prompt is accepted and
 * the instant a turn settles (C-CTX-6.a) — as one single-slot alarm at the
 * end of the idle window, re-armed from the later stamp. The callback is the
 * whole test
 * surface here (invoked through reflection, never by real sleep): it re-
 * derives every condition — clock identity, breaker, cap, latch, tier — and
 * passes ONLY into {@see SessionHost::requestAheadOfNeed()}, the block the
 * 70% dispatch tier used to carry inline. A timer fires state checks, never
 * a kill and never a turn: the landed summaries ride the existing splice
 * into the next submit, so an idle fold can bill its round-trip at most once
 * and can never dispatch a reply to a user who did not ask for one.
 *
 * The band mirrors tests/Chat/IdleAutoCompactionTest: an 88,000-token window
 * puts the blocking tier at 74,800 estimated tokens, and the fixture below
 * estimates ~80k — deep over it, with the 10 trivial exchanges left standing
 * verbatim so the fold cannot be blamed on the tail.
 */
final class SessionHostIdleAutoTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    /** @var list<SessionHost> hosts whose idle alarm must not outlive the test */
    private array $armedHosts = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-host-idle-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        // The alarm is armed at the real idle window; leaving it pending on
        // the shared loop would make any later Loop::run() in the process
        // wait out the hour with it.
        foreach ($this->armedHosts as $host) {
            $timer = self::timer($host);
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }
        $this->armedHosts = [];
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    // ── arming ───────────────────────────────────────────────────────────────

    public function testTheDefaultHostArmsNothing(): void
    {
        $host = $this->host(new IdleAutoBackend(88_000, 'ok', 'unused'));
        self::setIdleHistory($host);
        self::stampIdlePromptAt($host, new \DateTimeImmutable('-2 hours'));

        $host->submit('still here?', SubmitOptions::new()->withIdempotencyKey('k'));

        self::assertNull(self::timer($host), 'opt-in means a cold default costs no timer at all');
    }

    public function testAnOptedInSubmitArmsTheIdleAlarm(): void
    {
        $host = $this->host(new IdleAutoBackend(88_000, 'ok', 'unused'), self::autoOn());
        $this->armedHosts[] = $host;
        self::setIdleHistory($host);
        self::stampIdlePromptAt($host, new \DateTimeImmutable('-2 hours'));

        $host->submit('still here?', SubmitOptions::new()->withIdempotencyKey('k'));

        $first = self::timer($host);
        self::assertNotNull($first, 'the accepted prompt leaves exactly one alarm behind');

        // A user who returns swaps the deadline: cancel-and-rearm, never two.
        $host->submit('and again', SubmitOptions::new()->withIdempotencyKey('k2'));
        $second = self::timer($host);
        self::assertNotNull($second);
        self::assertNotSame($first, $second, 'the stale window is not carried into the next idle gap');
    }

    public function testTheShapesThatCannotFoldNeverArm(): void
    {
        // No window configured — the offer clock itself is off.
        $host = $this->host(new IdleAutoBackend(88_000, 'ok', 'unused'), self::autoOn()->withIdleOfferSeconds(0));
        self::stampIdlePromptAt($host, new \DateTimeImmutable('-2 hours'));
        $host->submit('q', SubmitOptions::new()->withIdempotencyKey('k0'));
        self::assertNull(self::timer($host), 'an unconfigured window arms nothing');

        // The tier is off — auto-compaction off outranks the auto-fold.
        $host = $this->host(new IdleAutoBackend(88_000, 'ok', 'unused'), self::autoOn()->withMode(CompactorConfig::MODE_OFF));
        $host->submit('q', SubmitOptions::new()->withIdempotencyKey('k1'));
        self::assertNull(self::timer($host), 'nothing folds unattended when the tier is off');

        // Nobody to ask — an offline session cannot pay for a summarisation.
        $host = $this->hostWithNullBackend(self::autoOn());
        $host->submit('q', SubmitOptions::new()->withIdempotencyKey('k2'));
        self::assertNull(self::timer($host), 'an offline host arms nothing');
    }

    // ── the callback ─────────────────────────────────────────────────────────

    public function testTheIdleAlarmPutsOutTheAheadOfNeedRequestAndSaysSo(): void
    {
        $backend = new IdleAutoBackend(88_000, str_repeat('z', 60_000), self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);

        self::fire($host, $stamp);

        self::assertSame(1, $backend->summaryCalls, 'the request went out once, through the dispatch block');
        self::assertSame(0, $backend->turnCalls, 'an idle fold never dispatches a turn');
        $held = self::held($host);
        self::assertNotNull($held, 'the summaries land in the same latch the 70% tier fills');
        self::assertIsArray($held['summaries'], 'the immediate-resolve double settled inline');
        $notices = array_values(array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->role === Role::System
                && str_contains($row->content, 'Idle auto-compaction'),
        ));
        self::assertCount(1, $notices, 'the human learns what ran unattended');
        self::assertTrue($notices[0]->uiOnly, 'the row is transcript-only: the next wire cannot accuse it');
        self::assertStringContainsString('88000', $notices[0]->content, 'the window figure is filled in');
    }

    public function testAUserWhoReturnedSilencesTheAlarm(): void
    {
        $backend = new IdleAutoBackend(88_000, 'ok', self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        self::setIdleHistory($host);
        self::stampIdlePromptAt($host, new \DateTimeImmutable('-2 hours'));

        // The alarm was armed against the PREVIOUS stamp; the user returned.
        self::fire($host, new \DateTimeImmutable('-3 hours'));

        self::assertNull(self::held($host), 'a stale window speaks for nobody');
        self::assertSame(0, $backend->summaryCalls, 'nothing was paid for');
    }

    public function testATranscriptUnderTheTierSilencesTheAlarm(): void
    {
        $backend = new IdleAutoBackend(88_000, 'ok', self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        self::stampIdlePromptAt($host, new \DateTimeImmutable('-2 hours'));

        self::fire($host, self::readStamp($host));

        self::assertNull(self::held($host), 'under the blocking tier the fold is nobody to make');
        self::assertSame(0, $backend->summaryCalls);
    }

    public function testABreachedCapSilencesTheAlarm(): void
    {
        $backend = new IdleAutoBackend(88_000, 'ok', self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn(), maxCostUsd: 0.05);
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);
        /** @var \SugarCraft\Code\Util\TokenTracker $spend */
        $spend = (new \ReflectionProperty(SessionHost::class, 'spend'))->getValue($host);
        $spend->addTotalUsage(100, 0.06);

        self::fire($host, $stamp);

        self::assertNull(self::held($host), 'unattended money stops at the same cap attended money does');
        self::assertSame(0, $backend->summaryCalls, 'nothing was paid for');
    }

    public function testATrippedBreakerSilencesTheAlarm(): void
    {
        $backend = new IdleAutoBackend(88_000, 'ok', self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);
        (new \ReflectionProperty(SessionHost::class, 'consecutiveRefills'))
            ->setValue($host, IdleCompactionPolicy::REFILL_LIMIT);

        self::fire($host, $stamp);

        self::assertNull(self::held($host), 'a fold run that keeps refilling is not continued by an idle');
        self::assertSame(0, $backend->summaryCalls, 'nothing was paid for');
    }

    public function testTheAlarmNeverStacksRequestsOnAnOpenLatch(): void
    {
        $backend = new IdleAutoBackend(88_000, str_repeat('z', 60_000), self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);

        self::fire($host, $stamp);
        self::fire($host, $stamp);

        self::assertSame(1, $backend->summaryCalls, 'one request per cycle, the dispatch rule verbatim');
        $notices = array_values(array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->role === Role::System
                && str_contains($row->content, 'Idle auto-compaction'),
        ));
        self::assertCount(1, $notices, 'and one row saying so');
    }

    /**
     * The opt-out polarity at the callback itself — belt and braces beside the
     * arming gate: a config flipped back to off between the two moments (the
     * setter is Live, a `/settings` round-trip can do exactly this) must not
     * buy a call the user has just revoked.
     */
    public function testAnOptOutBetweenArmAndAlarmSilencesTheAlarm(): void
    {
        $backend = new IdleAutoBackend(88_000, str_repeat('z', 60_000), self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn()->withIdleAuto(false));
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);

        self::fire($host, $stamp);

        self::assertNull(self::held($host), 'shouldAutoCompact is the last word');
        self::assertSame(0, $backend->summaryCalls, 'nothing was paid for');
    }

    // ── C-CTX-6.a: the watch survives the turn ───────────────────────────────

    /**
     * The one-shot fired while a turn owned the transcript. The old shape
     * nulled the alarm and returned — the fold the opt-in exists to make was
     * lost for the rest of the idle, and a long turn simply ate it. The alarm
     * re-arms from a busy fire, exactly as the TUI's tick simply re-checks
     * the next minute; paying and dispatching still wait for the verdict.
     */
    public function testAnAlarmThatFiresIntoARunningTurnReArmsItself(): void
    {
        $backend = new IdleAutoBackend(88_000, str_repeat('z', 60_000), self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        $this->armedHosts[] = $host;
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);

        // The timer's real closure nulls the slot BEFORE judging; mirror
        // that, then run the callback body with a turn in flight.
        self::clearAlarm($host);
        self::setBusy($host, new CancellationToken());

        self::fire($host, $stamp);

        self::assertNotNull(self::timer($host), 'a busy fire hands the watch to the next gap, never drops it');
        self::assertNull(self::held($host), 'the busy fire itself makes no request');
        self::assertSame(0, $backend->summaryCalls, 'and it pays for nothing');
        self::assertSame(0, $backend->turnCalls, 'and it dispatches no turn');
    }

    /**
     * The verdict side of the same fix: idleness counts from the LATER of
     * the two activity stamps. A session whose turn settled moments ago is
     * not idle — folding it off the prompt stamp alone would bill the
     * summarisation a moment after the user watched a reply land, counting
     * the turn itself as silence.
     */
    public function testTheAlarmMeasuresIdlenessFromTheSettle(): void
    {
        $backend = new IdleAutoBackend(88_000, str_repeat('z', 60_000), self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        $this->armedHosts[] = $host;
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);

        self::setBusy($host, $turn = new CancellationToken());
        self::settleTurn($host, $turn, Message::assistant('settled at last'));

        // Wind the prompt stamp back to the pre-turn hour and drop the
        // settle-armed alarm: the fire below is judged purely on the stamps.
        self::clearAlarm($host);
        self::stampIdlePromptAt($host, $stamp);

        self::fire($host, $stamp);

        self::assertNull(self::held($host), 'a session whose turn just settled is not an idle session');
        self::assertSame(0, $backend->summaryCalls, 'and the fold waits its window from the settle');
    }

    /**
     * Every settle re-arms, single-slot: a busy fire had already handed the
     * watch along; the settle replaces that alarm with one measured from
     * this instant, and the replaced one is cancelled, not stacked.
     */
    public function testEverySettleReArmsTheWatch(): void
    {
        $backend = new IdleAutoBackend(88_000, str_repeat('z', 60_000), self::idleSummaryReply());
        $host = $this->host($backend, self::autoOn());
        $this->armedHosts[] = $host;
        self::setIdleHistory($host);
        $stamp = new \DateTimeImmutable('-2 hours');
        self::stampIdlePromptAt($host, $stamp);

        self::clearAlarm($host);
        self::setBusy($host, $turn = new CancellationToken());
        self::fire($host, $stamp);
        $refired = self::timer($host);
        self::assertNotNull($refired, 'the busy fire kept the watch alive to see the settle');

        self::settleTurn($host, $turn, Message::assistant('the reply the alarm waited through'));

        $settled = self::timer($host);
        self::assertNotNull($settled, 'the settle re-arms — one idle window from NOW, not from the prompt');
        self::assertNotSame($refired, $settled, 'and the fire-carried alarm is replaced, never left to stack');

        $anchor = (new \ReflectionProperty(SessionHost::class, 'lastSettledAt'))->getValue($host);
        self::assertInstanceOf(\DateTimeImmutable::class, $anchor, 'the settle leaves its stamp behind');
        self::assertNull(self::held($host), 're-arming is not folding: nothing is paid for yet');
        self::assertSame(0, $backend->summaryCalls);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private static function autoOn(): CompactorConfig
    {
        return CompactorConfig::new()->withIdleAuto();
    }

    /**
     * Four exchanges of ~20,750 estimated tokens each on this host's own
     * scale (the meter divides characters by four — see the arithmetic in
     * {@see SessionHostAheadOfNeedTest}'s reminder band), plus ten trivial
     * ones: ~83,000 past the 74,800 blocking tier of an 88,000-token window.
     *
     * @return list<Message>
     */
    private static function idleOverBandPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 4; $i++) {
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
    private static function idleSummaryReply(): string
    {
        $records = [];
        for ($i = 1; $i <= 7; $i++) {
            $records[] = "{$i}.\nasked: idle-auto record {$i}";
        }

        return implode("\n", $records)
            . "\n<session-state>\n## Goal\nkeep the router fast\n</session-state>";
    }

    private function host(Backend $backend, ?CompactorConfig $config = null, ?float $maxCostUsd = null): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend, hooks: null, maxCostUsd: $maxCostUsd),
            customCommands: [],
            compactorConfig: $config,
        );
    }

    private function hostWithNullBackend(?CompactorConfig $config = null): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: null, hooks: null),
            customCommands: [],
            compactorConfig: $config,
        );
    }

    private static function setIdleHistory(SessionHost $host): void
    {
        (new \ReflectionProperty(SessionHost::class, 'history'))->setValue($host, self::idleOverBandPairs());
    }

    private static function stampIdlePromptAt(SessionHost $host, \DateTimeImmutable $at): void
    {
        (new \ReflectionProperty(SessionHost::class, 'lastPromptAt'))->setValue($host, $at);
    }

    private static function readStamp(SessionHost $host): ?\DateTimeImmutable
    {
        return (new \ReflectionProperty(SessionHost::class, 'lastPromptAt'))->getValue($host);
    }

    /** Invoke the one-shot's body directly — no real sleep in the suite. */
    private static function fire(SessionHost $host, ?\DateTimeImmutable $markedAt): void
    {
        (new \ReflectionMethod(SessionHost::class, 'onIdleAutoCompaction'))->invoke($host, $markedAt);
    }

    /** Mirror the timer closure's own first move: the slot is empty while judging. */
    private static function clearAlarm(SessionHost $host): void
    {
        $timer = self::timer($host);
        if ($timer !== null) {
            Loop::cancelTimer($timer);
            (new \ReflectionProperty(SessionHost::class, 'idleAutoTimer'))->setValue($host, null);
        }
    }

    private static function setBusy(SessionHost $host, CancellationToken $turn): void
    {
        (new \ReflectionProperty(SessionHost::class, 'turn'))->setValue($host, $turn);
    }

    /**
     * Run a turn's settle through the host's own settle path with a resolved
     * reply — the synchronous shape the double's completeAsync produces.
     */
    private static function settleTurn(SessionHost $host, CancellationToken $turn, Message $reply): void
    {
        $generation = (new \ReflectionProperty(SessionHost::class, 'generation'))->getValue($host);
        $promise = \React\Promise\resolve(new AssistantMsg($reply));
        (new \ReflectionMethod(SessionHost::class, 'settleWith'))->invoke($host, $promise, $generation, $turn);
    }

    private static function timer(SessionHost $host): ?object
    {
        return (new \ReflectionProperty(SessionHost::class, 'idleAutoTimer'))->getValue($host);
    }

    /** @return null|array<string,mixed> */
    private static function held(SessionHost $host): ?array
    {
        return (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->getValue($host);
    }
}

/**
 * Turn and summarization doubles under one roof: a fixed window, a fixed turn
 * reply, an immediate-resolve summary reply on the cache-reusing route — the
 * same shape the dispatch-path tests drive, under names that keep the two
 * files from sharing a hairball.
 */
final class IdleAutoBackend implements Backend, ReportsContextWindow, SummarisesWithCache
{
    public int $turnCalls = 0;

    public int $summaryCalls = 0;

    public function __construct(
        private readonly int $window,
        private readonly string $turnReply,
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

        return Message::assistant($this->turnReply);
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->turnCalls++;

        return \React\Promise\resolve(Message::assistant($this->turnReply));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->summaryCalls++;

        return \React\Promise\resolve(Message::assistant($this->summaryReply)->withUsage(Usage::new(100, 0.0)));
    }
}
