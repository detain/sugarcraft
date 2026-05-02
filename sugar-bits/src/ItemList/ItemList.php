<?php

declare(strict_types=1);

namespace CandyCore\Bits\ItemList;

use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Width;

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

    public function view(): string
    {
        $visible = $this->visibleItems();
        $count   = count($visible);
        $lines   = [];

        if ($this->title !== '') {
            $lines[] = $this->title;
        }
        if ($this->filtering || $this->filterText !== '') {
            $marker  = $this->filtering ? '/' : '/';
            $lines[] = $marker . $this->filterText;
        }
        if ($count === 0) {
            $lines[] = $this->filterText !== '' ? 'No matches.' : 'No items.';
            return implode("\n", $lines);
        }

        $top    = max(0, $this->offset);
        $window = array_slice($visible, $top, $this->height);
        foreach ($window as $i => $item) {
            $idx = $top + $i;
            $sel = $idx === $this->cursor;
            $title = $sel ? Ansi::sgr(Ansi::REVERSE) . $item->title() . Ansi::reset()
                          : '  ' . $item->title();
            // For selected, prefix '>'; for others, indent two spaces.
            $prefix = $sel ? '> ' : '  ';
            if ($sel) {
                // Replace the leading two-space indent with marker; SGR
                // wraps just the text.
                $title = $prefix . Ansi::sgr(Ansi::REVERSE) . $item->title() . Ansi::reset();
            }
            $lines[] = $title;
            if ($this->showDescription && $item->description() !== '') {
                $lines[] = '    ' . $item->description();
            }
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

    public function setSize(int $width, int $height): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('list width/height must be >= 0');
        }
        return $this->mutate(width: $width, height: $height)->reclamp();
    }

    public function withTitle(string $t): self           { return $this->mutate(title: $t); }
    public function withShowDescription(bool $on): self  { return $this->mutate(showDescription: $on); }

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
        $cursor = max(0, min($count - 1, $idx));
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
    ): self {
        return new self(
            items:           $items           ?? $this->items,
            cursor:          $cursor          ?? $this->cursor,
            offset:          $offset          ?? $this->offset,
            width:           $width           ?? $this->width,
            height:          $height          ?? $this->height,
            focused:         $focused         ?? $this->focused,
            title:           $title           ?? $this->title,
            filtering:       $filtering       ?? $this->filtering,
            filterText:      $filterText      ?? $this->filterText,
            showDescription: $showDescription ?? $this->showDescription,
        );
    }
}
