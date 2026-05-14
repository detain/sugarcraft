<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Pagination state for the grid.
 */
final class Pagination
{
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly int $totalRows = 0,
    ) {}

    /**
     * Total number of pages.
     */
    public function totalPages(): int
    {
        if ($this->perPage <= 0 || $this->totalRows === 0) {
            return 1;
        }

        return (int) ceil($this->totalRows / $this->perPage);
    }

    /**
     * Zero-based offset for the current page.
     */
    public function offset(): int
    {
        if ($this->perPage <= 0) {
            return 0;
        }

        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Clamp page to valid range.
     */
    public function clampPage(int $page): int
    {
        if ($page < 1) {
            return 1;
        }

        $max = $this->totalPages();

        return $page > $max ? $max : $page;
    }

    /**
     * Create a new pagination with updated page.
     */
    public function withPage(int $page): self
    {
        return new self(
            page: $this->clampPage($page),
            perPage: $this->perPage,
            totalRows: $this->totalRows,
        );
    }

    /**
     * Create a new pagination with updated perPage.
     */
    public function withPerPage(int $perPage): self
    {
        return new self(
            page: $this->page,
            perPage: $perPage,
            totalRows: $this->totalRows,
        );
    }

    /**
     * Create a new pagination with updated totalRows.
     */
    public function withTotalRows(int $totalRows): self
    {
        return new self(
            page: $this->page,
            perPage: $this->perPage,
            totalRows: $totalRows,
        );
    }
}
