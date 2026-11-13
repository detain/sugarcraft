<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Support;

/**
 * Per-source token bucket for the webhook door. The check runs BEFORE the
 * bearer is parsed, so a flood of unauthenticated POSTs costs a map lookup
 * and never a JWKS dial. A bucket is a float token count refilled at a
 * steady rate, capped at its capacity; the map is reset outright past a
 * size bound so a spray of spoofed sources cannot grow it without limit.
 */
final class RateLimiter
{
    private const MAX_BUCKETS = 4096;

    /** @var array<string, array{tokens: float, at: float}> */
    private array $buckets = [];

    public function __construct(
        private readonly float $capacity = 30.0,
        private readonly float $refillPerSecond = 0.5,
    ) {
    }

    public function take(string $key, ?float $now = null): bool
    {
        $now ??= \microtime(true);
        if (\count($this->buckets) > self::MAX_BUCKETS) {
            $this->buckets = [];
        }
        $bucket = $this->buckets[$key] ?? ['tokens' => $this->capacity, 'at' => $now];
        $tokens = \min($this->capacity, $bucket['tokens'] + \max(0.0, $now - $bucket['at']) * $this->refillPerSecond);
        $allowed = $tokens >= 1.0;
        $this->buckets[$key] = ['tokens' => $allowed ? $tokens - 1.0 : $tokens, 'at' => $now];

        return $allowed;
    }
}
