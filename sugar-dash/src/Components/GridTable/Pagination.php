<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Pagination state for data grid tables.
 *
 * Tracks current page, page size, and total records.
 */
final class Pagination
{
    public function __construct(
        private readonly int $page = 1,
        private readonly int $pageSize = 25,
        private readonly int $totalRows = 0,
    ) {}

    public static function new(int $page = 1, int $pageSize = 25, int $totalRows = 0): self
    {
        return new self(page: $page, pageSize: $pageSize, totalRows: $totalRows);
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function getTotalPages(): int
    {
        if ($this->pageSize <= 0) {
            return 0;
        }
        return (int) ceil($this->totalRows / $this->pageSize);
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->getTotalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function withPage(int $page): self
    {
        return new self(
            page: max(1, min($page, $this->getTotalPages())),
            pageSize: $this->pageSize,
            totalRows: $this->totalRows,
        );
    }

    public function withPageSize(int $pageSize): self
    {
        return new self(
            page: $this->page,
            pageSize: max(1, $pageSize),
            totalRows: $this->totalRows,
        );
    }
}
