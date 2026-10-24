<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

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

    public function horizontal(): OverflowMode
    {
        return $this->horizontal;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::horizontal()} instead. REMOVED-IN: 2.0.0.
     */
    public function getHorizontal(): OverflowMode { return $this->horizontal(); }

    public function vertical(): OverflowMode
    {
        return $this->vertical;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::vertical()} instead. REMOVED-IN: 2.0.0.
     */
    public function getVertical(): OverflowMode { return $this->vertical(); }
}
