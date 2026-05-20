<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Field;

use SugarCraft\Bits\ItemList\ItemList;
use SugarCraft\Bits\ItemList\StringItem;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Prompt\Field;
use SugarCraft\Prompt\Fuzzy\FuzzyMatcher;
use SugarCraft\Prompt\HasDynamicLabels;
use SugarCraft\Prompt\HasHideFunc;

/**
 * Single-choice picker. Wraps {@see ItemList}; the field's value is the
 * highlighted item's title (or null when empty).
 */
final class Select implements Field
{
    use HasHideFunc;
    use HasDynamicLabels;

    /** @var list<string> */
    private array $fuzzyCandidates = [];

    /** @var string */
    private string $fuzzyFilterText = '';

    private function __construct(
        public readonly string $key,
        public readonly ItemList $list,
        public readonly string $title,
        public readonly string $description,
        array $fuzzyCandidates = [],
    ) {
        $this->fuzzyCandidates = $fuzzyCandidates;
    }

    public static function new(string $key): self
    {
        return new self($key, ItemList::new([], 60, 5)->withShowDescription(false), '', '');
    }

    public function withOptions(string ...$options): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $options);
        return $this->mutate(list: $this->list->setItems($items));
    }

    /**
     * Provide a candidate pool that is filtered and ranked by fuzzy
     * substring match (Smith-Waterman scoring) against the user's
     * filter keystrokes. The suggestion list ranks by score; user picks
     * via arrow keys. Mirrors huh's `WithFuzzySuggestions([]string)`.
     *
     * @param list<string> $candidates
     */
    public function withFuzzySuggestions(array $candidates): self
    {
        $items = array_map(static fn(string $o) => new StringItem($o), $candidates);
        return $this->mutate(
            list: $this->list->setItems($items),
            fuzzyCandidates: $candidates,
        );
    }

    public function withTitle(string $t): self        { return $this->mutate(title: $t); }
    public function withDescription(string $d): self  { return $this->mutate(description: $d); }
    public function withHeight(int $h): self          { return $this->mutate(list: $this->list->setSize($this->list->width, max(1, $h))); }

    // Short-form aliases.
    public function title(string $t): self                { return $this->withTitle($t); }
    public function desc(string $d): self                 { return $this->withDescription($d); }
    public function height(int $h): self                  { return $this->withHeight($h); }
    public function options(string ...$options): self    { return $this->withOptions(...$options); }
    public function fuzzy(array $candidates): self         { return $this->withFuzzySuggestions($candidates); }

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

        // Apply fuzzy filtering when we have fuzzy candidates and list is in filtering mode.
        if ($this->fuzzyCandidates !== [] && $l->isFiltering()) {
            $filterText = $l->filterText;
            if ($filterText === '') {
                // No filter text - show all candidates in original order
                $items = array_map(
                    static fn(string $o) => new StringItem($o),
                    $this->fuzzyCandidates,
                );
                $l = $l->setItems($items);
            } else {
                // Apply fuzzy ranking
                $matcher = new FuzzyMatcher();
                $scored = $matcher->match($filterText, $this->fuzzyCandidates);
                if ($scored !== []) {
                    $ranked = array_column($scored, 0);
                    $items = array_map(static fn(string $o) => new StringItem($o), $ranked);
                    $l = $l->setItems($items);
                }
            }
        }

        return [$this->mutate(list: $l), $cmd];
    }

    public function view(): string
    {
        $lines = [];
        $title = $this->resolveTitle($this->title);
        $desc  = $this->resolveDescription($this->description);
        if ($title !== '') { $lines[] = $title; }
        if ($desc  !== '') { $lines[] = $desc; }
        $lines[] = $this->list->view();
        return implode("\n", $lines);
    }

    public function isFocused(): bool         { return $this->list->focused; }
    public function getTitle(): string        { return $this->resolveTitle($this->title); }
    public function getDescription(): string  { return $this->resolveDescription($this->description); }
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
            key:             $this->key,
            list:            $list        ?? $this->list,
            title:           $title       ?? $this->title,
            description:     $description ?? $this->description,
            fuzzyCandidates: $this->fuzzyCandidates,
        );
    }
}
