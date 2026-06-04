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
    case Debug       = 'debug';

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
            self::Debug       => 'Debug',
        };
    }

    public function section(): AdminSection
    {
        return match ($this) {
            self::ProcessList, self::Variables, self::Status, self::Debug => AdminSection::Management,
            self::QueryStats, self::Dashboard, self::TableStats, self::PerfSchema => AdminSection::Performance,
        };
    }

    /**
     * Get all panes in the order they are displayed in the sidebar.
     *
     * The sidebar renders section-grouped: Management panes first, then
     * Performance panes. This is the single source of truth for both the
     * sidebar renderer and the digit-key handler — they MUST agree, or
     * pressing a digit selects a different pane than what the sidebar shows.
     *
     * @return list<self>
     */
    public static function orderedCases(): array
    {
        $management = [];
        $performance = [];
        foreach (self::cases() as $pane) {
            if ($pane->section() === AdminSection::Management) {
                $management[] = $pane;
            } else {
                $performance[] = $pane;
            }
        }

        return [...$management, ...$performance];
    }
}
