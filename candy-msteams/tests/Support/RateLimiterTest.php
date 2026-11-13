<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

/** The webhook door's per-source budget: a burst is capped, refill is steady, sources are independent. */
final class RateLimiterTest extends TestCase
{
    public function testABurstIsCappedAndRefillsAtTheRate(): void
    {
        $limiter = new RateLimiter(3.0, 1.0);
        $now = 1000.0;
        self::assertTrue($limiter->take('10.0.0.1', $now));
        self::assertTrue($limiter->take('10.0.0.1', $now));
        self::assertTrue($limiter->take('10.0.0.1', $now));
        self::assertFalse($limiter->take('10.0.0.1', $now), 'the fourth request inside the burst is shed');

        self::assertTrue($limiter->take('10.0.0.1', $now + 1.0), 'one second refills one token');
        self::assertFalse($limiter->take('10.0.0.1', $now + 1.0));
    }

    public function testBucketsAreIndependentPerSource(): void
    {
        $limiter = new RateLimiter(1.0, 0.0);
        self::assertTrue($limiter->take('a', 1.0));
        self::assertFalse($limiter->take('a', 1.0));
        self::assertTrue($limiter->take('b', 1.0), 'a noisy source does not starve another');
    }
}
