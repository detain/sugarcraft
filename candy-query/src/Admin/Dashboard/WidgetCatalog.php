<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Calc\MakeTuple;
use SugarCraft\Query\Admin\Calc\TableOpenCacheHitRate;
use SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsageBytes;

/**
 * Declarative widget tables for the Performance Dashboard.
 *
 * Each array contains Widget descriptors matching the MySQL Workbench
 * dashboard definition (Appendix A). Version-gated assembly happens in
 * WidgetRegistry.
 *
 * All catalog methods are instance methods (not static), allowing
 * WidgetRegistry to build panels by calling $catalog->network() etc. and
 * optionally subclassing for version-specific overrides (e.g. mysqlPre80()
 * vs mysqlPost80() for MySQL 5.x vs 8.0+ DDL command coverage).
 *
 * Widget array entry format:
 *   [caption, kind, calc, format, color, tooltip, serverVarsKeys]
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard GLOBAL_DASHBOARD_WIDGETS_*
 */
final class WidgetCatalog
{
    /**
     * Com_create_* command keys folded into the 'create' DDL series (pre-8.0).
     *
     * @var list<string>
     */
    private const CREATE_KEYS = [
        'Com_create_db',
        'Com_create_function',
        'Com_create_procedure',
        'Com_create_server',
        'Com_create_table',
        'Com_create_tablespace',
        'Com_create_trigger',
    ];

    /** @var list<string> */
    private const ALTER_KEYS = [
        'Com_alter_db',
        'Com_alter_function',
        'Com_alter_procedure',
        'Com_alter_server',
        'Com_alter_table',
        'Com_alter_tablespace',
        'Com_alter_user',
    ];

    /** @var list<string> */
    private const DROP_KEYS = [
        'Com_drop_db',
        'Com_drop_function',
        'Com_drop_procedure',
        'Com_drop_server',
        'Com_drop_table',
        'Com_drop_tablespace',
        'Com_drop_trigger',
    ];

    /**
     * Network panel widgets.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function network(): array
    {
        return [
            [
                'Bytes In',
                'timeline',
                new RatePerSecond('Bytes_received'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'Incoming traffic: %(Bytes_received)s bytes total',
                null,
            ],
            [
                'Bytes In',
                'counter',
                new RatePerSecond('Bytes_received'),
                '%s B/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                '',
                null,
            ],
            [
                'Bytes Out',
                'timeline',
                new RatePerSecond('Bytes_sent'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'Outgoing traffic: %(Bytes_sent)s bytes total',
                null,
            ],
            [
                'Bytes Out',
                'counter',
                new RatePerSecond('Bytes_sent'),
                '%s B/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                '',
                null,
            ],
            [
                'Connections',
                'timeline',
                new StatusVar('Threads_connected'),
                '%d',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Client connections: %(Threads_connected)s',
                null,
            ],
            [
                'Connections',
                'level',
                new StatusVar('Threads_connected'),
                '%d / %d',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Connections level vs max_connections',
                ['max' => 'max_connections'],
            ],
        ];
    }

    /**
     * MySQL widgets for versions before 8.0.
     *
     * The 8.0 line adds role commands (Com_create_role, Com_drop_role,
     * Com_alter_user_default_role); the pre-8.0 DDL groups additionally carry
     * Com_alter_db_upgrade, the version the upgrade path actually bumps.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function mysqlPre80(): array
    {
        $createKeys = [...self::CREATE_KEYS];
        $alterKeys = [...self::ALTER_KEYS, 'Com_alter_db_upgrade'];
        $dropKeys = [...self::DROP_KEYS];

        return $this->mysqlWidgets($createKeys, $alterKeys, $dropKeys);
    }

    /**
     * MySQL widgets for version 8.0 and later.
     *
     * Role commands join their DDL groups; Com_alter_db_upgrade is gone.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function mysqlPost80(): array
    {
        $createKeys = [...self::CREATE_KEYS, 'Com_create_role'];
        $alterKeys = [...self::ALTER_KEYS, 'Com_alter_user_default_role'];
        $dropKeys = [...self::DROP_KEYS, 'Com_drop_role'];

        return $this->mysqlWidgets($createKeys, $alterKeys, $dropKeys);
    }

    /**
     * Shared MySQL panel body; only the DDL group memberships differ between
     * versions, and the statement timeline + CREATE/ALTER/DROP counters below
     * MUST use the very same key lists or graph and label would disagree.
     *
     * Workbench plots SQL statements as one multi-line graph with
     * select/insert/update/delete/create/alter/drop labels, so the tuple
     * carries all seven series (declaration order = palette order) and the
     * old flat 'DDL' counter is replaced by per-verb counters fed by the
     * summed groups.
     *
     * @param list<string> $createKeys
     * @param list<string> $alterKeys
     * @param list<string> $dropKeys
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    private function mysqlWidgets(array $createKeys, array $alterKeys, array $dropKeys): array
    {
        $statements = static fn(): MakeTuple => (new MakeTuple(','))
            ->addRateAs('select', 'Com_select')
            ->addRateAs('insert', 'Com_insert')
            ->addRateAs('update', 'Com_update')
            ->addRateAs('delete', 'Com_delete')
            ->addRateSum('create', ...$createKeys)
            ->addRateSum('alter', ...$alterKeys)
            ->addRateSum('drop', ...$dropKeys);

        return [
            [
                'Table Open Cache',
                'round',
                new TableOpenCacheHitRate(),
                '%.0f%%',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'Table open cache hit ratio',
                null,
            ],
            [
                'SQL Statements',
                'timeline',
                $statements(),
                '%s/s',
                ['r' => 255, 'g' => 215, 'b' => 0],
                'SQL statement rates: select / insert / update / delete / create / alter / drop',
                null,
            ],
            [
                'SELECT',
                'counter',
                new RatePerSecond('Com_select'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'SELECT rate',
                null,
            ],
            [
                'INSERT',
                'counter',
                new RatePerSecond('Com_insert'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'INSERT rate',
                null,
            ],
            [
                'UPDATE',
                'counter',
                new RatePerSecond('Com_update'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'UPDATE rate',
                null,
            ],
            [
                'DELETE',
                'counter',
                new RatePerSecond('Com_delete'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'DELETE rate',
                null,
            ],
            [
                'CREATE',
                'counter',
                (new MakeTuple(','))->addRateSum('create', ...$createKeys),
                '%s/s',
                ['r' => 155, 'g' => 89, 'b' => 182],
                'CREATE command rate (all Com_create_* verbs)',
                null,
            ],
            [
                'ALTER',
                'counter',
                (new MakeTuple(','))->addRateSum('alter', ...$alterKeys),
                '%s/s',
                ['r' => 155, 'g' => 89, 'b' => 182],
                'ALTER command rate (all Com_alter_* verbs)',
                null,
            ],
            [
                'DROP',
                'counter',
                (new MakeTuple(','))->addRateSum('drop', ...$dropKeys),
                '%s/s',
                ['r' => 155, 'g' => 89, 'b' => 182],
                'DROP command rate (all Com_drop_* verbs)',
                null,
            ],
        ];
    }

    /**
     * InnoDB panel widgets.
     *
     * Order is deliberate: the buffer-pool donut first, then its three spec
     * label counters (read reqs / write reqs / disk reads), then the disk
     * write/read line graphs with their byte-rate counters, then the deeper
     * detail counters. DashboardPage renders widgets top-to-bottom, so this
     * IS the panel layout Workbench shows.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function innodb(): array
    {
        return [
            [
                'Buffer Pool Usage',
                'round',
                new InnoDBBufferPoolUsageBytes(),
                '%.0f%%',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'InnoDB buffer pool usage percentage (bytes-based, Appendix A)',
                null,
            ],
            [
                'Buffer Pool Read Reqs',
                'counter',
                new RatePerSecond('Innodb_buffer_pool_read_requests'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'InnoDB buffer pool read requests per second',
                null,
            ],
            [
                'Buffer Pool Write Reqs',
                'counter',
                new RatePerSecond('Innodb_buffer_pool_write_requests'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB buffer pool write requests per second',
                null,
            ],
            [
                'Disk Reads (not from pool)',
                'counter',
                new RatePerSecond('Innodb_buffer_pool_reads'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB buffer pool reads from disk per second',
                null,
            ],
            [
                'InnoDB Disk Writes',
                'timeline',
                new RatePerSecond('Innodb_data_written'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB data bytes written to disk per second',
                null,
            ],
            [
                'InnoDB Disk Writes',
                'counter',
                new RatePerSecond('Innodb_data_written'),
                '%s B/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                '',
                null,
            ],
            [
                'InnoDB Disk Reads',
                'timeline',
                new RatePerSecond('Innodb_data_read'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'InnoDB data bytes read from disk per second',
                null,
            ],
            [
                'InnoDB Disk Reads',
                'counter',
                new RatePerSecond('Innodb_data_read'),
                '%s B/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                '',
                null,
            ],
            [
                'Row Lock Waits',
                'counter',
                new RatePerSecond('Innodb_row_lock_waits'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB row lock waits per second',
                null,
            ],
            [
                'Row Lock Time',
                'counter',
                new RatePerSecond('Innodb_row_lock_time'),
                '%s ms/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB row lock time per second (milliseconds)',
                null,
            ],
            [
                'Pages Flushed',
                'counter',
                new RatePerSecond('Innodb_pages_flushed'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB pages flushed per second',
                null,
            ],
            [
                'Pages Created',
                'counter',
                new RatePerSecond('Innodb_pages_created'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'InnoDB pages created per second',
                null,
            ],
            [
                'Pages Read',
                'counter',
                new RatePerSecond('Innodb_pages_read'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'InnoDB pages read per second',
                null,
            ],
            [
                'Insert Buffer',
                'counter',
                new RatePerSecond('Innodb_ibuf_size'),
                '%s',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'InnoDB insert buffer size (pages)',
                null,
            ],
            [
                'Read Ahead',
                'counter',
                new RatePerSecond('Innodb_buffer_pool_read_ahead'),
                '%s/s',
                ['r' => 60, 'g' => 178, 'b' => 191],
                'InnoDB buffer pool read-ahead per second',
                null,
            ],
            [
                'Redo Log Bytes Written',
                'counter',
                new RatePerSecond('Innodb_os_log_written'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB redo log bytes written per second',
                null,
            ],
            [
                'Redo Log Writes',
                'counter',
                new RatePerSecond('Innodb_log_writes'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB redo log writes per second',
                null,
            ],
            [
                'Doublewrite Writes',
                'counter',
                new RatePerSecond('Innodb_dblwr_writes'),
                '%s/s',
                ['r' => 253, 'g' => 138, 'b' => 39],
                'InnoDB doublewrite writes per second',
                null,
            ],
        ];
    }
}