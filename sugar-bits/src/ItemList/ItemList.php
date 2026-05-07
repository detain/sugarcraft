<?php

declare(strict_types=1);

namespace SugarCraft\Bits\ItemList;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Selectable, scrollable, filterable list of {@see Item}s.
 *
 * Modes:
 *
 * - **Normal** — `↑/k`, `↓/j`, `Home/g`, `End/G`, `PgUp/PgDn`. Press `/`
 *   to enter filtering mode.
 * - **Filtering** — keystrokes append to the filter string (case-
 *   insensitive substring match against {@see Item::filterValue()}).
 *   `Backspace` deletes the last char; `Esc` clears the filter and
 *   returns to normal mode; `Enter` exits filtering but keeps the
 *   results.
 *
 * The visible window is sized by {@see $height}; entries scroll under
 * the cursor automatically. Selection (`{@see selectedItem()}`) returns
 * the highlighted item from the *filtered* view.
 */
final class ItemList implements Model
{
    private function __construct(
        /** @var list<Item> */
        public readonly array $items,
        public readonly int $cursor,
        public readonly int $offset,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        public readonly string $title,
        public readonly bool $filtering,
        public readonly string $filterText,
        public readonly bool $showDescription,
        public readonly bool $showStatusBar = true,
        public readonly bool $showHelp = true,
        public readonly bool $showFilter = true,
        public readonly bool $infiniteScrolling = false,
        public readonly string $statusMessage = '',
        public readonly float $statusMessageExpiresAt = 0.0,
        public readonly float $statusMessageLifetime = 1.0,
        public readonly string $cursorPrefix     = '> ',
        public readonly string $unselectedPrefix = '  ',
    ) {}

    /**
     * @param list<Item> $items
     */
    public static function new(array $items = [], int $width = 60, int $height = 10): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('list width/height must be >= 0');
        }
        return new self(
            items: array_values($items),
            cursor: 0,
            offset: 0,
            width: $width,
            height: $height,
            focused: false,
            title: '',
            filtering: false,
            filterText: '',
            showDescription: true,
        );
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }

        if ($this->filtering) {
            return [$this->updateFilter($msg), null];
        }

        return match (true) {
            $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => [$this->moveCursor($this->cursor - 1), null],
            $msg->type === KeyType::Down
                || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => [$this->moveCursor($this->cursor + 1), null],
            $msg->type === KeyType::Home
                || ($msg->type === KeyType::Char && $msg->rune === 'g')
                => [$this->moveCursor(0), null],
            $msg->type === KeyType::End
                || ($msg->type === KeyType::Char && $msg->rune === 'G')
                => [$this->moveCursor(PHP_INT_MAX), null],
            $msg->type === KeyType::PageUp
                => [$this->moveCursor($this->cursor - max(1, $this->height)), null],
            $msg->type === KeyType::PageDown
                => [$this->moveCursor($this->cursor + max(1, $this->height)), null],
            $msg->type === KeyType::Char && $msg->rune === '/'
                => [$this->mutate(filtering: true, filterText: ''), null],
            default => [$this, null],
        };
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $visible = $this->visibleItems();
        $count   = count($visible);
        $lines   = [];

        if ($this->title !== '') {
            $lines[] = $this->title;
        }
        if ($this->showFilter && ($this->filtering || $this->filterText !== '')) {
            $lines[] = '/' . $this->filterText;
        }
        if ($count === 0) {
            $lines[] = $this->filterText !== '' ? 'No matches.' : 'No items.';
            if ($this->showStatusBar && ($status = $this->status()) !== '') {
                $lines[] = $status;
            }
            return implode("\n", $lines);
        }

        $top    = max(0, $this->offset);
        $window = array_slice($visible, $top, $this->height);
        foreach ($window as $i => $item) {
            $idx = $top + $i;
            $sel = $idx === $this->cursor;
            // For selected, render the cursor prefix; for others, the unselected prefix.
            if ($sel) {
                $title = $this->cursorPrefix . Ansi::sgr(Ansi::REVERSE) . $item->title() . Ansi::reset();
            } else {
                $title = $this->unselectedPrefix . $item->title();
            }
            $lines[] = $title;
            if ($this->showDescription && $item->description() !== '') {
                $lines[] = '    ' . $item->description();
            }
        }
        if ($this->showStatusBar && ($status = $this->status()) !== '') {
            $lines[] = $status;
        }
        return implode("\n", $lines);
    }

    public function selectedItem(): ?Item
    {
        $visible = $this->visibleItems();
        return $visible[$this->cursor] ?? null;
    }

    /** @return list<Item> */
    public function visibleItems(): array
    {
        if ($this->filterText === '') {
            return $this->items;
        }
        $needle = mb_strtolower($this->filterText, 'UTF-8');
        return array_values(array_filter(
            $this->items,
            static fn(Item $i) => str_contains(mb_strtolower($i->filterValue(), 'UTF-8'), $needle),
        ));
    }

    public function index(): int
    {
        return $this->cursor;
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        return [$this->mutate(focused: true), null];
    }

    /** Release focus; companion to { focus()}. */
    public function blur(): self
    {
        return $this->mutate(focused: false);
    }

    /** @param list<Item> $items */
    public function setItems(array $items): self
    {
        $clone = $this->mutate(items: array_values($items));
        return $clone->reclamp();
    }

    /**
     * Replace a single item in place. Negative indices count from the
     * end (Pythonic). Out-of-range indices are silently ignored, matching
     * upstream Bubbles' `SetItem`.
     */
    public function setItem(int $index, Item $item): self
    {
        $count = count($this->items);
        if ($count === 0) {
            return $this;
        }
        if ($index < 0) {
            $index += $count;
        }
        if ($index < 0 || $index >= $count) {
            return $this;
        }
        $items = $this->items;
        $items[$index] = $item;
        return $this->mutate(items: array_values($items));
    }

    /**
     * Insert one or more items at the given position. Negative indices
     * count from the end; an index >= count appends. Cursor / offset are
     * re-clamped against the new length.
     */
    public function insertItem(int $index, Item ...$items): self
    {
        if ($items === []) {
            return $this;
        }
        $count = count($this->items);
        if ($index < 0) {
            $index = max(0, $count + $index);
        }
        $index = min($count, max(0, $index));
        $merged = array_merge(
            array_slice($this->items, 0, $index),
            array_values($items),
            array_slice($this->items, $index),
        );
        return $this->mutate(items: array_values($merged))->reclamp();
    }

    /**
     * Remove the item at the given index. Negative indices count from
     * the end. Out-of-range indices are silently ignored. Cursor stays
     * on the same logical position where possible (clamps when removing
     * the last visible item).
     */
    public function removeItem(int $index): self
    {
        $count = count($this->items);
        if ($count === 0) {
            return $this;
        }
        if ($index < 0) {
            $index += $count;
        }
        if ($index < 0 || $index >= $count) {
            return $this;
        }
        $items = $this->items;
        array_splice($items, $index, 1);
        return $this->mutate(items: array_values($items))->reclamp();
    }

    /** @return list<Item> */
    public function items(): array
    {
        return $this->items;
    }

    /** Move cursor up `$n` rows. Mirrors Bubbles' `CursorUp`. */
    public function cursorUp(int $n = 1): self
    {
        return $this->moveCursor($this->cursor - max(1, $n));
    }

    /** Move cursor down `$n` rows. Mirrors Bubbles' `CursorDown`. */
    public function cursorDown(int $n = 1): self
    {
        return $this->moveCursor($this->cursor + max(1, $n));
    }

    /** Jump to row 0. Mirrors `GoToStart`. */
    public function goToStart(): self { return $this->moveCursor(0); }

    /** Jump to last row. Mirrors `GoToEnd`. */
    public function goToEnd(): self   { return $this->moveCursor(PHP_INT_MAX); }

    /** Page back by `$height` rows. Mirrors `PrevPage`. */
    public function prevPage(): self
    {
        return $this->moveCursor($this->cursor - max(1, $this->height));
    }

    /** Page forward by `$height` rows. Mirrors `NextPage`. */
    public function nextPage(): self
    {
        return $this->moveCursor($this->cursor + max(1, $this->height));
    }

    /**
     * Move the cursor directly to `$index` (0-based, clamped).
     * Mirrors Bubbles' `Select(int)`.
     */
    public function select(int $index): self
    {
        return $this->moveCursor($index);
    }

    /** Reset the selection to row 0. Mirrors `ResetSelected`. */
    public function resetSelected(): self
    {
        return $this->moveCursor(0);
    }

    /**
     * Drop the active filter text and exit filter mode. Mirrors
     * Bubbles' `ResetFilter`. Equivalent to {@see clearFilter()}.
     */
    public function resetFilter(): self
    {
        return $this->clearFilter();
    }

    /** True when a filter is currently typed. Mirrors `IsFiltered`. */
    public function isFiltered(): bool
    {
        return $this->filterText !== '';
    }

    /** Currently typed filter text. Mirrors `FilterValue`. */
    public function filterValue(): string
    {
        return $this->filterText;
    }

    /**
     * True when the user is actively typing into the filter buffer
     * (vs viewing filtered results). Mirrors `SettingFilter`.
     */
    public function settingFilter(): bool
    {
        return $this->filtering;
    }

    public function setSize(int $width, int $height): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('list width/height must be >= 0');
        }
        return $this->mutate(width: $width, height: $height)->reclamp();
    }

    public function withTitle(string $t): self           { return $this->mutate(title: $t); }
    public function withShowDescription(bool $on): self  { return $this->mutate(showDescription: $on); }
    public function withShowStatusBar(bool $on): self    { return $this->mutate(showStatusBar: $on); }
    public function withShowHelp(bool $on): self         { return $this->mutate(showHelp: $on); }
    public function withShowFilter(bool $on): self       { return $this->mutate(showFilter: $on); }
    public function withInfiniteScrolling(bool $on): self { return $this->mutate(infiniteScrolling: $on); }

    /**
     * Glyph rendered before the highlighted (cursor) item. Default '> '
     * (two cells: arrow + space). The string is taken verbatim — pass
     * the trailing space yourself if you want one.
     */
    public function withCursorPrefix(string $glyph): self
    {
        return $this->mutate(cursorPrefix: $glyph);
    }

    /**
     * Glyph rendered before non-cursor items. Default '  ' (two
     * spaces, matching the cursor prefix's width).
     */
    public function withUnselectedPrefix(string $glyph): self
    {
        return $this->mutate(unselectedPrefix: $glyph);
    }

    /**
     * Default lifetime (in seconds) of a status message set via
     * {@see newStatusMessage()}. Default 1.0s.
     */
    public function withStatusMessageLifetime(float $seconds): self
    {
        return $this->mutate(statusMessageLifetime: max(0.0, $seconds));
    }

    /**
     * Set a transient status message rendered in the status bar.
     * Expires `statusMessageLifetime` seconds later (the model expires
     * it on its next view() / status accessor; callers driving via
     * Cmds should pair this with `Cmd::tick()` for a hard refresh).
     */
    public function newStatusMessage(string $msg): self
    {
        return $this->mutate(
            statusMessage: $msg,
            statusMessageExpiresAt: microtime(true) + max(0.0, $this->statusMessageLifetime),
        );
    }

    /**
     * Status string rendered in the status bar — composes the cursor
     * position, total count, filter state, and any active transient
     * status message (only while it hasn't expired).
     */
    public function status(): string
    {
        $parts = [];
        $count = count($this->visibleItems());
        if ($count > 0) {
            $parts[] = ($this->cursor + 1) . '/' . $count;
        }
        if ($this->filterText !== '') {
            $parts[] = '"' . $this->filterText . '"';
        }
        if ($this->statusMessage !== '' && microtime(true) < $this->statusMessageExpiresAt) {
            $parts[] = $this->statusMessage;
        }
        return implode(' • ', $parts);
    }

    public function clearFilter(): self
    {
        return $this->mutate(filtering: false, filterText: '', cursor: 0, offset: 0);
    }

    public function isFiltering(): bool
    {
        return $this->filtering;
    }

    // ---- internals ---------------------------------------------------

    private function updateFilter(KeyMsg $msg): self
    {
        return match (true) {
            $msg->type === KeyType::Escape
                => $this->clearFilter(),
            $msg->type === KeyType::Enter
                => $this->mutate(filtering: false),
            $msg->type === KeyType::Up
                => $this->moveCursor($this->cursor - 1),
            $msg->type === KeyType::Down
                => $this->moveCursor($this->cursor + 1),
            $msg->type === KeyType::PageUp
                => $this->moveCursor($this->cursor - max(1, $this->height)),
            $msg->type === KeyType::PageDown
                => $this->moveCursor($this->cursor + max(1, $this->height)),
            $msg->type === KeyType::Backspace
                => $this->mutate(
                    filterText: mb_substr($this->filterText, 0, -1, 'UTF-8'),
                    cursor: 0, offset: 0,
                ),
            $msg->type === KeyType::Char && !$msg->ctrl
                => $this->mutate(
                    filterText: $this->filterText . $msg->rune,
                    cursor: 0, offset: 0,
                ),
            $msg->type === KeyType::Space
                => $this->mutate(
                    filterText: $this->filterText . ' ',
                    cursor: 0, offset: 0,
                ),
            default => $this,
        };
    }

    private function moveCursor(int $idx): self
    {
        $count = count($this->visibleItems());
        if ($count === 0) {
            return $this->mutate(cursor: 0, offset: 0);
        }
        if ($this->infiniteScrolling) {
            // Wrap cursor instead of clamping.
            $cursor = (($idx % $count) + $count) % $count;
        } else {
            $cursor = max(0, min($count - 1, $idx));
        }
        $offset = $this->offset;
        if ($cursor < $offset) {
            $offset = $cursor;
        }
        if ($this->height > 0 && $cursor >= $offset + $this->height) {
            $offset = $cursor - $this->height + 1;
        }
        return $this->mutate(cursor: $cursor, offset: max(0, $offset));
    }

    private function reclamp(): self
    {
        return $this->moveCursor($this->cursor);
    }

    /** @param list<Item>|null $items */
    private function mutate(
        ?array $items = null,
        ?int $cursor = null,
        ?int $offset = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $focused = null,
        ?string $title = null,
        ?bool $filtering = null,
        ?string $filterText = null,
        ?bool $showDescription = null,
        ?bool $showStatusBar = null,
        ?bool $showHelp = null,
        ?bool $showFilter = null,
        ?bool $infiniteScrolling = null,
        ?string $statusMessage = null,
        ?float $statusMessageExpiresAt = null,
        ?float $statusMessageLifetime = null,
        ?string $cursorPrefix = null,
        ?string $unselectedPrefix = null,
    ): self {
        return new self(
            items:                  $items                  ?? $this->items,
            cursor:                 $cursor                 ?? $this->cursor,
            offset:                 $offset                 ?? $this->offset,
            width:                  $width                  ?? $this->width,
            height:                 $height                 ?? $this->height,
            focused:                $focused                ?? $this->focused,
            title:                  $title                  ?? $this->title,
            filtering:              $filtering              ?? $this->filtering,
            filterText:             $filterText             ?? $this->filterText,
            showDescription:        $showDescription        ?? $this->showDescription,
            showStatusBar:          $showStatusBar          ?? $this->showStatusBar,
            showHelp:               $showHelp               ?? $this->showHelp,
            showFilter:             $showFilter             ?? $this->showFilter,
            infiniteScrolling:      $infiniteScrolling      ?? $this->infiniteScrolling,
            statusMessage:          $statusMessage          ?? $this->statusMessage,
            statusMessageExpiresAt: $statusMessageExpiresAt ?? $this->statusMessageExpiresAt,
            statusMessageLifetime:  $statusMessageLifetime  ?? $this->statusMessageLifetime,
            cursorPrefix:           $cursorPrefix           ?? $this->cursorPrefix,
            unselectedPrefix:       $unselectedPrefix       ?? $this->unselectedPrefix,
        );
    }
}
