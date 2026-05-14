<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Tile;

use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

/**
 * Base tile implementation.
 */
class BaseTile implements Item, Sizer
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
