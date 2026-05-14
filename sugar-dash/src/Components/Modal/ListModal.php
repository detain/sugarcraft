<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

/**
 * A modal dialog that displays a list of items.
 */
final class ListModal implements Item, Sizer
{
    /** @var list<string> */
    private array $items;
    private ?int $selectedIndex = null;
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<string> $items
     * @param callable|null $onEdit Callback(item, index) for edit action
     * @param callable|null $onDelete Callback(item, index) for delete action
     */
    public function __construct(
        array $items = [],
        private readonly ?string $title = null,
        private mixed $onEdit = null,
        private mixed $onDelete = null,
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

    public function withOnEdit(callable $onEdit): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            onEdit: $onEdit,
            onDelete: $this->onDelete,
        );
    }

    public function withOnDelete(callable $onDelete): self
    {
        return new self(
            items: $this->items,
            title: $this->title,
            onEdit: $this->onEdit,
            onDelete: $onDelete,
        );
    }

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    public function render(): string
    {
        $result = '';
        if ($this->title !== null) {
            $result .= $this->title . "\n";
        }

        // Render hints if edit/delete are enabled
        $hints = [];
        if ($this->onEdit !== null) {
            $hints[] = '[E]dit';
        }
        if ($this->onDelete !== null) {
            $hints[] = '[D]elete';
        }
        if (!empty($hints)) {
            $result .= implode(' ', $hints) . "\n";
        }

        foreach ($this->items as $i => $item) {
            $prefix = ($this->selectedIndex === $i) ? '> ' : '  ';
            $result .= $prefix . $item . "\n";
        }
        return rtrim($result, "\n");
    }
}
