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
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\Util\RenderSafe;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\Emphasis;
use SugarCraft\ScriptUi\Runtime\Geometry;

/**
 * Mirrors charmbracelet/gum.filter (the query-over-lines view).
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
        string $placeholder = '',
        string $prompt = '',
        int $tail = 0,
    ): self {
        // scriptui-15: the option payload stays RAW — it is the DATA
        // channel (`printf 'a\tb\n' | filter` must hand back the real
        // filename `a<TAB>b`, not the folded display form). Injection
        // defence lives at the DISPLAY sites: ItemList::view cleans every
        // title through RenderSafe at render, and the marked/fuzzy bodies
        // here do the same. The header travels into ItemList's title,
        // which the same display-site rule already covers. The pre-filled
        // filter $value stays sanitised: it is echoed raw on the input
        // line ('/' . filterText) and never emitted as a selection.
        $options     = array_values($options);
        $value       = \SugarCraft\Core\Util\Sanitize::cellValue($value);
        $placeholder = \SugarCraft\Core\Util\Sanitize::cellValue($placeholder);
        // scriptui-42 (finding 060): the prompt is a PAINT string (rides the
        // probe line, never emitted); the tail is the rows-to-keep-below the
        // cursor budget, clamped into the visible window.
        $prompt   = \SugarCraft\Core\Util\Sanitize::cellValue($prompt);
        $tailRows = $tail > 0 ? min($tail, max(0, $height - 1)) : 0;

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
        // scriptui-19: replay whole codepoints, never raw bytes — a
        // byte-per-keystroke replay arms a stream of transient invalid
        // UTF-8 filter buffers (lead byte without its continuation) that
        // every width/match consumer downstream must tolerate.
        foreach (mb_str_split($value, 1, 'UTF-8') as $ch) {
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
            placeholder: $placeholder,
            promptGlyph: $prompt,
            tailRows: $tailRows,
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
        // scriptui-12: cursor/offset INSIDE the fuzzy match set. The inner
        // ItemList cursor navigates the substring-filtered set, which is
        // not the set painted in fuzzy mode — these carry the real one.
        public readonly int $fuzzyCursor = 0,
        public readonly int $fuzzyOffset = 0,
        // scriptui-18: the hint painted over the bare '/' probe line while
        // the filter text is empty ('' = no ghost, the historical frame).
        public readonly string $placeholder = '',
        // scriptui-42 (finding 060): --prompt replaces the fixed '/' probe
        // glyph ('' = the historical slash); --tail N keeps N rows visible
        // BELOW the cursor (0 = unset, the top-anchored historical window).
        public readonly string $promptGlyph = '',
        public readonly int $tailRows = 0,
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
            // scriptui-12: with the fuzzy set painted, navigation is owned
            // HERE — the inner ItemList moves its cursor through ITS
            // substring-filtered set, which is a different set (and a
            // different index space) whenever a query fuzzy-matches rows
            // substring would drop. Arrows/PG/HOME/END step $fuzzyCursor
            // against the match rows themselves; the inner cursor stays
            // parked (every fuzzy-mode reader uses $fuzzyCursor).
            if ($this->fuzzyActive()) {
                $step = match ($msg->type) {
                    KeyType::Up        => -1,
                    KeyType::Down      => 1,
                    KeyType::PageUp    => -max(1, $this->list->height),
                    KeyType::PageDown  => max(1, $this->list->height),
                    default            => null,
                };
                if ($step !== null || $msg->type === KeyType::Home || $msg->type === KeyType::End) {
                    $count = count($this->fuzzyResults);
                    if ($count === 0) {
                        return [$this, null];
                    }
                    $cursor = match (true) {
                        $msg->type === KeyType::Home => 0,
                        $msg->type === KeyType::End  => $count - 1,
                        default => max(0, min($count - 1, $this->fuzzyCursor + $step)),
                    };
                    $height = max(1, $this->list->height);
                    // Keep the cursor inside the window: slide the offset
                    // only as far as needed (same math ItemList's viewport
                    // pan applies).
                    $offset = max(0, min($this->fuzzyOffset, max(0, $count - $height)));
                    if ($cursor < $offset) {
                        $offset = $cursor;
                    } elseif ($cursor >= $offset + $height) {
                        $offset = $cursor - $height + 1;
                    }
                    return [$this->copy(fuzzyCursor: $cursor, fuzzyOffset: $offset), null];
                }
            }
        }
        [$next, $cmd] = $this->list->update($msg);

        $filterText = $next->filterValue();
        if ($this->fuzzy && $filterText !== '') {
            // Cursor-only keys (arrows, Tab, …) leave the filter text
            // unchanged — reuse the cached results instead of re-running
            // O(n·m) Smith-Waterman scoring on every keystroke.
            $textChanged = $filterText !== $this->lastFilterText;
            $newResults = !$textChanged
                ? $this->fuzzyResults
                : $this->computeFuzzyResults($filterText);
            if ($textChanged) {
                // A re-narrowed set restarts selection at the top row
                // (ItemList's own edit arms do exactly this: cursor: 0).
                return [$this->copy(list: $next, fuzzyResults: $newResults, lastFilterText: $filterText, fuzzyCursor: 0, fuzzyOffset: 0), $cmd];
            }
            $cursor = min($this->fuzzyCursor, max(0, count($newResults) - 1));
            return [$this->copy(list: $next, fuzzyResults: $newResults, lastFilterText: $filterText, fuzzyCursor: $cursor), $cmd];
        }
        return [$this->copy(list: $next, fuzzyResults: [], lastFilterText: ''), $cmd];
    }

    /**
     * True while the painted list is the fuzzy match set (scriptui-12):
     * fuzzy mode is on AND a filter query is live. The zero-match case
     * counts as active too — "No matches." is then painted from THIS
     * space, never from the substring space, so what the user sees and
     * what Enter submits can never disagree.
     */
    private function fuzzyActive(): bool
    {
        return $this->fuzzy && $this->list->filterValue() !== '';
    }

    /** @return list<int> the match set's original-item indices, painted order */
    private function fuzzyOrder(): array
    {
        $order = array_keys($this->fuzzyResults);
        sort($order, SORT_NUMERIC);
        return $order;
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
        // scriptui-42: a custom prompt glyph or a tail budget both need the
        // model's own row window — ItemList hardcodes '/' (ItemList:197) and
        // keeps the cursor top-anchored. Single-select plain runs otherwise
        // delegate byte-for-byte as they always did.
        $needsCustom  = $this->multi || $this->promptGlyph !== '' || $this->tailRows > 0;
        // scriptui-14 belt: same model-side clip for both custom bodies and
        // the delegated ItemList path (idempotent where the widget already
        // clips), so no shipped vendor copy can re-widen the frame.
        $body = implode("\n", $this->clipLines(explode("\n",
            $fuzzyActive
                ? $this->fuzzyView()
                : ($needsCustom ? $this->markedBody() : Emphasis::stripDecor($this->list->view()))
        )));
        if ($this->multi) {
            $count = count(array_filter($this->checked));
            $cap = $this->limit > 0 ? "/{$this->limit}" : '';
            $body .= "\n[" . $count . $cap . " selected]";
        }
        if (!$this->submitted && !$this->aborted) {
            $body .= "\n" . Lang::t('hints.filter');
        }
        return $this->ghostFilterLine($body);
    }

    /**
     * scriptui-18: --placeholder reached nowhere — the flag was registered
     * and dropped. The ghost now rides -17's shared span doctrine: while the
     * filter text is empty, the bare probe line (ItemList:197's '/', or the
     * --prompt glyph under scriptui-42, mirrored by the custom bodies)
     * carries the dim hint after the glyph; the first typed character
     * extends that same line and the hint is gone. Bounded to the FIRST
     * exact bare-probe line so item text can never be touched.
     */
    private function ghostFilterLine(string $body): string
    {
        if ($this->placeholder === '' || $this->list->filterText !== '') {
            return $body;
        }

        $bare  = $this->promptGlyph !== '' ? $this->promptGlyph : '/';
        $ghost = $bare . Emphasis::sgr(Ansi::FAINT) . $this->placeholder . Emphasis::reset();
        // scriptui-14 (finding 004): the ghost replaces the probe line AFTER
        // the bodies clipped — a long --placeholder would re-introduce the
        // wrapped tail the clip just removed. Same budget, same no-ellipsis
        // form as ItemList::clip.
        if ($this->list->width > 0) {
            $ghost = Width::truncateAnsi($ghost, $this->list->width);
        }
        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if ($line === $bare) {
                $lines[$i] = $ghost;
                break;
            }
        }

        return implode("\n", $lines);
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
            $lines[] = ($this->promptGlyph !== '' ? $this->promptGlyph : '/') . $this->list->filterText;
        }
        if ($visible === []) {
            $lines[] = $this->list->filterText !== '' ? 'No matches.' : 'No items.';
            if ($this->list->showStatusBar && ($status = $this->list->status()) !== '') {
                $lines[] = $status;
            }
            return implode("\n", $lines);
        }

        // scriptui-13 (finding 041): the row ↔ checked mapping runs
        // through ITEM IDENTITY (array_search, strict), not title-first
        // matching — duplicate titles tick and mark per ROW, the same
        // space toggle() now writes.
        // scriptui-42: --tail shifts the window so TAIL rows stay visible
        // BELOW the cursor (gum's bottom-hugging footer); unset keeps the
        // ItemList offset byte-for-byte.
        $cursor = $this->list->index();
        $top    = $this->tailRows > 0
            ? max(0, min($cursor - (max(1, $this->list->height) - $this->tailRows - 1), max(0, count($visible) - $this->list->height)))
            : max(0, $this->list->offset);
        $window = array_slice($visible, $top, $this->list->height);
        foreach ($window as $i => $item) {
            $idx       = $top + $i;
            $itemTitle = RenderSafe::clean($item->title());
            if ($idx === $cursor) {
                $lines[] = $this->list->cursorPrefix . Emphasis::sgr(Ansi::REVERSE) . $itemTitle . Emphasis::reset();
            } else {
                $orig    = array_search($item, $this->allItems, true);
                $lines[] = $orig !== false && !empty($this->checked[$orig])
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
        return implode("\n", $this->clipLines($lines));
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
        $lines[] = ($this->promptGlyph !== '' ? $this->promptGlyph : '/') . $this->list->filterText;

        $visible = $this->fuzzyVisibleItems();
        // fuzzyVisibleItems() orders by ascending original index; walk the
        // match set the same way so indices stay aligned with the items.
        $originalIndices = array_keys($this->fuzzyResults);
        sort($originalIndices, SORT_NUMERIC);

        // scriptui-12: the cursor and scroll window are the fuzzy-set's
        // own (see fuzzyActive()), not the inner ItemList's substring view.
        $cursor = max(0, min($this->fuzzyCursor, count($visible) - 1));
        $top    = max(0, $this->fuzzyOffset);
        foreach (array_slice($visible, $top, $this->list->height, true) as $idx => $item) {
            // scriptui-15: clean at the DISPLAY site (ItemList::view does
            // the same for its rows) — the title bytes stay raw in the
            // model for the payload. Emphasis indices were scored on the
            // raw bytes; drift inside an escaped title is cosmetic, the
            // injection defence is not.
            $highlighted = self::emphasize(
                RenderSafe::clean($item->title()),
                $this->fuzzyResults[$originalIndices[$idx]]->indices(),
            );
            if ($idx === $cursor) {
                $lines[] = $this->list->cursorPrefix . Emphasis::sgr(Ansi::REVERSE) . $highlighted . Emphasis::reset();
            } elseif ($this->multi && !empty($this->checked[$originalIndices[$idx]])) {
                // A11y (M11): per-row tick mark in fuzzy multi mode too.
                $lines[] = "\u{2713} " . $highlighted;
            } else {
                $lines[] = $this->list->unselectedPrefix . $highlighted;
            }
        }
        return implode("\n", $this->clipLines($lines));
    }

    /**
     * scriptui-14 (finding 004): same clip contract as
     * {@see ChooseModel::clipLines()} — the delegated path rides
     * ItemList::view()'s own truncation, so the model-mirror bodies
     * (marked/fuzzy) must clamp to the identical width budget in the
     * identical no-ellipsis form, or one item would render two different
     * ways depending on the drawing path.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function clipLines(array $lines): array
    {
        $width = $this->list->width;
        if ($width <= 0) {
            return $lines;
        }

        return array_map(
            static fn(string $line): string => Width::truncateAnsi($line, $width),
            $lines
        );
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
                $out .= Emphasis::sgr(Ansi::BOLD);
            } elseif (!$match && $bold) {
                $out .= Emphasis::sgr(22);
            }
            $bold = $match;
            $out .= $ch;
        }
        return $bold ? $out . Emphasis::sgr(22) : $out;
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
        $cursor = $this->fuzzyCursor;
        $order  = $this->fuzzyOrder();
        return isset($order[$cursor]) ? $this->allItems[$order[$cursor]] : null;
    }

    /** @return list<string> */
    public function selectedAll(): array
    {
        if (!$this->submitted || !$this->multi) {
            return [];
        }
        // scriptui-13: $checked keys are ORIGINAL allItems positions in
        // both modes now — the previous fuzzy branch indexed the
        // re-sorted subset, silently dropping or swapping rows when the
        // painted set was narrower than the full list.
        $items = $this->allItems;
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
        // scriptui-13: resolve the highlighted row through the order
        // bridge directly to its original index — the previous
        // title-string search mis-resolved onto a colliding copy when the
        // same title existed twice.
        $order = $this->fuzzyOrder();
        $orig  = $order[$this->fuzzyCursor] ?? null;
        return $orig !== null && isset($this->fuzzyResults[$orig])
            ? $this->fuzzyResults[$orig]->indices()
            : [];
    }

    private function toggle(): self
    {
        // scriptui-13 (finding 017/041): resolve the toggled row to its
        // ORIGINAL index by row identity, in one shared key space with
        // selectedAll()/markedBody(). The old shape searched by
        // title-first-match, which (a) dropped or fabricated entries
        // whenever the painted list was a re-indexed subset (fuzzy), and
        // (b) ticked the first copy of a duplicate title while the user
        // stood on a later one.
        if ($this->fuzzyActive()) {
            $order = $this->fuzzyOrder();
            $idx   = $order[$this->fuzzyCursor] ?? null;
        } else {
            $visible = $this->list->visibleItems();
            $item    = $visible[$this->list->index()] ?? null;
            $found   = $item === null ? false : array_search($item, $this->allItems, true);
            $idx     = $found === false ? null : $found;
        }
        if ($idx === null || !isset($this->allItems[$idx])) {
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
        ?int $fuzzyCursor = null,
        ?int $fuzzyOffset = null,
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
            fuzzyCursor:    $fuzzyCursor    ?? $this->fuzzyCursor,
            fuzzyOffset:    $fuzzyOffset    ?? $this->fuzzyOffset,
            matcher:        $this->matcher,
            placeholder:    $this->placeholder,
            promptGlyph:    $this->promptGlyph,
            tailRows:       $this->tailRows,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
