<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Auth;

/**
 * Short-lived random secrets held only as SHA-256 hashes, each with an expiry
 * and an optional bound value — the one mechanism behind login codes, cookie
 * sessions and WebSocket tickets (Appendix O §8.2).
 *
 * HASHED AT REST, IN MEMORY TOO: a heap dump, a debug print or a stray
 * `var_export` of the server shows hashes, never a usable secret. Lookup is by
 * the hash, so no comparison ever runs over the secret itself.
 *
 * Expired entries are purged on every call; the map is bounded by the
 * {@see $capacity} cap besides, oldest expiry first, so a flood of mints (each
 * already behind authentication or the rate limiter) cannot grow it without
 * limit.
 */
final class ExpiringSecrets
{
    /** @var array<string, array{expires: float, value: string}> hash => entry */
    private array $entries = [];

    /** @param \Closure(): float $clock */
    private function __construct(
        private readonly float $ttlSeconds,
        private readonly \Closure $clock,
        private readonly int $capacity,
    ) {
    }

    /** @param (\Closure(): float)|null $clock seconds, defaults to microtime(true) */
    public static function new(float $ttlSeconds, ?\Closure $clock = null, int $capacity = 1024): self
    {
        return new self($ttlSeconds, $clock ?? static fn (): float => \microtime(true), $capacity);
    }

    /** A fresh secret ($bytes random bytes as hex) bound to $value. */
    public function mint(string $value = '', int $bytes = 32): string
    {
        $this->purge();
        if (\count($this->entries) >= $this->capacity) {
            \uasort($this->entries, static fn (array $a, array $b): int => $a['expires'] <=> $b['expires']);
            \array_shift($this->entries);
        }

        $secret = \bin2hex(\random_bytes($bytes));
        $this->entries[self::hash($secret)] = ['expires' => ($this->clock)() + $this->ttlSeconds, 'value' => $value];

        return $secret;
    }

    /** The value $secret is bound to, consuming it; null when unknown or expired. */
    public function consume(string $secret): ?string
    {
        $this->purge();
        $hash = self::hash($secret);
        $entry = $this->entries[$hash] ?? null;
        unset($this->entries[$hash]);

        return $entry['value'] ?? null;
    }

    /**
     * The value $secret is bound to, keeping it and — when $slide — pushing its
     * expiry a full TTL from now; null when unknown or expired.
     */
    public function peek(string $secret, bool $slide = false): ?string
    {
        $this->purge();
        $hash = self::hash($secret);
        if (!isset($this->entries[$hash])) {
            return null;
        }
        if ($slide) {
            $this->entries[$hash]['expires'] = ($this->clock)() + $this->ttlSeconds;
        }

        return $this->entries[$hash]['value'];
    }

    public function revoke(string $secret): void
    {
        unset($this->entries[self::hash($secret)]);
    }

    /** How many live secrets are held. */
    public function count(): int
    {
        $this->purge();

        return \count($this->entries);
    }

    private function purge(): void
    {
        $now = ($this->clock)();
        foreach ($this->entries as $hash => $entry) {
            if ($entry['expires'] <= $now) {
                unset($this->entries[$hash]);
            }
        }
    }

    private static function hash(string $secret): string
    {
        return \hash('sha256', $secret);
    }
}
