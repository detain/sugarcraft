<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Query\Admin\Calc\RatePerSecond;
use SugarCraft\Query\Admin\Calc\StatusVar;
use SugarCraft\Query\Admin\Calc\MakeTuple;
use SugarCraft\Query\Admin\Calc\TableOpenCacheHitRate;
use SugarCraft\Query\Admin\Calc\InnoDBBufferPoolUsage;

/**
 * Declarative widget tables for the Performance Dashboard.
 *
 * Each array contains Widget descriptors matching the MySQL Workbench
 * dashboard definition (Appendix A). Version-gated assembly happens in
 * WidgetRegistry.
 *
 * Widget array entry format:
 *   [caption, kind, calc, format, color, tooltip, serverVarsKeys]
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard GLOBAL_DASHBOARD_WIDGETS_*
 */
final class WidgetCatalog
{
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
     * DDL expression includes Com_alter_db_upgrade; version 8.0 removes it
     * and adds role-related commands (Com_create_role, Com_drop_role,
     * Com_alter_user_default_role).
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function mysqlPre80(): array
    {
        $ddlRates = (new MakeTuple(','))
            ->addRate('Com_create_db')
            ->addRate('Com_create_function')
            ->addRate('Com_create_procedure')
            ->addRate('Com_create_server')
            ->addRate('Com_create_table')
            ->addRate('Com_create_tablespace')
            ->addRate('Com_create_trigger')
            ->addRate('Com_drop_db')
            ->addRate('Com_drop_function')
            ->addRate('Com_drop_procedure')
            ->addRate('Com_drop_server')
            ->addRate('Com_drop_table')
            ->addRate('Com_drop_tablespace')
            ->addRate('Com_drop_trigger')
            ->addRate('Com_alter_db')
            ->addRate('Com_alter_function')
            ->addRate('Com_alter_procedure')
            ->addRate('Com_alter_server')
            ->addRate('Com_alter_table')
            ->addRate('Com_alter_tablespace')
            ->addRate('Com_alter_user')
            ->addRate('Com_alter_db_upgrade');

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
                (new MakeTuple(','))
                    ->addRate('Com_select')
                    ->addRate('Com_insert')
                    ->addRate('Com_update')
                    ->addRate('Com_delete'),
                '%s/s',
                ['r' => 255, 'g' => 215, 'b' => 0],
                'SQL statement rates: SELECT / INSERT,UPDATE,DELETE / DDL',
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
                'DDL',
                'counter',
                $ddlRates,
                '%s/s',
                ['r' => 155, 'g' => 89, 'b' => 182],
                'CREATE/ALTER/DROP rate',
                null,
            ],
        ];
    }

    /**
     * MySQL widgets for version 8.0 and later.
     *
     * Differs from pre-8.0 by including role commands
     * (Com_create_role, Com_drop_role, Com_alter_user_default_role)
     * and excluding Com_alter_db_upgrade.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function mysqlPost80(): array
    {
        $ddlRates = (new MakeTuple(','))
            ->addRate('Com_create_db')
            ->addRate('Com_create_function')
            ->addRate('Com_create_procedure')
            ->addRate('Com_create_server')
            ->addRate('Com_create_table')
            ->addRate('Com_create_tablespace')
            ->addRate('Com_create_trigger')
            ->addRate('Com_drop_db')
            ->addRate('Com_drop_function')
            ->addRate('Com_drop_procedure')
            ->addRate('Com_drop_server')
            ->addRate('Com_drop_table')
            ->addRate('Com_drop_tablespace')
            ->addRate('Com_drop_trigger')
            ->addRate('Com_alter_db')
            ->addRate('Com_alter_function')
            ->addRate('Com_alter_procedure')
            ->addRate('Com_alter_server')
            ->addRate('Com_alter_table')
            ->addRate('Com_alter_tablespace')
            ->addRate('Com_alter_user')
            ->addRate('Com_create_role')
            ->addRate('Com_drop_role')
            ->addRate('Com_alter_user_default_role');

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
                (new MakeTuple(','))
                    ->addRate('Com_select')
                    ->addRate('Com_insert')
                    ->addRate('Com_update')
                    ->addRate('Com_delete'),
                '%s/s',
                ['r' => 255, 'g' => 215, 'b' => 0],
                'SQL statement rates: SELECT / INSERT,UPDATE,DELETE / DDL',
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
                'DDL',
                'counter',
                $ddlRates,
                '%s/s',
                ['r' => 155, 'g' => 89, 'b' => 182],
                'CREATE/ALTER/DROP rate',
                null,
            ],
        ];
    }

    /**
     * InnoDB panel widgets.
     *
     * @return list<array{string,string,object,string,array{r:int,g:int,b:int},string,array<string,string>|null}>
     */
    public function innodb(): array
    {
        return [
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
                'Buffer Pool Usage',
                'round',
                new InnoDBBufferPoolUsage(),
                '%.0f%%',
                ['r' => 124, 'g' => 193, 'b' => 80],
                'InnoDB buffer pool usage percentage',
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
        ];
    }
}
