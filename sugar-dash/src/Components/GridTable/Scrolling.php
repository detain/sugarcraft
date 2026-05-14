<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Scrolling configuration for data grid tables.
 *
 * Controls virtual scrolling behavior and visible viewport.
 */
final class Scrolling
{
    public function __construct(
        private readonly bool $enabled = false,
        private readonly int $visibleRows = 10,
        private readonly int $rowHeight = 1,
    ) {}

    public static function new(bool $enabled = false, int $visibleRows = 10, int $rowHeight = 1): self
    {
        return new self(enabled: $enabled, visibleRows: $visibleRows, rowHeight: $rowHeight);
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getVisibleRows(): int
    {
        return $this->visibleRows;
    }

    public function getRowHeight(): int
    {
        return $this->rowHeight;
    }

    public function getTotalHeight(): int
    {
        return $this->visibleRows * $this->rowHeight;
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            enabled: $enabled,
            visibleRows: $this->visibleRows,
            rowHeight: $this->rowHeight,
        );
    }

    public function withVisibleRows(int $visibleRows): self
    {
        return new self(
            enabled: $this->enabled,
            visibleRows: max(1, $visibleRows),
            rowHeight: $this->rowHeight,
        );
    }
}
