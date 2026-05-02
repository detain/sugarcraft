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
 * Variant of {@see ChooseModel} that opens directly in filter mode and
 * sends every keystroke to the inner {@see ItemList}'s filter buffer.
 * Enter on the highlighted result submits; Esc / Ctrl-C aborts.
 */
final class FilterModel implements Model
{
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
    ): self {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        $list  = ItemList::new($items, 60, max(1, $height))->withShowDescription(false);
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
        return new self($list, false, false, $multi, $cap, $checked, $reverse);
    }

    private function __construct(
        public readonly ItemList $list,
        public readonly bool $submitted,
        public readonly bool $aborted,
        public readonly bool $multi    = false,
        public readonly int $limit     = 1,
        public readonly array $checked = [],
        public readonly bool $reverse  = false,
    ) {}

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
                if ($this->list->visibleItems() === []) {
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
        return [$this->copy(list: $next), $cmd];
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
        $item = $this->list->selectedItem();
        return $item?->title();
    }

    /** @return list<string> */
    public function selectedAll(): array
    {
        if (!$this->submitted || !$this->multi) {
            return [];
        }
        $items = $this->list->items;
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

    private function toggle(): self
    {
        $items = $this->list->visibleItems();
        if ($items === []) {
            return $this;
        }
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
    ): self {
        return new self(
            list:      $list      ?? $this->list,
            submitted: $submitted ?? $this->submitted,
            aborted:   $aborted   ?? $this->aborted,
            multi:     $this->multi,
            limit:     $this->limit,
            checked:   $checked   ?? $this->checked,
            reverse:   $this->reverse,
        );
    }
}
