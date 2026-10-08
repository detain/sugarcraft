<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

/**
 * The App's menu stack — btop's `Menu::menuMask` + `currentMenu`, where the
 * highest set bit is the visible menu and closing it re-shows the next one
 * down. Only the top overlay is painted and receives input.
 *
 * Immutable: every change returns a new stack.
 */
final class OverlayStack
{
    /**
     * @param list<Overlay> $items bottom first
     */
    private function __construct(
        public readonly array $items,
    ) {
    }

    public static function new(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function top(): ?Overlay
    {
        return $this->items === [] ? null : $this->items[count($this->items) - 1];
    }

    /**
     * Open `$overlay` on top — btop Menu::show + Menu::process's size rule:
     * when the terminal is smaller than the overlay's {@see Overlay::minSize()}
     * every pending menu is dropped and the size-error box shows instead
     * (`menuMask.reset(); menuMask.set(SizeError)`).
     */
    public function push(Overlay $overlay, int $cols, int $rows): self
    {
        [$w, $h] = $overlay->minSize();
        if ($cols < $w || $rows < $h) {
            return new self([Menus::sizeError()]);
        }

        return new self([...$this->items, $overlay]);
    }

    /**
     * Swap the top for `$overlay`; null pops it (btop `Closed`). A pop
     * re-runs btop's size rule for the menu it uncovers (Menu::process picks
     * the next set bit as a NEW current menu and checks the terminal again),
     * so a menu the terminal has meanwhile become too small for gives way to
     * the size-error box.
     */
    public function replaceTop(?Overlay $overlay, int $cols, int $rows): self
    {
        if ($this->items === []) {
            return $this;
        }
        $items = $this->items;
        array_pop($items);
        if ($overlay !== null) {
            $items[] = $overlay;

            return new self($items);
        }
        $below = $items === [] ? null : $items[count($items) - 1];
        if ($below !== null) {
            [$w, $h] = $below->minSize();
            if ($cols < $w || $rows < $h) {
                return new self([Menus::sizeError()]);
            }
        }

        return new self($items);
    }
}
