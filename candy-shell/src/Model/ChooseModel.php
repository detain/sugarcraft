<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Bits\ItemList\ItemList;
use CandyCore\Bits\ItemList\StringItem;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Selection chooser used by {@see \CandyCore\Shell\Command\ChooseCommand}.
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
    ): self {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        $list  = ItemList::new($items, 60, max(1, $height))->withShowDescription(false);
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
        return new self($list, false, false, $multi, $cap, $checked, $ordered);
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
    ) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($this->submitted || $this->aborted) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            $listOwnsKey = $this->list->isFiltering()
                && ($msg->type === KeyType::Enter || $msg->type === KeyType::Escape);

            if (!$listOwnsKey) {
                if ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c')) {
                    return [$this->copy(aborted: true), Cmd::quit()];
                }
                if ($msg->type === KeyType::Enter && !empty($this->list->visibleItems())) {
                    return [$this->copy(submitted: true), Cmd::quit()];
                }
                // Multi-select: Space toggles the highlighted item.
                if ($this->multi && $msg->type === KeyType::Space) {
                    return [$this->toggle(), null];
                }
            }
        }
        [$nextList, $cmd] = $this->list->update($msg);
        return [$this->copy(list: $nextList), $cmd];
    }

    public function view(): string
    {
        $body = $this->list->view();
        if (!$this->multi) {
            return $body;
        }
        // Append a status line: '(N selected)'.
        $count = $this->selectedCount();
        $cap = $this->limit > 0 ? "/{$this->limit}" : '';
        return $body . "\n[" . $count . $cap . " selected]";
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
        ksort($this->checked, SORT_NUMERIC);
        foreach ($this->checked as $idx => $on) {
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
    public function isMulti(): bool     { return $this->multi; }

    /** Toggle the highlighted index in multi mode. */
    private function toggle(): self
    {
        $items = $this->list->visibleItems();
        if ($items === []) {
            return $this;
        }
        // Map cursor to the underlying item index (filtering may have
        // reordered).
        $cursor = $this->list->index();
        $title  = $items[$cursor]->title();
        $idx = null;
        foreach ($this->list->items as $i => $item) {
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
    ): self {
        return new self(
            list:      $list      ?? $this->list,
            submitted: $submitted ?? $this->submitted,
            aborted:   $aborted   ?? $this->aborted,
            multi:     $this->multi,
            limit:     $this->limit,
            checked:   $checked   ?? $this->checked,
            ordered:   $this->ordered,
        );
    }
}
