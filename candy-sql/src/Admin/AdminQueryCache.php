<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Sql\Db\DatabaseInterface;

/**
 * Process-level coordinator bridging the synchronous render path to the
 * asynchronous ReactPHP query layer for the admin panel.
 *
 * Admin pages render synchronously in view(), but their data must come from
 * non-blocking queries so a slow `sys.*` report never freezes the event loop.
 * Providers read results from here (via {@see AsyncCachedConnection}); a cache miss
 * is recorded as "pending" and returns null instead of blocking. App's admin
 * tick drains the pending set, runs those queries on the React event loop, and
 * stores the rows back — so the next render shows data without ever blocking.
 *
 * Entries carry a timestamp and are re-requested once stale, giving periodic
 * live refresh (process list, replica status) without hammering the server:
 * a single in-flight fetch at a time is enforced by App's adminLoading gate.
 *
 * The shared default is a deliberate process-global: App is rebuilt immutably
 * on every update(), so the live cache cannot live on the model. Call reset()
 * in tests. Instances are also directly constructible so collaborators
 * (AsyncCachedConnection, AsyncCachingServerContext, ReconnectManager) can receive an
 * isolated cache via injection — tests then don't have to touch global state,
 * and multiple caches can coexist in one process.
 */
final class AdminQueryCache
{
    /** Seconds a cached result stays fresh before it is re-requested. */
    private const TTL = CacheTtl::STATUS;

    private static ?self $instance = null;

    /** @var array<string, list<array<string,mixed>>> */
    private array $results = [];

    /** @var array<string, float> */
    private array $storedAt = [];

    /** @var array<string, true> */
    private array $pending = [];

    /**
     * client-012: the last known failure per query. An errored drain leg used
     * to `store($sql, [])` — the page then rendered that empty list as an
     * authoritative "No data" grid (the processlist's permanent blank while
     * the header said Connected/Running). Errored legs are now MARKED here and
     * their value slots left untouched, so a page sees loading/last-known
     * truth plus this named cause instead of a fabricated empty success.
     *
     * @var array<string, string>
     */
    private array $errors = [];

    private ?AsyncConnection $connection = null;
    private string $connectionKey = '';

    private ?ServerContextInterface $serverContext = null;
    private ?DatabaseInterface $serverContextDb = null;

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
        // If we have a result, return it without re-adding to pending.
        // Only schedule a refresh if the entry is stale.
        if (array_key_exists($sql, $this->results)) {
            if ((microtime(true) - ($this->storedAt[$sql] ?? 0.0)) >= self::TTL) {
                // Stale: schedule a background refresh but keep showing last value.
                $this->pending[$sql] = true;
            }
            return $this->results[$sql];
        }

        // Cache miss: record as pending and return null so the caller
        // renders its loading state while the query runs async.
        $this->pending[$sql] = true;
        return null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function store(string $sql, array $rows): void
    {
        $this->results[$sql] = $rows;
        $this->storedAt[$sql] = microtime(true);
        unset($this->pending[$sql], $this->errors[$sql]);
    }

    /**
     * Mark a query leg as errored WITHOUT caching an empty result (client-012).
     *
     * The drain's failure arm used to `store($sql, [])`; a page reading that
     * back through {@see self::lookup()} saw an authoritative empty list and
     * rendered a permanent "No data" grid while the header still said
     * "Connected". Recording the failure separately leaves the value slot a
     * true cache miss (null → the page keeps its loading/last-known shape), so
     * the defect's tell is a named cause instead of a fabricated empty success.
     */
    public function markError(string $sql, string $message): void
    {
        $this->errors[$sql] = $message;
        unset($this->results[$sql], $this->storedAt[$sql], $this->pending[$sql]);
    }

    /**
     * The last known failure for $sql, or null when none. Cleared by a
     * subsequent success ({@see self::store()}) or a
     * {@see self::invalidateConnection()}/{@see self::forget()}.
     */
    public function errorFor(string $sql): ?string
    {
        return $this->errors[$sql] ?? null;
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
        $this->errors = [];
    }

    /**
     * When the payload under $key last arrived, or null if never stored.
     *
     * SQL-A03: adopters must stamp their snapshots with the payload's TRUE
     * fetchedAt rather than "now" — the same anti-laundering rule the
     * AsyncCachingServerContext constructor carries for MINOR-1.
     */
    public function storedAt(string $key): ?float
    {
        return $this->storedAt[$key] ?? null;
    }

    /**
     * Lazily build and reuse the async connection for the given target so we
     * don't open a fresh TCP/auth connection on every tick. The key encodes
     * the flavor + DSN; a change rebuilds the connection.
     */
    public function connection(string $key, \Closure $factory): AsyncConnection
    {
        if ($this->connection === null) {
            $this->connection = $factory();
            $this->connectionKey = $key;
        } elseif ($this->connectionKey !== $key) {
            // admindata-004: a live session is being replaced — retire it
            // (close + purge its rows) before the new dial takes the slot;
            // the first-ever dial must NOT purge, because A03's adoption
            // path can legitimately store rows before any async leg ran.
            $this->invalidateConnection();
            $this->connection = $factory();
            $this->connectionKey = $key;
        }

        return $this->connection;
    }

    /**
     * Single-slot memo for the synchronous-side ServerContext, keyed by the
     * identity of the database handle it wraps.
     *
     * WHY (E718): App is immutable and rebuilt on every update(), and
     * ConnectionState::$serverContext is null on every production entry point,
     * so App::createContext() used to mint a FRESH ServerContext on each call.
     * A pane switch drops the admin page (AdminState::withPane()); the next
     * render rebuilds it, and any synchronous delegation into the inner context
     * used to hit a brand-new, cold TTL cache — one blocking SHOW GLOBAL STATUS
     * per navigation, inside the very window the TTL was supposed to cover
     * (SQL-A03 cured the fetch-on-stamp-read itself: the stamp getters are now
     * non-fetching by contract). The memo still carries the weight: it is the
     * stable seed target for the restart latch and Uptime baseline that
     * refreshFromLiveCache adoption feeds. Holding one context per live
     * database handle — the same process-global pattern as {@see connection()}
     * above — keeps that latch state and seed target stable across page resets.
     *
     * Identity (not DSN) is the key: a reconnect that swaps in a new handle
     * must not resurrect a context bound to the dead one, while a handle that
     * reconnects in place keeps its warm cache.
     */
    public function serverContext(DatabaseInterface $db, \Closure $factory): ServerContextInterface
    {
        if ($this->serverContext !== null && $this->serverContextDb === $db) {
            return $this->serverContext;
        }

        /** @var ServerContextInterface $context */
        $context = $factory($db);
        $this->serverContext = $context;
        $this->serverContextDb = $db;

        return $context;
    }

    /**
     * Drop the cached async connection so the next {@see connection()} call
     * rebuilds it via its factory.
     *
     * Needed after a synchronous-side reconnect: the connection key encodes
     * only flavor + DSN + user + credential fingerprint, which do not change
     * across a server restart, so without an explicit invalidation the cache
     * would keep handing out an async connection whose socket died with the
     * old server process.
     *
     * admindata-004: the dropped wrapper owns a live server session — it is
     * CLOSE()d, not merely unreferenced (a dropped-but-open client holds an fd
     * and loop watchers until the server's wait_timeout expires).
     * admindata-007: the rows it produced are retired too. Entries are keyed
     * by SQL TEXT alone, so after a reconnect/failover behind the same DSN the
     * old session's rows would otherwise keep serving with no TTL floor —
     * clearing $results/$storedAt/$pending makes every value slot a true
     * miss that the next drain re-fetches over the new session. ($errors is
     * deliberately kept: each entry records the last drain attempt, which is
     * a true statement regardless of session identity, and store() clears it
     * on the next success.)
     */
    public function invalidateConnection(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // The session may already be dead (that is often why this
                // runs); a refusal to close must not strand the rest.
            }
        }

        $this->connection = null;
        $this->connectionKey = '';
        $this->results = [];
        $this->storedAt = [];
        $this->pending = [];
    }

    /**
     * Get cached status variables from the last async fetch.
     *
     * @return array<string, string>|null
     */
    public function statusVariables(): ?array
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
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::statusVariables()} instead. REMOVED-IN: 2.0.0.
     */
    public function getStatusVariables(): ?array { return $this->statusVariables(); }

    /**
     * Get cached server variables from the last async fetch.
     *
     * @return array<string, string>|null
     */
    public function serverVariables(): ?array
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

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::serverVariables()} instead. REMOVED-IN: 2.0.0.
     */
    public function getServerVariables(): ?array { return $this->serverVariables(); }
}
