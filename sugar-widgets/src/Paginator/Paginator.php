<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Paginator;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Widgets\Lang;

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
    /**
     * Upper bound on dots rendered by the Dots type. Beyond it the row
     * windows to first … neighbours … last instead of growing with the
     * page count (C6: the old renderer scaled with totalPages, so a
     * 100k-item list emitted a ~10k-dot line into the frame).
     */
    private const MAX_VISIBLE_DOTS = 11;

    /** Marks a run of hidden pages in the windowed dot row. */
    private const DOT_ELLIPSIS = '…';

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
        if (!($msg instanceof KeyMsg)) {
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
        $total = $this->totalPages();
        // An empty list renders nothing: the old max(1, ...) showed a
        // lone active dot (or "1/1") claiming a page exists when it does
        // not (m25). Hosts that want a placeholder render their own.
        if ($total <= 0) {
            return '';
        }
        return match ($this->type) {
            Type::Dots => $this->renderDots($total),
            Type::Arabic => sprintf($this->arabicFormat, min($this->page + 1, $total), $total),
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

    public function withPerPage(int $n): self
    {
        $clone = $this->mutate(perPage: max(1, $n));
        // Shrinking the page size shrinks the page count: a stale page
        // index would render dots for page 46 of 5 and hand out inverted
        // slice bounds (M3). Clamp exactly like withTotalItems().
        $last = max(0, $clone->totalPages() - 1);
        if ($clone->page > $last) {
            $clone = $clone->mutate(page: $last);
        }
        return $clone;
    }
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
     * `'Page %d of %d'`. Mirrors charmbracelet/bubbles `ArabicFormat`.
     *
     * The format is parsed at the boundary: exactly two digit-family
     * `%d`-style specs (optional -,+,' ',0,# flags, width/precision up to
     * two digits) and no other percent-anything, so view() can trust it
     * later — an unchecked `'%s/%s'` throws a ValueError mid-frame (M21),
     * and the trial alone would still admit `'%c/%c'` (raw control bytes
     * at page 27) or `'%.999d'` (runaway width) (W-38).
     */
    public function withArabicFormat(string $fmt): self
    {
        // Parse at the boundary (M21): view() feeds exactly two integers,
        // so a bad specifier or over-large arity must raise here — not as
        // a ValueError/ArgumentCountError mid-frame at render time.
        try {
            sprintf($fmt, 1, 1);
        } catch (\ValueError | \ArgumentCountError) {
            throw new \InvalidArgumentException(Lang::t('paginator.arabic_format_invalid'));
        }
        // Grammar law (W-38/sugar-widgets-060): exactly TWO digit-family
        // specs (%d with the -,+,' ',0,# flags and at most 2 digits of
        // width/precision — '%.999d' class rejected as runaway width),
        // and NO other percent-anything: mask the two legal specs, then
        // refuse any surviving '%' (kills %c/%s/%x payloads, %% tricks,
        // and positional %(arg)$n reordering in one sweep).
        $masked = \preg_replace(
            '/%[-+ 0#]*(?:\d+(?:\.\d{1,2})?)?d/',
            "\0",
            $fmt,
            2,
            $count,
        );
        if ($count !== 2 || $masked === null || \str_contains($masked, '%')) {
            throw new \InvalidArgumentException(Lang::t('paginator.arabic_format_invalid'));
        }
        return $this->mutate(arabicFormat: $fmt);
    }

    /**
     * Pin the total number of pages directly. Inverse of computing
     * pages from `totalItems / perPage` — useful when the list isn't
     * evenly partitioned by `perPage` (custom pagination strategies)
     * or when the total isn't yet known and the caller wants to
     * reserve N dots up-front. The total reflects through to
     * {@see totalPages()} by setting `totalItems = pages * perPage`,
     * which keeps the existing arithmetic consistent. Mirrors charmbracelet/bubbles
     * `SetTotalPages`.
     */
    public function withTotalPages(int $pages): self
    {
        $pages = max(0, $pages);
        // Choose a totalItems that evaluates to exactly $pages via
        // ceil(totalItems / perPage). The simplest is `pages * perPage`.
        return $this->withTotalItems($pages * max(1, $this->perPage));
    }

    /**
     * @deprecated The `set`-prefixed name suggests mutation; this class is
     *     immutable and returns a new instance. Use {@see withTotalPages()}.
     *     Kept as a BC alias for external callers.
     */
    public function setTotalPages(int $pages): self
    {
        return $this->withTotalPages($pages);
    }

    /**
     * Number of items rendered on the current page. Equivalent to
     * `min(perPage, totalItems - perPage * page)` clamped at 0.
     * Mirrors charmbracelet/bubbles `ItemsOnPage(totalItems)` minus the redundant arg.
     */
    public function itemsOnPage(): int
    {
        if ($this->totalItems <= 0 || $this->perPage <= 0) {
            return 0;
        }
        $start = $this->page * $this->perPage;
        return max(0, min($this->perPage, $this->totalItems - $start));
    }

    /**
     * Render the dot row, windowed to {@see MAX_VISIBLE_DOTS}: below the
     * cap every page gets a dot (byte-identical to the historical
     * output); beyond it the row becomes `first … neighbours … last` so
     * the geometry stops scaling with the page count (C6).
     */
    private function renderDots(int $total): string
    {
        if ($total <= self::MAX_VISIBLE_DOTS) {
            $out = [];
            for ($i = 0; $i < $total; $i++) {
                $out[] = $i === $this->page ? $this->activeDot : $this->inactiveDot;
            }
            return implode(' ', $out);
        }

        $dot  = fn(int $i): string => $i === $this->page ? $this->activeDot : $this->inactiveDot;
        $last = $total - 1;
        $out  = [$dot(0), self::DOT_ELLIPSIS];

        $window = self::MAX_VISIBLE_DOTS - 4;
        $lo     = match (true) {
            $this->page === 0    => 1,
            $this->page === $last => $last - $window,
            default              => max(1, min($this->page - intdiv($window, 2), $last - $window)),
        };
        for ($i = $lo; $i < $lo + $window; $i++) {
            $out[] = $dot($i);
        }

        $out[] = self::DOT_ELLIPSIS;
        $out[] = $dot($last);
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
