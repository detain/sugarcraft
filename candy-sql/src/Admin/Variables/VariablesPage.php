<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Variables;

use SugarCraft\Widgets\Tabs\Tabs;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Sql\Admin\PageBase;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\RowNavigable;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
use SugarCraft\Style\Style;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;
use SugarCraft\Sql\CellValue;

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
 * @see Mirrors jorgerojas26/lazysql VariablesPage
 */
final class VariablesPage extends PageBase implements RowNavigable
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

    /**
     * SQL-P16 (adminpages-033): which region owns input. The Categories
     * sidebar used to be display-only chrome — no key could move a cursor
     * into it, and the legend's bare "j/k navigate" could not say which
     * side it meant. $categoryFocused pins the side; the tree cursor is the
     * in-flight choice before Enter applies it.
     */
    private bool $categoryFocused = false;
    private int $categoryCursor = 0;
    private bool $showReadWriteOnly = false;
    private int $selectedRowIndex = 0;
    private bool $searchFocused = false;

    /**
     * SQL-P09 (adminpages-010): the grid used to build a Row object (RowData
     * wrap + two sanitizes) for EVERY filtered variable on EVERY frame — a
     * ~1,000-variable SHOW VARIABLES dump rendered ~16 pane lines, so 98 % of
     * the work was discarded. Rows are now built only for a window around the
     * cursor; the window follows the cursor with the same minimal-shift rule
     * DebugPage codifies (shift only when the cursor leaves the window), so a
     * cursor inside the first window renders byte-identical output. The
     * visible budget is house PAGE_SIZE (DebugPage::PAGE_SIZE = 50): generous
     * for any pane height, and window + shift math depends on nothing but the
     * cursor staying inside it.
     */
    private const GRID_WINDOW = 50;

    /** @var int Index of the first row built for the grid (auto-scrolls with the cursor). */
    private int $gridScrollOffset = 0;

    /**
     * SQL-P09 (adminpages-017): getFilteredVariableNames ran on every read
     * (footer count, nav clamp, grid build) and each pass re-copy + double
     * strtolower-filtered the whole name list — re-running per keystroke even
     * when the derived list could not have changed. The result memoizes on
     * exactly its inputs; the data identity is $dataEpoch, bumped only by
     * loadVariables (the single writer of $variables/$variableNames), so a
     * fresh arrival recomputes while navigation and repeated reads ride the
     * memo. The sort was already per-arrival (loadVariables is the only
     * writer of the sorted list).
     */
    private int $dataEpoch = 0;
    /** @var list<string>|null */
    private ?array $filterMemo = null;
    private string $memoQuery = '';
    private ?string $memoCategory = null;
    private bool $memoReadWrite = false;
    private bool $memoHasMetadata = false;
    private int $memoEpoch = -1;
    /** Observation seam (tests): number of ACTUAL filter passes, not reads. */
    private int $filterComputes = 0;

    /** @var list<string> */
    private array $categories = [];

    /** SQL-P09: catalog/groups extraction is static per construction — run it once. */
    private bool $categoriesLoaded = false;

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
     * SQL-C02 (client-002): this factory used to accept ONLY the context, so
     * App::buildVariablesPage()'s loaded Catalog and VariableEditor were
     * silently discarded as extra positional args — the category tree, [rw]
     * indicators and the editor died at the wire while every page-side test
     * (which used the 3-arg constructor directly) stayed green. The signature
     * now mirrors the constructor; pass collaborators or get the plain page.
     */
    public static function new(ServerContextInterface $context, ?Catalog $catalog = null, ?VariableEditor $editor = null): self
    {
        return new self($context, $catalog, $editor);
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

        // SQL-P09: arrivals are the refresh signal now that build() no longer
        // re-reads the store every frame (the loadVariables === guard folds
        // identical snapshots). Same arm ConnectionsPage codifies: pull the
        // fresh cache into the async context, then let the next reads re-derive.
        if ($msg instanceof ReloadReportMsg) {
            if ($this->context instanceof AsyncCachingServerContext) {
                $this->context->refreshFromLiveCache();
            }
            $clone = clone $this;
            $this->loadVariables();
            $clone->variables = $this->variables;
            $clone->variableNames = $this->variableNames;
            $clone->dataEpoch = $this->dataEpoch;
            $clone->filterMemo = null;
            return [$clone, null];
        }

        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune;
        $type = $msg->type;

        // Tab key toggles between Status/System tabs
        // NOTE: App::update() consumes Tab for the global pane cycle before
        // admin delegation, so this width-toggle branch is unreachable while
        // the sidebar keeps Tab as its escape hatch (review F1).
        if ($type === KeyType::Tab && !$msg->shift) {
            return [$this->withToggleTab(), null];
        }

        // SQL-P16 (adminpages-033): ONE explicit sidebar↔grid jump key.
        // 'c' toggles the tree's ownership of input; while it holds, j/k
        // move the tree cursor, Enter/e applies the highlighted category
        // (landing back on the grid), 'c'/Esc release. w/s stay grid-side
        // (suppressed in tree mode), q always leaves the pane.
        if ($ch === 'c') {
            if ($this->categories === []) {
                return [$this, null]; // SQL-P16: no ring to focus when the catalog gave no categories
            }

            return [$this->withCategoryFocus(!$this->categoryFocused), null];
        }

        if ($this->categoryFocused) {
            if ($ch === 'j' || $type === KeyType::Down) {
                return [$this->withCategoryCursor($this->categoryCursor + 1), null];
            }
            if ($ch === 'k' || $type === KeyType::Up) {
                return [$this->withCategoryCursor($this->categoryCursor - 1), null];
            }
            if ($type === KeyType::Enter || $ch === 'e') {
                return [$this->applyCategorySelection(), null];
            }
            if ($type === KeyType::Escape) {
                return [$this->withCategoryFocus(false), null];
            }
            if ($ch === 'q') {
                // The legend promises q always leaves the pane — keep it true
                // while the tree holds input (SQL-P16).
                return [$this->withQuit(), null];
            }
            return [$this, null];
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

        // e opens editor for selected variable (Enter is the same intent —
        // SQL-P16: "editor reachable via e/Enter" is the probe the finding
        // never saw pass)
        if ($ch === 'e') {
            return $this->handleEdit();
        }
        if ($type === KeyType::Enter) {
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
        $ch = $msg->rune;
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
                default => self::MODE_GLOBAL,
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
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
        $clone->activeCategory = null;
        $clone->searchFocused = false;
        $clone->categoryFocused = false;
        $clone->categoryCursor = 0; // SQL-P16: a filter reset clears the ring too
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
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
        $clone->activeCategory = null;
        $clone->searchFocused = false;
        $clone->categoryFocused = false;
        $clone->categoryCursor = 0; // SQL-P16: a filter reset clears the ring too
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
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
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
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
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
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
        return $clone;
    }

    /**
     * SQL-P16: sidebar focus withers (adminpages-033). Re-entry snaps the
     * cursor to the applied category so the ring continues where the eye is.
     */
    private function withCategoryFocus(bool $focused): self
    {
        $clone = clone $this;
        $clone->categoryFocused = $focused;
        if ($focused) {
            $clone->categoryCursor = $this->categoryIndexFor($this->activeCategory);
        }

        return $clone;
    }

    private function withCategoryCursor(int $index): self
    {
        $entries = $this->categoryEntries();
        $clone = clone $this;
        $clone->categoryCursor = max(0, min(count($entries) - 1, $index));

        return $clone;
    }

    private function applyCategorySelection(): self
    {
        $picked = $this->categoryEntries()[$this->categoryCursor] ?? 'All';
        $applied = $this->withCategory($picked === 'All' ? null : $picked);
        $applied->categoryFocused = false;

        return $applied;
    }

    /** @return list<string> */
    private function categoryEntries(): array
    {
        return ['All', ...$this->categories];
    }

    private function categoryIndexFor(?string $category): int
    {
        $index = array_search($category ?? 'All', $this->categoryEntries(), true);

        return $index === false ? 0 : (int) $index;
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
            // SQL-P09: DebugPage's auto-scroll rule — the window shifts only
            // when the cursor tries to leave it, so the rendered bytes stay
            // identical for every cursor the un-windowed pane could already
            // reach at the top of the list.
            if ($clone->selectedRowIndex > $clone->gridScrollOffset + self::GRID_WINDOW - 1) {
                $clone->gridScrollOffset = $clone->selectedRowIndex - self::GRID_WINDOW + 1;
            }
        }
        return $clone;
    }

    private function withNavigateUp(): self
    {
        $clone = clone $this;
        if ($clone->selectedRowIndex > 0) {
            $clone->selectedRowIndex--;
            if ($clone->selectedRowIndex < $clone->gridScrollOffset) {
                $clone->gridScrollOffset = \max(0, $clone->selectedRowIndex);
            }
        }
        return $clone;
    }

    private function withAppendSearch(string $char): self
    {
        $clone = clone $this;
        $clone->searchQuery .= $char;
        $clone->selectedRowIndex = 0;
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
        return $clone;
    }

    private function withBackspaceSearch(): self
    {
        $clone = clone $this;
        $clone->searchQuery = \substr($clone->searchQuery, 0, -1);
        $clone->selectedRowIndex = 0;
        $clone->gridScrollOffset = 0; // SQL-P09: cursor parks at the top → window re-anchors
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
        $vars = $this->activeTab === self::TAB_STATUS
            ? $this->context->statusVariables()
            : $this->context->serverVariables();

        // SQL-P09 (adminpages-017): build() ran this EVERY frame. The context
        // answers within its TTL window with the identical array, so an ===
        // check skips the key-extraction + sort of ~1,000 names per paint —
        // and only a data change can move the derived lists, so the epoch
        // (the filter memo's data-identity stamp) bumps exactly when the
        // memo may be stale.
        if ($vars === $this->variables) {
            return;
        }

        $this->variables = $vars;
        $this->variableNames = \array_keys($this->variables);
        \sort($this->variableNames);
        // SQL-P09: single writer of the sorted name list — bump the data
        // identity so the filter memo knows its inputs moved.
        $this->dataEpoch++;
    }

    private function loadCategories(): void
    {
        // SQL-P09: the catalog is immutable per construction (static
        // metadata), so groups()/all() — a map rebuild over every variable —
        // runs once, not once per frame.
        if ($this->categoriesLoaded) {
            return;
        }
        $this->categoriesLoaded = true;

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
        // SQL-P09 (adminpages-017): memo hit check — every input that can
        // change the answer is in the stamp; identical stamp means the
        // stored list IS the answer (the epoch covers any data reload, and
        // metadata presence covers the lazy catalog leg the rw/category
        // filters close over).
        $hasMetadata = $this->metadataMap !== null;
        if (
            $this->filterMemo !== null
            && $this->memoEpoch === $this->dataEpoch
            && $this->memoQuery === $this->searchQuery
            && $this->memoCategory === $this->activeCategory
            && $this->memoReadWrite === $this->showReadWriteOnly
            && $this->memoHasMetadata === $hasMetadata
        ) {
            return $this->filterMemo;
        }

        $this->filterComputes++;
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

        $this->filterMemo = $names;
        $this->memoEpoch = $this->dataEpoch;
        $this->memoQuery = $this->searchQuery;
        $this->memoCategory = $this->activeCategory;
        $this->memoReadWrite = $this->showReadWriteOnly;
        $this->memoHasMetadata = $hasMetadata;
        return $names;
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
        // SQL-P16 (adminpages-034): the Categories panel used to be permanent
        // chrome whose only empty-state content was "(no data)" — every
        // capture without catalog groups showed a dead column stealing grid
        // width. Hide-when-empty: dead chrome gone; with data it rides.
        if ($this->categories === []) {
            return $this->renderVariableGrid(80);
        }

        $leftPanel = $this->renderCategoryTree(25);
        $rightPanel = $this->renderVariableGrid(80);

        return Layout::joinHorizontal(Position::TOP, $leftPanel, '  ', $rightPanel);
    }

    private function renderCategoryTree(int $width): string
    {
        // SQL-P16 (adminpages-033): the focus ring. While the tree owns
        // input its header carries a ▸ and the cursor row is marked — the
        // user can SEE which side a keystroke lands on. Unfocused, the
        // applied category keeps the old green marker and the header stays
        // plain, so the panel reads as state, not as a competing cursor.
        $headerLabel = $this->categoryFocused ? 'Categories ▸' : 'Categories';
        $header = Style::new()->bold()->foreground(Color::hex('#c084fc'))->render($headerLabel);

        // (adminpages-034: the old '(no data)' empty leg is gone with the
        // panel itself — renderLayout() only calls this when there ARE
        // categories; dead chrome was the finding.)

        // "All" plus each catalog group, rendered through Forms\ItemList so the
        // selection styling and scroll window are the widget's job.
        $entries = ['All', ...$this->categories];
        $items = [];
        foreach ($entries as $index => $entry) {
            $active = ($entry === 'All' && $this->activeCategory === null)
                || $entry === $this->activeCategory;
            $isCursor = $this->categoryFocused && $index === $this->categoryCursor;

            $style = $isCursor
                ? Style::new()->bold()->foreground(Color::hex('#22d3ee'))
                : ($active
                    ? Style::new()->bold()->foreground(Color::hex('#4ade80'))
                    : Style::new()->foreground(Color::hex('#6b7280')));

            $items[] = new StringItem(
                ($isCursor || ($active && !$this->categoryFocused) ? '▸ ' : '  ') . $style->render($entry),
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

        // SQL-P09 (adminpages-010): build Row objects for the window only —
        // the nav arms keep cursor - offset inside [0, GRID_WINDOW), and the
        // pane never renders past the frame edge anyway. The widget is told
        // the WINDOW-LOCAL index so its highlight lands on the same row the
        // full-list render used to mark.
        $window = \array_slice($filteredNames, $this->gridScrollOffset, self::GRID_WINDOW);

        $columns = [
            Column::new('name', 'Name', 30)->withAlignLeft(true),
            Column::new('value', 'Value', 25)->withAlignLeft(true),
            Column::new('editable', 'Edit', 8),
        ];

        $rows = [];
        foreach ($window as $varName) {
            $value = $this->variables[$varName] ?? '';

            // Truncate long values for display
            if (\strlen($value) > 23) {
                $value = \substr($value, 0, 20) . '...';
            }

            // Names and values both come from SHOW VARIABLES (server text) —
            // sanitize the display copy; $varName stays the raw lookup key.
            $rows[] = Row::new(RowData::from([
                'name' => CellValue::sanitize($varName),
                'value' => CellValue::sanitize($value),
                'editable' => $this->isDynamic($varName) ? 'rw' : '',
            ]));
        }

        // sugar-table owns the grid; withSelectedIndex highlights the cursor row.
        return Table::fromColumns($columns)
            ->withRows($rows)
            ->withSelectable(true)
            ->withSelectedIndex(\max(0, $this->selectedRowIndex - $this->gridScrollOffset))
            ->withShowFooter(false)
            ->View();
    }

    private function renderFooter(): string
    {
        // SQL-P16 (adminpages-033): the legend names what j/k actually moves
        // right now (tree vs rows), and the edit hint only rides when the
        // grid has rows to edit — "f:filter-style action copy on a No data
        // page" was the chrome lie this leg fixes.
        $parts = $this->categories === []
            ? [$this->categoryFocused ? '[j/k] tree' : '[j/k] rows']
            : ['[c] categories', $this->categoryFocused ? '[j/k] tree' : '[j/k] rows'];
        if ($this->getFilteredVariableNames() !== []) {
            $parts[] = '[Enter/e] edit(dynamic)';
        }
        $parts[] = '[w] rw filter';
        $parts[] = '[s] search';
        $parts[] = '[q] back';

        return Style::new()->foreground(Color::hex('#6b7280'))
            ->render(implode('  ', $parts));
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
            $prompt = "  Edit ({$modeLabel}) {$varName} (current: {$currentValue})\n";
            // D-M28: the raw scope tokens meant nothing on screen — spell the
            // semantics out, the one place an explainer is load-bearing.
            $prompt .= "  GLOBAL = runtime only (lost on restart) · PERSIST = runtime + config file · PERSIST_ONLY = config file only (applies at next restart)\n";
            $prompt .= '  Enter new value, [p] toggle mode, [Enter] confirm, [Esc] cancel';
            return Style::new()->foreground(Color::hex('#fde047'))->render($prompt);
        }

        if ($phase === self::DLG_CONFIRM) {
            $lines = [];
            $lines[] = '';

            // Generate the correct SQL preview using the editor's preview method
            $preview = $this->editor !== null
                ? $this->editor->editPreview($varName, $newValue, $persistMode)
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

    /** The edit/confirm dialog is open — it owns every keystroke. */
    public function isModal(): bool
    {
        return $this->editDialogPhase !== null;
    }

    public function editor(): ?VariableEditor
    {
        return $this->editor;
    }
}
