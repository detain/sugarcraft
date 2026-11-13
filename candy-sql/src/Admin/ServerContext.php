<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\Version;

/**
 * MySQL-focused ServerContext implementation.
 *
 * Caches SHOW GLOBAL VARIABLES, SHOW GLOBAL STATUS (with timestamp),
 * SHOW PLUGINS, and parsed version/flavor. Gracefully degrades on
 * MySQL errors 1142/1227/1146/2002/2003/2013.
 *
 * ## How this fits the non-blocking admin data path (E718)
 *
 * The live feed for the admin pane is asynchronous: App::subscriptions()
 * ticks once per second (throttled to CacheTtl::STATUS) and
 * App::createAdminFetchPromise() runs SHOW GLOBAL STATUS / VARIABLES on the
 * React event loop, storing results in AdminQueryCache. Pages render through
 * AsyncCachingServerContext, which answers variable queries from that cache
 * and NEVER calls into this class's fetches for them.
 *
 * This class's own TTL caches ({@see statusVariables()}, {@see
 * serverVariables()}) are the synchronous backstop behind the few delegations
 * AsyncCachingServerContext still forwards — statusVariablesTs(), wasReset(),
 * lastUptime() and the sync-side admin providers. To keep that backstop
 * actually cheap across admin-pane resets, the model hands out ONE shared
 * instance via AdminQueryCache::serverContext() — a single slot keyed to the
 * database handle it wraps (a process alternating two handles re-mints on
 * each switch: bounded, pinned by the displacement test):
 * dropping and rebuilding the page (AdminState::withPane()) reuses the same
 * warm cache instead of minting a cold context whose first read would block.
 *
 * ACCEPTED RESIDUAL: the first synchronous read in each TTL window still
 * issues one blocking fetch on the calling (render) path. Removing that
 * entirely means every consumer moves onto the async cache; the status page's
 * sampling helpers are not there yet. The window bounds the blast radius —
 * repeated renders inside it execute the query exactly once — but do not
 * eliminate the call, and the surviving call is DURATION-unbounded: it is a
 * synchronous PDO read, so a hung server can stall that one render for its
 * full connection timeout. E646 bans blanket timeouts on our side, so this
 * is a disclosed property, not an oversight.
 *
 * @see Mirrors charmbracelet/lazysql ServerContext
 */
final class ServerContext implements ServerContextInterface
{
    private const STATUS_CACHE_TTL = CacheTtl::STATUS;
    private const SERVER_CACHE_TTL = CacheTtl::SERVER;

    private ?array $serverVariablesCache = null;
    private ?float $serverVariablesTsCache = null;
    private ?array $statusVariablesCache = null;
    private ?float $statusVariablesTsCache = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $pluginsCache = null;
    private ?Version $versionCache = null;
    private ?Flavor $flavorCache = null;
    private ?string $versionStringCache = null;
    private ?bool $wasResetCache = null;
    private ?int $lastUptime = null;

    public function __construct(
        private readonly DatabaseInterface $connection,
        private readonly ?Flavor $flavor = null,
    ) {
        // If flavor is provided, use it to pre-set the cache
        if ($this->flavor !== null) {
            $this->flavorCache = $this->flavor;
        }
    }

    public function connection(): DatabaseInterface
    {
        return $this->connection;
    }

    /**
     * SHOW GLOBAL VARIABLES with an in-window TTL cache (fail-open).
     *
     * Fresh cache → return it, no query. Expired/absent → issue exactly one
     * fetch per window. If that fetch fails with a non-ignorable error and a
     * previous snapshot exists, the stale snapshot is served rather than
     * crashing the render — a monitoring pane showing 3-second-old data beats
     * a dead UI, and the next window retries. With no previous snapshot the
     * error is rethrown (there is nothing honest to serve).
     *
     * The timestamp records the last refresh ATTEMPT, not the last success:
     * a failed window still swallows its retry until it expires, so a
     * struggling server costs at most one blocking call per TTL, never one
     * per render.
     *
     * @return array<string, string>
     */
    public function serverVariables(): array
    {
        $now = microtime(true);

        if ($this->serverVariablesCache !== null && $this->serverVariablesTsCache !== null) {
            if (($now - $this->serverVariablesTsCache) < self::SERVER_CACHE_TTL) {
                return $this->serverVariablesCache;
            }
        }

        $stale = $this->serverVariablesCache;
        $this->serverVariablesTsCache = $now;

        try {
            $this->serverVariablesCache = $this->fetchServerVariables();
        } catch (\PDOException $e) {
            if ($stale === null) {
                throw $e;
            }
            return $stale;
        }

        return $this->serverVariablesCache;
    }

    /**
     * SHOW GLOBAL STATUS with an in-window TTL cache (fail-open).
     *
     * Same contract as {@see serverVariables()}: within CacheTtl::STATUS of
     * the last refresh attempt the cached snapshot answers every read — two
     * renders inside one window execute the query exactly once — and a failed
     * refresh serves the last-known snapshot instead of propagating into the
     * render path. Cold-with-error rethrows; detection of a server restart
     * (detectReset) runs only on a successful fetch.
     *
     * @return array<string, string>
     */
    public function statusVariables(): array
    {
        $now = microtime(true);

        if ($this->statusVariablesCache !== null && $this->statusVariablesTsCache !== null) {
            if (($now - $this->statusVariablesTsCache) < self::STATUS_CACHE_TTL) {
                return $this->statusVariablesCache;
            }
        }

        $stale = $this->statusVariablesCache;
        $this->statusVariablesTsCache = $now;

        try {
            $this->statusVariablesCache = $this->fetchStatusVariables();
        } catch (\PDOException $e) {
            if ($stale === null) {
                throw $e;
            }
            return $stale;
        }

        $this->detectReset();

        return $this->statusVariablesCache;
    }

    public function statusVariablesTs(): float
    {
        // SQL-A03: NON-fetching by contract. This used to call
        // statusVariables() on a cold cache, which is how the "non-blocking"
        // AsyncCachingServerContext ended up issuing one duration-unbounded
        // blocking SHOW GLOBAL STATUS from inside the render path every TTL
        // window (the remote-RTT UI freeze). The stamp describes the data
        // this context already holds: fresh snapshots arrive either through a
        // successful statusVariables() fetch or through the async pipeline's
        // seedStatusSnapshot() adoption — both advance it. Readers that want
        // DATA call statusVariables(); that method alone owns fetching.
        return $this->statusVariablesTsCache ?? 0.0;
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        if ($this->pluginsCache !== null) {
            return $this->pluginsCache;
        }

        $this->pluginsCache = $this->fetchPlugins();
        return $this->pluginsCache;
    }

    public function version(): Version
    {
        if ($this->versionCache !== null) {
            return $this->versionCache;
        }

        $raw = $this->connection()->serverVersion();
        $this->versionStringCache = $raw;
        $this->versionCache = Version::parse($raw);
        return $this->versionCache;
    }

    public function flavor(): Flavor
    {
        if ($this->flavorCache !== null) {
            return $this->flavorCache;
        }

        $version = $this->version();
        $numericVersion = $version->major . '.' . $version->minor . '.' . $version->release;
        $raw = $this->connection()->serverVersion();
        $this->flavorCache = Flavor::detectFromVersionString($numericVersion, $raw);
        return $this->flavorCache;
    }

    public function versionString(): string
    {
        if ($this->versionStringCache !== null) {
            return $this->versionStringCache;
        }

        return $this->connection()->serverVersion();
    }

    public function password(): string
    {
        // Delegated to the underlying connection if it has password().
        // AsyncCachedConnection no longer exposes password() so we return ''
        // when the connection doesn't support it.
        if (method_exists($this->connection(), 'password')) {
            return $this->connection()->password();
        }
        return '';
    }

    public function wasReset(): bool
    {
        // SQL-A03: NON-fetching — same contract as statusVariablesTs(). The
        // latch is written by detectReset(), which runs on every successful
        // fetch and on every async adoption via seedStatusSnapshot(); a
        // render-path read must never issue SHOW GLOBAL STATUS to answer it.
        return $this->wasResetCache ?? false;
    }

    /**
     * Adopt a snapshot the async admin pipeline fetched elsewhere (SQL-A03).
     *
     * WHY: the AsyncCachingServerContext wrapper serves data from
     * AdminQueryCache and never lets the render path reach this context's
     * fetch — but the restart latch and the freshness stamp live HERE, so
     * unless adopted snapshots are seeded, Uptime is never observed and
     * wasReset() lies false for the whole session. Called from the arrival
     * site (refreshFromLiveCache) with the payload's TRUE fetchedAt so age
     * math reads honestly (same anti-laundering rule as MINOR-1).
     *
     * @param array<string, string> $statusVars
     */
    public function seedStatusSnapshot(array $statusVars, float $fetchedAt): void
    {
        $this->statusVariablesCache = $statusVars;
        $this->statusVariablesTsCache = $fetchedAt;
        $this->detectReset();
    }

    /**
     * Get the last known server uptime.
     *
     * Returns null if uptime has not been tracked yet.
     */
    public function lastUptime(): ?int
    {
        // SQL-A03: non-fetching, same contract as wasReset()/statusVariablesTs().
        return $this->lastUptime;
    }

    /**
     * Force a refresh of all cached values.
     */
    public function refresh(): void
    {
        $this->serverVariablesCache = null;
        $this->serverVariablesTsCache = null;
        $this->statusVariablesCache = null;
        $this->statusVariablesTsCache = null;
        $this->pluginsCache = null;
        $this->versionCache = null;
        $this->flavorCache = null;
        $this->versionStringCache = null;
    }

    /**
     * @return array<string, string>
     */
    private function fetchServerVariables(): array
    {
        try {
            $rows = $this->connection()->query('SHOW GLOBAL VARIABLES');
            $out = [];
            foreach ($rows as $row) {
                if (isset($row['Variable_name'], $row['Value'])) {
                    $out[(string) $row['Variable_name']] = (string) $row['Value'];
                }
            }
            return $out;
        } catch (\PDOException $e) {
            if ($this->isIgnorableError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function fetchStatusVariables(): array
    {
        try {
            $rows = $this->connection()->query('SHOW GLOBAL STATUS');
            $out = [];
            foreach ($rows as $row) {
                if (isset($row['Variable_name'], $row['Value'])) {
                    $out[(string) $row['Variable_name']] = (string) $row['Value'];
                }
            }
            return $out;
        } catch (\PDOException $e) {
            if ($this->isIgnorableError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchPlugins(): array
    {
        try {
            $rows = $this->connection()->query('SHOW PLUGINS');
            /** @var list<array<string, mixed>> */
            return $rows;
        } catch (\PDOException $e) {
            if ($this->isIgnorableError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Detect whether the server has restarted since the last poll.
     *
     * Compares the current Uptime status variable against the last observed
     * value. SQL-A02 (admindata-001): this is EDGE-TRIGGERED — the single
     * authoritative write of the latch — true on a decrease, false on every
     * other comparison. It used to write true and nothing else, so a lone
     * restart permanently disabled Queries/s, QPS and Running: Sampler
     * re-armed its firstSample baseline on every tick the latch said true.
     * Consumers (Sampler) delegate here via
     * StatusSnapshotProviderInterface::wasReset().
     *
     * Called automatically by statusVariables() after fetching fresh data.
     */
    private function detectReset(): void
    {
        $uptime = $this->statusVariablesCache['Uptime'] ?? null;
        if ($uptime === null) {
            return;
        }

        // Snapshot semantics: one read of $lastUptime into a local, compare
        // against it, then one write. PHP is single-threaded per request, but
        // this runs amid interleaved ReactPHP callbacks — an interleaved
        // read/compare/write on the property itself would let another callback
        // update lastUptime between our read and our write, masking (or
        // fabricating) a restart. The local pins the value this comparison is
        // based on, so the compare and the state transition stay consistent.
        $previousUptime = $this->lastUptime;
        $currentUptime = (int) $uptime;
        $this->wasResetCache = $previousUptime !== null && $currentUptime < $previousUptime;
        $this->lastUptime = $currentUptime;
    }

    /**
     * MySQL driver codes (PDO errorInfo[1]) that indicate missing privileges,
     * unavailable features, or a dead transport — the graceful-degradation set.
     *
     * SQL-A02 (admindata-002): the old list matched PDOException::getCode()
     * against a mix of SQLSTATEs and driver codes, but real PDO puts the
     * SQLSTATE in getCode() (e.g. '42S02'/'HY000') and the driver code in
     * errorInfo[1] — so production errors were misclassified on both axes:
     * degradation paths stayed dead (cold statusVariables() against a lost
     * connection rethrew into the render backstop — the same crash family as
     * client-001) while the broad '42000' SQLSTATE would have swallowed
     * genuine syntax errors had any production error ever carried it.
     *
     * Note what is deliberately absent: 1054 (ER_BAD_FIELD_ERROR) — a broken
     * column reference must surface, not masquerade as access-denied
     * (adminpages-004's P16 leg leans on this); and no bare '42000' SQLSTATE
     * (ER_PARSE_ERROR 1064 shares it).
     *
     * @see https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference/
     * @see https://dev.mysql.com/doc/mysql-errors/8.0/en/client-error-reference/
     */
    private const IGNORABLE_DRIVER_CODES = [
        1044,  // ER_DBACCESS_DENIED_ERROR
        1049,  // ER_BAD_DB_ERROR
        1142,  // ER_TABLEACCESS_DENIED_ERROR
        1143,  // ER_COLUMNACCESS_DENIED_ERROR
        1146,  // ER_NO_SUCH_TABLE
        1227,  // ER_SPECIFIC_ACCESS_DENIED_ERROR (proxy, replication grants)
        2002,  // CR_CONNECTION_ERROR (Can't connect to MySQL server)
        2003,  // CR_CONN_HOST_ERROR (Unknown MySQL server host)
        2013,  // CR_SERVER_LOST (Lost connection to MySQL server during query)
    ];

    /**
     * Standard SQLSTATEs safe to degrade on (portable across the MySQL and
     * PostgreSQL drivers): 42S02 base-table-not-found, 28000 invalid
     * authorization, 3D000 catalog/database disappears, and the PostgreSQL
     * privilege/undefined-relation pair 42501/42P01.
     */
    private const IGNORABLE_SQLSTATES = ['42S02', '28000', '3D000', '42501', '42P01'];

    /**
     * True for errors that indicate missing privileges or unavailable features.
     *
     * Classification order (SQL-A02): driver code from errorInfo[1] first —
     * it is the precise signal; then a deliberate SQLSTATE set. An int-coded
     * hand-built PDOException (test fixtures with no errorInfo) matches by
     * neither and rethrows — that is the honest default.
     */
    private function isIgnorableError(\PDOException $e): bool
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode !== 0 && \in_array($driverCode, self::IGNORABLE_DRIVER_CODES, true)) {
            return true;
        }
        return \in_array((string) $e->getCode(), self::IGNORABLE_SQLSTATES, true);
    }
}