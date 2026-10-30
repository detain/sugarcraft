<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CacheHealthWatch;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Context\KeepWarmPolicy;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;

/**
 * C-CTX-5.d: keep-warm — the ping an idle session may buy where the cacheTtl
 * fold declined it, capped per idle stretch and off by default because every
 * one of them is a billed call to a session nobody is watching.
 *
 * The decision table lives in {@see KeepWarmPolicy}; the count lives on
 * {@see CacheHealthWatch}. The TUI's tick and the served host's alarm consult
 * the SAME table (that is the point of the split), so the end-to-end half of
 * this file drives the served alarm — the shape with fewer moving parts to
 * stand in for both — and pins: a fold-declining fire that earns a ping pays
 * exactly one, bills it (`warm`), and re-arms its own watch; a fire with the
 * key off, the cap reached, or the spacing unmet pays none; and a fire the
 * FOLD took never also buys a ping. The money-side defaults (off,
 * nonsense-clamped-to-off, runaway-clamped) are the first tests here because
 * a key that bills unattended must fail closed.
 *
 * The table legs run on a fixed epoch the policy is handed as `$now`; the
 * served legs use stamps relative to the real clock, because the served
 * host calls the policy without a hand-fed now (as does everything that
 * lives in a timer callback) — mixing the two would make the spacing gate
 * read a future as the past.
 */
final class KeepWarmTest extends TestCase
{
    /** Fixed epoch for the table legs; the live latch clock is real and relative. */
    private const NOW = 1_800_000_000;

    private string $dir;

    private EnhancedSessionStore $store;

    /** @var list<SessionHost> hosts whose idle alarm must not outlive the test */
    private array $warmHosts = [];

    protected function setUp(): void
    {
        CacheHealthWatch::resetWarmStateForTesting();
        $this->dir = sys_get_temp_dir() . '/crush-keepwarm-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach ($this->warmHosts as $host) {
            $timer = self::alarm($host);
            if ($timer !== null) {
                Loop::cancelTimer($timer);
            }
        }
        $this->warmHosts = [];
        CacheHealthWatch::resetWarmStateForTesting();
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    // ── the money key fails closed ───────────────────────────────────────────

    public function testTheCapRefusesNonsenseAndClampsRunaways(): void
    {
        $this->assertSame(0, KeepWarmPolicy::limitFrom([]), 'unset is OFF');
        $this->assertSame(0, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => 0]));
        $this->assertSame(0, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => -3]), 'a negative cannot mean "ping backwards"');
        $this->assertSame(0, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => true]), 'a bool is not a count');
        $this->assertSame(0, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => 'sometimes']));
        $this->assertSame(0, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => []]));
        $this->assertSame(1, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => 1]));
        $this->assertSame(3, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => 3]));
        $this->assertSame(2, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => 2.9]), 'integers, truncated never rounded up');
        $this->assertSame(KeepWarmPolicy::MAX_PINGS, KeepWarmPolicy::limitFrom([KeepWarmPolicy::SETTING => 10_000]), 'one idle evening cannot be billed open-ended');
    }

    // ── the table ────────────────────────────────────────────────────────────

    public function testAnOffCapNeverBuysAnythingEvenInAPerfectSilence(): void
    {
        $this->assertFalse($this->ask([], self::anchorPast(300)));
    }

    public function testNoPingWhileATurnRuns(): void
    {
        $this->assertFalse(
            $this->ask([KeepWarmPolicy::SETTING => 2], self::anchorPast(300), turnInFlight: true),
            'a running turn writes the cache itself; the ping exists only for the silence that will not',
        );
    }

    public function testNoSecondPingWhileTheFirstStillHangsOut(): void
    {
        $this->assertTrue(CacheHealthWatch::noteWarmPing('s', self::anchorPast(300)), 'latch the ping out');

        $this->assertFalse(
            $this->ask([KeepWarmPolicy::SETTING => 2], self::anchorPast(300)),
            'one request per cycle, the dispatch rule',
        );

        CacheHealthWatch::settleWarmPing('s', true);
        $this->assertFalse(CacheHealthWatch::warmPingInFlight('s'), 'the landing takes the latch down');

        // The cadence restarts AT the ping, not at the anchor: a stretch idle
        // enough to warm once is not instantly idle enough to warm twice.
        self::parkLastPingAt(self::NOW - 100);
        $this->assertFalse($this->ask([KeepWarmPolicy::SETTING => 2], self::anchorPast(300)));
        self::parkLastPingAt(self::NOW - 241);
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 2], self::anchorPast(300)));
    }

    public function testTheFoldStillOwnsTheTickItTook(): void
    {
        $this->assertFalse(
            $this->ask([KeepWarmPolicy::SETTING => 3], self::anchorPast(300), foldDue: true),
            'warming what is already being folded pays twice for one prefix',
        );
    }

    public function testAnEngineWithoutACacheClockIsNeverWarmed(): void
    {
        $this->assertFalse(
            $this->ask([KeepWarmPolicy::SETTING => 3], self::anchorPast(300), noEngine: true),
            'no cache, no clock, nothing to warm',
        );
    }

    public function testTheCapCountsAgainstTheIdleStretchAndAReturnRollsIt(): void
    {
        $anchor = self::anchorPast(300);

        // Cap 1: one ping, then the stretch is spent.
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 1], $anchor));
        self::sendAndLand($anchor);
        $this->assertFalse($this->ask([KeepWarmPolicy::SETTING => 1], $anchor), 'the cap, not the clock, says stop');

        // The same session, a NEW idle stretch (the user returned and left
        // again): the stretch count rolls and one ping is on again.
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 1], self::anchorPast(250)), 'per stretch, not per session');

        // Cap 2 spends both.
        $second = self::anchorPast(900);
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 2], $second));
        self::sendAndLand($second);
        self::parkLastPingAt($second);
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 2], $second), 'the second of two is allowed');
        self::sendAndLand($second);
        self::parkLastPingAt($second);
        $this->assertFalse($this->ask([KeepWarmPolicy::SETTING => 2], $second), 'the third is not');
    }

    public function testAPrefixBelowWhatProvidersCacheIsNeverWarmed(): void
    {
        $anchor = self::anchorPast(300);

        $this->assertFalse(
            $this->ask([KeepWarmPolicy::SETTING => 2], $anchor, tokens: CacheHealthWatch::BREAK_MIN_TOKENS - 1),
            'a cache the provider would not keep is a cache not worth warming — the full-prefill ping is the harm reversed',
        );
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 2], $anchor, tokens: CacheHealthWatch::BREAK_MIN_TOKENS));
    }

    public function testThePingLandsBeforeTheCacheGoesColdAndNotSooner(): void
    {
        $fireAt = IdleCompactionPolicy::CACHE_TTL_IMPLICIT_SECONDS - IdleCompactionPolicy::CACHE_TTL_MARGIN_SECONDS;

        $this->assertFalse(
            $this->ask([KeepWarmPolicy::SETTING => 3], self::anchorPast($fireAt)),
            'exactly at the margin is the fold\'s own strict >, shared',
        );
        $this->assertTrue($this->ask([KeepWarmPolicy::SETTING => 3], self::anchorPast($fireAt + 1)));
    }

    // ── the served alarm end to end ──────────────────────────────────────────

    public function testTheServedAlarmWarmsWhereTheFoldDeclined(): void
    {
        $backend = new WarmHostBackend(88_000, '.');
        $host = $this->warmHost($backend, cap: 2);
        $this->warmHosts[] = $host;
        self::setWarmHistory($host, self::belowFloorPairs());
        $stamp = new \DateTimeImmutable('-5 minutes');
        self::stampWarmPrompt($host, $stamp);

        self::ring($host, $stamp);

        $this->assertSame(1, $backend->summaryCalls, 'the fire the fold declined bought exactly one cached-read refresh');
        $this->assertSame(0, $backend->turnCalls, 'a ping is not a turn and never dispatches one');
        $this->assertNull(self::latch($host), 'and it is not a fold: no summaries were asked for');
        $this->assertSame(1, CacheHealthWatch::warmPings('s'), 'the billed landing recorded the warm hit');
        $this->assertGreaterThan(0.0, $host->spentUsd(), 'the ledger saw it like any other call');
        $this->assertNotNull(self::alarm($host), 'a one-shot watch that pinged hands itself to the next gap');
        $this->assertFalse(CacheHealthWatch::warmPingInFlight('s'), 'the inline-resolved landing took its latch down');
    }

    public function testTheServedAlarmPaysForNothingWhileTheKeyIsOff(): void
    {
        $backend = new WarmHostBackend(88_000, '.');
        $host = $this->warmHost($backend, cap: 0);
        self::setWarmHistory($host, self::belowFloorPairs());
        $stamp = new \DateTimeImmutable('-5 minutes');
        self::stampWarmPrompt($host, $stamp);

        self::ring($host, $stamp);

        $this->assertSame(0, $backend->summaryCalls, 'the default pays nothing — silence is free again');
        $this->assertSame(0, CacheHealthWatch::warmPings());
        $this->assertNull(self::alarm($host), 'a declined fire that did not ping also does not re-arm: the status quo stands');
    }

    public function testTheServedAlarmNeverRacesItselfAcrossTwoFires(): void
    {
        $backend = new WarmHostBackend(88_000, '.');
        $host = $this->warmHost($backend, cap: 9);
        $this->warmHosts[] = $host;
        self::setWarmHistory($host, self::belowFloorPairs());
        $stamp = new \DateTimeImmutable('-5 minutes');
        self::stampWarmPrompt($host, $stamp);

        self::ring($host, $stamp);
        self::ring($host, $stamp);

        $this->assertSame(1, $backend->summaryCalls, 'the second fire meets the spacing clock its own first ping started');
        $this->assertSame(1, CacheHealthWatch::warmStretchPings('s', $stamp->getTimestamp()));
    }

    public function testTheFireTheFoldTookBuysNoWarmPingToo(): void
    {
        // The middle band: over the cacheTtl fold's floor — the fold owns this
        // fire outright, and a ping beside it would double-pay the prefix the
        // fold is about to shorten.
        $backend = new WarmHostBackend(88_000, self::foldReply());
        $host = $this->warmHost($backend, cap: 5);
        $this->warmHosts[] = $host;
        self::setWarmHistory($host);
        $stamp = new \DateTimeImmutable('-5 minutes');
        self::stampWarmPrompt($host, $stamp);

        self::ring($host, $stamp);

        $this->assertSame(1, $backend->summaryCalls, 'the fold went out, once');
        $this->assertNotNull(self::latch($host), 'and it rode requestAheadOfNeed, the fold\'s own block');
        $this->assertSame(0, CacheHealthWatch::warmPings(), 'keep-warm spoke nowhere near this tick');
    }

    public function testAStaleFireWarmsNobody(): void
    {
        $backend = new WarmHostBackend(88_000, '.');
        $host = $this->warmHost($backend, cap: 3);
        self::setWarmHistory($host, self::belowFloorPairs());
        self::stampWarmPrompt($host, new \DateTimeImmutable('-5 minutes'));

        self::ring($host, new \DateTimeImmutable('-25 hours'));

        $this->assertSame(0, $backend->summaryCalls, 'the user returned — this stamp speaks for no stretch');
    }

    // ── the policy table's plumbing ──────────────────────────────────────────

    /**
     * One row of the table: a capped session on the implicit 300-second
     * clock, over the cacheability floor, asked at NOW. `$noEngine` is the
     * nobody-to-ask shape the mock cannot stand in for.
     *
     * @param array<string, mixed> $config
     */
    private function ask(
        array $config,
        int $anchorTs,
        bool $foldDue = false,
        bool $turnInFlight = false,
        int $tokens = 20_000,
        bool $noEngine = false,
    ): bool {
        $subject = $noEngine ? null : $this->createMock(Backend::class);

        return KeepWarmPolicy::shouldPing(
            $config,
            's',
            $subject,
            $tokens,
            88_000,
            $foldDue,
            $turnInFlight,
            $anchorTs,
            self::NOW,
        );
    }

    /**
     * `backend: null` means NO engine at all — the mock cannot be built by a
     * default, so the sentinel default stands and the parameter documents it.
     */
    private static function anchorPast(int $seconds): int
    {
        return self::NOW - $seconds;
    }

    /** Complete the latch dance a real send+landing perform. */
    private static function sendAndLand(int $anchorTs): void
    {
        CacheHealthWatch::noteWarmPing('s', $anchorTs);
        CacheHealthWatch::settleWarmPing('s', true);
    }

    /**
     * The spacing clock runs on wall time; the table wants a fixed epoch.
     * Park the stretch's ping instant wherever the leg is testing, so the
     * ONLY thing the next ask can trip on is the gate under test.
     */
    private static function parkLastPingAt(int $at): void
    {
        $state = (new \ReflectionProperty(CacheHealthWatch::class, 'warm'))->getValue();
        $state['s']['at'] = $at;
        (new \ReflectionProperty(CacheHealthWatch::class, 'warm'))->setValue(null, $state);
    }

    // ── served-host fixtures ─────────────────────────────────────────────────

    private function warmHost(Backend $backend, int $cap): SessionHost
    {
        // The cap reaches the policy through the workspace's layered config,
        // exactly as /settings writes it; the CompactorConfig half only owns
        // the mode whose alarm this watch exists under.
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                sessionStore: $this->store,
                backend: $backend,
                hooks: null,
                userConfig: [KeepWarmPolicy::SETTING => $cap],
            ),
            customCommands: [],
            compactorConfig: CompactorConfig::new()->withIdleAutoMode(CompactorConfig::IDLE_AUTO_CACHE_TTL),
        );
    }

    /**
     * The below-floor band: over the 1024-token cacheability floor C-CTX-5.d
     * borrows, under the 52,800 (60%) fold floor of an 88,000 window — the
     * silence the fold declines and keep-warm exists for.
     *
     * @return list<Message>
     */
    private static function belowFloorPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 30_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 30_000));
        }

        return $history;
    }

    private static function foldReply(): string
    {
        $records = [];
        for ($i = 1; $i <= 7; $i++) {
            $records[] = "{$i}.\nasked: keep-warm band record {$i}";
        }

        return implode("\n", $records)
            . "\n<session-state>\n## Goal\nstay under the floor\n</session-state>";
    }

    /** @param list<Message>|null $pairs the below-floor band by default */
    private static function setWarmHistory(SessionHost $host, ?array $pairs = null): void
    {
        (new \ReflectionProperty(SessionHost::class, 'history'))
            ->setValue($host, $pairs ?? self::middleBandFoldPairs());
    }

    /** The over-floor band, for the fire the fold should take. @return list<Message> */
    private static function middleBandFoldPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 46_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 46_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    private static function stampWarmPrompt(SessionHost $host, \DateTimeImmutable $at): void
    {
        (new \ReflectionProperty(SessionHost::class, 'lastPromptAt'))->setValue($host, $at);
    }

    /** Ring the one-shot's body directly — no real sleep in the suite. */
    private static function ring(SessionHost $host, ?\DateTimeImmutable $markedAt): void
    {
        (new \ReflectionMethod(SessionHost::class, 'onIdleAutoCompaction'))->invoke($host, $markedAt);
    }

    private static function alarm(SessionHost $host): ?object
    {
        return (new \ReflectionProperty(SessionHost::class, 'idleAutoTimer'))->getValue($host);
    }

    /** @return null|array<string,mixed> */
    private static function latch(SessionHost $host): ?array
    {
        return (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->getValue($host);
    }
}

/**
 * The keep-warm double: a fixed window and an immediate-resolve
 * frozen-prefix ask carrying a priced Usage — the ping's landing has to be
 * billable, so unlike its turn-test siblings this reply is NOT free. Under
 * its own name so the two files never share a hairball.
 */
final class WarmHostBackend implements Backend, ReportsContextWindow, SummarisesWithCache
{
    public int $turnCalls = 0;

    public int $summaryCalls = 0;

    public function __construct(
        private readonly int $window,
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

        return Message::assistant('.');
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->turnCalls++;

        return \React\Promise\resolve(Message::assistant('.'));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->summaryCalls++;

        return \React\Promise\resolve(
            Message::assistant($this->summaryReply)->withUsage(Usage::new(100, 0.0004)),
        );
    }
}
