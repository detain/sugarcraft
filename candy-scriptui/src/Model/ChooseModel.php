<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\Util\RenderSafe;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\Geometry;

/**
 * Selection chooser used by {@see \SugarCraft\ScriptUi\Command\ChooseCommand}.
 *
 * Two modes:
 *
 * - **single** (default) — pick one item; Enter submits, Esc aborts.
 * - **multi** — `--limit > 1` or `--no-limit`. Space toggles selection,
 *   Enter submits the set, Esc aborts. `selectedAll()` returns all
 *   ticked items.
 *
 * Header text (rendered above the list) and pre-selected items are
 * configured via the constructor / {@see fromOptions()} factory.
 */
final class ChooseModel implements Model
{
    /**
     * @param list<string> $options
     * @param list<string> $preselected  options that start checked (multi mode)
     */
    public static function fromOptions(
        array $options,
        int $height = 10,
        int $limit = 1,
        bool $noLimit = false,
        string $header = '',
        array $preselected = [],
        bool $ordered = false,
        ?string $cursorPrefix = null,
        ?string $unselectedPrefix = null,
        int $width = 0,
        bool $typeToFilter = true,
    ): self {
        // scriptui-15: options and the preselection list stay RAW — they
        // are the DATA channel (the selection is emitted verbatim by
        // design). Untrusted paint is handled at the DISPLAY sites:
        // ItemList::view and ChooseModel::markedBody both fold every title
        // through RenderSafe at render, matching how sugar-forms Select
        // already cleans at render rather than at value.
        $options = array_values($options);

        // 0 (and any negative) means auto: take the terminal width when it
        // is knowable, else keep the historical 60-cell list so piped or
        // cron contexts render exactly what they always did.
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
        return new self($list, false, false, $multi, $cap, $checked, $ordered, typeToFilter: $typeToFilter);
    }

    /**
     * @param array<int,bool> $checked  index → ticked
     */
    private function __construct(
        public readonly ItemList $list,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly bool $multi      = false,
        public readonly int $limit       = 1,
        public readonly array $checked   = [],
        public readonly bool $ordered    = false,
        public readonly bool $interrupted = false,
        // scriptui-22: multi-mode Enter pressed with zero ticks — the
        // session ends refused (rc 1 + STDERR notice), not "succeeded".
        public readonly bool $refusedEmpty = false,
        // scriptui-43: printable keys start filtering immediately (gum
        // parity) unless --no-filter opted out.
        public readonly bool $typeToFilter = true,
    ) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($this->submitted || $this->aborted || $this->refusedEmpty) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            $listOwnsKey = $this->list->isFiltering()
                && ($msg->type === KeyType::Enter || $msg->type === KeyType::Escape);

            if ($listOwnsKey && $msg->type === KeyType::Enter && $this->typeToFilter) {
                // scriptui-43: while the filter is OPEN, Enter commits the
                // narrowing instead of dissolving it (ItemList's default
                // keepFilter=false would erase the text and restore the
                // full list, so the next Enter could submit a row the user
                // never saw). The second Enter — no longer filtering —
                // submits the cursor row of the narrowed view.
                [$nextList, ] = $this->list->withKeepFilter(true)->update($msg);
                return [$this->copy(list: $nextList), null];
            }

            if (!$listOwnsKey) {
                if ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c')) {
                    return [$this->copy(aborted: true, interrupted: $msg->ctrl), Cmd::quit()];
                }
                if ($msg->type === KeyType::Enter && !empty($this->list->visibleItems())) {
                    // scriptui-22 (finding 020, gate G3 default ship): in
                    // multi mode Enter with NOTHING ticked used to submit
                    // an EMPTY selection with exit 0 — `files=$(choose
                    // --limit 9 -- *.bak); rm $files` "succeeded" having
                    // chosen nothing. The refusal ends the session with a
                    // plain diagnostic and rc 1 instead of laundering a
                    // no-op as a successful answer.
                    if ($this->multi && $this->selectedCount() === 0) {
                        return [$this->copy(refusedEmpty: true), Cmd::quit()];
                    }
                    return [$this->copy(submitted: true), Cmd::quit()];
                }
                // Multi-select: Space toggles the highlighted item.
                if ($this->multi && $msg->type === KeyType::Space) {
                    return [$this->toggle(), null];
                }

                // scriptui-43 (finding 061): gum filters as you type; candy
                // made plain letters fall into a void until '/' mode was
                // entered. Any printable key the list does not otherwise
                // claim — j/k/g/G ride the cursor, '/' opens the mode
                // natively, and in multi mode Space stays the tick — now
                // opens the filter with itself as the first byte.
                // --no-filter keeps the historical keys.
                if (
                    $this->typeToFilter
                    && !$this->list->isFiltering()
                    && !$msg->ctrl
                    && (
                        ($msg->type === KeyType::Char && $msg->rune !== '' && !in_array($msg->rune, ['j', 'k', 'g', 'G', '/'], true))
                        || ($msg->type === KeyType::Space && !$this->multi)
                    )
                ) {
                    [$primed] = $this->list->update(new KeyMsg(KeyType::Char, '/'));
                    [$nextList, $cmd] = $primed->update($msg);
                    return [$this->copy(list: $nextList), $cmd];
                }
            }
        }
        [$nextList, $cmd] = $this->list->update($msg);
        return [$this->copy(list: $nextList), $cmd];
    }

    public function view(): string
    {
        $body = $this->multi ? $this->markedBody() : $this->list->view();
        if ($this->multi) {
            // Append a status line: '(N selected)'.
            $count = $this->selectedCount();
            $cap = $this->limit > 0 ? "/{$this->limit}" : '';
            $body .= "\n[" . $count . $cap . " selected]";
        }
        if (!$this->submitted && !$this->aborted && !$this->refusedEmpty) {
            // In-app keybinding discoverability (gum's list help line).
            // scriptui-57 (109): the space clause only exists in multi
            // mode — single-select choose used to advertise a tick key
            // that does nothing there.
            $body .= "\n" . ($this->multi
                ? Lang::t('hints.choose.multi')
                : Lang::t('hints.choose'));
        }
        return $body;
    }

    /**
     * Multi-mode list body: mirrors {@see ItemList::view()} byte-for-byte
     * except that non-cursor rows carry a per-item check mark where the
     * item is ticked. The cursor row keeps its exact bytes (the reverse
     * video already flags it), and the mark wins over nothing on unchecked
     * rows — they keep the (possibly user-overridden) unselected prefix.
     * A11y (M11): the tick state must be visible per ROW, not only in the
     * aggregate '[N selected]' count line the caller appends.
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

        // scriptui-13 (finding 041): row ↔ checked mapping via ITEM
        // IDENTITY (same space toggle() writes) — title-first mapping
        // ticked and marked the FIRST copy of a duplicated title no
        // matter which row the cursor stood on.
        $top    = max(0, $this->list->offset);
        $window = array_slice($visible, $top, $this->list->height);
        $cursor = $this->list->index();
        foreach ($window as $i => $item) {
            $idx       = $top + $i;
            $itemTitle = RenderSafe::clean($item->title());
            if ($idx === $cursor) {
                $lines[] = $this->list->cursorPrefix . Ansi::sgr(Ansi::REVERSE) . $itemTitle . Ansi::reset();
            } else {
                $orig    = array_search($item, $this->list->items, true);
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
        return implode("\n", $lines);
    }

    /** Selected option's title once submitted (single mode). */
    public function selected(): ?string
    {
        if (!$this->submitted || $this->multi) {
            return null;
        }
        $item = $this->list->selectedItem();
        return $item?->title();
    }

    /**
     * Multi-select payload — every ticked option's title in original
     * (input) order, or in selection order when `--ordered` is set.
     *
     * @return list<string>
     */
    public function selectedAll(): array
    {
        if (!$this->submitted || !$this->multi) {
            return [];
        }
        $items = $this->list->items;
        $out = [];
        // Iterate in stable input order; --ordered preserves selection
        // order via the way checked is keyed.
        if ($this->ordered) {
            foreach ($this->checked as $idx => $on) {
                if ($on && isset($items[$idx])) {
                    $out[] = $items[$idx]->title();
                }
            }
            return $out;
        }
        // ksort() takes its array by reference, which fatals on a readonly
        // property — sort a local copy instead.
        $checked = $this->checked;
        ksort($checked, SORT_NUMERIC);
        foreach ($checked as $idx => $on) {
            if ($on && isset($items[$idx])) {
                $out[] = $items[$idx]->title();
            }
        }
        return $out;
    }

    public function selectedCount(): int
    {
        return count(array_filter($this->checked));
    }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
    public function isInterrupted(): bool { return $this->interrupted; }
    public function isMulti(): bool     { return $this->multi; }
    public function isRefusedEmpty(): bool { return $this->refusedEmpty; }

    /** Toggle the highlighted index in multi mode. */
    private function toggle(): self
    {
        $items = $this->list->visibleItems();
        if ($items === []) {
            return $this;
        }
        // Map cursor to the underlying item index by IDENTITY — the
        // filtered view re-positions rows, and a title search would
        // collapse duplicate titles onto the first copy (scriptui-13).
        $cursor = $this->list->index();
        $item   = $items[$cursor] ?? null;
        $idx    = $item === null ? false : array_search($item, $this->list->items, true);
        if ($idx === false) {
            return $this;
        }
        $checked = $this->checked;
        if (isset($checked[$idx])) {
            unset($checked[$idx]);
        } else {
            // Enforce limit (when not no-limit and currently at cap).
            if ($this->limit > 0 && $this->selectedCount() >= $this->limit) {
                return $this;
            }
            $checked[$idx] = true;
        }
        return $this->copy(checked: $checked);
    }

    /**
     * @param array<int,bool>|null $checked
     */
    private function copy(
        ?ItemList $list = null,
        ?bool $submitted = null,
        ?bool $aborted = null,
        ?array $checked = null,
        ?bool $interrupted = null,
        ?bool $refusedEmpty = null,
    ): self {
        return new self(
            list:       $list       ?? $this->list,
            submitted:  $submitted  ?? $this->submitted,
            aborted:    $aborted    ?? $this->aborted,
            multi:      $this->multi,
            limit:      $this->limit,
            checked:    $checked    ?? $this->checked,
            ordered:    $this->ordered,
            interrupted: $interrupted ?? $this->interrupted,
            refusedEmpty: $refusedEmpty ?? $this->refusedEmpty,
            typeToFilter: $this->typeToFilter,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
