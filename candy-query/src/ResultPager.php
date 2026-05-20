<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Query\Lang;

/**
 * Cursor-based result-set pager for SQL row sets.
 *
 * Immutable — all navigation returns a new Pager instance.  Page size
 * defaults to 25 rows and is configurable via constructor or
 * {@see withPageSize()}.
 *
 * @template T of array<string, mixed>
 */
final class ResultPager
{
    /**
     * @param list<T> $rows       Full result-set
     * @param int     $pageSize    Rows per page
     * @param int     $offset      Current offset (0-based)
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $pageSize = 25,
        public readonly int $offset = 0,
    ) {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException(
                Lang::t('pager.invalid_page_size', ['size' => (string) $pageSize]),
            );
        }
    }

    /**
     * Number of rows in the full result-set.
     */
    public function totalRows(): int
    {
        return count($this->rows);
    }

    /**
     * Total number of pages.
     */
    public function totalPages(): int
    {
        return $this->pageSize > 0
            ? (int) ceil($this->totalRows() / $this->pageSize)
            : 0;
    }

    /**
     * Current 1-based page number.
     */
    public function currentPage(): int
    {
        if ($this->totalPages() === 0) {
            return 0;
        }
        return (int) floor($this->offset / $this->pageSize) + 1;
    }

    /**
     * Whether a next page exists.
     */
    public function hasNextPage(): bool
    {
        return ($this->offset + $this->pageSize) < $this->totalRows();
    }

    /**
     * Whether a previous page exists.
     */
    public function hasPrevPage(): bool
    {
        return $this->offset > 0;
    }

    /**
     * Rows on the current page.
     *
     * @return list<T>
     */
    public function page(): array
    {
        return array_slice($this->rows, $this->offset, $this->pageSize);
    }

    /**
     * Advance to the next page.
     */
    public function nextPage(): self
    {
        $next = $this->offset + $this->pageSize;
        return new self(
            rows: $this->rows,
            pageSize: $this->pageSize,
            offset: min($next, max(0, $this->totalRows() - 1)),
        );
    }

    /**
     * Go back to the previous page.
     */
    public function prevPage(): self
    {
        return new self(
            rows: $this->rows,
            pageSize: $this->pageSize,
            offset: max(0, $this->offset - $this->pageSize),
        );
    }

    /**
     * Jump to a specific page number (1-based).
     */
    public function goToPage(int $page): self
    {
        if ($page < 1) {
            $page = 1;
        }
        $total = $this->totalPages();
        if ($total > 0 && $page > $total) {
            $page = $total;
        }
        $offset = ($page - 1) * $this->pageSize;
        return new self(
            rows: $this->rows,
            pageSize: $this->pageSize,
            offset: max(0, min($offset, max(0, $this->totalRows() - 1))),
        );
    }

    /**
     * Return a new pager with a different page size.
     */
    public function withPageSize(int $size): self
    {
        if ($size < 1) {
            $size = 1;
        }
        $rows = $this->rows;
        $offset = $this->offset;
        $maxOffset = max(0, count($rows) - 1);
        return new self(rows: $rows, pageSize: $size, offset: min($offset, $maxOffset));
    }
}
