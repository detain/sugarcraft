<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Shared cache-freshness windows for the admin data path.
 *
 * The same two numbers were previously duplicated as private constants in
 * AdminQueryCache, ServerContext, and PostgresServerContext (and inlined in
 * App::subscriptions()), each under a different name. They must agree: the
 * admin fetch throttle, the async result cache, and the per-context status
 * cache all describe the same "how stale may live server data get" policy,
 * so a drifting copy silently changes refresh behaviour in one layer only.
 */
final class CacheTtl
{
    /**
     * Seconds a status/live snapshot (SHOW GLOBAL STATUS, process list,
     * async admin query results) stays fresh before it is re-requested.
     */
    public const STATUS = 3.0;

    /**
     * Seconds server configuration (SHOW GLOBAL VARIABLES / pg_settings)
     * stays fresh — variables change rarely, so poll far less often.
     */
    public const SERVER = 30.0;

    /**
     * Seconds between full admin status fetch batches (SHOW GLOBAL STATUS +
     * SHOW GLOBAL VARIABLES) while the Admin pane is open, and the matching
     * gate on DashboardPage's cell poll. MySQL Workbench's Performance
     * Dashboard refreshes once per second; the graphs are rate-based, so the
     * cadence is the feature. Deliberately NOT a cut of STATUS: that window
     * is also the AdminQueryCache / ServerContext freshness stamp for page
     * queries (processlist, async results), and halving it would double those
     * caches' re-query load for no dashboard benefit — the 1s rhythm only
     * needs the status fetch and the page poll to agree, and both read here.
     */
    public const DASHBOARD = 1.0;

    private function __construct()
    {
        // Constants-only holder.
    }
}
