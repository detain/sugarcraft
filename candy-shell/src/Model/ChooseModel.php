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
 * Single-selection chooser used by {@see \CandyCore\Shell\Command\ChooseCommand}.
 *
 * Wraps {@see ItemList} and adds two terminal states:
 *
 * - **submitted** — user pressed `Enter`; {@see selected()} returns the
 *   highlighted option's title.
 * - **aborted**   — user pressed `Esc` or `Ctrl+C`; the program quits
 *   with no selection.
 */
final class ChooseModel implements Model
{
    /** @param list<string> $options */
    public static function fromOptions(array $options, int $height = 10): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        $list  = ItemList::new($items, 60, max(1, $height))->withShowDescription(false);
        [$list, ] = $list->focus();
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
            // Let the list eat Enter / Escape while it's filtering.
            $listOwnsKey = $this->list->isFiltering()
                && ($msg->type === KeyType::Enter || $msg->type === KeyType::Escape);

            if (!$listOwnsKey) {
                if ($msg->type === KeyType::Escape || ($msg->ctrl && $msg->rune === 'c')) {
                    return [new self($this->list, false, true), Cmd::quit()];
                }
                if ($msg->type === KeyType::Enter && !empty($this->list->visibleItems())) {
                    return [new self($this->list, true, false), Cmd::quit()];
                }
            }
        }
        [$nextList, $cmd] = $this->list->update($msg);
        return [new self($nextList, false, false), $cmd];
    }

    public function view(): string
    {
        return $this->list->view();
    }

    /** Selected option's title once submitted, or null when aborted. */
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
