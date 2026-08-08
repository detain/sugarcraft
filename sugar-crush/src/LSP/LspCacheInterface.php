<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Contract for LSP response caching.
 */
interface LspCacheInterface
{
    /**
     * Cache a value for a given URI + method key.
     *
     * @param string $uri     File URI (e.g. file:///path/to/File.php)
     * @param string $method  LSP method (e.g. textDocument/definition)
     * @param mixed  $value   The cached response value
     */
    public function set(string $uri, string $method, mixed $value): void;

    /**
     * Retrieve a cached value if it exists and has not expired.
     *
     * @param string $uri    File URI
     * @param string $method LSP method
     * @return mixed|null The cached value or null if absent/expired
     */
    public function get(string $uri, string $method): mixed;

    /**
     * Evict all cache entries for a given URI.
     *
     * @param string $uri File URI
     */
    public function clearFile(string $uri): void;

    /**
     * Evict all cache entries.
     */
    public function clear(): void;

    /**
     * Remove expired entries from the cache.
     *
     * @return int Number of entries evicted
     */
    public function prune(): int;

    /**
     * Number of entries currently held (including expired, which are pruned on read).
     */
    public function count(): int;

    /**
     * Whether a non-expired entry exists for the given key.
     */
    public function has(string $uri, string $method): bool;
}
