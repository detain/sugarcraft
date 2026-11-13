<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\HttpSmartProtocol;

use PHPUnit\Framework\TestCase;
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
        $cache = $this->cache();
        $cache->verify('top-secret-pw', \password_hash('x', \PASSWORD_BCRYPT, ['cost' => 4]), 'dave');
        $prop = new \ReflectionProperty(PasswordVerifyCache::class, 'entries');
        $prop->setAccessible(true);
        foreach ((array) $prop->getValue($cache) as $key => $_) {
            self::assertStringNotContainsString('top-secret-pw', (string) $key);
        }
    }
}
