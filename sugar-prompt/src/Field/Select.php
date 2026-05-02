<?php

declare(strict_types=1);

namespace CandyCore\Prompt\Field;

use CandyCore\Bits\ItemList\ItemList;
use CandyCore\Bits\ItemList\StringItem;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Prompt\Field;
use CandyCore\Prompt\HasHideFunc;

/**
 * Single-choice picker. Wraps {@see ItemList}; the field's value is the
 * highlighted item's title (or null when empty).
 */
final class Select implements Field
{
    use HasHideFunc;

    private function __construct(
        public readonly string $key,
        public readonly ItemList $list,
        public readonly string $title,
        public readonly string $description,
    ) {}

    public static function new(string $key): self
    {
        return new self($key, ItemList::new([], 60, 5)->withShowDescription(false), '', '');
    }

    public function withOptions(string ...$options): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        return $this->mutate(list: $this->list->setItems($items));
    }

    public function withTitle(string $t): self        { return $this->mutate(title: $t); }
    public function withDescription(string $d): self  { return $this->mutate(description: $d); }
    public function withHeight(int $h): self          { return $this->mutate(list: $this->list->setSize($this->list->width, max(1, $h))); }

    public function key(): string  { return $this->key; }
    public function value(): mixed
    {
        $sel = $this->list->selectedItem();
        return $sel?->title();
    }

    public function focus(): array
    {
        [$l, $cmd] = $this->list->focus();
        return [$this->mutate(list: $l), $cmd];
    }

    public function blur(): Field
    {
        return $this->mutate(list: $this->list->blur());
    }

    public function update(Msg $msg): array
    {
        [$l, $cmd] = $this->list->update($msg);
        return [$this->mutate(list: $l), $cmd];
    }

    public function view(): string
    {
        $lines = [];
        if ($this->title !== '')       { $lines[] = $this->title; }
        if ($this->description !== '') { $lines[] = $this->description; }
        $lines[] = $this->list->view();
        return implode("\n", $lines);
    }

    public function isFocused(): bool         { return $this->list->focused; }
    public function getTitle(): string        { return $this->title; }
    public function getDescription(): string  { return $this->description; }
    public function getError(): ?string       { return null; }
    public function skippable(): bool         { return false; }

    /**
     * In filter mode the inner ItemList uses Enter to leave the filter
     * and Escape to clear it; both must be consumed locally so the Form
     * doesn't advance/abort while the user is filtering. Up / Down also
     * belong to the list — without consuming them the form would steal
     * arrow keys for between-field navigation.
     */
    public function consumes(Msg $msg): bool
    {
        if (!$this->list->focused || !$msg instanceof KeyMsg) {
            return false;
        }
        if ($msg->type === KeyType::Up || $msg->type === KeyType::Down) {
            return true;
        }
        if ($this->list->isFiltering()) {
            return $msg->type === KeyType::Enter || $msg->type === KeyType::Escape;
        }
        return false;
    }

    private function mutate(?ItemList $list = null, ?string $title = null, ?string $description = null): self
    {
        return new self(
            key:         $this->key,
            list:        $list        ?? $this->list,
            title:       $title       ?? $this->title,
            description: $description ?? $this->description,
        );
    }
}
