<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Overflow handling for data grid tables.
 *
 * Defines behavior when content exceeds cell boundaries.
 */
enum OverflowMode
{
    case Truncate;
    case Wrap;
    case Ellipsis;
}

final class Overflow
{
    public function __construct(
        private readonly OverflowMode $horizontal = OverflowMode::Truncate,
        private readonly OverflowMode $vertical = OverflowMode::Wrap,
    ) {}

    public static function new(
        OverflowMode $horizontal = OverflowMode::Truncate,
        OverflowMode $vertical = OverflowMode::Wrap,
    ): self {
        return new self(horizontal: $horizontal, vertical: $vertical);
    }

    public static function truncate(): self
    {
        return new self(horizontal: OverflowMode::Truncate, vertical: OverflowMode::Truncate);
    }

    public static function wrap(): self
    {
        return new self(horizontal: OverflowMode::Wrap, vertical: OverflowMode::Wrap);
    }

    public static function ellipsis(): self
    {
        return new self(horizontal: OverflowMode::Ellipsis, vertical: OverflowMode::Ellipsis);
    }

    public function getHorizontal(): OverflowMode
    {
        return $this->horizontal;
    }

    public function getVertical(): OverflowMode
    {
        return $this->vertical;
    }
}
