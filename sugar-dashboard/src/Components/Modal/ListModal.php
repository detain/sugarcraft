<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;

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
        // D-69 (finding 064): rebuild-through-new-self dropped the stored
        // allocation (D-56/D-67/D-77 drop-class screened); carry it.
        $next = new self(
            items: $this->items,
            title: $this->title,
            onEdit: $onEdit,
            onDelete: $this->onDelete,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    public function withOnDelete(callable $onDelete): self
    {
        $next = new self( // D-69 allocation carry (finding 064)
            items: $this->items,
            title: $this->title,
            onEdit: $this->onEdit,
            onDelete: $onDelete,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the list, honoring the allocated box (finding sugar-dashboard-
     * 064: setSize used to be stored and never read).
     *
     * With no positive allocation the plain-list bytes are exactly what the
     * class always emitted. With a height budget the items window around a
     * valid selection (header rows are subtracted first; a selection past
     * the end shows no cursor and never crashes); with a width budget every
     * emitted row is cell-truncated to it, unpadded.
     */
    public function render(): string
    {
        $allocW = $this->width !== null && $this->width > 0 ? $this->width : 0;
        $allocH = $this->height !== null && $this->height > 0 ? $this->height : 0;

        $lines = [];
        if ($this->title !== null) {
            $lines[] = $this->title;
        }

        // Render hints if edit/delete are enabled
        $hints = [];
        if ($this->onEdit !== null) {
            $hints[] = '[E]dit';
        }
        if ($this->onDelete !== null) {
            $hints[] = '[D]elete';
        }
        if ($hints !== []) {
            $lines[] = implode(' ', $hints);
        }

        $count = count($this->items);
        if ($allocH > 0) {
            $budget = max(1, $allocH - count($lines));
            $visible = min($budget, $count);
            $hasCursor = $this->selectedIndex !== null
                && $this->selectedIndex >= 0
                && $this->selectedIndex < $count;
            $top = 0;
            if ($visible > 0) {
                $top = $hasCursor
                    ? max(0, min($this->selectedIndex - intdiv($visible - 1, 2), $count - $visible))
                    : 0;
            }
            for ($i = $top; $i < $top + $visible; $i++) {
                $lines[] = ($this->selectedIndex === $i ? '> ' : '  ') . $this->items[$i];
            }
        } else {
            foreach ($this->items as $i => $item) {
                $prefix = ($this->selectedIndex === $i) ? '> ' : '  ';
                $lines[] = $prefix . $item;
            }
        }

        if ($allocW > 0) {
            $lines = array_map(
                static fn(string $line): string => Width::truncateAnsi($line, $allocW),
                $lines,
            );
        }

        return implode("\n", $lines);
    }
}
