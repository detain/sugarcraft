<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Admin\Calc\CacheHitRate;
use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Calc\MakeTuple;

/**
 * PostgreSQL widget catalog for the Performance Dashboard.
 *
 * Provides I/O, Transactions, and Cache panel widgets using
 * PostgreSQL pg_stat_database metrics.
 *
 * Widget array entry format:
 *   [caption, kind, calc, format, color, tooltip, serverVarsKeys]
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard for PostgreSQL
 */
final class PostgresWidgetCatalog
{
    /**
     * I/O panel widgets (10 widgets).
     *
     * Covers block-level I/O metrics, tuple traffic, and connection count.
     * Mirrors MySQL Network panel (Bytes In/Out) via tup_fetched/tup_returned.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public static function io(): array
    {
        return [
            [
                'Tuples Fetched',
                'timeline',
                new RatePerSecond('pg_stat_database.tup_fetched'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'Rows fetched from DB: %(pg_stat_database.tup_fetched)s total',
                null,
            ],
            [
                'Tuples Fetched',
                'counter',
                new RatePerSecond('pg_stat_database.tup_fetched'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'Row fetch rate',
                null,
            ],
            [
                'Tuples Returned',
                'timeline',
                new RatePerSecond('pg_stat_database.tup_returned'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Rows returned to client: %(pg_stat_database.tup_returned)s total',
                null,
            ],
            [
                'Tuples Returned',
                'counter',
                new RatePerSecond('pg_stat_database.tup_returned'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Row return rate',
                null,
            ],
            [
                'Blocks Read',
                'timeline',
                new RatePerSecond('pg_stat_database.blks_read'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'Disk blocks read: %(pg_stat_database.blks_read)s total',
                null,
            ],
            [
                'Blocks Read',
                'counter',
                new RatePerSecond('pg_stat_database.blks_read'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                '',
                null,
            ],
            [
                'Blocks Hit',
                'timeline',
                new RatePerSecond('pg_stat_database.blks_hit'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Buffer cache hits: %(pg_stat_database.blks_hit)s total',
                null,
            ],
            [
                'Blocks Hit',
                'counter',
                new RatePerSecond('pg_stat_database.blks_hit'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                '',
                null,
            ],
            [
                'Connections',
                'timeline',
                new StatusVar('pg_stat_database.numbackends'),
                '%d',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Active backends: %(pg_stat_database.numbackends)s',
                null,
            ],
            [
                'Connections',
                'level',
                new StatusVar('pg_stat_database.numbackends'),
                '%d / %d',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Connections level vs max_connections',
                ['max' => 'max_connections'],
            ],
        ];
    }

    /**
     * Transactions panel widgets (7 widgets).
     *
     * Covers transaction commit/rollback rates and tuple-level DML.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public static function transactions(): array
    {
        return [
            [
                'Transactions',
                'timeline',
                (new MakeTuple(','))
                    ->addRate('pg_stat_database.xact_commit')
                    ->addRate('pg_stat_database.xact_rollback'),
                '%s/s',
                ['r' => 255, 'g' => 215, 'b' => 0],
                'Transaction rates: commits / rollbacks',
                null,
            ],
            [
                'Commits',
                'counter',
                new RatePerSecond('pg_stat_database.xact_commit'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'Transaction commit rate',
                null,
            ],
            [
                'Rollbacks',
                'counter',
                new RatePerSecond('pg_stat_database.xact_rollback'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Transaction rollback rate',
                null,
            ],
            [
                'INSERT',
                'counter',
                new RatePerSecond('pg_stat_database.tup_inserted'),
                '%s/s',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Tuple insert rate',
                null,
            ],
            [
                'UPDATE',
                'counter',
                new RatePerSecond('pg_stat_database.tup_updated'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Tuple update rate',
                null,
            ],
            [
                'DELETE',
                'counter',
                new RatePerSecond('pg_stat_database.tup_deleted'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Tuple delete rate',
                null,
            ],
            [
                'Deadlocks',
                'counter',
                new RatePerSecond('pg_stat_database.deadlocks'),
                '%s/s',
                ['r' => 238, 'g' => 75, 'b' => 130],
                'Deadlock rate',
                null,
            ],
        ];
    }

    /**
     * Cache panel widgets (4 widgets).
     *
     * Covers temporary file usage, buffer cache efficiency, and shared buffers config.
     * Mirrors MySQL InnoDB panel (buffer pool usage) via shared_buffers from pg_settings.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public static function cache(): array
    {
        return [
            [
                'Shared Buffers',
                'counter',
                new StatusVar('pg_settings.shared_buffers'),
                '%.0f B',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'PostgreSQL shared_buffers configuration (buffer pool size)',
                null,
            ],
            [
                'Cache Hit Rate',
                'round',
                new CacheHitRate('pg_stat_database.blks_hit', 'pg_stat_database.blks_read'),
                '%.1f%%',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Buffer cache hit ratio: blks_hit / (blks_hit + blks_read)',
                null,
            ],
            [
                'Temp Files',
                'counter',
                new RatePerSecond('pg_stat_database.temp_files'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Temp file creation rate',
                null,
            ],
            [
                'Temp Bytes',
                'counter',
                new RatePerSecond('pg_stat_database.temp_bytes'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Bytes written to temp files per second',
                null,
            ],
        ];
    }
}
