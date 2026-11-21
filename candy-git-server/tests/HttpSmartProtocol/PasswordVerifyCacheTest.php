<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\LruMap;
use SugarCraft\GitServer\HttpSmartProtocol\PasswordVerifyCache;

/**
 * @covers \SugarCraft\GitServer\HttpSmartProtocol\PasswordVerifyCache
 */
final class PasswordVerifyCacheTest extends TestCase
{
    private float $now = 1000.0;

    private function cache(): PasswordVerifyCache
    {
        return new PasswordVerifyCache(fn (): float => $this->now);
    }

    public function testHitReturnsCachedOutcomeWithoutTouchingTheHash(): void
    {
        $hash = \password_hash('sword', \PASSWORD_BCRYPT, ['cost' => 4]);
        $cache = $this->cache();

        self::assertTrue($cache->verify('sword', $hash, 'alice'));
        // On a hit the $hash argument must never be re-verified — prove it
        // by handing the cache a deliberately WRONG digest for the same
        // (user, credential): a real bcrypt pass would flip the answer.
        self::assertTrue($cache->verify('sword', 'not-a-digest', 'alice'));
        self::assertFalse($cache->verify('nope', $hash, 'alice'));
    }

    public function testRefusalsAreCachedToo(): void
    {
        $hash = \password_hash('sword', \PASSWORD_BCRYPT, ['cost' => 4]);
        $cache = $this->cache();

        self::assertFalse($cache->verify('wrong', $hash, 'bob'));
        self::assertFalse($cache->verify('wrong', 'not-a-digest', 'bob'));
    }

    public function testKeySeparatesUsernameDimension(): void
    {
        $hash = \password_hash('sword', \PASSWORD_BCRYPT, ['cost' => 4]);
        $cache = $this->cache();

        self::assertTrue($cache->verify('sword', $hash, 'alice'));
        // Same credential bytes, different account name: fresh verify
        // (which a poisoned hash proves).
        self::assertFalse($cache->verify('sword', 'not-a-digest', 'carol'));
    }

    public function testExpiredEntryRecomputes(): void
    {
        $hash = \password_hash('sword', \PASSWORD_BCRYPT, ['cost' => 4]);
        $cache = $this->cache();
        self::assertTrue($cache->verify('sword', $hash, 'alice'));

        $this->now += PasswordVerifyCache::TTL_SECONDS + 1;
        // Expired: the poisoned hash now actually decides the answer.
        self::assertFalse($cache->verify('sword', 'not-a-digest', 'alice'));
    }

    public function testLruEvictsOldestBeyondCapAndKeepsTouchedEntries(): void
    {
        $cache = $this->cache();
        $fillerHash = \password_hash('f', \PASSWORD_BCRYPT, ['cost' => 4]);

        // Fill to capacity (every leg TRUE), then re-verify the FIRST
        // entry so a later insertion must evict entry #2, not #1.
        for ($i = 0; $i < PasswordVerifyCache::MAX_ENTRIES; $i++) {
            self::assertTrue($cache->verify('f', $fillerHash, 'u' . $i));
        }
        self::assertSame(PasswordVerifyCache::MAX_ENTRIES, $cache->size());

        self::assertTrue($cache->verify('f', $fillerHash, 'u0'));  // touch #0
        self::assertFalse($cache->verify('pw-new', $fillerHash, 'overflow')); // evict #1

        self::assertSame(PasswordVerifyCache::MAX_ENTRIES, $cache->size());
        // #0 survived its touch: hit with poisoned hash still true.
        self::assertTrue($cache->verify('f', 'not-a-digest', 'u0'));
        // #1 is gone: fresh verify against the poisoned hash answers false.
        self::assertFalse($cache->verify('f', 'not-a-digest', 'u1'));
    }

    public function testPasswordBytesNeverLandInIdentityOfStoredKeys(): void
    {
        // Structural: keys are (username, sha256(password)) — read the
        // entry map via reflection and prove no raw secret is recoverable.
        $password = 'top-secret-pw';
        $cache = $this->cache();
        $cache->verify($password, \password_hash('x', \PASSWORD_BCRYPT, ['cost' => 4]), 'dave');
        $prop = new \ReflectionProperty(PasswordVerifyCache::class, 'entries');
        $prop->setAccessible(true);
        /** @var LruMap<bool> $map */
        $map = $prop->getValue($cache);
        $keys = $map->keys();

        // Guard against a vacuous pass: the loop must actually see the one key.
        self::assertCount(1, $keys);
        foreach ($keys as $key) {
            self::assertStringNotContainsString($password, $key);
            // Pin the exact shape (username + sha256 digest): a key carrying the
            // password in any other form fails here, not only the literal text.
            self::assertSame('dave' . "\n" . \hash('sha256', $password), $key);
        }
    }

    public function testDefaultConstructionMemoizesWithoutAnInjectedClock(): void
    {
        // Production builds the cache with no clock (Server::verifyCache()), so
        // the default path must memoize too, not only the injected-clock one.
        $hash = \password_hash('sword', \PASSWORD_BCRYPT, ['cost' => 4]);
        $cache = new PasswordVerifyCache();

        self::assertTrue($cache->verify('sword', $hash, 'alice'));
        // A hit on the default clock must skip the poisoned digest entirely.
        self::assertTrue($cache->verify('sword', 'not-a-digest', 'alice'));
        self::assertSame(1, $cache->size());
    }

    public function testDefaultConstructionWiresTtlAndMonotonicClock(): void
    {
        // Expiry cannot be waited out on the real clock (120 s), so pin the
        // wiring instead: the TTL is TTL_SECONDS and no test clock is injected,
        // which leaves LruMap on its monotonic hrtime default.
        $cache = new PasswordVerifyCache();
        $prop = new \ReflectionProperty(PasswordVerifyCache::class, 'entries');
        $prop->setAccessible(true);
        /** @var LruMap<bool> $map */
        $map = $prop->getValue($cache);

        $ttl = new \ReflectionProperty(LruMap::class, 'ttl');
        $ttl->setAccessible(true);

        self::assertSame(PasswordVerifyCache::TTL_SECONDS, $ttl->getValue($map));
    }

    public function testExpiredEntriesAreReapedBeforeLiveOnesAreEvicted(): void
    {
        $cache = $this->cache();
        $hash = \password_hash('f', \PASSWORD_BCRYPT, ['cost' => 4]);

        // One entry goes stale while the rest of the cache fills with live entries.
        self::assertTrue($cache->verify('f', $hash, 'stale'));
        $this->now += PasswordVerifyCache::TTL_SECONDS + 1;
        for ($i = 1; $i < PasswordVerifyCache::MAX_ENTRIES; $i++) {
            self::assertTrue($cache->verify('f', $hash, 'u' . $i));
        }

        // Inserting past capacity must drop the expired entry, not a live one.
        self::assertFalse($cache->verify('pw-new', $hash, 'overflow'));
        self::assertTrue($cache->verify('f', 'not-a-digest', 'u1'));
        // The expired entry was dropped, so the poisoned digest decides again.
        self::assertFalse($cache->verify('f', 'not-a-digest', 'stale'));
    }
}
