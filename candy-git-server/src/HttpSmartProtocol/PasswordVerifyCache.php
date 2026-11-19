<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

use SugarCraft\Core\Util\LruMap;

/**
 * Small capped LRU of bcrypt results (finding 048).
 *
 * An LFS-heavy or multi-request fetch re-sends the same Basic header on
 * every request, and the old shape paid a fresh 100-300 ms verify each
 * time. This cache keys the outcome on (username, password-digest) so a
 * repeated credential settles in microseconds. It is deliberately
 * process-scoped, not file-backed like the S08 budget: under the
 * worker-per-request pool its whole life is the connection's, which is
 * exactly the repeat pattern being fixed, and persisting auth RESULTS to
 * disk would be a far worse trade than a per-connection re-verify.
 *
 * Freshness bound: the TTL. A password changed out from under a warm
 * entry is honored (or refused, for a revoked credential) at most
 * self::TTL_SECONDS late — the ceiling the old per-request shape paid
 * zero of, since only a process restart cleared it.
 *
 * Only REAL digests are cached. The unknown-user and key-only decoy
 * burns stay outside (their uniform refusal timing is the enumeration
 * oracle closure, and a cache hit on the decoy side would shortcut the
 * very work that makes refusals indistinguishable); the cache is asked
 * solely where a stored hash was found to check against.
 *
 * Password bytes never land in the keys — the digest's SHA-256 does —
 * so the process memory holds no recoverable credential table.
 */
final class PasswordVerifyCache
{
    /** Entries kept; evicted least-recently-used first. */
    public const MAX_ENTRIES = 128;

    /** How long one outcome stands (the 60-300 s band, mid point). */
    public const TTL_SECONDS = 120.0;

    /** @var LruMap<bool> outcome per (username, password-digest) key, TTL-bound */
    private readonly LruMap $entries;

    /**
     * Without an injected clock, TTL math runs on LruMap's monotonic hrtime
     * clock. The pre-P1-11 cache read the wall clock (microtime), which a
     * backwards NTP step could stretch past TTL_SECONDS; a monotonic clock
     * keeps the freshness bound in the class docblock true.
     *
     * @param (callable(): float)|null $clock test seam for TTL math
     */
    public function __construct(?callable $clock = null)
    {
        $this->entries = LruMap::new(self::MAX_ENTRIES)->withTtl(
            self::TTL_SECONDS,
            $clock === null ? null : \Closure::fromCallable($clock),
        );
    }

    /**
     * password_verify() with memoization: an unexpired hit is returned
     * WITHOUT touching the $hash argument at all (which the unit pins
     * use to prove the bcrypt was skipped), anything else verifies and
     * records the outcome.
     */
    public function verify(string $password, string $hash, string $username): bool
    {
        $key = $username . "\n" . \hash('sha256', $password);

        // get() treats an expired entry as absent and promotes a live one, so
        // a hit is the recency touch the hand-rolled LRU used to do by hand.
        $hit = $this->entries->get($key);
        if ($hit !== null) {
            return $hit;
        }

        $outcome = \password_verify($password, $hash);
        $this->entries->put($key, $outcome);

        return $outcome;
    }

    /** Live entries (tests + introspection). */
    public function size(): int
    {
        return $this->entries->count();
    }
}
