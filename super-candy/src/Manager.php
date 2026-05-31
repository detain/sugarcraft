<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Undo\UndoActionType;

/**
 * The dual-pane file manager `Model`.
 *
 * Holds two {@see Pane}s plus an active-pane index (Tab swaps),
 * a status line, and a confirmation-state enum so destructive
 * operations route through an explicit "press y to confirm,
 * anything else to cancel" gate.
 *
 * Mutations (delete) are gated by ConfirmState. The first press
 * of `d` arms the confirmation; the next `y` actually deletes.
 * Any other key cancels. This is one TUI pattern that's worth
 * the extra state — accidental deletes are too annoying to ship.
 *
 * Filesystem reads happen through an injected lister closure.
 * `FsLister::lister()` is the prod default; tests pass a fake.
 */
final class Manager implements Model
{
    use SubscriptionCapable;
    /** @var \Closure(string): list<Entry> */
    private readonly \Closure $lister;

    /**
     * @param \Closure(string): list<Entry>|null $lister
     * @param array<int,array{left:Pane,right:Pane,activeIdx:int}> $tabs
     * @param list<UndoAction> $undoStack Stack of undoable actions
     * @param list<UndoAction> $redoStack Stack of redoable actions (cleared on new action)
     */
    public function __construct(
        public readonly Pane $left,
        public readonly Pane $right,
        public readonly int $activeIdx = 0,
        public readonly string $status = '',
        public readonly ConfirmState $confirm = ConfirmState::None,
        \Closure $lister = null,
        public readonly ?string $searchQuery = null,
        public readonly array $searchResults = [],
        public readonly int $searchCursor = 0,
        public readonly array $tabs = [],
        public readonly int $tabIndex = 0,
        public readonly bool $showTabBar = false,
        public readonly array $undoStack = [],
        public readonly array $redoStack = [],
        public readonly ?string $pendingOpDest = null,
        public readonly ?string $pendingOpType = null,
    ) {
        $this->lister = $lister ?? FsLister::lister();
    }

    /**
     * @param \Closure(string): list<Entry>|null $lister
     */
    public static function start(string $leftCwd, string $rightCwd, ?\Closure $lister = null): self
    {
        $lister ??= FsLister::lister();
        $left = Pane::open($leftCwd, $lister);
        $right = Pane::open($rightCwd, $lister);
        // Initialize with 1 tab containing the dual panes
        $initialTab = [
            'left' => $left,
            'right' => $right,
            'activeIdx' => 0,
        ];
        return new self(
            $left,
            $right,
            lister: $lister,
            tabs: [$initialTab],
            tabIndex: 0,
            showTabBar: false,
            undoStack: [],
            redoStack: [],
            pendingOpDest: null,
            pendingOpType: null,
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * Create a Manager via a fluent builder.
     */
    public static function builder(): ManagerBuilder
    {
        return new ManagerBuilder();
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        // Confirmation gate consumes the next keystroke entirely.
        if ($this->confirm !== ConfirmState::None) {
            return [$this->resolveConfirm($msg), null];
        }

        // Search mode intercepts all keys
        if ($this->searchQuery !== null) {
            return [$this->handleSearchKey($msg), null];
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }

        return [$this->dispatch($msg), null];
    }

    public function view(): string
    {
        return Renderer::render($this);
    }

    public function activePane(): Pane
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            return $tab['activeIdx'] === 0 ? $tab['left'] : $tab['right'];
        }
        return $this->activeIdx === 0 ? $this->left : $this->right;
    }

    public function inactivePane(): Pane
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            return $tab['activeIdx'] === 0 ? $tab['right'] : $tab['left'];
        }
        return $this->activeIdx === 0 ? $this->right : $this->left;
    }

    private function dispatch(KeyMsg $msg): self
    {
        return match (true) {
            $msg->type === KeyType::Char && $msg->rune === '/'
                => $this->search(''),
            $msg->type === KeyType::Tab
                => $this->withActive(1 - $this->activeIdx),
            $msg->type === KeyType::Up,
            $msg->type === KeyType::Char && $msg->rune === 'k'
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(-1)),
            $msg->type === KeyType::Down,
            $msg->type === KeyType::Char && $msg->rune === 'j'
                => $this->withActivePane(fn(Pane $p) => $p->moveCursor(1)),
            $msg->type === KeyType::Home,
            $msg->type === KeyType::Char && $msg->rune === 'g'
                => $this->withActivePane(fn(Pane $p) => $p->gotoTop()),
            $msg->type === KeyType::End,
            $msg->type === KeyType::Char && $msg->rune === 'G'
                => $this->withActivePane(fn(Pane $p) => $p->gotoBottom()),
            $msg->type === KeyType::Enter,
            $msg->type === KeyType::Right
                => $this->navigate(),
            $msg->type === KeyType::Left,
            $msg->type === KeyType::Char && $msg->rune === 'h'
                => $this->goUp(),
            $msg->type === KeyType::Char && $msg->rune === ' '
                => $this->withActivePane(fn(Pane $p) => $p->toggleSelection())
                       ->withActivePane(fn(Pane $p) => $p->moveCursor(1)),
            $msg->type === KeyType::Char && $msg->rune === 's'
                => $this->cycleSort(),
            $msg->type === KeyType::Char && $msg->rune === '.'
                => $this->withActivePane(fn(Pane $p) => $p->toggleHidden($this->lister)),
            $msg->type === KeyType::Char && $msg->rune === 'd'
                => $this->armDelete(),
            $msg->type === KeyType::Char && $msg->rune === 'c'
                => $this->armCopy(),
            $msg->type === KeyType::Char && $msg->rune === 'm'
                => $this->armMove(),
            $msg->type === KeyType::Char && $msg->rune === 'R'
                => $this->armRename(),
            $msg->type === KeyType::Char && $msg->rune === 'r'
                => $this->refresh(),
            $msg->type === KeyType::Char && $msg->rune === 'u'
                => $this->undo(),
            $msg->ctrl && $msg->rune === 'z'
                => $this->undo(),
            // Tab management: t duplicates, Ctrl+w closes, Ctrl+Tab/Ctrl+Shift+Tab cycles
            $msg->ctrl && $msg->rune === 'w'
                => $this->closeTab(),
            $msg->ctrl && $msg->shift && $msg->rune === "\t"
                => $this->tabs === [] ? $this : $this->switchTab(($this->tabIndex - 1 + count($this->tabs)) % count($this->tabs)),
            $msg->ctrl && !$msg->shift && $msg->rune === "\t"
                => $this->tabs === []
                    ? $this->duplicateTab()
                    : $this->switchTab(($this->tabIndex + 1) % count($this->tabs)),
            $msg->type === KeyType::Char && $msg->rune === 't'
                => $this->duplicateTab(),
            default => $this,
        };
    }

    private function withActive(int $idx): self
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            $newTab = ['left' => $tab['left'], 'right' => $tab['right'], 'activeIdx' => $idx];
            $newTabs = $this->tabs;
            $newTabs[$this->tabIndex] = $newTab;
            return new self(
                $this->left, $this->right, $idx, $this->status, $this->confirm, $this->lister,
                $this->searchQuery, $this->searchResults, $this->searchCursor,
                $newTabs, $this->tabIndex, $this->showTabBar,
                $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
            );
        }
        return new self(
            $this->left, $this->right, $idx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $this->tabs, $this->tabIndex, $this->showTabBar,
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /**
     * @param \Closure(Pane): Pane $fn
     */
    private function withActivePane(\Closure $fn): self
    {
        if ($this->tabs !== [] && ($tab = $this->tabs[$this->tabIndex] ?? null) !== null) {
            if ($tab['activeIdx'] === 0) {
                $newPane = $fn($tab['left']);
                $newTab = ['left' => $newPane, 'right' => $tab['right'], 'activeIdx' => 0];
            } else {
                $newPane = $fn($tab['right']);
                $newTab = ['left' => $tab['left'], 'right' => $newPane, 'activeIdx' => 1];
            }
            $newTabs = $this->tabs;
            $newTabs[$this->tabIndex] = $newTab;
            // Keep $this->left/$this->right in sync with the active tab's panes
            $newLeft = $this->tabIndex === 0 ? $newTab['left'] : ($this->tabs[0]['left'] ?? $this->left);
            $newRight = $this->tabIndex === 0 ? $newTab['right'] : ($this->tabs[0]['right'] ?? $this->right);
            return new self(
                $newLeft, $newRight, $this->activeIdx, $this->status, $this->confirm, $this->lister,
                $this->searchQuery, $this->searchResults, $this->searchCursor,
                $newTabs, $this->tabIndex, $this->showTabBar,
                $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
            );
        }
        if ($this->activeIdx === 0) {
            return new self(
                $fn($this->left), $this->right, 0, $this->status, $this->confirm, $this->lister,
                $this->searchQuery, $this->searchResults, $this->searchCursor,
                $this->tabs, $this->tabIndex, $this->showTabBar,
                $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
            );
        }
        return new self(
            $this->left, $fn($this->right), 1, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $this->tabs, $this->tabIndex, $this->showTabBar,
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    private function navigate(): self
    {
        return $this->withActivePane(fn(Pane $p) => $p->navigate($this->lister));
    }

    private function goUp(): self
    {
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open(Pane::parentPath($p->cwd), $this->lister, $p->sort, $p->showHidden));
    }

    private function cycleSort(): self
    {
        $active = $this->activePane();
        return $this->withActivePane(fn(Pane $p) => $p->setSort($active->sort->cycle(), $this->lister));
    }

    private function refresh(): self
    {
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withStatus('refreshed');
    }

    private function armDelete(): self
    {
        $selectedCount = count($this->activePane()->selected);
        $current = $this->activePane()->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus(Lang::t('status.nothing_to_delete'));
        }
        $names = $selectedCount > 0
            ? "{$selectedCount} selected entries"
            : "'{$current->name}'";
        return $this->withConfirm(ConfirmState::DeleteSelected, "delete {$names}? (y/n)");
    }

    private function resolveConfirm(KeyMsg $msg): self
    {
        $confirmed = $msg->type === KeyType::Char && $msg->rune === 'y';
        if (!$confirmed) {
            return $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'));
        }
        return match ($this->confirm) {
            ConfirmState::DeleteSelected => $this->performDelete(),
            ConfirmState::CopySelected => $this->performCopy(),
            ConfirmState::MoveSelected => $this->performMove(),
            ConfirmState::RenameSelected => $this->performRename(),
            default => $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled')),
        };
    }

    private function performDelete(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $errors = 0;
        $deletedItems = [];
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $full = Pane::join($pane->cwd, $name);
            // Capture info before deletion for undo
            $deletedItems[] = [
                'path' => $full,
                'isDir' => is_dir($full),
                'content' => is_file($full) ? @file_get_contents($full) : null,
                'stat' => @stat($full) ?: [],
            ];
            if (!self::removePath($full)) {
                $errors++;
            }
        }
        $msg = $errors === 0
            ? Lang::t('status.deleted', ['count' => count($names)])
            : Lang::t('status.deleted_with_errors', ['errors' => $errors]);
        // Build new undo stack with this deletion
        $newUndoStack = $this->undoStack;
        if ($deletedItems !== []) {
            $newUndoStack[] = UndoAction::delete($deletedItems);
        }
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withConfirm(ConfirmState::None, $msg)
            ->withUndoRedoStacks($newUndoStack, []); // Clear redo on new action
    }

    /** Arm copy confirmation — next KeyMsg triggers performCopy or cancel. */
    private function armCopy(): self
    {
        $pane = $this->activePane();
        $selectedCount = count($pane->selected);
        $current = $pane->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus(Lang::t('status.nothing_to_copy'));
        }
        $inactive = $this->inactivePane();
        $names = $selectedCount > 0
            ? "{$selectedCount} selected entries"
            : "'{$current->name}'";
        return $this->withConfirm(
            ConfirmState::CopySelected,
            "copy {$names} to {$inactive->cwd}? (y/n)",
            $inactive->cwd,
            'copy'
        );
    }

    /** Arm move confirmation — next KeyMsg triggers performMove or cancel. */
    private function armMove(): self
    {
        $pane = $this->activePane();
        $selectedCount = count($pane->selected);
        $current = $pane->currentEntry();
        if ($selectedCount === 0 && ($current === null || $current->isParentSentinel())) {
            return $this->withStatus(Lang::t('status.nothing_to_move'));
        }
        $inactive = $this->inactivePane();
        $names = $selectedCount > 0
            ? "{$selectedCount} selected entries"
            : "'{$current->name}'";
        return $this->withConfirm(
            ConfirmState::MoveSelected,
            "move {$names} to {$inactive->cwd}? (y/n)",
            $inactive->cwd,
            'move'
        );
    }

    /** Arm rename confirmation — next KeyMsg triggers performRename or cancel. */
    private function armRename(): self
    {
        $pane = $this->activePane();
        $current = $pane->currentEntry();
        if ($current === null || $current->isParentSentinel()) {
            return $this->withStatus(Lang::t('status.nothing_to_rename'));
        }
        return $this->withConfirm(
            ConfirmState::RenameSelected,
            "rename '{$current->name}' to: ",
            $current->name,
            'rename'
        );
    }

    /**
     * @param string $src Source path
     * @param string $dst Destination path
     * @return bool True on success; false on any error (file not found, permission denied, etc.)
     * @throws void
     */
    public function copy(string $src, string $dst): bool
    {
        if (is_dir($src)) {
            return $this->copyDir($src, $dst);
        }
        return @copy($src, $dst);
    }

    /**
     * @param string $src Source path
     * @param string $dst Destination path
     * @return bool True on success; false on any error
     * @throws void
     */
    public function move(string $src, string $dst): bool
    {
        return @rename($src, $dst);
    }

    /**
     * @param string $src Source path
     * @param string $newName New name (not full path — placed in the same directory as src)
     * @return bool True on success; false on any error
     * @throws void
     */
    public function rename(string $src, string $newName): bool
    {
        $dir = dirname($src);
        $dst = Pane::join($dir, $newName);
        return @rename($src, $dst);
    }

    /** Recursively copy a directory */
    private function copyDir(string $src, string $dst): bool
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }
        $items = @scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = Pane::join($src, $item);
            $dstPath = Pane::join($dst, $item);
            if (is_dir($srcPath)) {
                if (!$this->copyDir($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function performCopy(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $dst = $this->pendingOpDest;
        if ($dst === null) {
            return $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'));
        }
        $errors = 0;
        $copiedItems = [];
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $src = Pane::join($pane->cwd, $name);
            $target = Pane::join($dst, $name);
            $copiedItems[$src] = $target;
            if (!$this->copy($src, $target)) {
                $errors++;
            }
        }
        $msg = $errors === 0
            ? Lang::t('status.copied', ['count' => count($names)])
            : Lang::t('status.copied_with_errors', ['errors' => $errors]);
        $newUndoStack = $this->undoStack;
        if ($copiedItems !== []) {
            $newUndoStack[] = UndoAction::copy($copiedItems);
        }
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withConfirm(ConfirmState::None, $msg)
            ->withUndoRedoStacks($newUndoStack, []);
    }

    private function performMove(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $dst = $this->pendingOpDest;
        if ($dst === null) {
            return $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'));
        }
        $errors = 0;
        $movedItems = [];
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $src = Pane::join($pane->cwd, $name);
            $target = Pane::join($dst, $name);
            $movedItems[$src] = $target;
            if (!$this->move($src, $target)) {
                $errors++;
            }
        }
        $msg = $errors === 0
            ? Lang::t('status.moved', ['count' => count($names)])
            : Lang::t('status.moved_with_errors', ['errors' => $errors]);
        $newUndoStack = $this->undoStack;
        if ($movedItems !== []) {
            $newUndoStack[] = UndoAction::move($movedItems);
        }
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withConfirm(ConfirmState::None, $msg)
            ->withUndoRedoStacks($newUndoStack, []);
    }

    private function performRename(): self
    {
        $pane = $this->activePane();
        $current = $pane->currentEntry();
        if ($current === null || $current->isParentSentinel()) {
            return $this->withConfirm(ConfirmState::None, Lang::t('status.cancelled'));
        }
        $srcName = $current->name;
        $src = Pane::join($pane->cwd, $srcName);
        $newName = $this->pendingOpDest;
        if ($newName === null || $newName === '' || $newName === $srcName) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.cancelled'));
        }
        $renamedItems = [];
        $renamedItems[$src] = Pane::join($pane->cwd, $newName);
        if (!$this->rename($src, $newName)) {
            return $this->withActivePane(fn(Pane $p) =>
                Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
                ->withConfirm(ConfirmState::None, Lang::t('status.rename_failed', ['name' => $srcName]));
        }
        $newUndoStack = $this->undoStack;
        $newUndoStack[] = UndoAction::rename($renamedItems);
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withConfirm(ConfirmState::None, Lang::t('status.renamed', ['old' => $srcName, 'new' => $newName]))
            ->withUndoRedoStacks($newUndoStack, []);
    }

    private function withConfirm(ConfirmState $state, string $status, ?string $pendingOpDest = null, ?string $pendingOpType = null): self
    {
        return new self(
            $this->left, $this->right, $this->activeIdx, $status, $state, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $this->tabs, $this->tabIndex, $this->showTabBar,
            $this->undoStack, $this->redoStack, $pendingOpDest, $pendingOpType
        );
    }

    private function withStatus(string $status): self
    {
        return new self(
            $this->left, $this->right, $this->activeIdx, $status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $this->tabs, $this->tabIndex, $this->showTabBar,
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** Start search mode with a query */
    public function search(string $query): self
    {
        $cwd = $this->activePane()->cwd;
        $allEntries = ($this->lister)($cwd);
        if ($query === '') {
            // Empty query shows all entries but stays in search mode
            return $this->withSearch('', $allEntries, 0);
        }
        $results = array_values(array_filter(
            $allEntries,
            fn(Entry $e) => str_contains(strtolower($e->name), strtolower($query))
        ));
        return $this->withSearch($query, $results, 0);
    }

    /** Exit search mode */
    public function exitSearch(): self
    {
        return $this->withSearch(null, [], 0);
    }

    /** Navigate search results with up/down */
    public function moveSearchCursor(int $delta): self
    {
        if ($this->searchQuery === null || $this->searchResults === []) {
            return $this;
        }
        $newCursor = max(0, min(count($this->searchResults) - 1, $this->searchCursor + $delta));
        return $this->withSearch($this->searchQuery, $this->searchResults, $newCursor);
    }

    /** Open selected search result */
    public function openSearchResult(): self
    {
        if ($this->searchQuery === null || $this->searchResults === []) {
            return $this;
        }
        $entry = $this->searchResults[$this->searchCursor] ?? null;
        if ($entry === null) {
            return $this;
        }
        // If it's a directory, navigate into it; if file, deselect and exit search
        if ($entry->isDir) {
            return $this->withActivePane(fn(Pane $p) => Pane::open(
                Pane::join($p->cwd, $entry->name),
                $this->lister,
                $p->sort,
                $p->showHidden
            ))->exitSearch();
        }
        return $this->exitSearch();
    }

    private function withSearch(?string $query, array $results, int $cursor): self
    {
        return new self(
            $this->left, $this->right, $this->activeIdx, $this->status, $this->confirm,
            $this->lister, $query, $results, $cursor,
            $this->tabs, $this->tabIndex, $this->showTabBar,
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** Handle keys while in search mode */
    private function handleSearchKey(KeyMsg $msg): self
    {
        // Escape exits search
        if ($msg->type === KeyType::Escape) {
            return $this->exitSearch();
        }
        // Enter opens result
        if ($msg->type === KeyType::Enter) {
            return $this->openSearchResult();
        }
        // Up/Down navigate results
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->moveSearchCursor(-1);
        }
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->moveSearchCursor(1);
        }
        // Backspace removes last char from query
        if ($msg->type === KeyType::Backspace) {
            if ($this->searchQuery === '') {
                // Empty query + backspace = exit search
                return $this->exitSearch();
            }
            $newQuery = self::dropLast($this->searchQuery);
            if ($newQuery === '') {
                // Backspacing to empty = exit search
                return $this->exitSearch();
            }
            return $this->search($newQuery);
        }
        // Regular chars append to query
        if ($msg->type === KeyType::Char && !$msg->ctrl && $msg->rune !== '/') {
            return $this->search(($this->searchQuery ?? '') . $msg->rune);
        }
        return $this;
    }

    /** Drop last UTF-8 character from string */
    private static function dropLast(string $s): string
    {
        if ($s === '') {
            return '';
        }
        return preg_replace('/.$/us', '', $s);
    }

    /** Recursive delete. Empty dirs use rmdir; files use unlink. */
    private static function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }
        $items = @scandir($path) ?: [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!self::removePath(rtrim($path, '/') . '/' . $name)) {
                return false;
            }
        }
        return @rmdir($path);
    }

    /** Open a new tab with a given directory path */
    public function openNewTab(string $path = '/'): self
    {
        $current = $this->currentTabs();
        $cwd = $current !== null
            ? ($this->tabs[$this->tabIndex]['left']->cwd ?? $path)
            : ($this->left->cwd ?? $path);
        $newTab = [
            'left' => Pane::open($cwd, $this->lister),
            'right' => Pane::open($cwd, $this->lister),
            'activeIdx' => 0,
        ];
        $newTabs = [...$this->tabs, $newTab];
        return new self(
            $this->left, $this->right, $this->activeIdx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $newTabs, count($newTabs) - 1, $newTabs !== [],
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** Close the tab at index, unless it's the last tab */
    public function closeTab(?int $index = null): self
    {
        $index ??= $this->tabIndex;
        if ($this->tabs === [] || count($this->tabs) <= 1) {
            return $this->withStatus(Lang::t('status.cannot_close_last_tab'));
        }
        $newTabs = $this->tabs;
        array_splice($newTabs, $index, 1);
        $newIndex = min($this->tabIndex, count($newTabs) - 1);
        return new self(
            $this->left, $this->right, $this->activeIdx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $newTabs, $newIndex, $newTabs !== [],
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** Switch to tab at index */
    public function switchTab(int $index): self
    {
        if ($this->tabs === [] || $index < 0 || $index >= count($this->tabs)) {
            return $this;
        }
        return new self(
            $this->left, $this->right, $this->activeIdx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $this->tabs, $index, $this->showTabBar,
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** Open a new tab with current pane's directory */
    public function duplicateTab(): self
    {
        $current = $this->currentTabs();
        $cwd = $current !== null
            ? ($this->tabs[$this->tabIndex]['left']->cwd ?? '/')
            : ($this->left->cwd ?? '/');
        $newTab = [
            'left' => Pane::open($cwd, $this->lister),
            'right' => Pane::open($cwd, $this->lister),
            'activeIdx' => 0,
        ];
        $newTabs = [...$this->tabs, $newTab];
        return new self(
            $this->left, $this->right, $this->activeIdx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $newTabs, count($newTabs) - 1, $newTabs !== [],
            $this->undoStack, $this->redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** @return array{left:Pane,right:Pane,activeIdx:int}|null */
    private function currentTabs(): ?array
    {
        return $this->tabs[$this->tabIndex] ?? null;
    }

    /** Create a new Manager with modified undo/redo stacks */
    private function withUndoRedoStacks(array $undoStack, array $redoStack): self
    {
        return new self(
            $this->left, $this->right, $this->activeIdx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor,
            $this->tabs, $this->tabIndex, $this->showTabBar,
            $undoStack, $redoStack, $this->pendingOpDest, $this->pendingOpType
        );
    }

    /** Undo the last operation */
    public function undo(): self
    {
        if ($this->undoStack === []) {
            return $this->withStatus(Lang::t('status.nothing_to_undo'));
        }
        $newUndoStack = $this->undoStack;
        $action = array_pop($newUndoStack);
        if ($action === null) {
            return $this->withStatus(Lang::t('status.nothing_to_undo'));
        }
        $errors = $this->reverseAction($action);
        $msg = $errors === 0
            ? Lang::t('status.undone', ['description' => $action->description])
            : Lang::t('status.undo_with_errors', ['description' => $action->description, 'errors' => $errors]);
        // Push to redo stack
        $newRedoStack = [...$this->redoStack, $action];
        return $this
            ->refresh()
            ->withStatus($msg)
            ->withUndoRedoStacks($newUndoStack, $newRedoStack);
    }

    /** Reverse an undo action (for restore on redo) */
    private function reverseAction(UndoAction $action): int
    {
        $errors = 0;
        // Route by UndoActionType enum — no string prefix detection
        match ($action->type) {
            UndoActionType::Delete => $errors = $this->reverseDelete($action->items),
            UndoActionType::Move => $errors = $this->reverseMove($action->items),
            UndoActionType::Rename => $errors = $this->reverseRename($action->items),
            // Insert (mkdir): items is list<array{path:string,isDir:bool}> — same reverse as delete
            UndoActionType::Insert => $errors = $this->reverseDelete($action->items),
            // Copy cannot be undone — original still exists
            UndoActionType::Copy, UndoActionType::Modify, UndoActionType::Custom => $errors = 0,
        };
        return $errors;
    }

    /**
     * @param list<array{path:string,isDir:bool,content:?string,stat:array}> $items
     */
    private function reverseDelete(array $items): int
    {
        $errors = 0;
        foreach ($items as $item) {
            if (!isset($item['path'])) {
                continue;
            }
            $path = $item['path'];
            if (isset($item['isDir']) && $item['isDir']) {
                // Restore directory
                if (!is_dir($path) && !@mkdir($path, 0755, true)) {
                    $errors++;
                }
            } elseif (isset($item['content']) && $item['content'] !== null) {
                // Restore file
                $dir = dirname($path);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                    $errors++;
                    continue;
                }
                if (@file_put_contents($path, $item['content']) === false) {
                    $errors++;
                }
            }
        }
        return $errors;
    }

    /**
     * @param array<string,string> $moves Map of original path => new path
     */
    private function reverseMove(array $moves): int
    {
        $errors = 0;
        foreach ($moves as $oldPath => $newPath) {
            if (!is_string($oldPath) || !is_string($newPath)) {
                continue;
            }
            // newPath -> oldPath reverses the move
            if (!@rename($newPath, $oldPath)) {
                $errors++;
            }
        }
        return $errors;
    }

    /**
     * @param array<string,string> $renames Map of old path => new path
     */
    private function reverseRename(array $renames): int
    {
        $errors = 0;
        foreach ($renames as $oldPath => $newPath) {
            if (!is_string($oldPath) || !is_string($newPath)) {
                continue;
            }
            // Extract original name from oldPath
            $oldName = basename($oldPath);
            $newDir = dirname($newPath);
            $targetPath = Pane::join($newDir, $oldName);
            if (!@rename($newPath, $targetPath)) {
                $errors++;
            }
        }
        return $errors;
    }

    /** Check if undo is available */
    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    /** Check if redo is available */
    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }
}
