<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Variables;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * Variables page displaying SHOW GLOBAL STATUS / SHOW GLOBAL VARIABLES.
 *
 * Features dual tabs (Status/System), category tree navigation,
 * search filtering, and inline editing of editable variables.
 *
 * Keyboard shortcuts:
 *   [tab]     - toggle between Status/System tabs
 *   [w]       - toggle read/write filter (show only editable variables)
 *   [s]       - focus search input
 *   [j/k]     - navigate rows down/up
 *   [e]       - edit selected variable
 *   [q]       - quit to previous view
 *
 * @see Mirrors charmbracelet/lazysql VariablesPage
 */
final class VariablesPage extends PageBase
{
    /** Tab enum for Status vs System variables. */
    public const TAB_STATUS = 'status';
    public const TAB_SYSTEM = 'system';

    /** @var array<string, string> */
    private array $variables = [];

    /** @var list<string> */
    private array $variableNames = [];

    /** @var array<string, VariableMetadata>|null */
    private ?array $metadataMap = null;

    private string $activeTab = self::TAB_STATUS;
    private string $searchQuery = '';
    private ?string $activeCategory = null;
    private bool $showReadWriteOnly = false;
    private int $selectedRowIndex = 0;
    private bool $searchFocused = false;

    /** @var list<string> */
    private array $categories = [];

    public function __construct(
        ServerContextInterface $context,
        private readonly ?Catalog $catalog = null,
        private readonly ?VariableEditor $editor = null,
    ) {
        parent::__construct($context);
    }

    /**
     * Create a new VariablesPage from the server context.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * Verify we can query variables before rendering.
     */
    protected function validate(): bool
    {
        try {
            $vars = $this->activeTab === self::TAB_STATUS
                ? $this->context->statusVariables()
                : $this->context->serverVariables();

            return \count($vars) > 0;
        } catch (\Throwable $e) {
            $this->errorMessage = 'Unable to load variables: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Build the complete variables page output.
     */
    protected function build(): string
    {
        $this->loadVariables();
        $this->loadCategories();

        $lines = [];

        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = $this->renderTabBar();
        $lines[] = '';
        $lines[] = $this->renderLayout();
        $lines[] = '';
        $lines[] = $this->renderFooter();

        return \implode("\n", $lines);
    }

    /**
     * Handle keyboard shortcuts for navigation and editing.
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

        // Tab key toggles between Status/System tabs
        if ($type === KeyType::Tab && !$msg->shift) {
            return [$this->withToggleTab(), null];
        }

        // w toggles read/write filter
        if ($ch === 'w') {
            return [$this->withToggleReadWrite(), null];
        }

        // s focuses search
        if ($ch === 's') {
            return [$this->withSearchFocused(true), null];
        }

        // j/k navigate rows
        if ($ch === 'j' || $type === KeyType::Down) {
            return [$this->withNavigateDown(), null];
        }

        if ($ch === 'k' || $type === KeyType::Up) {
            return [$this->withNavigateUp(), null];
        }

        // e opens editor for selected variable
        if ($ch === 'e') {
            return $this->handleEdit();
        }

        // q quits (handled by parent)
        if ($ch === 'q') {
            return [$this->withQuit(), null];
        }

        // Escape clears search focus
        if ($type === KeyType::Escape) {
            return [$this->withSearchFocused(false), null];
        }

        // Letter keys type into search when focused
        if ($this->searchFocused && $msg->type === KeyType::Char && $ch !== '') {
            return [$this->withAppendSearch($ch), null];
        }

        // Backspace removes last character from search
        if ($this->searchFocused && $type === KeyType::Backspace) {
            return [$this->withBackspaceSearch(), null];
        }

        return [$this, null];
    }

    // ─── Wither Methods ───────────────────────────────────────────────────────

    /**
     * Return a new instance with the tab toggled.
     */
    public function withToggleTab(): self
    {
        $clone = clone $this;
        $clone->activeTab = $clone->activeTab === self::TAB_STATUS
            ? self::TAB_SYSTEM
            : self::TAB_STATUS;
        $clone->searchQuery = '';
        $clone->selectedRowIndex = 0;
        $clone->activeCategory = null;
        $clone->searchFocused = false;
        return $clone;
    }

    /**
     * Return a new instance with a specific tab.
     */
    public function withTab(string $tab): self
    {
        $clone = clone $this;
        $clone->activeTab = $tab;
        $clone->searchQuery = '';
        $clone->selectedRowIndex = 0;
        $clone->activeCategory = null;
        $clone->searchFocused = false;
        return $clone;
    }

    /**
     * Return a new instance with search filter applied.
     */
    public function withSearch(string $search): self
    {
        $clone = clone $this;
        $clone->searchQuery = $search;
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    /**
     * Return a new instance with category filter applied.
     */
    public function withCategory(?string $category): self
    {
        $clone = clone $this;
        $clone->activeCategory = $category;
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    /**
     * Return a clone (quit is handled by parent controller).
     */
    public function withQuit(): self
    {
        return clone $this;
    }

    // ─── Private Wither Helpers ───────────────────────────────────────────────

    private function withToggleReadWrite(): self
    {
        $clone = clone $this;
        $clone->showReadWriteOnly = !$clone->showReadWriteOnly;
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    private function withSearchFocused(bool $focused): self
    {
        $clone = clone $this;
        $clone->searchFocused = $focused;
        return $clone;
    }

    private function withNavigateDown(): self
    {
        $clone = clone $this;
        $filteredCount = \count($this->getFilteredVariableNames());
        if ($clone->selectedRowIndex < $filteredCount - 1) {
            $clone->selectedRowIndex++;
        }
        return $clone;
    }

    private function withNavigateUp(): self
    {
        $clone = clone $this;
        if ($clone->selectedRowIndex > 0) {
            $clone->selectedRowIndex--;
        }
        return $clone;
    }

    private function withAppendSearch(string $char): self
    {
        $clone = clone $this;
        $clone->searchQuery .= $char;
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    private function withBackspaceSearch(): self
    {
        $clone = clone $this;
        $clone->searchQuery = \substr($clone->searchQuery, 0, -1);
        $clone->selectedRowIndex = 0;
        return $clone;
    }

    private function handleEdit(): array
    {
        $filteredNames = $this->getFilteredVariableNames();
        if ($filteredNames === []) {
            return [$this, null];
        }

        $varName = $filteredNames[$this->selectedRowIndex] ?? null;
        if ($varName === null) {
            return [$this, null];
        }

        // Check if editable
        if (!$this->isEditable($varName)) {
            return [$this, null];
        }

        // Editor must be available for editing
        if ($this->editor === null) {
            return [$this, null];
        }

        $currentValue = $this->variables[$varName] ?? '';

        // Get edit preview to show what will happen
        $preview = $this->editor->getEditPreview($varName, $currentValue, false);

        // Perform the edit (using current value for now - proper implementation
        // would prompt user for new value via a dialog component)
        $result = $this->editor->edit($varName, $currentValue);

        if ($result['success']) {
            // Reload variables to reflect the new value
            $this->loadVariables();
            return [$this, null];
        }

        // Error occurred - could set an error message state here
        // For now, just return with current state
        return [$this, null];
    }

    // ─── Data Loading ────────────────────────────────────────────────────────

    private function loadVariables(): void
    {
        $this->variables = $this->activeTab === self::TAB_STATUS
            ? $this->context->statusVariables()
            : $this->context->serverVariables();

        $this->variableNames = \array_keys($this->variables);
        \sort($this->variableNames);
    }

    private function loadCategories(): void
    {
        if ($this->catalog === null) {
            $this->categories = [];
            return;
        }

        try {
            $this->categories = $this->catalog->groups();
            $this->metadataMap = $this->catalog->all();
        } catch (\Throwable) {
            $this->categories = [];
            $this->metadataMap = null;
        }
    }

    // ─── Filtering ───────────────────────────────────────────────────────────

    /**
     * Get filtered variable names based on search, category, and rw filter.
     *
     * @return list<string>
     */
    private function getFilteredVariableNames(): array
    {
        $names = $this->variableNames;

        // Filter by search query
        if ($this->searchQuery !== '') {
            $query = \strtolower($this->searchQuery);
            $names = \array_values(
                \array_filter($names, function (string $name) use ($query): bool {
                    $lowerName = \strtolower($name);
                    $value = \strtolower($this->variables[$name] ?? '');
                    return \strpos($lowerName, $query) !== false
                        || \strpos($value, $query) !== false;
                })
            );
        }

        // Filter by category
        if ($this->activeCategory !== null && $this->metadataMap !== null) {
            $names = \array_values(
                \array_filter($names, function (string $name): bool {
                    $metadata = $this->metadataMap[$name] ?? null;
                    return $metadata !== null && $metadata->inGroup($this->activeCategory);
                })
            );
        }

        // Filter by read/write (editable) only
        if ($this->showReadWriteOnly) {
            $names = \array_values(
                \array_filter($names, function (string $name): bool {
                    return $this->isEditable($name);
                })
            );
        }

        return $names;
    }

    private function isEditable(string $varName): bool
    {
        if ($this->metadataMap === null) {
            return false;
        }

        $metadata = $this->metadataMap[$varName] ?? null;
        return $metadata !== null && $metadata->editable;
    }

    // ─── Rendering ───────────────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $tabLabel = $this->activeTab === self::TAB_STATUS ? 'Status' : 'System';
        $rwIndicator = $this->showReadWriteOnly ? ' [rw]' : '';
        $categoryIndicator = $this->activeCategory !== null
            ? " [{$this->activeCategory}]"
            : '';

        return "\x1b[1;36mVariables\x1b[0m | {$tabLabel}{$rwIndicator}{$categoryIndicator}";
    }

    private function renderTabBar(): string
    {
        $statusActive = $this->activeTab === self::TAB_STATUS;
        $systemActive = $this->activeTab === self::TAB_SYSTEM;

        $statusTab = $statusActive
            ? "\x1b[1;33m[Status]\x1b[0m"
            : "\x1b[90m[Status]\x1b[0m";

        $systemTab = $systemActive
            ? "\x1b[1;33m[System]\x1b[0m"
            : "\x1b[90m[System]\x1b[0m";

        $searchDisplay = $this->searchQuery === ''
            ? "\x1b[90m[search]\x1b[0m"
            : "\x1b[1;37m[{$this->searchQuery}]\x1b[0m";

        if ($this->searchFocused) {
            $searchDisplay = "\x1b[1;32m[{$this->searchQuery}_\x1b[0m";
        }

        return "  {$statusTab} {$systemTab}     {$searchDisplay}";
    }

    private function renderLayout(): string
    {
        $leftWidth = 25;
        $rightWidth = 80;

        $leftPanel = $this->renderCategoryTree($leftWidth);
        $rightPanel = $this->renderVariableGrid($rightWidth);

        return $this->renderSideBySide($leftPanel, $rightPanel);
    }

    private function renderCategoryTree(int $width): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mCategories\x1b[0m";

        if ($this->categories === []) {
            $lines[] = "\x1b[90m  (no data)\x1b[0m";
            return \implode("\n", $lines);
        }

        // Add "All" option
        $allSelected = $this->activeCategory === null;
        $allDisplay = $allSelected
            ? "\x1b[1;32m> All\x1b[0m"
            : "\x1b[90m  All\x1b[0m";
        $lines[] = $allDisplay;

        // Add each category
        foreach ($this->categories as $category) {
            $selected = $this->activeCategory === $category;
            $display = $selected
                ? "\x1b[1;32m> {$category}\x1b[0m"
                : "\x1b[90m  {$category}\x1b[0m";
            $lines[] = $display;
        }

        return \implode("\n", $lines);
    }

    private function renderVariableGrid(int $width): string
    {
        $filteredNames = $this->getFilteredVariableNames();

        // Build columns for sugar-table
        $columns = [
            Column::new('name', 'Name', 30)->withAlignLeft(true),
            Column::new('value', 'Value', 25)->withAlignLeft(true),
            Column::new('editable', 'Edit', 8),
        ];

        // Build rows
        $rows = [];
        foreach ($filteredNames as $index => $varName) {
            $value = $this->variables[$varName] ?? '';
            $editable = $this->isEditable($varName) ? 'rw' : '';

            // Truncate long values for display
            if (\strlen($value) > 23) {
                $value = \substr($value, 0, 20) . '...';
            }

            $rowData = RowData::from([
                'name' => $varName,
                'value' => $value,
                'editable' => $editable,
            ]);

            $row = Row::new($rowData);

            // Highlight selected row
            if ($index === $this->selectedRowIndex) {
                $row = $row->withStyle('7'); // reverse video
            }

            $rows[] = $row;
        }

        // Create and configure table
        $table = Table::withColumns($columns)
            ->withRows($rows)
            ->withSelectable(true)
            ->withShowFooter(false);

        // Note: we're using raw table rendering since we're building a composite view
        return $this->renderTableSimple($columns, $rows, $width);
    }

    /**
     * Render a simple table without the full Table::View() border treatment.
     *
     * @param list<Column> $columns
     * @param list<Row> $rows
     */
    private function renderTableSimple(array $columns, array $rows, int $totalWidth): string
    {
        $table = Table::withColumns($columns)->withRows($rows);

        // Note: footer with count is handled separately in renderVariableGrid
        // since we're using a custom composite layout
        return $table->View();
    }

    private function renderSideBySide(string $left, string $right): string
    {
        $leftLines = \explode("\n", $left);
        $rightLines = \explode("\n", $right);

        $maxLines = \max(\count($leftLines), \count($rightLines));
        $result = [];

        for ($i = 0; $i < $maxLines; $i++) {
            $leftLine = $leftLines[$i] ?? '';
            $rightLine = $rightLines[$i] ?? '';

            $leftLine = \str_pad($leftLine, 25, ' ');
            $rightLine = \str_pad($rightLine, 80, ' ');

            $result[] = $leftLine . '  ' . $rightLine;
        }

        return \implode("\n", $result);
    }

    private function renderFooter(): string
    {
        return "\x1b[90m[tab] toggle  [w] rw filter  [s] search  [j/k] nav  [e] edit  [q] quit\x1b[0m";
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function activeTab(): string
    {
        return $this->activeTab;
    }

    public function searchQuery(): string
    {
        return $this->searchQuery;
    }

    public function activeCategory(): ?string
    {
        return $this->activeCategory;
    }

    public function showReadWriteOnly(): bool
    {
        return $this->showReadWriteOnly;
    }

    public function selectedRowIndex(): int
    {
        return $this->selectedRowIndex;
    }

    public function catalog(): ?Catalog
    {
        return $this->catalog;
    }

    public function editor(): ?VariableEditor
    {
        return $this->editor;
    }
}
