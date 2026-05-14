<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Dash\Foundation\Item;

/**
 * A modal dialog that displays a list of items.
 */
final class ListModal implements Item
{
    /** @var list<string> */
    private array $items;
    private ?int $selectedIndex = null;

    public function __construct(
        array $items = [],
        private readonly ?string $title = null,
    ) {
        $this->items = $items;
    }

    /**
     * @param list<string> $items
     */
    public static function new(array $items, ?string $title = null): self
    {
        return new self(items: $items, title: $title);
    }

    public function withSelectedIndex(?int $index): self
    {
        $clone = clone $this;
        $clone->selectedIndex = $index;
        return $clone;
    }

    public function render(): string
    {
        $result = '';
        if ($this->title !== null) {
            $result .= $this->title . "\n";
        }
        foreach ($this->items as $i => $item) {
            $prefix = ($this->selectedIndex === $i) ? '> ' : '  ';
            $result .= $prefix . $item . "\n";
        }
        return rtrim($result, "\n");
    }
}
