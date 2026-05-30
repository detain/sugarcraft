<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Model;

use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\MatchResult;

/**
 * Variant of {@see ChooseModel} that opens directly in filter mode and
 * sends every keystroke to the inner {@see ItemList}'s filter buffer.
 * Enter on the highlighted result submits; Esc / Ctrl-C aborts.
 *
 * When $fuzzy is true, uses SmithWatermanMatcher for scored fuzzy matching
 * with highlight indices instead of substring matching.
 */
final class FilterModel implements Model
{
    private SmithWatermanMatcher $matcher;

    /**
     * @param list<string> $options
     * @param list<string> $preselected
     */
    public static function fromOptions(
        array $options,
        int $height = 10,
        int $limit = 1,
        bool $noLimit = false,
        string $header = '',
        array $preselected = [],
        bool $reverse = false,
        string $value = '',
        ?string $cursorPrefix = null,
        ?string $unselectedPrefix = null,
        bool $fuzzy = false,
    ): self {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        $list  = ItemList::new($items, 60, max(1, $height))->withShowDescription(false);
        if ($cursorPrefix !== null) {
            $list = $list->withCursorPrefix($cursorPrefix);
        }
        if ($unselectedPrefix !== null) {
            $list = $list->withUnselectedPrefix($unselectedPrefix);
        }
        if ($header !== '') {
            $list = $list->withTitle($header);
        }
        [$list, ] = $list->focus();
        // Enter filter mode immediately by simulating the '/' keystroke.
        [$list, ] = $list->update(new KeyMsg(KeyType::Char, '/'));
        // Pre-fill the filter buffer if the caller supplied a value.
        foreach (str_split($value) as $ch) {
            if ($ch === ' ') {
                [$list, ] = $list->update(new KeyMsg(KeyType::Space, ' '));
            } else {
                [$list, ] = $list->update(new KeyMsg(KeyType::Char, $ch));
            }
        }
        $multi = $noLimit || $limit !== 1;
        $cap = $noLimit ? 0 : max(0, $limit);
        $checked = [];
        if ($multi && $preselected !== []) {
            $set = array_flip($preselected);
            foreach ($options as $i => $o) {
                if (isset($set[$o])) {
                    $checked[$i] = true;
                }
            }
        }
        $self = new self(
            list: $list,
            submitted: false,
            aborted: false,
            multi: $multi,
            limit: $cap,
            checked: $checked,
            reverse: $reverse,
            fuzzy: $fuzzy,
            allItems: $items,
            fuzzyResults: [],
        );
        $self->matcher = new SmithWatermanMatcher();
        if ($fuzzy && $value !== '') {
            $self = $self->recomputeFuzzy($self, $list->filterValue());
        }
        return $self;
    }

    /** @param list<Item> */
    private function __construct(
        public readonly ItemList $list,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly bool $multi    = false,
        public readonly int $limit     = 1,
        public readonly array $checked = [],
        public readonly bool $reverse  = false,
        public readonly bool $fuzzy    = false,
        public readonly array $allItems = [],
        public readonly array $fuzzyResults = [],
    ) {
        $this->matcher = new SmithWatermanMatcher();
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
        if ($this->submitted || $this->aborted) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this->copy(aborted: true), Cmd::quit()];
            }
            if ($msg->type === KeyType::Enter) {
                $visible = $this->fuzzy ? $this->fuzzyVisibleItems() : $this->list->visibleItems();
                if ($visible === []) {
                    return [$this, null];
                }
                return [$this->copy(submitted: true), Cmd::quit()];
            }
            // Multi-select: Tab toggles the highlight (Space is consumed
            // by the inner filter buffer).
            if ($this->multi && $msg->type === KeyType::Tab) {
                return [$this->toggle(), null];
            }
        }
        [$next, $cmd] = $this->list->update($msg);

        $filterText = $next->filterValue();
        if ($this->fuzzy && $filterText !== '') {
            $newResults = $this->computeFuzzyResults($filterText);
            return [$this->copy(list: $next, fuzzyResults: $newResults), $cmd];
        } elseif (!$this->fuzzy || $filterText === '') {
            return [$this->copy(list: $next, fuzzyResults: []), $cmd];
        }

        return [$this->copy(list: $next, fuzzyResults: $this->fuzzyResults), $cmd];
    }

    /**
     * @return array<int, MatchResult>
     */
    private function computeFuzzyResults(string $filterText): array
    {
        $candidates = array_map(static fn(Item $i) => $i->filterValue(), $this->allItems);
        $matches = $this->matcher->matchAll($filterText, $candidates);

        $indices = [];
        foreach ($matches as $match) {
            $haystack = $match->haystack;
            foreach ($this->allItems as $idx => $item) {
                if ($item->filterValue() === $haystack) {
                    $indices[$idx] = $match;
                    break;
                }
            }
        }

        return $indices;
    }

    private function recomputeFuzzy(self $self, string $filterText): self
    {
        $candidates = array_map(static fn(Item $i) => $i->filterValue(), $self->allItems);
        $matches = $self->matcher->matchAll($filterText, $candidates);

        $indices = [];
        foreach ($matches as $match) {
            $haystack = $match->haystack;
            foreach ($self->allItems as $idx => $item) {
                if ($item->filterValue() === $haystack) {
                    $indices[$idx] = $match;
                    break;
                }
            }
        }

        return $self->copy(fuzzyResults: $indices);
    }

    /** @return list<Item> */
    public function fuzzyVisibleItems(): array
    {
        if ($this->fuzzyResults === []) {
            return $this->allItems;
        }
        $result = [];
        $originalIndices = array_keys($this->fuzzyResults);
        sort($originalIndices, SORT_NUMERIC);
        foreach ($originalIndices as $idx) {
            $result[] = $this->allItems[$idx];
        }
        return $result;
    }

    public function view(): string
    {
        $body = $this->list->view();
        if ($this->multi) {
            $count = count(array_filter($this->checked));
            $cap = $this->limit > 0 ? "/{$this->limit}" : '';
            $body .= "\n[" . $count . $cap . " selected]";
        }
        return $body;
    }

    public function selected(): ?string
    {
        if (!$this->submitted || $this->multi) {
            return null;
        }
        $item = $this->fuzzy ? $this->fuzzySelectedItem() : $this->list->selectedItem();
        return $item?->title();
    }

    private function fuzzySelectedItem(): ?Item
    {
        if ($this->fuzzyResults === []) {
            return $this->allItems[0] ?? null;
        }
        $cursor = $this->list->index();
        $visible = $this->fuzzyVisibleItems();
        return $visible[$cursor] ?? null;
    }

    /** @return list<string> */
    public function selectedAll(): array
    {
        if (!$this->submitted || !$this->multi) {
            return [];
        }
        $items = $this->fuzzy ? $this->fuzzyVisibleItems() : $this->list->items;
        $out = [];
        ksort($this->checked, SORT_NUMERIC);
        foreach ($this->checked as $idx => $on) {
            if ($on && isset($items[$idx])) {
                $out[] = $items[$idx]->title();
            }
        }
        if ($this->reverse) {
            $out = array_reverse($out);
        }
        return $out;
    }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function isMulti(): bool     { return $this->multi; }

    /**
     * Returns the highlight indices for the currently selected item.
     * @return list<int>
     */
    public function highlightIndices(): array
    {
        if (!$this->fuzzy || $this->fuzzyResults === []) {
            return [];
        }
        $cursor = $this->list->index();
        $visible = $this->fuzzyVisibleItems();
        if (!isset($visible[$cursor])) {
            return [];
        }
        $selectedTitle = $visible[$cursor]->title();
        foreach ($this->fuzzyResults as $idx => $match) {
            if ($this->allItems[$idx]->title() === $selectedTitle) {
                return $match->indices();
            }
        }
        return [];
    }

    private function toggle(): self
    {
        $visible = $this->fuzzy ? $this->fuzzyVisibleItems() : $this->list->visibleItems();
        if ($visible === []) {
            return $this;
        }
        $cursor = $this->list->index();
        $title  = $visible[$cursor]->title();
        $idx = null;
        foreach ($this->allItems as $i => $item) {
            if ($item->title() === $title) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return $this;
        }
        $checked = $this->checked;
        if (isset($checked[$idx])) {
            unset($checked[$idx]);
        } else {
            if ($this->limit > 0 && count(array_filter($checked)) >= $this->limit) {
                return $this;
            }
            $checked[$idx] = true;
        }
        return $this->copy(checked: $checked);
    }

    /** @param array<int,bool>|null $checked */
    private function copy(
        ?ItemList $list = null,
        ?bool $submitted = null,
        ?bool $aborted = null,
        ?array $checked = null,
        ?array $fuzzyResults = null,
    ): self {
        return new self(
            list:          $list          ?? $this->list,
            submitted:     $submitted     ?? $this->submitted,
            aborted:       $aborted       ?? $this->aborted,
            multi:         $this->multi,
            limit:         $this->limit,
            checked:       $checked       ?? $this->checked,
            reverse:       $this->reverse,
            fuzzy:         $this->fuzzy,
            allItems:      $this->allItems,
            fuzzyResults:  $fuzzyResults  ?? $this->fuzzyResults,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
