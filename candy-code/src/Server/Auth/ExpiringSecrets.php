<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Auth;

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
        $this->trimToCapacity();

        $secret = \bin2hex(\random_bytes($bytes));
        $this->entries[self::hash($secret)] = ['expires' => ($this->clock)() + $this->ttlSeconds, 'value' => $value];

        return $secret;
    }

    /**
     * The live rows as `hash => expiresAt` — {@see export()}'s counterpart for
     * a store whose owner persists it (WEB-6): hashes only, never a secret,
     * in either direction.
     *
     * @return array<string, float>
     */
    public function export(): array
    {
        $this->purge();
        $rows = [];
        foreach ($this->entries as $hash => $entry) {
            $rows[$hash] = $entry['expires'];
        }

        return $rows;
    }

    /**
     * Merge rows back in after a restart, binding each to $value. The caller
     * parsed them (a {@see SessionFileStore} rejects malformed and expired
     * rows at the boundary); a hash already live in memory keeps its entry —
     * this hydrates, it does not overwrite.
     *
     * @param array<string, float> $hashToExpires
     */
    public function import(array $hashToExpires, string $value): void
    {
        foreach ($hashToExpires as $hash => $expires) {
            $this->entries[$hash] ??= ['expires' => $expires, 'value' => $value];
        }
        $this->trimToCapacity();
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

    /** Forget every secret at once (the credential they were minted under changed). */
    public function revokeAll(): void
    {
        $this->entries = [];
    }

    /** How many live secrets are held. */
    public function count(): int
    {
        $this->purge();

        return \count($this->entries);
    }

    /** Oldest expiry first, so a flood of mints cannot grow the map past the cap. */
    private function trimToCapacity(): void
    {
        if (\count($this->entries) >= $this->capacity) {
            \uasort($this->entries, static fn (array $a, array $b): int => $a['expires'] <=> $b['expires']);
            \array_shift($this->entries);
        }
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
