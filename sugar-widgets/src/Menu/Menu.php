<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Menu;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Width;

/**
 * A keyboard-driven menu over a tree of {@see MenuItem}s, with cascades
 * (submenus) and an optional dropdown layout under a menu bar.
 *
 * `Menu::new($tree)` starts on the first root item with no cascade open. The
 * cursor is an immutable {@see MenuCursor} path held by the instance, never
 * a static, so two menus, or two versions of one menu, never share it. Keys:
 *
 *  - Up and Down move within the active level and wrap at both ends;
 *  - Right opens the cascade of a branch item and does nothing on a leaf;
 *  - Enter opens the cascade of a branch item, and on a leaf returns a Cmd
 *    that sends a {@see MenuSelectedMsg} with the leaf's id and index path;
 *  - Left and Escape close the active cascade back to its parent item, and
 *    do nothing (no Cmd) on the root level.
 *
 * Every other key, a key release, and a Ctrl or Alt chord is ignored. Only a
 * leaf selection returns a Cmd. Like Confirm there is no focus gate: the host
 * routes keys to the menu while it is shown. The key map is the same in both
 * layouts.
 *
 * {@see view()} draws the open levels left to right. In the default layout
 * each level is a column, the cascade of an item starting on that item's row
 * beside it; the item under the cursor on every open level is marked `> `
 * and a branch carries a trailing `▸`. {@see withDropdown()} draws the root
 * level as a one-line bar instead, the item under the cursor in brackets,
 * and the open cascade as a dropdown starting on the line below, under its
 * bar item, with deeper cascades to its right. The view is a pure function
 * of the tree, the cursor and the layout flag, with trailing spaces trimmed.
 *
 * Immutable: every `with*()` and {@see update()} returns a new instance
 * through `mutate()` from sugar-core `Concerns\Mutable`; a key that changes
 * nothing returns the same instance.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no menu component,
 * so there is no upstream Mirrors citation.
 */
final class Menu implements Model
{
    use Mutable;

    private const MARK = '> ';
    private const NO_MARK = '  ';
    private const BRANCH = ' ▸';

    /**
     * @param list<MenuItem> $items
     */
    private function __construct(
        public readonly array $items,
        public readonly MenuCursor $cursor,
        public readonly bool $dropdown,
    ) {
    }

    /**
     * @param array<MenuItem> $tree the root level; non-MenuItem entries throw a \TypeError
     */
    public static function new(array $tree): self
    {
        return new self(MenuItem::listOf($tree), MenuCursor::new(), false);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * @return array{0: Menu, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || $msg instanceof KeyReleaseMsg || $msg->ctrl || $msg->alt) {
            return [$this, null];
        }
        return match ($msg->type) {
            KeyType::Up => [$this->move(-1), null],
            KeyType::Down => [$this->move(1), null],
            KeyType::Right => [$this->open(), null],
            KeyType::Enter => $this->activate(),
            KeyType::Left, KeyType::Escape => [$this->close(), null],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        if ($this->items === []) {
            return '';
        }
        $path = $this->cursor->path;
        $levels = $this->levels();
        /** @var array<int, string> $rows */
        $rows = [];

        if ($this->dropdown) {
            $bar = '';
            $x = 0;
            foreach ($this->items as $i => $item) {
                if ($i === $path[0]) {
                    $x = Width::string($bar);
                    $bar .= '[' . $item->label . ']';
                } else {
                    $bar .= ' ' . $item->label . ' ';
                }
            }
            $rows[0] = $bar;
            $y = 1;
            $first = 1;
        } else {
            $x = 0;
            $y = 0;
            $first = 0;
        }

        for ($depth = $first, $n = count($levels); $depth < $n; $depth++) {
            $cells = [];
            foreach ($levels[$depth] as $i => $item) {
                $cells[] = ($i === $path[$depth] ? self::MARK : self::NO_MARK)
                    . $item->label
                    . ($item->hasChildren() ? self::BRANCH : '');
            }
            $width = 0;
            foreach ($cells as $row => $cell) {
                $line = $rows[$y + $row] ?? '';
                $rows[$y + $row] = Width::padRight($line, $x) . $cell;
                $width = max($width, Width::string($cell));
            }
            $x += $width + 1;
            $y += $path[$depth];
        }

        $out = [];
        for ($r = 0, $last = max(array_keys($rows)); $r <= $last; $r++) {
            $out[] = rtrim($rows[$r] ?? '', ' ');
        }
        return implode("\n", $out);
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /** @return list<MenuItem> the root level */
    public function items(): array
    {
        return $this->items;
    }

    public function cursor(): MenuCursor
    {
        return $this->cursor;
    }

    /** True when {@see view()} draws the root level as a bar with dropdowns. */
    public function isDropdown(): bool
    {
        return $this->dropdown;
    }

    /** 0 on the root level, 1 inside the first cascade, and so on. */
    public function depth(): int
    {
        return $this->cursor->depth();
    }

    /** True while a cascade is open. */
    public function isOpen(): bool
    {
        return $this->cursor->depth() > 0;
    }

    /** @return list<MenuItem> the active level, the one Up and Down move in */
    public function level(): array
    {
        $levels = $this->levels();
        return $levels[count($levels) - 1];
    }

    /** The item under the cursor, or null for an empty menu. */
    public function current(): ?MenuItem
    {
        return $this->level()[$this->cursor->index()] ?? null;
    }

    /**
     * A new tree, with the cursor back on the first root item and every
     * cascade closed, since the old path may not exist in the new tree.
     *
     * @param array<MenuItem> $tree non-MenuItem entries throw a \TypeError
     */
    public function withItems(array $tree): self
    {
        return $this->mutate(['items' => MenuItem::listOf($tree), 'cursor' => MenuCursor::new()]);
    }

    /** Draw the root level as a menu bar and the open cascade as a dropdown below it. */
    public function withDropdown(bool $dropdown = true): self
    {
        return $this->mutate(['dropdown' => $dropdown]);
    }

    /**
     * The item lists from the root down to the active level, following the
     * cursor path.
     *
     * @return non-empty-list<list<MenuItem>>
     */
    private function levels(): array
    {
        $levels = [$this->items];
        $level = $this->items;
        $path = $this->cursor->path;
        for ($depth = 0, $last = count($path) - 1; $depth < $last; $depth++) {
            $level = $level[$path[$depth]]->children;
            $levels[] = $level;
        }
        return $levels;
    }

    private function move(int $delta): self
    {
        $count = count($this->level());
        if ($count === 0) {
            return $this;
        }
        $index = $this->cursor->index();
        $next = (($index + $delta) % $count + $count) % $count;
        if ($next === $index) {
            return $this;
        }
        return $this->mutate(['cursor' => $this->cursor->withIndex($next)]);
    }

    private function open(): self
    {
        if ($this->current()?->hasChildren() !== true) {
            return $this;
        }
        return $this->mutate(['cursor' => $this->cursor->descend()]);
    }

    private function close(): self
    {
        if ($this->cursor->depth() === 0) {
            return $this;
        }
        return $this->mutate(['cursor' => $this->cursor->ascend()]);
    }

    /**
     * @return array{0: Menu, 1: ?\Closure}
     */
    private function activate(): array
    {
        $item = $this->current();
        if ($item === null) {
            return [$this, null];
        }
        if ($item->hasChildren()) {
            return [$this->open(), null];
        }
        return [$this, Cmd::send(new MenuSelectedMsg($item->id, $item->label, $this->cursor->path))];
    }
}
