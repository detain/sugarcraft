<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\Util\RenderSafe;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\Geometry;

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
    private FuzzyMatcher $matcher;

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
        ?FuzzyMatcher $matcher = null,
        int $width = 0,
    ): self {
        // Untrusted paint (cluster 12): same sweep as ChooseModel plus the
        // pre-filled filter value, which lands in the filter input line.
        $options    = array_map(static fn (string $s): string => \SugarCraft\Core\Util\Sanitize::cellValue($s), $options);
        $header     = \SugarCraft\Core\Util\Sanitize::cellValue($header);
        $preselected = array_map(static fn (string $s): string => \SugarCraft\Core\Util\Sanitize::cellValue($s), $preselected);
        $value       = \SugarCraft\Core\Util\Sanitize::cellValue($value);

        // 0 = auto width: terminal columns when knowable, else the
        // historical 60-cell list (see ChooseModel for the same rule).
        $auto  = $width <= 0;
        $cols  = Geometry::columnsOrNull();
        $width = $auto ? ($cols ?? 60) : min($width, $cols ?? $width);
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        $list  = ItemList::new($items, $width, max(1, $height))->withShowDescription(false);
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
            matcher: $matcher,
        );
        if ($fuzzy && $value !== '') {
            $filterText = $list->filterValue();
            $self = $self->copy(
                fuzzyResults:   $self->computeFuzzyResults($filterText),
                lastFilterText: $filterText,
            );
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
        // Cache key for $fuzzyResults: the filter text they were computed
        // for. $allItems is fixed at construction, so text is the only
        // input that can invalidate the cache.
        public readonly string $lastFilterText = '',
        public readonly bool $interrupted = false,
        ?FuzzyMatcher $matcher = null,
    ) {
        $this->matcher = $matcher ?? new SmithWatermanMatcher();
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
                return [$this->copy(aborted: true, interrupted: $msg->ctrl), Cmd::quit()];
            }
            if ($msg->type === KeyType::Enter) {
                // scriptui-11: Enter always ends the session. When nothing
                // is highlighted there is nothing to fabricate — the empty
                // selection submits with exit 0 (gum's empty-answer posture,
                // gate G2 default). The old shape froze forever on an empty
                // list (finding 003) and, in fuzzy mode, resolved a typed
                // zero-match query through fuzzyVisibleItems()'s all-items
                // fallback — confidently printing an item nobody selected
                // while the screen said "No matches." (finding 012).
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
            // Cursor-only keys (arrows, Tab, …) leave the filter text
            // unchanged — reuse the cached results instead of re-running
            // O(n·m) Smith-Waterman scoring on every keystroke.
            $newResults = $filterText === $this->lastFilterText
                ? $this->fuzzyResults
                : $this->computeFuzzyResults($filterText);
            return [$this->copy(list: $next, fuzzyResults: $newResults, lastFilterText: $filterText), $cmd];
        }
        return [$this->copy(list: $next, fuzzyResults: [], lastFilterText: ''), $cmd];
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
        // In fuzzy mode the inner ItemList's substring filter does not know
        // about the scored match set, so render the fuzzy list ourselves —
        // mirroring ItemList::view()'s layout — with matched characters
        // emphasised via their highlight indices.
        $fuzzyActive = $this->fuzzy && $this->list->filterValue() !== '' && $this->fuzzyResults !== [];
        $body = $fuzzyActive
            ? $this->fuzzyView()
            : ($this->multi ? $this->markedBody() : $this->list->view());
        if ($this->multi) {
            $count = count(array_filter($this->checked));
            $cap = $this->limit > 0 ? "/{$this->limit}" : '';
            $body .= "\n[" . $count . $cap . " selected]";
        }
        if (!$this->submitted && !$this->aborted) {
            $body .= "\n" . Lang::t('hints.filter');
        }
        return $body;
    }

    /**
     * Multi-mode non-fuzzy list body: mirrors {@see ItemList::view()}
     * byte-for-byte except that non-cursor rows carry a per-item check
     * mark where the item is ticked (A11y M11 — tick state visible per
     * ROW, not only in the aggregate '[N selected]' count). Cursor row
     * bytes untouched; unchecked rows keep the unselected prefix.
     */
    private function markedBody(): string
    {
        $visible = $this->list->visibleItems();
        $lines   = [];

        if ($this->list->title !== '') {
            $lines[] = RenderSafe::clean($this->list->title);
        }
        if ($this->list->showFilter && ($this->list->filtering || $this->list->filterText !== '')) {
            $lines[] = '/' . $this->list->filterText;
        }
        if ($visible === []) {
            $lines[] = $this->list->filterText !== '' ? 'No matches.' : 'No items.';
            if ($this->list->showStatusBar && ($status = $this->list->status()) !== '') {
                $lines[] = $status;
            }
            return implode("\n", $lines);
        }

        // toggle() keys $checked by the FIRST allItems entry carrying a
        // title; map the same way so the mark lands on the toggled row.
        $firstByTitle = [];
        foreach ($this->allItems as $i => $item) {
            $t = $item->title();
            if (!isset($firstByTitle[$t])) {
                $firstByTitle[$t] = $i;
            }
        }

        $top    = max(0, $this->list->offset);
        $window = array_slice($visible, $top, $this->list->height);
        $cursor = $this->list->index();
        foreach ($window as $i => $item) {
            $idx       = $top + $i;
            $itemTitle = RenderSafe::clean($item->title());
            if ($idx === $cursor) {
                $lines[] = $this->list->cursorPrefix . Ansi::sgr(Ansi::REVERSE) . $itemTitle . Ansi::reset();
            } else {
                $orig    = $firstByTitle[$item->title()] ?? null;
                $lines[] = $orig !== null && !empty($this->checked[$orig])
                    ? "\u{2713} " . $itemTitle
                    : $this->list->unselectedPrefix . $itemTitle;
            }
            if ($this->list->showDescription && $item->description() !== '') {
                $lines[] = '    ' . RenderSafe::clean($item->description());
            }
        }
        if ($this->list->showStatusBar && ($status = $this->list->status()) !== '') {
            $lines[] = $status;
        }
        return implode("\n", $lines);
    }

    /**
     * Fuzzy-mode list body: same shape as {@see ItemList::view()} (title,
     * `/filter` line, windowed items, REVERSE video on the cursor row) but
     * sourced from the scored match set, with each matched character
     * rendered bold so the user can see WHY an item survived the filter.
     */
    private function fuzzyView(): string
    {
        $lines = [];
        if ($this->list->title !== '') {
            $lines[] = $this->list->title;
        }
        $lines[] = '/' . $this->list->filterText;

        $visible = $this->fuzzyVisibleItems();
        // fuzzyVisibleItems() orders by ascending original index; walk the
        // match set the same way so indices stay aligned with the items.
        $originalIndices = array_keys($this->fuzzyResults);
        sort($originalIndices, SORT_NUMERIC);

        $cursor = min($this->list->index(), count($visible) - 1);
        $top    = max(0, $this->list->offset);
        foreach (array_slice($visible, $top, $this->list->height, true) as $idx => $item) {
            $highlighted = self::emphasize(
                $item->title(),
                $this->fuzzyResults[$originalIndices[$idx]]->indices(),
            );
            if ($idx === $cursor) {
                $lines[] = $this->list->cursorPrefix . Ansi::sgr(Ansi::REVERSE) . $highlighted . Ansi::reset();
            } elseif ($this->multi && !empty($this->checked[$originalIndices[$idx]])) {
                // A11y (M11): per-row tick mark in fuzzy multi mode too.
                $lines[] = "\u{2713} " . $highlighted;
            } else {
                $lines[] = $this->list->unselectedPrefix . $highlighted;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Wrap runs of matched characters in bold. SGR 22 (normal intensity)
     * closes each run instead of a full reset so the REVERSE video on the
     * cursor row survives.
     *
     * @param list<int> $indices 0-based character indices to emphasise
     */
    private static function emphasize(string $title, array $indices): string
    {
        if ($indices === []) {
            return $title;
        }
        $set  = array_flip($indices);
        $out  = '';
        $bold = false;
        foreach (mb_str_split($title) as $i => $ch) {
            $match = isset($set[$i]);
            if ($match && !$bold) {
                $out .= Ansi::sgr(Ansi::BOLD);
            } elseif (!$match && $bold) {
                $out .= Ansi::sgr(22);
            }
            $bold = $match;
            $out .= $ch;
        }
        return $bold ? $out . Ansi::sgr(22) : $out;
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
            if ($this->list->filterValue() !== '') {
                // scriptui-11 (finding 012): a typed filter with zero scored
                // matches paints "No matches." — there is no selection.
                return null;
            }
            // No filter typed: the full list is painted; the cursor row wins.
            return $this->allItems[$this->list->index()] ?? null;
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
        // ksort() takes its array by reference, which fatals on a readonly
        // property — sort a local copy instead.
        $checked = $this->checked;
        ksort($checked, SORT_NUMERIC);
        foreach ($checked as $idx => $on) {
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
    public function isInterrupted(): bool { return $this->interrupted; }
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
        ?string $lastFilterText = null,
        ?bool $interrupted = null,
    ): self {
        return new self(
            list:           $list           ?? $this->list,
            submitted:      $submitted      ?? $this->submitted,
            aborted:        $aborted        ?? $this->aborted,
            multi:          $this->multi,
            limit:          $this->limit,
            checked:        $checked        ?? $this->checked,
            reverse:        $this->reverse,
            fuzzy:          $this->fuzzy,
            allItems:       $this->allItems,
            fuzzyResults:   $fuzzyResults   ?? $this->fuzzyResults,
            lastFilterText: $lastFilterText ?? $this->lastFilterText,
            interrupted:    $interrupted    ?? $this->interrupted,
            matcher:        $this->matcher,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
