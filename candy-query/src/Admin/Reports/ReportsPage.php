<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

use SugarCraft\Bits\Tree\Node;
use SugarCraft\Bits\Tree\Tree;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Core\Msg\ReloadReportMsg;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Export\CsvExporter;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;
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
    /** @var DatabaseInterface|null */
    private ?DatabaseInterface $db = null;

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

    /** @var string|null */
    private ?string $lastExportCsv = null;

    /** @var string */
    private string $sortColumn = '';

    /** @var bool */
    private bool $sortAsc = true;

    /** @var list<string> */
    private array $categories = [];

    /** @var array<string, list<ReportDefinition>> */
    private array $reportsByCategory = [];

    /**
     * @param ServerContextInterface $context Server context
     * @param DatabaseInterface|null $db Optional DB handle. Note: any value
     *        passed here is overwritten on first validate() which unconditionally
     *        sets $this->db = $this->context->connection(). For test doubles,
     *        inject via ServerContextInterface instead.
     */
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
     * Validate that the report catalog can be loaded.
     *
     * All DB-dependent setup (sys schema availability, report execution) is
     * deferred to the async flow via CachedConnection/AdminQueryCache.
     * This allows validate() to return immediately without blocking on
     * network I/O, while the loading screen is shown until data arrives.
     */
    protected function validate(): bool
    {
        try {
            $this->db = $this->context->connection();

            // Catalog load is file I/O — always sync, never blocks.
            $this->catalog = Catalog::new();
            $this->catalog->load();

            // Availability and runner are created but NOT queried here.
            // Queries are routed through CachedConnection and are drained
            // by App::createAdminFetchPromise() on the next tick.
            $this->availability = AvailabilityChecker::new($this->db);
            $this->runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

            $this->categories = $this->catalog->categories();
            $this->reportsByCategory = $this->catalog->groupedByCategory();

            // Default selection from catalog (availability filtering happens
            // in loadCurrentReport via CachedConnection async query).
            if ($this->selectedCategory === null && !empty($this->categories)) {
                $this->selectedCategory = $this->categories[0];
            }

            if ($this->selectedReport === null && isset($this->reportsByCategory[$this->selectedCategory])) {
                $reports = $this->reportsByCategory[$this->selectedCategory];
                if (!empty($reports)) {
                    $this->selectedReport = $reports[0]->name;
                }
            }

            // loadCurrentReport() is NOT called here — it queues queries via
            // CachedConnection which are drained by the admin fetch tick.
            // The page will show a loading state until the async query completes.

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
        if ($msg instanceof ReloadReportMsg) {
            // Triggered by App after AdminDataLoadedMsg. Queue the report
            // query via CachedConnection for the next tick to process.
            $this->loadCurrentReport();
            return [$this, null];
        }

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
        $clone = clone $this;
        $clone->lastExportCsv = $this->exportToCsv();

        return $clone;
    }

    /**
     * Export the current report result to CSV format.
     *
     * Returns a CSV string with column headers and all rows (not just the
     * current page). Returns empty string if no report is loaded.
     * Delegates to CsvExporter for RFC-4180 compliant output with formula
     * injection protection.
     */
    public function exportToCsv(): string
    {
        if ($this->currentResult === null) {
            return '';
        }

        $rows = $this->currentResult->rows;
        if (count($rows) === 0) {
            return '';
        }

        // Get column names from first row
        $columns = array_keys($rows[0]);

        if ($this->db === null) {
            return '';
        }

        $exporter = new CsvExporter($this->db);

        return $exporter->exportReportResultsToString($columns, $rows);
    }

    // ─── Rendering Methods ────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        return Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Performance Reports');
    }

    private function renderLayout(): string
    {
        $left = $this->renderCategoryTree();
        $right = $this->renderReportGrid();

        return Layout::joinHorizontal(Position::TOP, $left, '  ', $right);
    }

    private function renderCategoryTree(): string
    {
        $header = Style::new()->bold()->foreground(Color::hex('#c084fc'))->render('Categories');

        if ($this->categories === []) {
            return $header . "\n" . Style::new()->faint()->render('  (no data)');
        }

        $activeStyle = Style::new()->bold()->foreground(Color::hex('#4ade80'));
        $mutedStyle = Style::new()->foreground(Color::hex('#6b7280'));

        // Each category is a Bits\Tree branch; only the selected category is
        // expanded, so its reports show as indented leaves. The widget owns the
        // indentation, expand/collapse glyphs and structure. Selection is baked
        // into the label styling because the tree is display-only here (never
        // focused — its single cursor can't express category + report at once).
        $roots = [];
        foreach ($this->categories as $category) {
            $reports = $this->reportsByCategory[$category] ?? [];
            $isSelectedCategory = $category === $this->selectedCategory;

            $leaves = [];
            foreach ($reports as $report) {
                $style = $report->name === $this->selectedReport ? $activeStyle : $mutedStyle;
                $leaves[] = new Node($style->render($report->caption), $report->name);
            }

            $count = count($reports);
            $style = $isSelectedCategory ? $activeStyle : $mutedStyle;
            // width 0 (set below) keeps these styled labels intact — Tree::view()
            // truncates with the ANSI-stripping Width::truncate when width > 0.
            $roots[] = new Node($style->render("{$category} ({$count})"), $category, $leaves, $isSelectedCategory);
        }

        $tree = Tree::fromList($roots)->withSize(0, 0);

        return $header . "\n" . $tree->view();
    }

    private function renderReportGrid(): string
    {
        if ($this->currentResult === null) {
            // The available-report list comes from the sys-schema availability
            // probe, which is fetched asynchronously; until it lands no report
            // is selected and we are still loading rather than awaiting input.
            $glyph = Spinner::new(SpinnerStyle::dot())->view();
            $label = $this->selectedReport === null
                ? 'Loading reports…'
                : "Loading {$this->currentReportCaption()}…";

            return Style::new()->foreground(Color::hex('#fbbf24'))->render("{$glyph} {$label}");
        }

        if ($this->currentResult->isEmpty()) {
            return Style::new()->faint()->render("No data available for {$this->currentResult->report->caption}");
        }

        $report = $this->currentResult->report;
        $rows = $this->currentResult->rows;

        $lines = [];

        $lines[] = Style::new()->bold()->render($report->caption);
        $lines[] = Style::new()->foreground(Color::hex('#6b7280'))->render($report->description);
        $lines[] = '';

        $columns = $this->buildColumns($report);
        $tableRows = $this->buildRows($rows);

        $table = Table::withColumns($columns)
            ->withRows($tableRows)
            ->withSelectable(true)
            ->withShowFooter(true);

        $lines[] = $table->View();

        $lines[] = '';
        $lines[] = Style::new()->foreground(Color::hex('#6b7280'))
            ->render('Showing ' . count($rows) . ' rows' . ($this->currentResult->limited ? ' (limited)' : ''));

        return \implode("\n", $lines);
    }

    /**
     * @return list<Column>
     */
    private function buildColumns(ReportDefinition $report): array
    {
        $columns = [];
        foreach ($report->columns as $col) {
            $column = Column::new($col->name, $col->name, $col->width);
            // Numeric columns stay right-aligned (the Column default); text,
            // time and byte columns read better left-aligned.
            if (!$col->isNumeric()) {
                $column = $column->withAlignLeft();
            }
            $columns[] = $column;
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
            // Key cells by column name so the Table matches them to the columns
            // built in buildColumns() (keyed by $col->name).
            $data = [];
            foreach ($row as $key => $value) {
                $data[(string) $key] = (string) ($value ?? 'NULL');
            }
            $tableRow = Row::new(RowData::from($data));

            if ($index === $this->selectedRowIndex) {
                $tableRow = $tableRow->withStyle('7');
            }

            $tableRows[] = $tableRow;
        }

        return $tableRows;
    }

    private function renderFooter(): string
    {
        return Style::new()->foreground(Color::hex('#6b7280'))
            ->render('[j/k] nav  [r] refresh  [x] export  [c] unit toggle  [q] quit');
    }

    private function renderErrorScreen(): string
    {
        $muted = Style::new()->foreground(Color::hex('#6b7280'));

        $lines = [];
        $lines[] = Style::new()->bold()->foreground(Color::hex('#f87171'))->render('Performance Reports Unavailable');
        $lines[] = '';
        $lines[] = $this->errorMessage;
        $lines[] = '';
        $lines[] = $muted->render('Ensure MySQL 5.6.6+ with performance_schema=enabled');
        $lines[] = $muted->render('and the sys schema installed.');
        $lines[] = '';
        $lines[] = $muted->render('[q] quit');

        return \implode("\n", $lines);
    }

    // ─── Data Loading ─────────────────────────────────────────────────────────

    /**
     * Human-readable caption for the currently selected report (falls back to
     * the raw view name), used by the loading placeholder.
     */
    private function currentReportCaption(): string
    {
        if ($this->selectedReport === null) {
            return 'report';
        }
        $report = $this->catalog?->get($this->selectedReport);
        return $report->caption ?? $this->selectedReport;
    }

    private function loadCurrentReport(): void
    {
        if ($this->runner === null || $this->selectedReport === null) {
            return;
        }

        try {
            // Look up the catalog key (view name) for this report.
            // The catalog is keyed by view name (e.g. 'x$statement_analysis'),
            // but selectedReport is the report name (e.g. 'statement_analysis').
            $viewName = null;
            foreach ($this->catalog?->all() ?? [] as $key => $report) {
                if ($report->name === $this->selectedReport) {
                    $viewName = $key;
                    break;
                }
            }

            if ($viewName === null) {
                $this->currentResult = null;
                $this->errorMessage = "Report not found in catalog: {$this->selectedReport}";

                return;
            }

            $result = $this->showRawValues
                ? $this->runner->runRaw($viewName)
                : $this->runner->run($viewName);

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

    /**
     * Get the CSV from the most recent export.
     */
    public function lastExportCsv(): ?string
    {
        return $this->lastExportCsv;
    }
}
