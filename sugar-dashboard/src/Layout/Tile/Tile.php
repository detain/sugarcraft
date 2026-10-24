<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Tile;

use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;

/**
 * A tile in a tile layout.
 */
final class Tile extends BaseTile
{
    private ?TileLayout $parent = null;

    public function __construct(
        string $name = '',
        Size $size = new Size(),
        private readonly ?Item $content = null,
    ) {
        parent::__construct($name, $size);
    }

    public function parent(): ?TileLayout
    {
        return $this->parent;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::parent()} instead. REMOVED-IN: 2.0.0.
     */
    public function getParent(): ?TileLayout { return $this->parent(); }

    public function setParent(?TileLayout $parent): void
    {
        $this->parent = $parent;
    }

    public function isOptional(): bool
    {
        return $this->size->optional;
    }

    public function isMinSizeFit(): bool
    {
        return $this->size->minSizeFit;
    }

    public function content(): ?Item
    {
        return $this->content;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::content()} instead. REMOVED-IN: 2.0.0.
     */
    public function getContent(): ?Item { return $this->content(); }

    public function render(): string
    {
        if ($this->content === null) {
            return '';
        }

        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return $this->content->render();
        }

        if ($this->content instanceof Sizer) {
            $sized = $this->content->setSize($w, $h);
            return $sized->render();
        }

        return $this->content->render();
    }
}
