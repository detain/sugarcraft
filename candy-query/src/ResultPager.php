<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Bits\Paginator\Paginator;
use SugarCraft\Query\Lang;

/**
 * Cursor-based result-set pager for SQL row sets.
 *
 * Immutable — all navigation returns a new pager instance. Page size
 * defaults to 25 rows and is configurable via the constructor or
 * {@see withPageSize()}.
 *
 * The page-index arithmetic (page count, slice bounds, range clamping,
 * next/prev) is delegated to the shared {@see Paginator} primitive from
 * sugar-bits rather than hand-rolled here; this class adds the row-set
 * slicing and the offset-based, 1-based public surface candy-query's UI
 * is built on. Because the cursor is page-indexed underneath, {@see $offset}
 * is always snapped to its enclosing page boundary.
 *
 * @template T of array<string, mixed>
 */
final class ResultPager
{
    /** Current offset (0-based), aligned to a page boundary. */
    public readonly int $offset;

    private readonly Paginator $paginator;

    /**
     * @param list<T> $rows     Full result-set
     * @param int     $pageSize Rows per page
     * @param int     $offset   Desired offset (0-based); snapped to the
     *                          enclosing page boundary and clamped in range
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $pageSize = 25,
        int $offset = 0,
    ) {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException(
                Lang::t('pager.invalid_page_size', ['size' => (string) $pageSize]),
            );
        }

        $this->paginator = Paginator::new()
            ->withPerPage($pageSize)
            ->withTotalItems(count($rows))
            ->withPage(intdiv(max(0, $offset), $pageSize));

        $this->offset = $this->paginator->page * $pageSize;
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
        return $this->paginator->totalPages();
    }

    /**
     * Current 1-based page number (0 when the result-set is empty).
     */
    public function currentPage(): int
    {
        return $this->totalRows() > 0 ? $this->paginator->page + 1 : 0;
    }

    /**
     * Whether a next page exists.
     */
    public function hasNextPage(): bool
    {
        return $this->totalPages() > 0 && !$this->paginator->onLastPage();
    }

    /**
     * Whether a previous page exists.
     */
    public function hasPrevPage(): bool
    {
        return !$this->paginator->onFirstPage();
    }

    /**
     * Rows on the current page.
     *
     * @return list<T>
     */
    public function page(): array
    {
        [$start, $end] = $this->paginator->sliceBounds();

        return array_slice($this->rows, $start, $end - $start);
    }

    /**
     * Advance to the next page (clamped at the last page).
     */
    public function nextPage(): self
    {
        return $this->atPage($this->paginator->nextPage()->page);
    }

    /**
     * Go back to the previous page (clamped at the first page).
     */
    public function prevPage(): self
    {
        return $this->atPage($this->paginator->prevPage()->page);
    }

    /**
     * Jump to a specific page number (1-based; clamped into range).
     */
    public function goToPage(int $page): self
    {
        // Public API is 1-based; Paginator is 0-based internally.
        return $this->atPage(max(0, $page - 1));
    }

    /**
     * Return a new pager with a different page size, keeping the cursor
     * on the row that currently heads the page.
     */
    public function withPageSize(int $size): self
    {
        return new self($this->rows, max(1, $size), $this->offset);
    }

    /**
     * Rebuild the pager positioned at a 0-based page index. The
     * constructor re-clamps the resulting offset, so out-of-range
     * indices are safe.
     */
    private function atPage(int $page): self
    {
        return new self($this->rows, $this->pageSize, $page * $this->pageSize);
    }
}
