<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Admin dashboard pages split into two sections:
 * - Management: process list, variables, status
 * - Performance: query stats, connection stats, table stats
 */
enum AdminPane: string
{
    case ProcessList = 'processlist';
    case Variables   = 'variables';
    case Status      = 'status';
    case QueryStats  = 'query_stats';
    case Dashboard   = 'dashboard';
    case TableStats  = 'table_stats';
    case PerfSchema  = 'perf_schema';

    public function label(): string
    {
        return match ($this) {
            self::ProcessList => 'Process List',
            self::Variables   => 'Variables',
            self::Status      => 'Status',
            self::QueryStats  => 'Query Stats',
            self::Dashboard   => 'Dashboard',
            self::TableStats  => 'Table Stats',
            self::PerfSchema  => 'Performance Schema',
        };
    }

    public function section(): AdminSection
    {
        return match ($this) {
            self::ProcessList, self::Variables, self::Status => AdminSection::Management,
            self::QueryStats, self::Dashboard, self::TableStats, self::PerfSchema => AdminSection::Performance,
        };
    }

    public function next(): self
    {
        return match ($this) {
            self::ProcessList => self::Variables,
            self::Variables   => self::Status,
            self::Status      => self::QueryStats,
            self::QueryStats  => self::Dashboard,
            self::Dashboard   => self::TableStats,
            self::TableStats  => self::PerfSchema,
            self::PerfSchema  => self::ProcessList,
        };
    }

    /**
     * Get all panes in order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::ProcessList,
            self::Variables,
            self::Status,
            self::QueryStats,
            self::Dashboard,
            self::TableStats,
            self::PerfSchema,
        ];
    }
}
