<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Reports;

use SugarCraft\Widgets\Tree\Node;
use SugarCraft\Widgets\Tree\Tree;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Sql\Admin\AdminPane;
use SugarCraft\Sql\Admin\PageBase;
use SugarCraft\Sql\Admin\RowNavigable;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Export\CsvExporter;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
use SugarCraft\Style\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;
use SugarCraft\Sql\CellValue;

/**
 * Performance Reports page with left category/report tree and right sortable/exportable grid.
 *
 * Features:
 * - Left tree: reports grouped by category (problems, schema, io, memory, etc.)
 * - Right grid: sortable, paginated data from sys schema views
 * - Per-column unit toggle for time/byte columns
 * - In-state CSV rendering of current report results (lastExportCsv();
 *   the [x] key never writes a file — file export is the Db\Export API)
 * - PS + sys schema validation gate
 *
 * Keyboard shortcuts:
 *   [j/k]     - navigate rows down/up
 *   [r]       - refresh current report
 *   [x]       - rebuild the CSV string into page state (no file write)
 *   [c]       - toggle unit display (time columns)
 *   [q]       - quit to previous view
 *
 * @see Mirrors mysql-workbench wb_admin_perfschema_reports
 */
final class ReportsPage extends PageBase implements RowNavigable
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

    /**
     * SQL-P10 arrival fingerprint: the raw generation the current result was
     * built from, plus the view and unit-mode that produced it.
     *
     * @var list<array<string,mixed>>|null
     */
    private ?array $reloadedRows = null;
    private ?string $reloadedView = null;
    private bool $reloadedRawMode = false;

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

    /** @var int */
    private int $selectedColumnIndex = 0;

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
        private readonly ?string $initialCategory = null,
        private readonly ?string $paneLabel = null,
    ) {
        parent::__construct($context);
        $this->db = $db;
    }

    /**
     * Create a new ReportsPage from the server context.
     *
     * SQL-P14 (adminpages-025): $initialCategory seeds the category tree so
     * the Query Stats and Table Stats panes open on their own subject
     * instead of one undifferentiated page; $paneLabel surfaces the pane
     * the digit opened (headers carrying it answer "what did I press?").
     */
    public static function new(ServerContextInterface $context, ?DatabaseInterface $db = null, ?string $initialCategory = null, ?string $paneLabel = null): self
    {
        return new self($context, $db, $initialCategory, $paneLabel);
    }

    /**
     * The admin panes that share this page and the category each seeds,
     * keyed by AdminPane value (SQL-P14).
     *
     * @var array<string,string>
     */
    public const PANE_SEEDS = [
        'query_stats' => 'problems',
        'table_stats' => 'schema',
    ];

    public static function seedCategoryFor(AdminPane $pane): ?string
    {
        return self::PANE_SEEDS[$pane->value] ?? null;
    }

    /**
     * Validate that the report catalog can be loaded.
     *
     * All DB-dependent setup (sys schema availability, report execution) is
     * deferred to the async flow via AsyncCachedConnection/AdminQueryCache.
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
            // Queries are routed through AsyncCachedConnection and are drained
            // by App::createAdminFetchPromise() on the next tick.
            $this->availability = AvailabilityChecker::new($this->db);
            $this->runner = ReportRunner::new($this->db, $this->catalog, $this->availability);

            $this->categories = $this->catalog->categories();
            $this->reportsByCategory = $this->catalog->groupedByCategory();

            // Default selection from catalog (availability filtering happens
            // in loadCurrentReport via AsyncCachedConnection async query).
            // SQL-P14: a pane seed wins over the raw first category when it
            // exists on this server's catalog.
            if ($this->selectedCategory === null && !empty($this->categories)) {
                $this->selectedCategory = $this->initialCategory !== null
                    && isset($this->reportsByCategory[$this->initialCategory])
                    ? $this->initialCategory
                    : $this->categories[0];
            }

            if ($this->selectedReport === null && isset($this->reportsByCategory[$this->selectedCategory])) {
                $reports = $this->reportsByCategory[$this->selectedCategory];
                if (!empty($reports)) {
                    $this->selectedReport = $reports[0]->name;
                }
            }

            // loadCurrentReport() is NOT called here — it queues queries via
            // AsyncCachedConnection which are drained by the admin fetch tick.
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
            // query via AsyncCachedConnection for the next tick to process.
            $this->loadCurrentReport();
            return [$this, null];
        }

        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune;
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

        // Category navigation: h = prev category, l = next category
        if ($ch === 'h' || $type === KeyType::Left) {
            return [$this->withSelectPrevCategory(), null];
        }

        if ($ch === 'l' || $type === KeyType::Right) {
            return [$this->withSelectNextCategory(), null];
        }

        // Report navigation within current category: [ = prev report, ] = next report
        if ($ch === '[') {
            return [$this->withSelectPrevReport(), null];
        }

        if ($ch === ']') {
            return [$this->withSelectNextReport(), null];
        }

        // Column navigation for unit cycling: , = prev column, . = next column
        if ($ch === ',') {
            return [$this->withSelectPrevColumn(), null];
        }

        if ($ch === '.') {
            return [$this->withSelectNextColumn(), null];
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
        $clone->selectedColumnIndex = 0;
        $clone->loadCurrentReport();

        return $clone;
    }

    public function withQuit(): self
    {
        return clone $this;
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
        $clone->selectedColumnIndex = 0;

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
        $clone->selectedColumnIndex = 0;

        $clone->loadCurrentReport();

        return $clone;
    }

    /**
     * Select the previous category, wrapping to the last if already at the first.
     *
     * Navigation wraps cyclically so the user never gets stuck at a boundary.
     * Selecting a category resets the selected column index to 0.
     *
     * @return self New instance with the previous category selected
     */
    public function withSelectPrevCategory(): self
    {
        if (empty($this->categories)) {
            return $this;
        }

        $currentIndex = array_search($this->selectedCategory, $this->categories, true);
        if ($currentIndex === false || $currentIndex <= 0) {
            // Wrap to last category
            $newCategory = $this->categories[count($this->categories) - 1];
        } else {
            $newCategory = $this->categories[$currentIndex - 1];
        }

        return $this->withSelectCategory($newCategory);
    }

    /**
     * Select the next category, wrapping to the first if already at the last.
     *
     * Navigation wraps cyclically so the user never gets stuck at a boundary.
     * Selecting a category resets the selected column index to 0.
     *
     * @return self New instance with the next category selected
     */
    public function withSelectNextCategory(): self
    {
        if (empty($this->categories)) {
            return $this;
        }

        $currentIndex = array_search($this->selectedCategory, $this->categories, true);
        if ($currentIndex === false || $currentIndex >= count($this->categories) - 1) {
            // Wrap to first category
            $newCategory = $this->categories[0];
        } else {
            $newCategory = $this->categories[$currentIndex + 1];
        }

        return $this->withSelectCategory($newCategory);
    }

    /**
     * Select the previous report within the current category, wrapping to the last
     * report if already at the first.
     *
     * Navigation wraps cyclically so the user never gets stuck at a boundary.
     * Selecting a report resets the selected column index to 0.
     *
     * @return self New instance with the previous report selected
     */
    public function withSelectPrevReport(): self
    {
        $category = $this->selectedCategory;
        if ($category === null || !isset($this->reportsByCategory[$category])) {
            return $this;
        }

        $reports = $this->reportsByCategory[$category];
        if (empty($reports)) {
            return $this;
        }

        $currentIndex = -1;
        foreach ($reports as $i => $report) {
            if ($report->name === $this->selectedReport) {
                $currentIndex = $i;
                break;
            }
        }

        if ($currentIndex <= 0) {
            // Wrap to last report in category
            return $this->withSelectReport($reports[count($reports) - 1]->name);
        }

        return $this->withSelectReport($reports[$currentIndex - 1]->name);
    }

    /**
     * Select the next report within the current category, wrapping to the first
     * report if already at the last.
     *
     * Navigation wraps cyclically so the user never gets stuck at a boundary.
     * Selecting a report resets the selected column index to 0.
     *
     * @return self New instance with the next report selected
     */
    public function withSelectNextReport(): self
    {
        $category = $this->selectedCategory;
        if ($category === null || !isset($this->reportsByCategory[$category])) {
            return $this;
        }

        $reports = $this->reportsByCategory[$category];
        if (empty($reports)) {
            return $this;
        }

        $currentIndex = -1;
        foreach ($reports as $i => $report) {
            if ($report->name === $this->selectedReport) {
                $currentIndex = $i;
                break;
            }
        }

        if ($currentIndex === -1 || $currentIndex >= count($reports) - 1) {
            // Wrap to first report in category
            return $this->withSelectReport($reports[0]->name);
        }

        return $this->withSelectReport($reports[$currentIndex + 1]->name);
    }

    /**
     * Select the previous column for unit display targeting.
     *
     * Wraps to the last column when already at the first.
     * No-op when no report is loaded.
     *
     * @return self New instance with the previous column index selected
     */
    public function withSelectPrevColumn(): self
    {
        $report = $this->currentResult?->report;
        if ($report === null) {
            return $this;
        }

        $numColumns = count($report->columns);
        if ($numColumns === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedColumnIndex = $this->selectedColumnIndex <= 0
            ? $numColumns - 1
            : $this->selectedColumnIndex - 1;

        return $clone;
    }

    /**
     * Select the next column for unit display targeting.
     *
     * Wraps to the first column when already at the last.
     * No-op when no report is loaded.
     *
     * @return self New instance with the next column index selected
     */
    public function withSelectNextColumn(): self
    {
        $report = $this->currentResult?->report;
        if ($report === null) {
            return $this;
        }

        $numColumns = count($report->columns);
        if ($numColumns === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedColumnIndex = $this->selectedColumnIndex >= $numColumns - 1
            ? 0
            : $this->selectedColumnIndex + 1;

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
        // SQL-P14: when a pane opened this page, say which one — the two
        // report panes must be tellable apart from the page itself.
        $base = 'Performance Reports';

        return Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render(
            $this->paneLabel !== null ? $base . ' · ' . $this->paneLabel : $base,
        );
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

        // Each category is a Bits\Tree branch; only the selected category is
        // expanded, so its reports show as indented leaves. The widget owns the
        // indentation, expand/collapse glyphs and structure — and its label
        // pipeline runs EVERY label through Sanitize::controlChars() (Tree's
        // untrusted-content door), which strips the ESC of an embedded SGR and
        // leaves the PARAMETERS visible: '[1m[38;2;74;222;128mproblems (6)[0m'
        // shredded the whole left column, every node (adminpages-005). No colour
        // may ride a Node label at any width — the old width-0 note blamed the
        // truncation path, but the strip is unconditional. Selection is carried
        // as plain-text state instead: the selected leaf gets a visible dot
        // marker, and the selected category needs none — it is the one branch
        // expanded. Bold/green contrast returns only if the widget ever grows a
        // style channel (an upstream sugar-widgets leg, not this page's call).
        $roots = [];
        foreach ($this->categories as $category) {
            $reports = $this->reportsByCategory[$category] ?? [];
            $isSelectedCategory = $category === $this->selectedCategory;

            $leaves = [];
            foreach ($reports as $report) {
                $caption = $report->name === $this->selectedReport
                    ? "\u{25CF} {$report->caption}"
                    : $report->caption;
                $leaves[] = new Node($caption, $report->name);
            }

            $count = count($reports);
            $roots[] = new Node("{$category} ({$count})", $category, $leaves, $isSelectedCategory);
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

        $table = Table::fromColumns($columns)
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
            ->render('[j/k] nav rows  [h/l] category  [[]/[]] report  [,/.] column  [c] unit  [r] refresh  [x] export  [q] back');
    }

    private function renderErrorScreen(): string
    {
        $muted = Style::new()->foreground(Color::hex('#6b7280'));

        $lines = [];
        $lines[] = Style::new()->bold()->foreground(Color::hex('#f87171'))->render('Performance Reports Unavailable');
        $lines[] = '';
        // errorMessage embeds raw driver/server text — neutralize at egress.
        $lines[] = CellValue::sanitize($this->errorMessage);
        $lines[] = '';
        // SQL-P17 (adminpages-022): one grammatical sentence, not a two-line
        // verbless fragment split mid-thought.
        $lines[] = $muted->render('Ensure MySQL 5.6.6+ with performance_schema=enabled and the sys schema installed.');
        $lines[] = '';
        $lines[] = $muted->render('[q] back');

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

    /**
     * Load the currently selected report.
     *
     * Called by update() in response to ReloadReportMsg (triggered by App after
     * AdminDataLoadedMsg) and by withering methods that change the selection.
     * Catches \Throwable so a failed query sets an error message rather than
     * propagating — view() will show the error inline without crashing the page.
     */
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

            // SQL-P10 (adminpages-016): App forwards ReloadReportMsg for both
            // AdminDataLoadedMsg and AdminDrainCompletedMsg of the SAME
            // arrival, so the formatted grid used to run formatRows over up
            // to 1,000 rows twice per arrival. The cache makes the re-query
            // cheap inside its window, but the format was pure repetition:
            // the raw generation is fingerprinted, and an identical arrival
            // keeps the existing ReportResult object instead of rebuilding
            // it. Selection, unit-mode and data moves all miss the guard by
            // construction (view key, mode flag, row content).
            if (!$this->showRawValues) {
                $this->runner->assertAvailable($viewName);
            }
            $raw = $this->runner->runRaw($viewName);

            if (
                $this->currentResult !== null
                && $this->reloadedView === $viewName
                && $this->reloadedRawMode === $this->showRawValues
                && $this->reloadedRows === $raw->rows
            ) {
                $this->errorMessage = '';

                return;
            }

            $this->reloadedView = $viewName;
            $this->reloadedRawMode = $this->showRawValues;
            $this->reloadedRows = $raw->rows;
            $this->currentResult = $this->showRawValues
                ? $raw
                : $this->runner->formatResult($raw);
            $this->errorMessage = '';
        } catch (\Throwable $e) {
            $this->currentResult = null;
            // A failed load invalidates the fingerprint so the next arrival
            // retries instead of riding a stale guard.
            $this->reloadedView = null;
            $this->reloadedRows = null;
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

    public function selectedColumnIndex(): int
    {
        return $this->selectedColumnIndex;
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
