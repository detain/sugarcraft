<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\CacheHealthWatch;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Providers\CacheBreakpoints;
use SugarCraft\Crush\Tests\Support\DiscardsErrorLogTrait;
use SugarCraft\Crush\Usage;

/**
 * The turn loop's consumer of {@see CacheBreakpoints::observeCacheHealth()}
 * (P10.S3): the sentence goes out once per session, and the state that decides
 * it crosses the fork as a plain array.
 */
final class CacheHealthWatchTest extends TestCase
{
    use DiscardsErrorLogTrait;

    protected function setUp(): void
    {
        parent::setUp();
        RuntimeNoticeSink::reset();
        // The in-process backend: a transport would put the rows on a socket
        // this test cannot drain. Unarmed, every assertion on drain() below
        // would pass vacuously.
        RuntimeNoticeSink::arm(crossFork: false);
        self::assertTrue(RuntimeNoticeSink::isArmed());
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        parent::tearDown();
    }

    public function testTheThirdZeroReportRaisesTheNoticeAndNothingAfterItDoes(): void
    {
        $watch = new CacheHealthWatch();
        $returned = [];

        $log = self::withErrorLogDiscarded(static function () use ($watch, &$returned): void {
            for ($i = 0; $i < 6; $i++) {
                $returned[] = $watch->observe(self::zero());
            }
        });

        $this->assertSame([null, null], array_slice($returned, 0, 2), 'fewer than three zeros is not a pattern yet');
        $this->assertIsString($returned[2]);
        $this->assertStringContainsString('3 consecutive responses', $returned[2]);
        $this->assertSame([null, null, null], array_slice($returned, 3), 'the notice is once per session, not once per zero');

        $notices = RuntimeNoticeSink::drain();
        $this->assertCount(1, $notices, 'exactly one transcript row for the whole streak');
        $this->assertStringContainsString('cache_read_input_tokens = 0', $notices[0]);
        $this->assertStringContainsString('3 consecutive responses', $log, 'warn() keeps the forensic copy');
        $this->assertTrue($watch->noticed());
    }

    public function testAReportedCacheHitResetsTheStreak(): void
    {
        $watch = new CacheHealthWatch();

        self::withErrorLogDiscarded(static function () use ($watch): void {
            $watch->observe(self::zero());
            $watch->observe(self::zero());
            $watch->observe(Usage::new(100, 0.0, 10, 5, 85, 0));
            $watch->observe(self::zero());
            $watch->observe(self::zero());
        });

        $this->assertSame([], RuntimeNoticeSink::drain());
        $this->assertSame(['zeroReports' => 2, 'noticed' => false], $watch->state());
    }

    public function testTheStreakContinuesInTheWatchThatAdoptsIt(): void
    {
        $child = new CacheHealthWatch();
        $child->observe(self::zero());
        $child->observe(self::zero());

        $parent = new CacheHealthWatch();
        $parent->adopt($child->state());

        $this->assertSame(['zeroReports' => 2, 'noticed' => false], $parent->state());

        $raised = null;
        self::withErrorLogDiscarded(static function () use ($parent, &$raised): void {
            $raised = $parent->observe(self::zero());
        });

        $this->assertIsString($raised, 'two carried zeros plus one observed here is three');
    }

    public function testANoticeTheChildRaisedIsNotRaisedAgainByTheParent(): void
    {
        $parent = new CacheHealthWatch();
        $parent->adopt(['zeroReports' => 3, 'noticed' => true]);

        self::withErrorLogDiscarded(static function () use ($parent): void {
            $parent->observe(self::zero());
        });

        $this->assertSame([], RuntimeNoticeSink::drain());
        $this->assertTrue($parent->noticed());
    }

    public function testAParentThatAlreadyNoticedStaysNoticed(): void
    {
        $parent = new CacheHealthWatch();
        self::withErrorLogDiscarded(static function () use ($parent): void {
            for ($i = 0; $i < 3; $i++) {
                $parent->observe(self::zero());
            }
        });
        RuntimeNoticeSink::drain();

        // A child forked BEFORE the parent noticed reports false.
        $parent->adopt(['zeroReports' => 4, 'noticed' => false]);

        self::withErrorLogDiscarded(static function () use ($parent): void {
            $parent->observe(self::zero());
        });

        $this->assertTrue($parent->noticed());
        $this->assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function garbageFrames(): iterable
    {
        yield 'a frame without the key' => [null];
        yield 'a scalar' => ['3'];
        yield 'a string count' => [['zeroReports' => '2', 'noticed' => false]];
        yield 'a non-bool noticed' => [['zeroReports' => 2, 'noticed' => 1]];
        yield 'a missing noticed' => [['zeroReports' => 2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('garbageFrames')]
    public function testAnythingButTheWrittenShapeAdoptsNothing(mixed $frame): void
    {
        $watch = new CacheHealthWatch();
        $watch->observe(self::zero());

        $watch->adopt($frame);

        $this->assertSame(['zeroReports' => 1, 'noticed' => false], $watch->state());
    }

    public function testANegativeCarriedStreakIsClampedRatherThanInvented(): void
    {
        $watch = new CacheHealthWatch();
        $watch->adopt(['zeroReports' => -7, 'noticed' => false]);

        $this->assertSame(['zeroReports' => 0, 'noticed' => false], $watch->state());
    }

    public function testOneBreakAfterARewriteIsCountedButNotSaid(): void
    {
        $watch = new CacheHealthWatch();

        $this->assertNull($watch->observeReuse(self::split(0, 5000), 0.0, 'turn'));
        $this->assertNull($watch->observeReuse(self::split(5000, 6000), 1.0, 'turn'));
        // A prune rewrote the prefix: the request reads under half of the
        // 6000 tokens the one before it sent.
        $this->assertNull($watch->observeReuse(self::split(1000, 4000), 2.0, 'turn'));
        // …and recovers on the next.
        $this->assertNull($watch->observeReuse(self::split(4000, 4500), 3.0, 'turn'));

        $this->assertSame(1, $watch->cacheBreaks());
        $this->assertSame(['from' => 83, 'to' => 25], $watch->lastCacheBreak());
        $this->assertSame([], RuntimeNoticeSink::drain());
        $this->assertSame(['zeroReports' => 0, 'noticed' => false, 'reuse' => ['breaks' => 1, 'lastBreak' => ['from' => 83, 'to' => 25], 'breakNoticed' => false]], $watch->state());
    }

    public function testTwoBreaksInARowAreSaidOnce(): void
    {
        $watch = new CacheHealthWatch();
        $raised = [];

        self::withErrorLogDiscarded(static function () use ($watch, &$raised): void {
            $raised[] = $watch->observeReuse(self::split(0, 5000), 0.0, 'turn');
            $raised[] = $watch->observeReuse(self::split(4800, 6000), 1.0, 'turn');
            $raised[] = $watch->observeReuse(self::split(100, 6500), 2.0, 'turn');
            $raised[] = $watch->observeReuse(self::split(100, 7000), 3.0, 'turn');
            $raised[] = $watch->observeReuse(self::split(100, 7500), 4.0, 'turn');
        });

        $this->assertSame([null, null, null], array_slice($raised, 0, 3));
        $this->assertIsString($raised[3]);
        $this->assertStringContainsString('2 requests in a row', $raised[3]);
        $this->assertNull($raised[4], 'once per session');
        $this->assertCount(1, RuntimeNoticeSink::drain());
        $this->assertSame(3, $watch->cacheBreaks());
    }

    public function testOnlyRequestsOfOneConversationWithinTheIdleWindowAreCompared(): void
    {
        $watch = new CacheHealthWatch();

        $watch->observeReuse(self::split(5000, 6000), 0.0, 'parent');
        $watch->observeReuse(self::split(0, 3000), 1.0, 'sub-agent');
        $watch->observeReuse(self::split(0, 3000), 2.0 + CacheHealthWatch::BREAK_IDLE_SECONDS, 'sub-agent');
        $watch->observeReuse(self::split(100, 600), 3.0 + CacheHealthWatch::BREAK_IDLE_SECONDS, 'sub-agent');

        $this->assertSame(0, $watch->cacheBreaks(), 'another conversation, an idle eviction, and a prefix below what is cached at all');
    }

    public function testACacheThatNeverWorkedIsNotBroken(): void
    {
        $watch = new CacheHealthWatch();

        $watch->observeReuse(self::split(0, 5000), 0.0, 'turn');
        $watch->observeReuse(self::split(0, 6000), 1.0, 'turn');

        $this->assertSame(0, $watch->cacheBreaks(), 'that is the zero streak\'s finding, not a break');
    }

    public function testTheBreakTallyCrossesTheFork(): void
    {
        $child = new CacheHealthWatch();
        self::withErrorLogDiscarded(static function () use ($child): void {
            $child->observeReuse(self::split(5000, 6000), 0.0, 'turn');
            $child->observeReuse(self::split(5900, 6200), 1.0, 'turn');
            $child->observeReuse(self::split(100, 6500), 2.0, 'turn');
            $child->observeReuse(self::split(100, 7000), 3.0, 'turn');
        });
        RuntimeNoticeSink::drain();

        $parent = new CacheHealthWatch();
        $parent->adopt($child->state());
        $parent->adopt(['zeroReports' => 0, 'noticed' => false, 'reuse' => ['breaks' => 'x', 'breakNoticed' => true]]);

        $this->assertSame(2, $parent->cacheBreaks());
        $this->assertSame(['from' => 2, 'to' => 1], $parent->lastCacheBreak());
        self::withErrorLogDiscarded(static function () use ($parent): void {
            $parent->observeReuse(self::split(5000, 6000), 10.0, 'next');
            $parent->observeReuse(self::split(100, 6500), 11.0, 'next');
            $parent->observeReuse(self::split(100, 7000), 12.0, 'next');
        });
        $this->assertSame([], RuntimeNoticeSink::drain(), 'the child already said it');
    }

    private static function split(int $read, int $prompt): Usage
    {
        return Usage::new(0, 0.0, $prompt - $read, 10, $read, 0);
    }

    private static function zero(): Usage
    {
        return Usage::new(100, 0.0, 80, 20, 0, 0);
    }
}
