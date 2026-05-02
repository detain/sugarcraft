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
    /** @param list<string> $options */
    public static function fromOptions(array $options, int $height = 10): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        $list  = ItemList::new($items, 60, max(1, $height))->withShowDescription(false);
        [$list, ] = $list->focus();
        // Enter filter mode immediately by simulating the '/' keystroke.
        [$list, ] = $list->update(new KeyMsg(KeyType::Char, '/'));
        return new self($list, false, false);
    }

    private function __construct(
        public readonly ItemList $list,
        public readonly bool $submitted,
        public readonly bool $aborted,
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
            // Esc and Ctrl-C always abort (escape from inside filter mode
            // would otherwise just clear the filter).
            if ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c')) {
                return [new self($this->list, false, true), Cmd::quit()];
            }
            // Enter submits when there's a result; otherwise no-op.
            if ($msg->type === KeyType::Enter) {
                if ($this->list->visibleItems() === []) {
                    return [$this, null];
                }
                return [new self($this->list, true, false), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->list->update($msg);
        return [new self($next, false, false), $cmd];
    }

    public function view(): string
    {
        return $this->list->view();
    }

    public function selected(): ?string
    {
        if (!$this->submitted) {
            return null;
        }
        $item = $this->list->selectedItem();
        return $item?->title();
    }

    public function isSubmitted(): bool { return $this->submitted; }
    public function isAborted(): bool   { return $this->aborted; }
}
