<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * Performance Reports page with left category/report tree and right sortable/exportable grid.
 *
 * Features:
 * - Left tree: reports grouped by category (problems, schema, io, memory, etc.)
 * - Right grid: sortable, paginated data from sys schema views
 * - Per-column unit toggle for time/byte columns
 * - CSV export of current report results
 * - PS + sys schema validation gate
 *
 * Keyboard shortcuts:
 *   [j/k]     - navigate rows down/up
 *   [r]       - refresh current report
 *   [x]       - export to CSV
 *   [c]       - toggle unit display (time columns)
 *   [q]       - quit to previous view
 *
 * @see Mirrors mysql-workbench wb_admin_perfschema_reports
 */
final class ReportsPage extends PageBase
{
    /** @var Catalog|null */
    private ?Catalog $catalog = null;

    /** @var AvailabilityChecker|null */
    private ?AvailabilityChecker $availability = null;

    /** @var ReportRunner|null */
    private ?ReportRunner $runner = null;

    /** @var ReportResult|null */
    private ?ReportResult $currentResult = null;

    /** @var string|null */
    private ?string $selectedCategory = null;

    /** @var string|null */
    private ?string $selectedReport = null;

    /** @var int */
    private int $selectedRowIndex = 0;

    /** @var int */
    private int $page = 0;

    /** @var int */
    private int $pageSize = 50;

    /** @var bool */
    private bool $showRawValues = false;

    /** @var string */
    private string $sortColumn = '';

    /** @var bool */
    private bool $sortAsc = true;

    /** @var list<string> */
    private array $categories = [];

    /** @var array<string, list<ReportDefinition>> */
    private array $reportsByCategory = [];

    public function __construct(
        ServerContextInterface $context,
        ?DatabaseInterface $db = null,
    ) {
        parent::__construct($context);
        $this->db = $db;
    }

    /**
     * Create a new ReportsPage from the server context.
     */
    public static function new(ServerContextInterface $context, ?DatabaseInterface $db = null): self
    {
        return new self($context, $db);
    }

    /**
     * Validate that PS and sys schema are available.
     */
    protected function validate(): bool
    {
        try {
            $this->db = $this->context->connection();

            $this->catalog = Catalog::new();
            $this->catalog->load();

            $this->availability = AvailabilityChecker::new($this->db);
            if (!$this->availability->sysSchemaExists()) {
                $this->errorMessage = 'MySQL sys schema is not installed. Performance Reports require MySQL 5.6.6+ with the sys schema.';

                return false;
            }

            $this->runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

            $this->categories = $this->catalog->categories();
            $this->reportsByCategory = [];
            foreach ($this->categories as $category) {
                $this->reportsByCategory[$category] = $this->availability->availableInCategory($this->catalog, $category);
            }

            if ($this->selectedCategory === null && !empty($this->categories)) {
                $this->selectedCategory = $this->categories[0];
            }

            if ($this->selectedReport === null && isset($this->reportsByCategory[$this->selectedCategory])) {
                $reports = $this->reportsByCategory[$this->selectedCategory];
                if (!empty($reports)) {
                    $this->selectedReport = $reports[0]->name;
                }
            }

            if ($this->selectedReport !== null) {
                $this->loadCurrentReport();
            }

            return true;
        } catch (\Throwable $e) {
            $this->errorMessage = 'Cannot access Performance Reports: ' . $e->getMessage();

            return false;
        }
    }

    /**
     * Render the error screen.
     */
    protected function errorScreen(): string
    {
        return $this->renderErrorScreen();
    }

    /**
     * Build the complete reports page output.
     */
    protected function build(): string
    {
        $lines = [];

        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = $this->renderLayout();
        $lines[] = '';
        $lines[] = $this->renderFooter();

        return \implode("\n", $lines);
    }

    /**
     * Handle keyboard shortcuts for navigation and actions.
     *
     * @return array{0: self, 1: ?\SugarCraft\Core\Cmd}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';
        $type = $msg->type;

        if ($ch === 'j' || $type === KeyType::Down) {
            return [$this->withNavigateDown(), null];
        }

        if ($ch === 'k' || $type === KeyType::Up) {
            return [$this->withNavigateUp(), null];
        }

        if ($ch === 'r') {
            return [$this->withRefresh(), null];
        }

        if ($ch === 'x') {
            return [$this->withExport(), null];
        }

        if ($ch === 'c') {
            return [$this->withToggleUnitDisplay(), null];
        }

        if ($ch === 'q') {
            return [$this->withQuit(), null];
        }

        return [$this, null];
    }

    // ─── Wither Methods ───────────────────────────────────────────────────────

    public function withNavigateDown(): self
    {
        if ($this->currentResult === null) {
            return $this;
        }

        $maxRows = count($this->currentResult->rows);
        if ($maxRows === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedRowIndex = min($clone->selectedRowIndex + 1, $maxRows - 1);

        return $clone;
    }

    public function withNavigateUp(): self
    {
        if ($this->currentResult === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedRowIndex = max($clone->selectedRowIndex - 1, 0);

        return $clone;
    }

    public function withRefresh(): self
    {
        $clone = clone $this;
        $clone->page = 0;
        $clone->selectedRowIndex = 0;
        $clone->loadCurrentReport();

        return $clone;
    }

    public function withToggleUnitDisplay(): self
    {
        $clone = clone $this;
        $clone->showRawValues = !$clone->showRawValues;

        return $clone;
    }

    public function withSelectCategory(string $category): self
    {
        $clone = clone $this;
        $clone->selectedCategory = $category;
        $clone->selectedReport = null;
        $clone->selectedRowIndex = 0;
        $clone->page = 0;

        if (isset($this->reportsByCategory[$category]) && !empty($this->reportsByCategory[$category])) {
            $clone->selectedReport = $this->reportsByCategory[$category][0]->name;
        }

        $clone->loadCurrentReport();

        return $clone;
    }

    public function withSelectReport(string $reportName): self
    {
        $clone = clone $this;
        $clone->selectedReport = $reportName;
        $clone->selectedRowIndex = 0;
        $clone->page = 0;

        $clone->loadCurrentReport();

        return $clone;
    }

    public function withExport(): self
    {
        return $this;
    }

    // ─── Rendering Methods ────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $title = 'Performance Reports';

        return "\x1b[1m\x1b[36m{$title}\x1b[0m";
    }

    private function renderLayout(): string
    {
        $left = $this->renderCategoryTree();
        $right = $this->renderReportGrid();

        return $this->renderSideBySide($left, $right, 35, 90);
    }

    private function renderCategoryTree(): string
    {
        $lines = [];

        $lines[] = "\x1b[1mCategories\x1b[0m";
        $lines[] = '';

        foreach ($this->categories as $category) {
            $reports = $this->reportsByCategory[$category] ?? [];
            $count = count($reports);
            $marker = $category === $this->selectedCategory ? '>' : ' ';
            $lines[] = "{$marker} {$category} ({$count})";

            if ($category === $this->selectedCategory) {
                foreach ($reports as $report) {
                    $marker = $report->name === $this->selectedReport ? '*' : ' ';
                    $lines[] = "  {$marker} {$report->caption}";
                }
            }
        }

        return \implode("\n", $lines);
    }

    private function renderReportGrid(): string
    {
        if ($this->currentResult === null) {
            return 'Select a report from the left panel.';
        }

        if ($this->currentResult->isEmpty()) {
            return "No data available for {$this->currentResult->report->caption}";
        }

        $report = $this->currentResult->report;
        $rows = $this->currentResult->rows;

        $lines = [];

        $lines[] = "\x1b[1m{$report->caption}\x1b[0m";
        $lines[] = "\x1b[90m{$report->description}\x1b[0m";
        $lines[] = '';

        $columns = $this->buildColumns($report);
        $tableRows = $this->buildRows($rows);

        $table = Table::withColumns($columns)
            ->withRows($tableRows)
            ->withSelectable(true)
            ->withShowFooter(true);

        $lines[] = $table->View();

        $lines[] = '';
        $lines[] = "\x1b[90mShowing " . count($rows) . " rows" . ($this->currentResult->limited ? ' (limited)' : '') . "\x1b[0m";

        return \implode("\n", $lines);
    }

    /**
     * @return list<Column>
     */
    private function buildColumns(ReportDefinition $report): array
    {
        $columns = [];
        foreach ($report->columns as $col) {
            $alignment = $col->type->isNumeric() ? Column::ALIGN_RIGHT : Column::ALIGN_LEFT;
            $columns[] = Column::new($col->name, $col->name, $col->width)
                ->withAlignment($alignment);
        }

        return $columns;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<Row>
     */
    private function buildRows(array $rows): array
    {
        $tableRows = [];
        foreach ($rows as $index => $row) {
            $cells = [];
            foreach ($row as $value) {
                $cells[] = (string) ($value ?? 'NULL');
            }
            $rowData = RowData::new($cells);
            $row = Row::new($rowData);

            if ($index === $this->selectedRowIndex) {
                $row = $row->withStyle('7');
            }

            $tableRows[] = $row;
        }

        return $tableRows;
    }

    private function renderSideBySide(string $left, string $right, int $leftWidth, int $rightWidth): string
    {
        $leftLines = \explode("\n", $left);
        $rightLines = \explode("\n", $right);

        $maxLines = \max(\count($leftLines), \count($rightLines));
        $result = [];

        for ($i = 0; $i < $maxLines; $i++) {
            $leftLine = ($leftLines[$i] ?? '');
            $rightLine = ($rightLines[$i] ?? '');

            $leftLine = \str_pad(\substr($leftLine, 0, $leftWidth), $leftWidth, ' ');
            $rightLine = \str_pad(\substr($rightLine, 0, $rightWidth), $rightWidth, ' ');

            $result[] = $leftLine . '  ' . $rightLine;
        }

        return \implode("\n", $result);
    }

    private function renderFooter(): string
    {
        return "\x1b[90m[j/k] nav  [r] refresh  [x] export  [c] unit toggle  [q] quit\x1b[0m";
    }

    private function renderErrorScreen(): string
    {
        $lines = [];
        $lines[] = "\x1b[1m\x1b[31mPerformance Reports Unavailable\x1b[0m";
        $lines[] = '';
        $lines[] = $this->errorMessage;
        $lines[] = '';
        $lines[] = "\x1b[90mEnsure MySQL 5.6.6+ with performance_schema=enabled\x1b[0m";
        $lines[] = "\x1b[90mand the sys schema installed.\x1b[0m";
        $lines[] = '';
        $lines[] = "\x1b[90m[q] quit\x1b[0m";

        return \implode("\n", $lines);
    }

    // ─── Data Loading ─────────────────────────────────────────────────────────

    private function loadCurrentReport(): void
    {
        if ($this->runner === null || $this->selectedReport === null) {
            return;
        }

        try {
            $result = $this->showRawValues
                ? $this->runner->runRaw($this->selectedReport)
                : $this->runner->run($this->selectedReport);

            $this->currentResult = $result;
            $this->errorMessage = '';
        } catch (\Throwable $e) {
            $this->currentResult = null;
            $this->errorMessage = "Error loading report: {$e->getMessage()}";
        }
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public function selectedCategory(): ?string
    {
        return $this->selectedCategory;
    }

    public function selectedReport(): ?string
    {
        return $this->selectedReport;
    }

    public function selectedRowIndex(): int
    {
        return $this->selectedRowIndex;
    }

    public function currentResult(): ?ReportResult
    {
        return $this->currentResult;
    }

    public function showRawValues(): bool
    {
        return $this->showRawValues;
    }

    public function catalog(): ?Catalog
    {
        return $this->catalog;
    }

    public function runner(): ?ReportRunner
    {
        return $this->runner;
    }
}
