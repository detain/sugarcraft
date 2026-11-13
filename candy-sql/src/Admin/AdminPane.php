<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Sql\Lang;

/**
 * Admin dashboard pages split into two sections:
 * - Management: process list, variables, status, debug
 * - Performance: query stats, dashboard, table stats, performance schema
 *
 * Section membership is the truth of section(); no "connection stats"
 * page exists - per-connection data lives on the Process List page.
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
            self::ProcessList => Lang::t('pane.process_list'),
            self::Variables   => Lang::t('pane.variables'),
            self::Status      => Lang::t('pane.status'),
            self::QueryStats  => Lang::t('pane.query_stats'),
            self::Dashboard   => Lang::t('pane.dashboard'),
            self::TableStats  => Lang::t('pane.table_stats'),
            self::PerfSchema  => Lang::t('pane.perf_schema'),
            self::Debug       => Lang::t('pane.debug'),
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
