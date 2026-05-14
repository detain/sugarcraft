<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

/**
 * An item within a layout with flex properties.
 */
final class LayoutItem
{
    public function __construct(
        public readonly \SugarCraft\Dash\Foundation\Item $content,
        public readonly int $flex = 0,
    ) {}

    /**
     * Create a LayoutItem with flex grow behavior.
     */
    public static function flex(\SugarCraft\Dash\Foundation\Item $content, int $flex = 1): self
    {
        return new self($content, $flex);
    }

    /**
     * Create a LayoutItem that takes its natural size.
     */
    public static function fixed(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self($content, 0);
    }
}
