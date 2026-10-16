<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Tile;

use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\SizedItem;

/**
 * Base tile implementation.
 */
class BaseTile implements Item, SizedItem
{
    protected ?int $width = null;
    protected ?int $height = null;

    public function __construct(
        protected readonly string $name = '',
        protected readonly Size $size = new Size(),
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSize(): Size
    {
        return $this->size;
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
        return '';
    }

    public function getInnerSize(): array
    {
        return [$this->width ?? 0, $this->height ?? 0];
    }
}
