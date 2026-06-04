<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Variables;

use SugarCraft\Bits\Tabs\Tabs;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * Variables page displaying SHOW GLOBAL STATUS / SHOW GLOBAL VARIABLES.
 *
 * Features dual tabs (Status/System), category tree navigation,
 * search filtering, and inline editing of dynamic variables via a
 * value-input dialog that confirms before issuing SET GLOBAL.
 *
 * Keyboard shortcuts:
 *   [tab]     - toggle between Status/System tabs
 *   [w]       - toggle read/write filter (show only dynamic variables)
 *   [s]       - focus search input
 *   [j/k]     - navigate rows down/up
 *   [e]       - edit selected variable (dynamic vars only)
 *   [q]       - quit to previous view
 *
 * @see Mirrors charmbracelet/lazysql VariablesPage
 */
final class VariablesPage extends PageBase
{
    /** Tab enum for Status vs System variables. */
    public const TAB_STATUS = 'status';
    public const TAB_SYSTEM = 'system';

    // Edit dialog phase constants
    private const DLG_INPUT  = 'input';
    private const DLG_CONFIRM = 'confirm';

    // Persist mode constants for SET statement variants
    private const MODE_GLOBAL = 'global';
    private const MODE_PERSIST = 'persist';
    private const MODE_PERSIST_ONLY = 'persist_only';

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

    // Edit dialog state — null means no dialog active
    /** @var string|null DLG_INPUT | DLG_CONFIRM | null */
    private ?string $editDialogPhase = null;

    /** @var string|null variable name being edited */
    private ?string $editVarName = null;

    /** @var string|null value entered by user in the dialog (starts empty, accumulates typed chars) */
    private ?string $editNewValue = null;

    /** @var string|null the value when the dialog was opened (for self-write guard) */
    private ?string $editCurrentValue = null;

    /** @var string|null error message from a failed SET (e.g. 1238) */
    private ?string $editErrorMessage = null;

    /** @var string persist mode: 'global', 'persist', or 'persist_only' */
    private string $editPersistMode = self::MODE_GLOBAL;

    /**
     * @param ServerContextInterface $context Server context for variable access
     * @param Catalog|null $catalog Variable metadata catalog (loaded eagerly by
     *        App::buildVariablesPage). Enables category tree, group filtering, and
     *        per-variable [rw] editability indicators. Missing metadata is non-fatal —
     *        the page renders without categories or edit indicators.
     * @param VariableEditor|null $editor Edit bridge for MySQL variables via SET GLOBAL /
     *        SET PERSIST. Created by App::buildVariablesPage() with the same catalog so
     *        it can validate editability per variable. Null when catalog is absent or
     *        on non-MySQL flavors.
     */
    public function __construct(
        ServerContextInterface $context,
        private readonly ?Catalog $catalog = null,
        private readonly ?VariableEditor $editor = null,
        // Dialog state — stored as individual fields for immutability via clone
        ?string $editDialogPhase = null,
        ?string $editVarName = null,
        ?string $editNewValue = null,
        ?string $editCurrentValue = null,
        ?string $editErrorMessage = null,
        ?string $editPersistMode = null,
    ) {
        parent::__construct($context);
        $this->editDialogPhase = $editDialogPhase;
        $this->editVarName = $editVarName;
        $this->editNewValue = $editNewValue;
        $this->editCurrentValue = $editCurrentValue;
        $this->editErrorMessage = $editErrorMessage;
        $this->editPersistMode = $editPersistMode ?? self::MODE_GLOBAL;
    }

    /**
     * Create a new VariablesPage from the server context.
     *
     * Note: the no-arg form creates a page without Catalog or VariableEditor,
     * which means no category tree, no [rw] indicators, and editing disabled.
     * For a fully-equipped page with collaborators, use
     * App::buildVariablesPage() which wires in the eagerly-loaded Catalog
     * and a properly-configured VariableEditor.
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

        $dialog = $this->renderDialog();
        if ($dialog !== '') {
            $lines[] = '';
            $lines[] = $dialog;
        }

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
        // Delegate to dialog state machine when active
        if ($this->editDialogPhase !== null) {
            return $this->updateDialog($msg);
        }

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

    /**
     * Handle dialog key events — input phase, confirm phase.
     *
     * @return array{0: self, 1: ?\SugarCraft\Core\Cmd}
     */
    private function updateDialog(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $type = $msg->type;

        if ($this->editDialogPhase === self::DLG_INPUT) {
            return $this->updateDialogInput($msg);
        }

        if ($this->editDialogPhase === self::DLG_CONFIRM) {
            // In confirm phase, Enter executes the SET, Escape cancels to browse
            if ($type === KeyType::Enter) {
                return $this->executeEdit();
            }

            if ($type === KeyType::Escape) {
                return [$this->withEditDialog(null, null, null, null, null), null];
            }

            return [$this, null];
        }

        // Unknown phase — cancel dialog
        return [$this->withEditDialog(null, null, null, null, null), null];
    }

    /**
     * Handle key events in the input phase of the edit dialog.
     *
     * @return array{0: self, 1: ?\SugarCraft\Core\Cmd}
     */
    private function updateDialogInput(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $type = $msg->type;
        $ch = $msg->rune ?? '';
        $varName = $this->editVarName ?? '';
        $currentValue = $this->editCurrentValue ?? '';
        $persistMode = $this->editPersistMode;

        // Escape cancels and returns to browse
        if ($type === KeyType::Escape) {
            return [$this->withEditDialog(null, null, null, null, null), null];
        }

        // 'p' cycles through persist modes: global → persist → persist_only → global
        if ($ch === 'p') {
            $persistMode = match ($this->editPersistMode) {
                self::MODE_GLOBAL => self::MODE_PERSIST,
                self::MODE_PERSIST => self::MODE_PERSIST_ONLY,
                self::MODE_PERSIST_ONLY => self::MODE_GLOBAL,
            };
            return [$this->withEditDialog(
                self::DLG_INPUT,
                $varName,
                $this->editNewValue ?? '',
                $currentValue,
                null,
                $persistMode,
            ), null];
        }

        // Character input — build the new value from scratch
        if ($type === KeyType::Char) {
            // If user hasn't started typing yet (null), start from empty
            $newValue = ($this->editNewValue ?? '') . $ch;
            return [$this->withEditDialog(
                self::DLG_INPUT,
                $varName,
                $newValue,
                $currentValue,
                null,
                $persistMode,
            ), null];
        }

        // Enter transitions to confirm phase (only if value changed from original)
        if ($type === KeyType::Enter) {
            $newValue = $this->editNewValue ?? '';

            // Self-write guard: no-op if new value same as original
            // (null newValue means user typed nothing — treat as same as original)
            if ($newValue === $currentValue) {
                return [$this->withEditDialog(
                    self::DLG_INPUT,
                    $varName,
                    $currentValue,
                    $currentValue,
                    null,
                    $persistMode,
                ), null];
            }

            // Transition to confirm phase
            return [$this->withEditDialog(
                self::DLG_CONFIRM,
                $varName,
                $newValue,
                $currentValue,
                null,
                $persistMode,
            ), null];
        }

        return [$this, null];
    }

    /**
     * Execute the SET edit and return the new page state.
     *
     * Chooses the editor method based on the current persist mode (global,
     * persist, or persist_only). Error 1238 (variable not dynamic) is shown
     * with a suggestion to use PERSIST_ONLY, allowing the user to manually
     * switch modes by pressing 'p'.
     *
     * @return array{0: self, 1: ?\SugarCraft\Core\Cmd}
     */
    private function executeEdit(): array
    {
        $varName = $this->editVarName ?? '';
        $newValue = $this->editNewValue ?? '';
        $currentValue = $this->editCurrentValue ?? '';
        $persistMode = $this->editPersistMode;

        // Editor must be available (guard — should not be null if dialog is active)
        if ($this->editor === null) {
            return [$this->withEditDialog(null, null, null, null, null), null];
        }

        // No-op if same value (defensive — confirm phase already checked)
        if ($newValue === $currentValue) {
            return [$this->withEditDialog(null, null, null, null, null), null];
        }

        // Choose the editor method based on persist mode
        $result = match ($persistMode) {
            self::MODE_PERSIST => $this->editor->persist($varName, $newValue),
            self::MODE_PERSIST_ONLY => $this->editor->persistOnly($varName, $newValue),
            default => $this->editor->edit($varName, $newValue),
        };

        // Success — reload variables and return to browse
        if ($result['success']) {
            $this->loadVariables();
            return [$this->withEditDialog(null, null, null, null, null), null];
        }

        // Error — show in confirm phase (user can retry, cancel, or press 'p' to switch mode)
        $errorMessage = $result['errorMessage'] ?? 'Unknown error';

        // Error 1238 = variable is not dynamic (requires restart) — suggest PERSIST_ONLY
        if (($result['errorCode'] ?? 0) === 1238) {
            $errorMessage = "Error 1238: '{$varName}' is not dynamic — press [p] to use PERSIST_ONLY";
        }

        return [$this->withEditDialog(
            self::DLG_CONFIRM,
            $varName,
            $newValue,
            $currentValue,
            $errorMessage,
            $persistMode,
        ), null];
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

    /**
     * Return a new instance with the edit dialog in the given state.
     *
     * @param string|null $phase DLG_INPUT | DLG_CONFIRM | null
     * @param string|null $persistMode 'global' | 'persist' | 'persist_only'
     */
    private function withEditDialog(
        ?string $phase,
        ?string $varName = null,
        ?string $newValue = null,
        ?string $currentValue = null,
        ?string $errorMessage = null,
        ?string $persistMode = null,
    ): self {
        $clone = clone $this;
        $clone->editDialogPhase = $phase;
        $clone->editVarName = $varName;
        $clone->editNewValue = $newValue;
        $clone->editCurrentValue = $currentValue;
        $clone->editErrorMessage = $errorMessage;
        $clone->editPersistMode = $persistMode ?? self::MODE_GLOBAL;
        return $clone;
    }

    private function isDynamic(string $varName): bool
    {
        if ($this->metadataMap === null) {
            return false;
        }

        $metadata = $this->metadataMap[$varName] ?? null;
        return $metadata !== null && $metadata->isDynamic();
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

        // Editor must be available
        if ($this->editor === null) {
            return [$this, null];
        }

        // Gate on dynamic, not just editable — static vars hit error 1238
        if (!$this->isDynamic($varName)) {
            return [$this, null];
        }

        $currentValue = $this->variables[$varName] ?? '';

        // Enter the input phase. editNewValue starts empty and accumulates
        // typed characters; editCurrentValue preserves the original for the
        // self-write guard.  The TextInput placeholder shows $currentValue
        // so the user sees the original without pre-filling the state.
        $input = TextInput::new()
            ->withPrompt('new value > ')
            ->withPlaceholder($currentValue)
            ->setValue('');  // start empty so typing appends correctly

        [$focusedInput] = $input->focus();

        return [$this->withEditDialog(
            self::DLG_INPUT,
            $varName,
            '',         // editNewValue — empty, user types to set it
            $currentValue,  // editCurrentValue — original value for guard
            null,
        ), null];
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

        // Filter by read/write (dynamic) only — inline SET GLOBAL only works at runtime
        if ($this->showReadWriteOnly) {
            $names = \array_values(
                \array_filter($names, function (string $name): bool {
                    return $this->isDynamic($name);
                })
            );
        }

        return $names;
    }

    /**
     * Check if a variable is editable per its metadata catalog.
     *
     * Distinct from isDynamic(): isEditable() reflects the [rw] metadata flag,
     * while isDynamic() reflects whether SET GLOBAL actually works at runtime.
     * handleEdit() gates on isDynamic() so that static variables reach the
     * confirm phase and get a clear error 1238 message rather than silently
     * declining at the entry point.
     *
     * @return bool True if the variable is in the catalog with editable=true
     */
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

        $title = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Variables');

        return "{$title} | {$tabLabel}{$rwIndicator}{$categoryIndicator}";
    }

    private function renderTabBar(): string
    {
        // Bits\Tabs owns the active/inactive styling; the labels keep their
        // bracketed form so they read as toggles. A wide track avoids the
        // widget's ANSI-length truncation guard clipping the second tab.
        $tabs = Tabs::new(['[Status]', '[System]'], 200)
            ->withActive($this->activeTab === self::TAB_SYSTEM ? 1 : 0)
            ->withActiveStyle(Style::new()->bold()->foreground(Color::hex('#fde047')))
            ->withInactiveStyle(Style::new()->foreground(Color::hex('#6b7280')));

        // The search box is a Forms\TextInput driven from page state; when
        // empty + unfocused it shows the "[search]" placeholder.
        $search = TextInput::new()
            ->withPlaceholder('[search]')
            ->withPrompt('')
            ->setValue($this->searchQuery);
        if ($this->searchFocused) {
            [$search] = $search->focus();
        }

        return '  ' . $tabs->view() . '     ' . $search->view();
    }

    private function renderLayout(): string
    {
        $leftPanel = $this->renderCategoryTree(25);
        $rightPanel = $this->renderVariableGrid(80);

        return Layout::joinHorizontal(Position::TOP, $leftPanel, '  ', $rightPanel);
    }

    private function renderCategoryTree(int $width): string
    {
        $header = Style::new()->bold()->foreground(Color::hex('#c084fc'))->render('Categories');

        if ($this->categories === []) {
            return $header . "\n" . Style::new()->faint()->render('  (no data)');
        }

        // "All" plus each catalog group, rendered through Forms\ItemList so the
        // selection styling and scroll window are the widget's job.
        $entries = ['All', ...$this->categories];
        $items = [];
        foreach ($entries as $entry) {
            $active = ($entry === 'All' && $this->activeCategory === null)
                || $entry === $this->activeCategory;
            $items[] = new StringItem(
                $active
                    ? Style::new()->bold()->foreground(Color::hex('#4ade80'))->render($entry)
                    : Style::new()->foreground(Color::hex('#6b7280'))->render($entry)
            );
        }

        $list = ItemList::new($items, $width, max(3, \count($items)))
            ->withTitle('')
            ->withShowStatusBar(false)
            ->withShowHelp(false)
            ->withShowFilter(false)
            ->withCursorPrefix('')
            ->withUnselectedPrefix('');

        return $header . "\n" . $list->view();
    }

    private function renderVariableGrid(int $width): string
    {
        $filteredNames = $this->getFilteredVariableNames();

        $columns = [
            Column::new('name', 'Name', 30)->withAlignLeft(true),
            Column::new('value', 'Value', 25)->withAlignLeft(true),
            Column::new('editable', 'Edit', 8),
        ];

        $rows = [];
        foreach ($filteredNames as $varName) {
            $value = $this->variables[$varName] ?? '';

            // Truncate long values for display
            if (\strlen($value) > 23) {
                $value = \substr($value, 0, 20) . '...';
            }

            $rows[] = Row::new(RowData::from([
                'name' => $varName,
                'value' => $value,
                'editable' => $this->isDynamic($varName) ? 'rw' : '',
            ]));
        }

        // sugar-table owns the grid; withSelectedIndex highlights the cursor row.
        return Table::withColumns($columns)
            ->withRows($rows)
            ->withSelectable(true)
            ->withSelectedIndex($this->selectedRowIndex)
            ->withShowFooter(false)
            ->View();
    }

    private function renderFooter(): string
    {
        return Style::new()->foreground(Color::hex('#6b7280'))
            ->render('[tab] toggle  [w] rw filter  [s] search  [j/k] nav  [e] edit(dynamic)  [q] quit');
    }

    /**
     * Render the edit dialog overlay when one is active.
     *
     * DLG_INPUT phase: prompts for the new value, shows current value as placeholder,
     * and indicates the current persist mode. Press 'p' to cycle modes.
     * DLG_CONFIRM phase: shows the pending SET statement with the chosen mode,
     * [Enter] to execute, [Esc] to cancel. If executeEdit() returned an error
     * (e.g. 1238 for non-dynamic variables), the error message is shown in red
     * below the confirm line.
     *
     * @return string Empty string when no dialog is active.
     */
    private function renderDialog(): string
    {
        $phase = $this->editDialogPhase;
        $varName = $this->editVarName ?? '';
        $currentValue = $this->variables[$varName] ?? '';
        $newValue = $this->editNewValue ?? '';
        $errorMsg = $this->editErrorMessage;
        $persistMode = $this->editPersistMode;

        $modeLabel = match ($persistMode) {
            self::MODE_PERSIST => 'PERSIST',
            self::MODE_PERSIST_ONLY => 'PERSIST_ONLY',
            default => 'GLOBAL',
        };

        if ($phase === self::DLG_INPUT) {
            $prompt = "  Edit [{$modeLabel}]: {$varName}  (current: {$currentValue})\n";
            $prompt .= '  Enter new value, [p] toggle mode, [Enter] confirm, [Esc] cancel';
            return Style::new()->foreground(Color::hex('#fde047'))->render($prompt);
        }

        if ($phase === self::DLG_CONFIRM) {
            $lines = [];
            $lines[] = '';

            // Generate the correct SQL preview using the editor's preview method
            $preview = $this->editor !== null
                ? $this->editor->getEditPreview($varName, $newValue, $persistMode)
                : "SET {$modeLabel} `{$varName}` = ?";

            $lines[] = Style::new()->foreground(Color::hex('#22d3ee'))->render("  {$preview}");
            $lines[] = '  ' . Style::new()->foreground(Color::hex('#4ade80'))->render("[Enter] execute   [Esc] cancel");

            if ($errorMsg !== null) {
                $lines[] = '';
                $lines[] = Style::new()->foreground(Color::hex('#f87171'))->render("  {$errorMsg}");
            }

            return \implode("\n", $lines);
        }

        return '';
    }

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
