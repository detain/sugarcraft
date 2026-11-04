<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Code\Protocol\IdempotencyCache;

/**
 * Appendix O §6.3's dedupe map: an answer per (principal, method, key), kept
 * five minutes, at most 1,000 per principal AND a byte budget per principal,
 * the oldest forgotten first under either pressure (CWPD-7).
 */
final class IdempotencyCacheTest extends TestCase
{
    public function testAnAnswerIsKeptPerPrincipalMethodAndKey(): void
    {
        $cache = IdempotencyCache::new(static fn (): float => 100.0);
        $cache->put('owner', 'session.send', 'k', ['turnId' => 't1']);
        $cache->put('owner', 'session.create', 'k', null);

        self::assertSame(['answer' => ['turnId' => 't1']], $cache->get('owner', 'session.send', 'k'));
        self::assertSame(['answer' => null], $cache->get('owner', 'session.create', 'k'), 'a remembered null is still remembered');
        self::assertNull($cache->get('viewer', 'session.send', 'k'));
        self::assertNull($cache->get('owner', 'session.send', 'other'));
    }

    public function testAnAnswerExpiresAfterItsTtl(): void
    {
        $now = 0.0;
        $cache = IdempotencyCache::new(static function () use (&$now): float {
            return $now;
        });
        $cache->put('owner', 'm.x', 'k', 1);

        $now = IdempotencyCache::TTL_SECONDS - 1.0;
        self::assertNotNull($cache->get('owner', 'm.x', 'k'));
        $now = IdempotencyCache::TTL_SECONDS + 1.0;
        self::assertNull($cache->get('owner', 'm.x', 'k'));
    }

    public function testThePrincipalsOldestAnswersAreForgottenPastTheCap(): void
    {
        $cache = IdempotencyCache::new(static fn (): float => 0.0);
        for ($i = 0; $i <= IdempotencyCache::MAX_ENTRIES; $i++) {
            $cache->put('owner', 'm.x', 'k' . $i, $i);
        }

        self::assertSame(IdempotencyCache::MAX_ENTRIES, $cache->count('owner'));
        self::assertNull($cache->get('owner', 'm.x', 'k0'));
        self::assertSame(['answer' => IdempotencyCache::MAX_ENTRIES], $cache->get('owner', 'm.x', 'k' . IdempotencyCache::MAX_ENTRIES));
    }

    public function testTheBudgetIsADeliberateCeiling(): void
    {
        self::assertSame(8 * 1024 * 1024, IdempotencyCache::MAX_BYTES_PER_PRINCIPAL);
    }

    public function testFatAnswersHitTheByteBudgetLongBeforeTheCountCap(): void
    {
        $cache = IdempotencyCache::new(static fn (): float => 0.0);
        // Three answers at 40% of the budget each: 3 is nowhere near the
        // 1,000-entry cap, so only a byte budget can evict anything here.
        $payload = \str_repeat('a', (int) (IdempotencyCache::MAX_BYTES_PER_PRINCIPAL * 0.4));
        $entryBytes = \strlen(\serialize($payload));

        $cache->put('owner', 'm.x', 'k1', $payload);
        $cache->put('owner', 'm.x', 'k2', $payload);
        self::assertSame(2 * $entryBytes, $cache->bytes('owner'));

        $cache->put('owner', 'm.x', 'k3', $payload);
        self::assertSame(2, $cache->count('owner'), 'the third fat answer evicted the oldest');
        self::assertSame(2 * $entryBytes, $cache->bytes('owner'));
        self::assertNull($cache->get('owner', 'm.x', 'k1'), 'the mass eviction walks oldest-first too');
        self::assertNotNull($cache->get('owner', 'm.x', 'k3'));
    }

    public function testAnAnswerTooBigForTheBudgetIsForgottenAsFastAsItArrived(): void
    {
        $cache = IdempotencyCache::new(static fn (): float => 0.0);
        $cache->put('owner', 'm.x', 'k', \str_repeat('a', IdempotencyCache::MAX_BYTES_PER_PRINCIPAL + 1));

        self::assertSame(0, $cache->count('owner'));
        self::assertSame(0, $cache->bytes('owner'), 'nothing keeps the lease');
        self::assertNull($cache->get('owner', 'm.x', 'k'));

        $cache->put('owner', 'm.x', 'later', 1);
        self::assertSame(['answer' => 1], $cache->get('owner', 'm.x', 'later'), 'the budget recovers for the next answer');
    }

    public function testAnUnretainableAnswerRefusesTheCacheWithoutTouchingState(): void
    {
        $cache = IdempotencyCache::new(static fn (): float => 0.0);
        try {
            $cache->put('owner', 'm.x', 'k', static fn (): int => 1);
            self::fail('fixture: a closure is not a retainable answer');
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('cannot be retained', $refusal->getMessage());
        }
        self::assertSame(0, $cache->count('owner'));
        self::assertSame(0, $cache->bytes('owner'), 'the measurement happens before any state moves');
    }

    public function testExpiryGivesItsMassBack(): void
    {
        $now = 0.0;
        $cache = IdempotencyCache::new(static function () use (&$now): float {
            return $now;
        });
        $payload = \str_repeat('b', 1000);
        $cache->put('owner', 'm.x', 'k', $payload);
        self::assertSame(\strlen(\serialize($payload)), $cache->bytes('owner'));

        $now = IdempotencyCache::TTL_SECONDS + 1.0;
        self::assertNull($cache->get('owner', 'm.x', 'k'));
        self::assertSame(0, $cache->bytes('owner'), 'the read that forgot the answer paid its mass back');
    }

    public function testAnOverwriteReplacesItsMassInsteadOfStacking(): void
    {
        $cache = IdempotencyCache::new(static fn (): float => 0.0);
        $big = \str_repeat('c', 5000);
        $cache->put('owner', 'm.x', 'k', 'v1');
        $cache->put('owner', 'm.x', 'k', $big);

        self::assertSame(1, $cache->count('owner'));
        self::assertSame(\strlen(\serialize($big)), $cache->bytes('owner'));
        self::assertSame(['answer' => $big], $cache->get('owner', 'm.x', 'k'));
    }
}
