<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Paginator;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * Pagination state + renderer.
 *
 * ```php
 * $p = Paginator::new()->withTotalItems(42)->withPerPage(10); // 5 pages
 * [$start, $end] = $p->sliceBounds();                          // [0, 10]
 * echo $p->view();                                             // "● ○ ○ ○ ○"
 * ```
 *
 * The component is a {@see Model} so it can absorb left/right arrow,
 * h/l (vim), pgup/pgdn key presses directly. Pages are 0-indexed
 * internally; the Arabic view shows them 1-indexed.
 */
final class Paginator implements Model
{
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalItems,
        public readonly Type $type,
        public readonly string $activeDot,
        public readonly string $inactiveDot,
        public readonly string $arabicFormat = '%d/%d',
    ) {}

    public static function new(): self
    {
        return new self(
            page: 0,
            perPage: 10,
            totalItems: 0,
            type: Type::Dots,
            activeDot: '●',
            inactiveDot: '○',
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Right
                || ($msg->type === KeyType::Char && $msg->rune === 'l')
                || $msg->type === KeyType::PageDown
                => [$this->nextPage(), null],
            $msg->type === KeyType::Left
                || ($msg->type === KeyType::Char && $msg->rune === 'h')
                || $msg->type === KeyType::PageUp
                => [$this->prevPage(), null],
            $msg->type === KeyType::Home => [$this->firstPage(), null],
            $msg->type === KeyType::End  => [$this->lastPage(), null],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        $total = max(1, $this->totalPages());
        return match ($this->type) {
            Type::Dots => $this->renderDots($total),
            Type::Arabic => sprintf($this->arabicFormat, $this->page + 1, $total),
        };
    }

    public function totalPages(): int
    {
        if ($this->perPage <= 0 || $this->totalItems <= 0) {
            return 0;
        }
        return (int) ceil($this->totalItems / $this->perPage);
    }

    public function nextPage(): self
    {
        $last = max(0, $this->totalPages() - 1);
        return $this->mutate(page: min($this->page + 1, $last));
    }

    public function prevPage(): self
    {
        return $this->mutate(page: max(0, $this->page - 1));
    }

    /** Move to the first page (page index 0). */
    public function firstPage(): self
    {
        return $this->mutate(page: 0);
    }

    /** Move to the last page. */
    public function lastPage(): self
    {
        return $this->mutate(page: max(0, $this->totalPages() - 1));
    }

    public function onFirstPage(): bool
    {
        return $this->page === 0;
    }

    public function onLastPage(): bool
    {
        return $this->page >= max(0, $this->totalPages() - 1);
    }

    /** Item index range for the current page: [start (incl), end (excl)]. */
    public function sliceBounds(): array
    {
        if ($this->totalItems <= 0) {
            return [0, 0];
        }
        $start = $this->page * $this->perPage;
        $end   = min($this->totalItems, $start + $this->perPage);
        return [$start, $end];
    }

    public function withTotalItems(int $n): self
    {
        $n = max(0, $n);
        $clone = $this->mutate(totalItems: $n);
        // Clamp page if the new total moved us past the last page.
        $last = max(0, $clone->totalPages() - 1);
        if ($clone->page > $last) {
            $clone = $clone->mutate(page: $last);
        }
        return $clone;
    }

    public function withPerPage(int $n): self    { return $this->mutate(perPage: max(1, $n)); }
    public function withPage(int $p): self
    {
        $last = max(0, $this->totalPages() - 1);
        return $this->mutate(page: max(0, min($p, $last)));
    }
    public function withType(Type $t): self      { return $this->mutate(type: $t); }
    public function withDots(string $active, string $inactive): self
    {
        return $this->mutate(activeDot: $active, inactiveDot: $inactive);
    }

    /**
     * Format string for the Arabic page-counter view. Receives the
     * 1-based current page and total page count, in that order.
     * Default `'%d/%d'` (e.g. `"3/8"`); upstream Bubbles defaults to
     * `'%d/%d'` for ASCII contexts but lets callers swap it for e.g.
     * `'Page %d of %d'`. Mirrors `ArabicFormat`.
     */
    public function withArabicFormat(string $fmt): self
    {
        return $this->mutate(arabicFormat: $fmt);
    }

    /**
     * Pin the total number of pages directly. Inverse of computing
     * pages from `totalItems / perPage` — useful when the list isn't
     * evenly partitioned by `perPage` (custom pagination strategies)
     * or when the total isn't yet known and the caller wants to
     * reserve N dots up-front. The total reflects through to
     * {@see totalPages()} by setting `totalItems = pages * perPage`,
     * which keeps the existing arithmetic consistent. Mirrors
     * `SetTotalPages`.
     */
    public function setTotalPages(int $pages): self
    {
        $pages = max(0, $pages);
        // Choose a totalItems that evaluates to exactly $pages via
        // ceil(totalItems / perPage). The simplest is `pages * perPage`.
        return $this->withTotalItems($pages * max(1, $this->perPage));
    }

    /**
     * Number of items rendered on the current page. Equivalent to
     * `min(perPage, totalItems - perPage * page)` clamped at 0.
     * Mirrors `ItemsOnPage(totalItems)` minus the redundant arg.
     */
    public function itemsOnPage(): int
    {
        if ($this->totalItems <= 0 || $this->perPage <= 0) {
            return 0;
        }
        $start = $this->page * $this->perPage;
        return max(0, min($this->perPage, $this->totalItems - $start));
    }

    private function renderDots(int $total): string
    {
        $out = [];
        for ($i = 0; $i < $total; $i++) {
            $out[] = $i === $this->page ? $this->activeDot : $this->inactiveDot;
        }
        return implode(' ', $out);
    }

    private function mutate(
        ?int $page = null,
        ?int $perPage = null,
        ?int $totalItems = null,
        ?Type $type = null,
        ?string $activeDot = null,
        ?string $inactiveDot = null,
        ?string $arabicFormat = null,
    ): self {
        return new self(
            page:         $page         ?? $this->page,
            perPage:      $perPage      ?? $this->perPage,
            totalItems:   $totalItems   ?? $this->totalItems,
            type:         $type         ?? $this->type,
            activeDot:    $activeDot    ?? $this->activeDot,
            inactiveDot:  $inactiveDot  ?? $this->inactiveDot,
            arabicFormat: $arabicFormat ?? $this->arabicFormat,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
