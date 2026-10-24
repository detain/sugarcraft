<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\GridTable;

/**
 * Table footer for data grid tables.
 *
 * Renders a footer row with summaries or custom content.
 */
final class Footer
{
    public function __construct(
        private readonly ?array $summaryColumns = null,
        private readonly bool $showRowCount = true,
    ) {}

    public static function new(?array $summaryColumns = null, bool $showRowCount = true): self
    {
        return new self(summaryColumns: $summaryColumns, showRowCount: $showRowCount);
    }

    public static function none(): self
    {
        return new self(summaryColumns: null, showRowCount: false);
    }

    /**
     * @return array<string, string>|null
     */
    public function summaryColumns(): ?array
    {
        return $this->summaryColumns;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::summaryColumns()} instead. REMOVED-IN: 2.0.0.
     */
    public function getSummaryColumns(): ?array { return $this->summaryColumns(); }

    public function shouldShowRowCount(): bool
    {
        return $this->showRowCount;
    }

    public function render(): string
    {
        return '';
    }

    public function withSummaryColumns(?array $summaryColumns): self
    {
        return new self(
            summaryColumns: $summaryColumns,
            showRowCount: $this->showRowCount,
        );
    }

    public function withShowRowCount(bool $show): self
    {
        return new self(
            summaryColumns: $this->summaryColumns,
            showRowCount: $show,
        );
    }
}
