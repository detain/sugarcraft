<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\StatusSnapshot;
use SugarCraft\Table\{Column, Row, RowData, Table};
use SugarCraft\Table\ColumnWidth;

/**
 * Connections page component integrating processlist, counters, filters, and actions.
 *
 * Uses sugar-table Table for the processlist grid with columns for
 * Id, User, Host, DB, Command, Time, State, Info. Provides detail tabs
 * for selected threads.
 *
 * @see Mirrors charmbracelet/lazysql connections page
 */
final class ConnectionsPage
{
    private ?ProcesslistResult $selectedThread = null;

    public function __construct(
        private readonly ProcesslistProvider $processlistProvider,
        private readonly ConnectionCounters $counters,
        private readonly ConnectionFilters $filters,
        private readonly ConnectionActions $actions,
        private readonly ConnectionDetailTabs $detailTabs,
    ) {}

    /**
     * Create a new connections page with standard dependencies.
     */
    public static function new(
        ServerContextInterface $context,
        ?int $maxConnections = 151,
    ): self {
        $provider = ProcesslistProvider::new($context);
        $actions = ConnectionActions::new($context);
        $detailTabs = ConnectionDetailTabs::new($context);

        return new self(
            processlistProvider: $provider,
            counters: ConnectionCounters::fromSnapshot(
                new StatusSnapshot($context->statusVariables(), $context->statusVariablesTs()),
                $maxConnections ?? 151
            ),
            filters: ConnectionFilters::new(),
            actions: $actions,
            detailTabs: $detailTabs,
        );
    }

    /**
     * Get the processlist table with current filters applied.
     *
     * @return Table
     */
    public function getTable(): Table
    {
        $rows = $this->processlistProvider->fetchAll();
        $filtered = $this->applyFilters($rows);

        return $this->buildTable($filtered);
    }

    /**
     * Get connection counters.
     */
    public function counters(): ConnectionCounters
    {
        return $this->counters;
    }

    /**
     * Get current filters.
     */
    public function filters(): ConnectionFilters
    {
        return $this->filters;
    }

    /**
     * Return a new instance with updated filters.
     */
    public function withFilters(ConnectionFilters $filters): self
    {
        $clone = clone $this;
        $clone->filters = $filters;
        return $clone;
    }

    /**
     * Get the selected thread for detail view.
     */
    public function selectedThread(): ?ProcesslistResult
    {
        return $this->selectedThread;
    }

    /**
     * Select a thread by index in the current filtered list.
     *
     * @param int $index 0-based index in filtered processlist
     * @return self
     */
    public function withSelectedIndex(int $index): self
    {
        $clone = clone $this;
        $rows = $this->processlistProvider->fetchAll();
        $filtered = $this->applyFilters($rows);

        if ($index >= 0 && $index < \count($filtered)) {
            $clone->selectedThread = $filtered[$index];
        }
        return $clone;
    }

    /**
     * Get the processlist rows with current filters applied.
     *
     * @return list<ProcesslistResult>
     */
    public function filteredProcesslist(): array
    {
        $rows = $this->processlistProvider->fetchAll();
        return $this->applyFilters($rows);
    }

    /**
     * Refresh processlist data.
     */
    public function refresh(): self
    {
        $clone = clone $this;
        $clone->processlistProvider = $clone->processlistProvider->refresh();
        return $clone;
    }

    /**
     * Apply filters to processlist rows.
     *
     * @param list<ProcesslistResult> $rows
     * @return list<ProcesslistResult>
     */
    private function applyFilters(array $rows): array
    {
        if ($this->filters->hideSleeping) {
            $rows = \array_values(
                \array_filter($rows, fn(ProcesslistResult $r) => $r->command !== 'Sleep')
            );
        }

        if ($this->filters->hideBackground) {
            $rows = \array_values(
                \array_filter($rows, fn(ProcesslistResult $r) => !$r->isBackground())
            );
        }

        return $rows;
    }

    /**
     * Build a sugar-table Table from processlist rows.
     *
     * @param list<ProcesslistResult> $rows
     * @return Table
     */
    private function buildTable(array $rows): Table
    {
        $columns = $this->buildColumns();
        $tableRows = $this->buildRows($rows);

        $table = Table::withColumns($columns)
            ->withRows($tableRows)
            ->withSelectable()
            ->withZebra();

        if ($this->filters->skipFullInfo) {
            $table = $table->withShowFooter(false);
        }

        return $table;
    }

    /**
     * @return list<Column>
     */
    private function buildColumns(): array
    {
        return [
            Column::new('id', 'Id', 8)->withAlignLeft(),
            Column::new('user', 'User', 12)->withFilterable(),
            Column::new('host', 'Host', 20)->withFilterable()->withAlignLeft(),
            Column::new('db', 'DB', 12)->withFilterable()->withAlignLeft(),
            Column::new('command', 'Command', 10),
            Column::new('time', 'Time', 8),
            Column::new('state', 'State', 15)->withAlignLeft(),
            Column::new('info', 'Info', 40)->withAlignLeft()->withMaxWidth(100),
        ];
    }

    /**
     * @param list<ProcesslistResult> $rows
     * @return list<Row>
     */
    private function buildRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = RowData::from([
                'id' => $row->processId,
                'user' => $row->user,
                'host' => $row->host,
                'db' => $row->database,
                'command' => $row->command,
                'time' => $row->time,
                'state' => $row->state,
                'info' => $row->info,
            ]);
            $result[] = Row::new($data);
        }
        return $result;
    }
}
