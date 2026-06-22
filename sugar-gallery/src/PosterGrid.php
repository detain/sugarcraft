<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Zone\Manager as ZoneManager;

/**
 * A responsive, 2-D virtualized poster grid for large collections.
 *
 * The grid is *sparse*: it knows the {@see total()} item count up front but
 * holds only the {@see PosterCard}s that have been fetched, keyed by their
 * ABSOLUTE index. Indices with no card yet render as skeleton placeholders.
 * Only the rows inside the viewport are ever rendered, so a 50,000-item library
 * costs the same to draw as a 50-item one.
 *
 * Paging is owner-driven: after every cursor move the owner reads
 * {@see visibleRange()} (the absolute index window now on screen, the terminal
 * analogue of the web grid's `need-range` event) and fetches the page(s)
 * covering it, splicing the results back in with {@see withItems()} at their
 * absolute index. Navigation methods clamp safely and keep the cursor's row
 * inside the viewport. Immutable: every mutator returns a new grid (clone),
 * leaving the receiver untouched.
 *
 * Each cell is a uniform `cardWidth × cellHeight` box (`cellHeight =
 * posterHeight + 2`, reserving a title and a progress row) so columns and rows
 * always line up regardless of which cards carry progress bars.
 */
final class PosterGrid
{
    /** @var array<int, PosterCard> absolute index → card (sparse) */
    private array $items = [];

    private int $total = 0;
    private int $cursor = 0;
    private int $scrollRow = 0;
    private int $cols = 80;
    private int $rows = 24;

    private function __construct(
        private int $cardWidth,
        private int $posterHeight,
        private int $hSpacing,
        private int $vSpacing,
    ) {
    }

    /**
     * A new empty grid laid out with the given card geometry. Feed it a viewport
     * ({@see withViewport()}) and a result set ({@see reset()} / {@see withItems()}).
     */
    public static function new(int $cardWidth, int $posterHeight, int $hSpacing = 2, int $vSpacing = 1): self
    {
        $grid = new self(max(4, $cardWidth), max(1, $posterHeight), max(0, $hSpacing), max(0, $vSpacing));

        return $grid;
    }

    // ---- result set ----------------------------------------------------

    /**
     * Begin a fresh result set of $total items (e.g. after a filter/sort
     * change): drops all cached cards and returns the cursor to the top.
     */
    public function reset(int $total): self
    {
        $clone = clone $this;
        $clone->items = [];
        $clone->total = max(0, $total);
        $clone->cursor = 0;
        $clone->scrollRow = 0;

        return $clone;
    }

    /**
     * Update the known total without dropping cached cards (e.g. a first page
     * revealed how many items exist), clamping the cursor into range.
     */
    public function withTotal(int $total): self
    {
        $clone = clone $this;
        $clone->total = max(0, $total);

        return $clone->synced($clone->cursor);
    }

    /**
     * Splice a page of cards in at their ABSOLUTE indices, merging with whatever
     * is already loaded (new cards win). This is how a range fetch lands.
     *
     * @param array<int, PosterCard> $items absolute index → card
     */
    public function withItems(array $items): self
    {
        $clone = clone $this;
        foreach ($items as $index => $card) {
            if ($index >= 0) {
                $clone->items[$index] = $card;
            }
        }

        return $clone;
    }

    /** Set (or replace) a single card by absolute index — e.g. an async poster arriving. */
    public function withItem(int $index, PosterCard $card): self
    {
        if ($index < 0) {
            return $this;
        }
        $clone = clone $this;
        $clone->items[$index] = $card;

        return $clone;
    }

    // ---- viewport ------------------------------------------------------

    /** Resize the rendered area (cells), re-clamping the scroll to keep the cursor visible. */
    public function withViewport(int $cols, int $rows): self
    {
        $clone = clone $this;
        $clone->cols = max(1, $cols);
        $clone->rows = max(1, $rows);

        return $clone->synced($clone->cursor);
    }

    // ---- navigation ----------------------------------------------------

    public function left(): self
    {
        return $this->synced($this->cursor - 1);
    }

    public function right(): self
    {
        return $this->synced($this->cursor + 1);
    }

    public function up(): self
    {
        $cols = $this->columns();

        return $this->cursor >= $cols ? $this->synced($this->cursor - $cols) : $this;
    }

    public function down(): self
    {
        $cols = $this->columns();
        $target = $this->cursor + $cols;
        if ($target <= $this->total - 1) {
            return $this->synced($target);
        }

        // No full row below, but step onto the last item if it sits on a lower
        // (partial) row than the cursor.
        $last = $this->total - 1;
        if ($last > $this->cursor && intdiv($last, $cols) > intdiv($this->cursor, $cols)) {
            return $this->synced($last);
        }

        return $this;
    }

    public function pageDown(): self
    {
        return $this->synced($this->cursor + $this->columns() * $this->visibleRows());
    }

    public function pageUp(): self
    {
        return $this->synced($this->cursor - $this->columns() * $this->visibleRows());
    }

    public function home(): self
    {
        return $this->synced(0);
    }

    public function end(): self
    {
        return $this->synced($this->total - 1);
    }

    /** Jump the cursor to an absolute index (e.g. an A–Z letter offset), clamped. */
    public function moveTo(int $index): self
    {
        return $this->synced($index);
    }

    // ---- the need-range window -----------------------------------------

    /**
     * The inclusive absolute-index window currently on screen, optionally
     * widened by $overscanRows above and below for prefetch. The owner compares
     * this to what it last fetched and loads the covering page(s). Returns
     * `[0, -1]` (an empty range) when there are no items.
     *
     * @return array{0:int, 1:int} [startIndex, endIndex] inclusive
     */
    public function visibleRange(int $overscanRows = 0): array
    {
        if ($this->total <= 0) {
            return [0, -1];
        }

        $cols = $this->columns();
        $overscan = max(0, $overscanRows);
        $firstRow = max(0, $this->scrollRow - $overscan);
        $lastRow = $this->scrollRow + $this->visibleRows() - 1 + $overscan;

        $start = min($firstRow * $cols, $this->total - 1);
        $end = min(($lastRow + 1) * $cols - 1, $this->total - 1);

        return [$start, $end];
    }

    // ---- geometry / accessors ------------------------------------------

    public function columns(): int
    {
        return max(1, intdiv($this->cols + $this->hSpacing, $this->cardWidth + $this->hSpacing));
    }

    public function visibleRows(): int
    {
        return max(1, intdiv($this->rows + $this->vSpacing, $this->cellHeight() + $this->vSpacing));
    }

    public function totalRows(): int
    {
        return $this->total <= 0 ? 0 : intdiv($this->total - 1, $this->columns()) + 1;
    }

    public function cellHeight(): int
    {
        return $this->posterHeight + 2;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** How many cards are actually loaded (sparse map size). */
    public function loadedCount(): int
    {
        return count($this->items);
    }

    public function cursorIndex(): int
    {
        return $this->cursor;
    }

    public function cursorRow(): int
    {
        return intdiv($this->cursor, $this->columns());
    }

    public function scrollRow(): int
    {
        return $this->scrollRow;
    }

    public function item(int $index): ?PosterCard
    {
        return $this->items[$index] ?? null;
    }

    public function cursorCard(): ?PosterCard
    {
        return $this->items[$this->cursor] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->total <= 0;
    }

    // ---- rendering -----------------------------------------------------

    /**
     * Render the visible rows. The cell at the cursor is highlighted only when
     * $focused (so an unfocused grid shows no cursor). When a {@see ZoneManager}
     * is given, each real cell is wrapped as zone id `cell:<index>` for mouse
     * hit-testing — the caller scans the assembled frame and resolves clicks.
     */
    public function render(bool $focused = true, ?ZoneManager $zones = null): string
    {
        if ($this->total <= 0) {
            return '';
        }

        $cols = $this->columns();
        $vis = $this->visibleRows();
        $totalRows = $this->totalRows();

        $rowBlocks = [];
        for ($r = $this->scrollRow; $r < $this->scrollRow + $vis && $r < $totalRows; $r++) {
            $cells = [];
            for ($c = 0; $c < $cols; $c++) {
                $idx = $r * $cols + $c;
                $cells[] = $this->renderCell($idx, $focused && $idx === $this->cursor, $zones);
            }
            $rowBlocks[] = Layout::joinHorizontalWithSpacing(0.0, $this->hSpacing, ...$cells);
        }

        return $rowBlocks === [] ? '' : Layout::joinVerticalWithSpacing(0.0, $this->vSpacing, ...$rowBlocks);
    }

    private function renderCell(int $idx, bool $focused, ?ZoneManager $zones): string
    {
        if ($idx >= $this->total) {
            // A trailing slot in the last partial row — keep the grid square.
            return $this->blankCell();
        }

        $card = $this->items[$idx] ?? null;
        $body = $card !== null
            ? $this->box($card->render($focused, $this->cardWidth, $this->posterHeight))
            : $this->skeletonCell();

        return $zones !== null ? $zones->mark('cell:' . $idx, $body) : $body;
    }

    /**
     * Normalize a card render to exactly cardWidth × cellHeight *visual* cells:
     * clip/pad the line count to cellHeight and every line (ANSI-aware) to
     * cardWidth, so an over- or under-sized poster can never inflate its column
     * and break grid alignment.
     */
    private function box(string $block): string
    {
        $width = $this->cardWidth;
        $height = $this->cellHeight();
        $lines = explode("\n", $block);

        if (count($lines) > $height) {
            $lines = array_slice($lines, 0, $height);
        }
        while (count($lines) < $height) {
            $lines[] = '';
        }

        foreach ($lines as $i => $line) {
            $w = Width::string($line);
            if ($w > $width) {
                $lines[$i] = Width::truncateAnsi($line, $width);
            } elseif ($w < $width) {
                $lines[$i] = Width::padRight($line, $width);
            }
        }

        return implode("\n", $lines);
    }

    private function skeletonCell(): string
    {
        $rows = array_fill(0, $this->posterHeight, str_repeat('░', $this->cardWidth));

        return $this->box(implode("\n", $rows));
    }

    private function blankCell(): string
    {
        return implode("\n", array_fill(0, $this->cellHeight(), str_repeat(' ', $this->cardWidth)));
    }

    // ---- internals -----------------------------------------------------

    /**
     * Clamp the cursor into range and scroll the viewport the minimum needed to
     * keep the cursor's row visible. Returns the receiver unchanged when nothing
     * moves, so callers can cheaply detect "no-op" by identity.
     */
    private function synced(int $cursor): self
    {
        if ($this->total <= 0) {
            return $this;
        }

        $cursor = max(0, min($this->total - 1, $cursor));

        $cols = $this->columns();
        $vis = $this->visibleRows();
        $row = intdiv($cursor, $cols);
        $maxScroll = max(0, $this->totalRows() - $vis);

        $scroll = $this->scrollRow;
        if ($row < $scroll) {
            $scroll = $row;
        } elseif ($row >= $scroll + $vis) {
            $scroll = $row - $vis + 1;
        }
        $scroll = max(0, min($scroll, $maxScroll));

        if ($cursor === $this->cursor && $scroll === $this->scrollRow) {
            return $this;
        }

        $clone = clone $this;
        $clone->cursor = $cursor;
        $clone->scrollRow = $scroll;

        return $clone;
    }
}
