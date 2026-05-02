<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy;

/**
 * One half of the dual-pane file manager.
 *
 * Holds the current working directory, the listed entries, the
 * cursor position (which entry is highlighted), the multi-select
 * set (a Set of entry names — strings, not indices, so a refresh
 * doesn't break selection), and the active sort order.
 *
 * Immutable: every transition (`navigateTo`, `moveCursor`,
 * `toggleSelection`, `setSort`) returns a fresh Pane so the
 * surrounding {@see Manager} can return `[nextManager, ?cmd]`
 * cleanly.
 *
 * Filesystem reads happen via an injected `Listing` callable —
 * tests pass a closure that returns a fixed list, prod passes
 * one that scans `$cwd` with `scandir`/`stat`. Decoupling the
 * I/O lets the entire transition layer be tested with no
 * tmp-dir setup or filesystem fixtures.
 */
final class Pane
{
    /** @var list<Entry> */
    public readonly array $entries;
    /** @var array<string,true> name → true; presence == selected */
    public readonly array $selected;

    /**
     * @param list<Entry>          $entries
     * @param array<string,true>   $selected
     */
    public function __construct(
        public readonly string $cwd,
        array $entries,
        public readonly int $cursor = 0,
        array $selected = [],
        public readonly Sort $sort = Sort::NameAsc,
        public readonly bool $showHidden = false,
    ) {
        $this->entries = $entries;
        $this->selected = $selected;
    }

    /**
     * Build a fresh Pane for `$cwd` by listing entries via
     * `$lister` and applying the current sort order.
     *
     * @param \Closure(string): list<Entry> $lister
     */
    public static function open(string $cwd, \Closure $lister, Sort $sort = Sort::NameAsc, bool $showHidden = false): self
    {
        $entries = self::filterHidden($lister($cwd), $showHidden);
        if ($cwd !== '/' && $cwd !== '') {
            $entries = array_merge([Entry::parent()], $entries);
        }
        return new self($cwd, $sort->apply($entries), 0, [], $sort, $showHidden);
    }

    /**
     * Move into the directory under the cursor (or up if it's the
     * `..` sentinel). Files don't navigate — caller decides what
     * to do with them (open in viewer, edit, etc.).
     *
     * @param \Closure(string): list<Entry> $lister
     */
    public function navigate(\Closure $lister): self
    {
        $current = $this->currentEntry();
        if ($current === null || !$current->isDir) {
            return $this;
        }
        $next = $current->isParentSentinel()
            ? self::parentPath($this->cwd)
            : self::join($this->cwd, $current->name);
        return self::open($next, $lister, $this->sort, $this->showHidden);
    }

    public function moveCursor(int $delta): self
    {
        $count = count($this->entries);
        if ($count === 0) {
            return $this;
        }
        $next = max(0, min($count - 1, $this->cursor + $delta));
        return new self($this->cwd, $this->entries, $next, $this->selected, $this->sort, $this->showHidden);
    }

    public function gotoTop(): self
    {
        return new self($this->cwd, $this->entries, 0, $this->selected, $this->sort, $this->showHidden);
    }

    public function gotoBottom(): self
    {
        $last = max(0, count($this->entries) - 1);
        return new self($this->cwd, $this->entries, $last, $this->selected, $this->sort, $this->showHidden);
    }

    /** Toggle the entry under the cursor in/out of the selection set. */
    public function toggleSelection(): self
    {
        $entry = $this->currentEntry();
        if ($entry === null || $entry->isParentSentinel()) {
            return $this;
        }
        $sel = $this->selected;
        if (isset($sel[$entry->name])) {
            unset($sel[$entry->name]);
        } else {
            $sel[$entry->name] = true;
        }
        return new self($this->cwd, $this->entries, $this->cursor, $sel, $this->sort, $this->showHidden);
    }

    public function clearSelection(): self
    {
        return new self($this->cwd, $this->entries, $this->cursor, [], $this->sort, $this->showHidden);
    }

    /**
     * @param \Closure(string): list<Entry> $lister
     */
    public function setSort(Sort $sort, \Closure $lister): self
    {
        // Re-sort via re-listing rather than in-place: simpler and
        // keeps the parent sentinel handling consistent.
        return self::open($this->cwd, $lister, $sort, $this->showHidden);
    }

    /**
     * @param \Closure(string): list<Entry> $lister
     */
    public function toggleHidden(\Closure $lister): self
    {
        return self::open($this->cwd, $lister, $this->sort, !$this->showHidden);
    }

    public function currentEntry(): ?Entry
    {
        return $this->entries[$this->cursor] ?? null;
    }

    /** @return list<string> selected entry names */
    public function selectedNames(): array
    {
        return array_keys($this->selected);
    }

    /**
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    private static function filterHidden(array $entries, bool $showHidden): array
    {
        if ($showHidden) {
            return $entries;
        }
        return array_values(array_filter($entries, static fn(Entry $e) => !$e->isHidden));
    }

    public static function parentPath(string $cwd): string
    {
        $cwd = rtrim($cwd, '/');
        if ($cwd === '' || $cwd === '/') {
            return '/';
        }
        $parent = dirname($cwd);
        return $parent === '' ? '/' : $parent;
    }

    public static function join(string $cwd, string $name): string
    {
        $cwd = rtrim($cwd, '/');
        return ($cwd === '' ? '' : $cwd) . '/' . $name;
    }
}
