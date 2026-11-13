<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Core\Concerns\Mutable;

/**
 * One half of the dual-pane file manager.
 *
 * No upstream — house pattern. yorukot/superfile has no Pane symbol;
 * the twin-column layout evokes Midnight Commander, but this class
 * (and its transitions) are the TEA model layer, not a port.
 *
 * Holds the current working directory, the listed entries, the
 * cursor position (which entry is highlighted), the multi-select
 * set (a Set of entry names — strings, not indices, so a refresh
 * doesn't break selection), and the active sort order.
 *
 * Immutable: every transition (`navigate`, `moveCursor`,
 * `toggleSelection`, `withSort`) returns a fresh Pane so the
 * surrounding {@see Manager} can return `[nextManager, ?cmd]`
 * cleanly.
 *
 * Filesystem reads happen via an injected `\Closure(string $path):
 * list<Entry>|null` — tests pass a closure returning a fixed list, prod
 * passes an FsLister lister (`scandir` + `lstat`: size and mtime
 * always describe the link itself, never the followed target).
 * Decoupling the I/O lets the entire transition layer be tested
 * with no tmp-dir setup or filesystem fixtures.
 *
 * CF-22: a null listing is the lister saying "cannot read" — the
 * `readable` flag below carries that truth into the UI instead of
 * collapsing it to a fake empty directory.
 */
final class Pane
{
    use Mutable;

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
        public readonly bool $readable = true,
    ) {
        $this->entries = $entries;
        $this->selected = $selected;
    }

    /**
     * Build a fresh Pane for `$cwd` by listing entries via
     * `$lister` and applying the current sort order.
     *
     * CF-22: `$lister` answering null means "cannot read" — the pane is
     * built with only the escape route (the `..` sentinel when there is
     * one) and `readable = false`, so the UI can say unreadable instead
     * of lying "empty".
     *
     * @param \Closure(string): list<Entry>|null $lister
     */
    public static function open(string $cwd, \Closure $lister, Sort $sort = Sort::NameAsc, bool $showHidden = false): self
    {
        $listed = $lister($cwd);
        if ($listed === null) {
            $fallback = $cwd !== '/' && $cwd !== '' ? [Entry::parent()] : [];
            return new self($cwd, $fallback, 0, [], $sort, $showHidden, false);
        }
        $entries = self::filterHidden($listed, $showHidden);
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
     * CF-22: if the target answers null (unreadable), the move is
     * REFUSED and this listing is retained with `readable = false` —
     * the pane never strands the user inside a directory it cannot
     * show, and the caller can name the refusal in the status line.
     *
     * @param \Closure(string): list<Entry>|null $lister
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
        $moved = self::open($next, $lister, $this->sort, $this->showHidden);
        if (!$moved->readable) {
            return $this->unreadable();
        }
        return $moved;
    }

    /**
     * A copy of this pane carrying the same listing with the
     * "cannot read" truth attached (CF-22). The preserved entries
     * are the LAST HONEST READ, not a phantom: the directory simply
     * stopped answering between reads.
     */
    public function unreadable(): self
    {
        return $this->mutate(['readable' => false]);
    }

    /**
     * Re-read this pane's directory IN PLACE, preserving the selection and
     * cursor where they still make sense: a selected name survives only if
     * the entry still exists, and the cursor stays on the entry it was on
     * (falling back to a clamped index).
     *
     * This is the refresh/toggleHidden transition. Pane::open() is for
     * ENTERING a (new) directory and legitimately starts empty; using it
     * for re-reads silently wiped user selection on every `r` and `.`.
     *
     * CF-22: a null answer (the directory stopped answering — deleted,
     * unmounted, chmod'ed away under us) RETAINS this listing and marks
     * the pane unreadable, rather than serving a lying "empty" pane.
     *
     * @param \Closure(string): list<Entry>|null $lister
     * @param bool|null $showHidden new hidden-visibility state (default: keep)
     */
    public function relist(\Closure $lister, ?bool $showHidden = null): self
    {
        $show = $showHidden ?? $this->showHidden;
        $listed = $lister($this->cwd);
        if ($listed === null) {
            return $this->unreadable();
        }
        $entries = self::filterHidden($listed, $show);
        if ($this->cwd !== '/' && $this->cwd !== '') {
            $entries = array_merge([Entry::parent()], $entries);
        }
        $entries = $this->sort->apply($entries);

        $positions = [];
        foreach ($entries as $i => $entry) {
            $positions[$entry->name] = $i;
        }
        $selected = [];
        foreach (array_keys($this->selected) as $name) {
            if (isset($positions[$name])) {
                $selected[$name] = true;
            }
        }
        $underCursor = $this->currentEntry();
        $cursor = $underCursor !== null && isset($positions[$underCursor->name])
            ? $positions[$underCursor->name]
            : max(0, min($this->cursor, count($entries) - 1));

        return new self($this->cwd, $entries, $cursor, $selected, $this->sort, $show);
    }

    public function moveCursor(int $delta): self
    {
        $count = count($this->entries);
        if ($count === 0) {
            return $this;
        }
        $next = max(0, min($count - 1, $this->cursor + $delta));
        return $this->mutate(['cursor' => $next]);
    }

    public function gotoTop(): self
    {
        return $this->mutate(['cursor' => 0]);
    }

    public function gotoBottom(): self
    {
        return $this->mutate(['cursor' => max(0, count($this->entries) - 1)]);
    }

    /**
     * Position the cursor on the entry with the given name, if found.
     * Used to restore cursor position after goUp to the directory we
     * came from.
     */
    public function cursorOnName(string $name): self
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry->name === $name) {
                return $this->mutate(['cursor' => $i]);
            }
        }
        return $this;
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
        return $this->mutate(['selected' => $sel]);
    }

    public function clearSelection(): self
    {
        return $this->mutate(['selected' => []]);
    }

    /**
     * Replace the selection with the given name→true map, keeping only
     * names that are actually listed here right now (CF-31). The parent
     * sentinel can never be marked — it is navigation furniture, not a
     * file, and every operation walks real entries.
     */
    public function withSelected(array $wanted): self
    {
        $selectable = [];
        foreach ($this->entries as $entry) {
            if (!$entry->isParentSentinel()) {
                $selectable[$entry->name] = true;
            }
        }
        $kept = array_intersect_key($wanted, $selectable);
        return $this->mutate(['selected' => $kept]);
    }

    /**
     * CF-31 `Ctrl+a`: mark every listed entry. The acceptance wording is
     * "operating on 30-of-40 stops meaning Space 30 times".
     */
    public function selectAll(): self
    {
        $selected = [];
        foreach ($this->entries as $entry) {
            if (!$entry->isParentSentinel()) {
                $selected[$entry->name] = true;
            }
        }
        return $this->mutate(['selected' => $selected]);
    }

    /**
     * CF-31 `v`: flip every mark against every listed entry — the
     * complement operation, not a clear. Round-tripping it returns the
     * original set (pinned).
     */
    public function invertSelection(): self
    {
        $selected = [];
        foreach ($this->entries as $entry) {
            if ($entry->isParentSentinel()) {
                continue;
            }
            if (!isset($this->selected[$entry->name])) {
                $selected[$entry->name] = true;
            }
        }
        return $this->mutate(['selected' => $selected]);
    }

    /**
     * Re-order the CURRENT in-memory listing under a new sort — no
     * disk rescan by design. The old signature's required `\Closure
     * $lister` param was never read (finding 065): it invited a future
     * "fix" into silently rescanning, so it is dropped. Refresh-first
     * callers use relist().
     */
    public function withSort(Sort $sort): self
    {
        // Re-order in-memory entries: strip the parent sentinel,
        // sort the rest, re-prepend it.
        $parent = null;
        $rest = [];
        foreach ($this->entries as $e) {
            if ($e->isParentSentinel()) {
                $parent = $e;
                continue;
            }
            $rest[] = $e;
        }
        $sorted = $sort->apply($rest);
        $entries = $parent !== null ? array_merge([$parent], $sorted) : $sorted;
        return $this->mutate(['sort' => $sort, 'entries' => $entries, 'cursor' => 0]);
    }

    /**
     * Flip hidden-file visibility and re-read the directory IN PLACE.
     *
     * relist() (not open()): toggling `.` must not silently drop the
     * multi-selection for the entries that remain listed, nor teleport the
     * cursor to the top of the pane.
     *
     * @param \Closure(string): list<Entry>|null $lister
     */
    public function toggleHidden(\Closure $lister): self
    {
        return $this->relist($lister, !$this->showHidden);
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
        return $cwd . '/' . $name;
    }
}
