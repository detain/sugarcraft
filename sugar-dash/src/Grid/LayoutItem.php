<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * An item within a layout with flex properties.
 */
final class LayoutItem
{
    public function __construct(
        public readonly Item $content,
        public readonly int $flex = 0,
    ) {}

    /**
     * Create a LayoutItem with flex grow behavior.
     */
    public static function flex(Item $content, int $flex = 1): self
    {
        return new self($content, $flex);
    }

    /**
     * Create a LayoutItem that takes its natural size.
     */
    public static function fixed(Item $content): self
    {
        return new self($content, 0);
    }
}
