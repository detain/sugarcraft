<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

/**
 * Where the user is in the menu: which top-level menu is open (1-based, 0
 * when the menu is closed), which row is highlighted in every panel opened
 * down from it, and whether the deepest row's submenu is previewed without
 * having been entered ({@see hover()}).
 *
 * Immutable. {@see key()} is the whole interaction: it returns a
 * {@see MenuStep} — the next cursor plus, for a choice, the node chosen —
 * reading {@see MenuTree::rows()} only to skip separators and disabled rows.
 * Key spellings accept the canonical names and the vim aliases:
 * `up|k`, `down|j`, `right|l`, `left|h`, `enter|o`, `escape|q` (case
 * insensitive); anything else leaves the cursor untouched.
 *
 * The semantics pinned here, in Windows-95 house style:
 *  - up/down move within the deepest visible panel and wrap, skipping rows
 *    the cursor may not rest on; every move drops a hover preview;
 *  - right on a submenu row opens that submenu (entered, first selectable
 *    row highlighted); right on a leaf closes and opens the NEXT menu;
 *  - left ends a hover preview first, then pops one entered submenu, then —
 *    at menu depth — opens the PREVIOUS menu;
 *  - enter/o opens a submenu row, otherwise chooses a selectable leaf;
 *  - escape/q pops exactly one level: a preview, else the innermost entered
 *    submenu, else (at depth one) the whole menu closes;
 *  - the row indexes in `rows` are the 0-based dropdown indexes the marked
 *    twin turns into `menuitem:<dot-path>` click zones.
 */
final class MenuCursor
{
    /**
     * @param list<int> $rows one highlighted 0-based row per open panel below
     *                        the menu bar, innermost last; empty iff closed
     */
    private function __construct(
        public readonly int $menu,
        public readonly array $rows,
        public readonly bool $expanded,
    ) {
    }

    public static function closed(): self
    {
        return new self(0, [], false);
    }

    /**
     * Open the 1-based top-level menu, highlighting its first selectable row.
     * An out-of-range index yields the closed cursor.
     */
    public static function open(int $index, MenuTree $tree): self
    {
        $dropdown = $tree->rows([$index - 1]);

        if ($index < 1 || $index > count($tree->menus) || $dropdown === []) {
            return self::closed();
        }

        return new self($index, [self::firstSelectable($dropdown)], false);
    }

    public function isClosed(): bool
    {
        return $this->menu < 1;
    }

    /**
     * The path into the tree of the deepest highlighted row, in MenuTree
     * vocabulary: `[menu-1, r0, r1, ...]`. Empty for a closed cursor.
     *
     * @return list<int>
     */
    public function absolutePath(): array
    {
        return $this->isClosed() ? [] : [$this->menu - 1, ...$this->rows];
    }

    /**
     * Feed one key. A choice (Enter/o on a leaf) reports it in
     * {@see MenuStep::$chosen} and leaves the cursor where it is — closing
     * the menu is the caller's call, since the dispatched command may want
     * the tree gone or a notice printed in its place.
     */
    public function key(string $key, MenuTree $tree): MenuStep
    {
        if ($this->isClosed() || $this->rows === []) {
            return MenuStep::new($this);
        }

        return match (strtolower($key)) {
            'up', 'k' => MenuStep::new($this->move($tree, -1)),
            'down', 'j' => MenuStep::new($this->move($tree, 1)),
            'right', 'l' => $this->goRight($tree),
            'left', 'h' => MenuStep::new($this->goLeft($tree)),
            'enter', 'o' => $this->choose($tree),
            'escape', 'q' => MenuStep::new($this->escape()),
            default => MenuStep::new($this),
        };
    }

    /**
     * Point the cursor at $path (dropdown indexes below the menu bar) as a
     * mouse hover would: highlight it, and when it is a submenu row preview
     * that submenu WITHOUT entering it. A path that does not exist in $tree,
     * or lands on a row the cursor may not rest on, leaves the cursor alone.
     *
     * @param list<int> $path
     */
    public function hover(array $path, MenuTree $tree): self
    {
        if ($this->isClosed() || $path === []) {
            return $this;
        }

        $node = $tree->node([$this->menu - 1, ...$path]);

        if ($node === null || !$node->selectable()) {
            return $this;
        }

        return new self(
            $this->menu,
            $path,
            $node->kind === MenuNodeKind::Submenu,
        );
    }

    /**
     * Right: a submenu row is ENTERED (its first selectable row lights up,
     * depth grows); any other row is a leaf, and a leaf means "walk along
     * the menu bar" — close this menu, open the next.
     */
    private function goRight(MenuTree $tree): MenuStep
    {
        $tail = $tree->node($this->absolutePath());

        if ($tail !== null && $tail->kind === MenuNodeKind::Submenu && $tail->enabled) {
            $children = $tree->rows($this->absolutePath());

            if ($children !== []) {
                return MenuStep::new(new self(
                    $this->menu,
                    [...$this->rows, self::firstSelectable($children)],
                    false,
                ));
            }
        }

        return MenuStep::new($this->openSibling($tree, 1));
    }

    /**
     * Left: end a preview before it is popped — an entered submenu is only
     * left behind once no preview covers it. At menu depth with nothing to
     * pop, walk the bar backwards (mirrors {@see goRight()}).
     */
    private function goLeft(MenuTree $tree): self
    {
        if ($this->expanded) {
            return new self($this->menu, $this->rows, false);
        }

        if (count($this->rows) > 1) {
            return new self($this->menu, array_slice($this->rows, 0, -1), false);
        }

        return $this->openSibling($tree, -1);
    }

    /** Enter/o: open a submenu, choose a leaf, ignore anything else. */
    private function choose(MenuTree $tree): MenuStep
    {
        $tail = $tree->node($this->absolutePath());

        if ($tail === null) {
            return MenuStep::new($this);
        }

        if ($tail->kind === MenuNodeKind::Submenu) {
            return $tail->enabled ? $this->goRight($tree) : MenuStep::new($this);
        }

        return $tail->selectable()
            ? MenuStep::new($this, $tail)
            : MenuStep::new($this);
    }

    /** Escape/q: preview, then entered panel, then the whole menu — one step per press. */
    private function escape(): self
    {
        if ($this->expanded) {
            return new self($this->menu, $this->rows, false);
        }

        if (count($this->rows) > 1) {
            return new self($this->menu, array_slice($this->rows, 0, -1), false);
        }

        return self::closed();
    }

    /** Move inside the deepest visible panel, wrapping and skipping unselectable rows. */
    private function move(MenuTree $tree, int $direction): self
    {
        $parent = array_slice($this->absolutePath(), 0, -1);
        $panel = $tree->rows($parent);
        $count = count($panel);
        $current = $this->rows[count($this->rows) - 1] ?? 0;

        if ($count === 0 || $current >= $count) {
            return $this;
        }

        for ($hop = 1; $hop <= $count; $hop++) {
            $index = ((($current + $direction * $hop) % $count) + $count) % $count;

            if ($panel[$index]->selectable()) {
                return new self($this->menu, [...array_slice($this->rows, 0, -1), $index], false);
            }
        }

        return $this;
    }

    /** Swap the open menu for its neighbour, wrapping around the bar. */
    private function openSibling(MenuTree $tree, int $direction): self
    {
        $total = count($tree->menus);

        if ($total === 0) {
            return self::closed();
        }

        $index = ((($this->menu - 1 + $direction) % $total) + $total) % $total;
        $dropdown = $tree->rows([$index]);

        if ($dropdown === []) {
            return $this;
        }

        return new self($index + 1, [self::firstSelectable($dropdown)], false);
    }

    /** @param list<MenuNode> $panel */
    private static function firstSelectable(array $panel): int
    {
        foreach ($panel as $index => $node) {
            if ($node->selectable()) {
                return $index;
            }
        }

        return 0;
    }
}
