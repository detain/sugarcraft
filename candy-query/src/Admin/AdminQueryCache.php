<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Process-level coordinator bridging the synchronous render path to the
 * asynchronous ReactPHP query layer for the admin panel.
 *
 * Admin pages render synchronously in view(), but their data must come from
 * non-blocking queries so a slow `sys.*` report never freezes the event loop.
 * Providers read results from here (via {@see CachedConnection}); a cache miss
 * is recorded as "pending" and returns null instead of blocking. App's admin
 * tick drains the pending set, runs those queries on the React event loop, and
 * stores the rows back — so the next render shows data without ever blocking.
 *
 * Entries carry a timestamp and are re-requested once stale, giving periodic
 * live refresh (process list, replica status) without hammering the server:
 * a single in-flight fetch at a time is enforced by App's adminLoading gate.
 *
 * This is a deliberate process-global: App is rebuilt immutably on every
 * update(), so the live cache cannot live on the model. Call reset() in tests.
 */
final class AdminQueryCache
{
    /** Seconds a cached result stays fresh before it is re-requested. */
    private const TTL = 3.0;

    private static ?self $instance = null;

    /** @var array<string, list<array<string,mixed>>> */
    private array $results = [];

    /** @var array<string, float> */
    private array $storedAt = [];

    /** @var array<string, true> */
    private array $pending = [];

    private ?AsyncConnection $connection = null;
    private string $connectionKey = '';

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Reset all state — intended for tests. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Return cached rows for a query, or null when no usable value exists yet.
     *
     * A miss (or a stale entry) records the query as pending so the next drain
     * re-fetches it. Stale entries are still returned so the UI keeps showing
     * the last known data while the refresh is in flight.
     *
     * @return list<array<string,mixed>>|null
     */
    public function lookup(string $sql): ?array
    {
        if (!array_key_exists($sql, $this->results)) {
            $this->pending[$sql] = true;
            return null;
        }

        if ((microtime(true) - ($this->storedAt[$sql] ?? 0.0)) >= self::TTL) {
            // Stale: schedule a background refresh but keep showing last value.
            $this->pending[$sql] = true;
        }

        return $this->results[$sql];
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function store(string $sql, array $rows): void
    {
        $this->results[$sql] = $rows;
        $this->storedAt[$sql] = microtime(true);
        unset($this->pending[$sql]);
    }

    /**
     * Remove and return the set of queries awaiting a fetch.
     *
     * @return list<string>
     */
    public function takePending(): array
    {
        $pending = array_keys($this->pending);
        $this->pending = [];
        return $pending;
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /** Drop all cached results (e.g. on an explicit refresh). */
    public function forget(): void
    {
        $this->results = [];
        $this->storedAt = [];
    }

    /**
     * Lazily build and reuse the async connection for the given target so we
     * don't open a fresh TCP/auth connection on every tick. The key encodes
     * the flavor + DSN; a change rebuilds the connection.
     */
    public function connection(string $key, \Closure $factory): AsyncConnection
    {
        if ($this->connection === null || $this->connectionKey !== $key) {
            $this->connection = $factory();
            $this->connectionKey = $key;
        }

        return $this->connection;
    }

    /**
     * Get cached status variables from the last async fetch.
     *
     * @return array<string, string>|null
     */
    public function getStatusVariables(): ?array
    {
        $key = 'status';
        if (!array_key_exists($key, $this->results)) {
            return null;
        }

        if ((microtime(true) - ($this->storedAt[$key] ?? 0.0)) >= self::TTL) {
            $this->pending[$key] = true;
            return null;
        }

        $result = $this->results[$key];
        return is_array($result) ? $result : null;
    }

    /**
     * Get cached server variables from the last async fetch.
     *
     * @return array<string, string>|null
     */
    public function getServerVariables(): ?array
    {
        $key = 'server';
        if (!array_key_exists($key, $this->results)) {
            return null;
        }

        if ((microtime(true) - ($this->storedAt[$key] ?? 0.0)) >= self::TTL) {
            $this->pending[$key] = true;
            return null;
        }

        $result = $this->results[$key];
        return is_array($result) ? $result : null;
    }
}
