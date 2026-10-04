<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Protocol\IdempotencyCache;

/**
 * Appendix O §6.3's dedupe map: an answer per (principal, method, key), kept
 * five minutes, at most 1,000 per principal, the oldest forgotten first.
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
}
