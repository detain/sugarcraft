<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

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
    /** @var \Closure(string): list<Entry> */
    private readonly \Closure $lister;

    /**
     * @param \Closure(string): list<Entry> $lister
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
    ) {
        $this->lister = $lister ?? FsLister::lister();
    }

    /**
     * @param \Closure(string): list<Entry>|null $lister
     */
    public static function start(string $leftCwd, string $rightCwd, ?\Closure $lister = null): self
    {
        $lister ??= FsLister::lister();
        return new self(
            Pane::open($leftCwd, $lister),
            Pane::open($rightCwd, $lister),
            lister: $lister,
        );
    }

    public function init(): ?\Closure
    {
        return null;
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
        return $this->activeIdx === 0 ? $this->left : $this->right;
    }

    public function inactivePane(): Pane
    {
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
            $msg->type === KeyType::Char && $msg->rune === 'r'
                => $this->refresh(),
            default => $this,
        };
    }

    private function withActive(int $idx): self
    {
        return new self(
            $this->left, $this->right, $idx, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor
        );
    }

    /**
     * @param \Closure(Pane): Pane $fn
     */
    private function withActivePane(\Closure $fn): self
    {
        if ($this->activeIdx === 0) {
            return new self(
                $fn($this->left), $this->right, 0, $this->status, $this->confirm, $this->lister,
                $this->searchQuery, $this->searchResults, $this->searchCursor
            );
        }
        return new self(
            $this->left, $fn($this->right), 1, $this->status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor
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
            return $this->withStatus('nothing to delete');
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
            return $this->withConfirm(ConfirmState::None, 'cancelled');
        }
        return $this->performDelete();
    }

    private function performDelete(): self
    {
        $pane = $this->activePane();
        $names = $pane->selected !== []
            ? array_keys($pane->selected)
            : [$pane->currentEntry()?->name];
        $errors = 0;
        foreach ($names as $name) {
            if ($name === null || $name === '..' || $name === '') {
                continue;
            }
            $full = Pane::join($pane->cwd, $name);
            if (!self::removePath($full)) {
                $errors++;
            }
        }
        $msg = $errors === 0
            ? 'deleted ' . count($names) . ' entries'
            : "deleted with {$errors} errors";
        return $this->withActivePane(fn(Pane $p) =>
            Pane::open($p->cwd, $this->lister, $p->sort, $p->showHidden))
            ->withConfirm(ConfirmState::None, $msg);
    }

    private function withConfirm(ConfirmState $state, string $status): self
    {
        return new self(
            $this->left, $this->right, $this->activeIdx, $status, $state, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor
        );
    }

    private function withStatus(string $status): self
    {
        return new self(
            $this->left, $this->right, $this->activeIdx, $status, $this->confirm, $this->lister,
            $this->searchQuery, $this->searchResults, $this->searchCursor
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
            $this->lister,
            searchQuery: $query,
            searchResults: $results,
            searchCursor: $cursor
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
}
