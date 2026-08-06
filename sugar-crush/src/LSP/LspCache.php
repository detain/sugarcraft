<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * In-memory TTL cache for LSP responses keyed by file URI + method.
 *
 * Caches the five response types produced by LspConnection:
 * definitions, references, hover, symbols, and diagnostics.
 * Entries expire after $ttlSeconds and the cache supports clearing
 * by individual URI or flushing entirely.
 *
 * Mirrors the LSP spec: https://microsoft.github.io/language-server-protocol/
 */
class LspCache
{
    /**
     * @var array<string, array{value: mixed, expiresAt: float}>
     */
    private array $entries = [];

    public function __construct(
        private readonly int $ttlSeconds = 60,
    ) {}

    /**
     * Cache a value for a given URI + method key.
     *
     * @param string $uri     File URI (e.g. file:///path/to/File.php)
     * @param string $method  LSP method (e.g. textDocument/definition)
     * @param mixed  $value   The cached response value
     */
    public function set(string $uri, string $method, mixed $value): void
    {
        $key = $this->makeKey($uri, $method);
        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => microtime(true) + $this->ttlSeconds,
        ];
    }

    /**
     * Retrieve a cached value if it exists and has not expired.
     *
     * @param string $uri    File URI
     * @param string $method LSP method
     * @return mixed|null The cached value or null if absent/expired
     */
    public function get(string $uri, string $method): mixed
    {
        $key = $this->makeKey($uri, $method);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] < microtime(true)) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry['value'];
    }

    /**
     * Evict all cache entries for a given URI.
     *
     * @param string $uri File URI
     */
    public function clearFile(string $uri): void
    {
        $prefix = $uri . "\x00";
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->entries[$key]);
            }
        }
    }

    /**
     * Evict all cache entries.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Remove expired entries from the cache.
     *
     * @return int Number of entries evicted
     */
    public function prune(): int
    {
        $now = microtime(true);
        $pruned = 0;
        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] < $now) {
                unset($this->entries[$key]);
                ++$pruned;
            }
        }
        return $pruned;
    }

    /**
     * Number of entries currently held (including expired, which are
     * pruned on read or explicit prune()).
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Whether a non-expired entry exists for the given key.
     *
     * Distinguishes "entry absent/expired" from "entry present with null value".
     */
    public function has(string $uri, string $method): bool
    {
        $key = $this->makeKey($uri, $method);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['expiresAt'] < microtime(true)) {
            unset($this->entries[$key]);
            return false;
        }

        return true;
    }

    /**
     * @param string $uri
     * @param string $method
     * @return string Composite cache key
     */
    private function makeKey(string $uri, string $method): string
    {
        return $uri . "\x00" . $method;
    }
}
