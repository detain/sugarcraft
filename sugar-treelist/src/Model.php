<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

use Psr\Log\LoggerInterface;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;

/**
 * Core list model — stores items, renders visible lines within a viewport.
 *
 * Port of treilik/bubblelister Model. Key properties:
 * - Items are stored as {@see Item} wrappers around \Stringable values
 * - CursorOffset keeps the cursor N lines from the visible viewport edge
 * - lineOffset controls how many lines *before* the cursor to show in the
 *   prefixer/suffixer gap — distinct from cursorOffset which is the viewport
 *   anchor; lineOffset is passed to initPrefixer()/initSuffixer() to compute
 *   relative positioning markers
 * - Wrap limits how many physical lines a multi-line item may produce
 * - Prefixer/Suffixer hooks customise per-line prefix and suffix strings
 * - LessFunc/EqualsFunc plug in external sorting/equality logic
 *
 * ## Per-instance state
 *
 * Each Model instance has its own $idCounter starting at 0. Cloned models
 * via mutate() share the counter's current value (clone is a shallow copy of
 * primitives), so two independent model instances may produce items with the
 * same ID. This is fine for display but IDs should not be used for cross-model
 * identity without coordination.
 *
 * Usage:
 * ```php
 * $model = Model::new()->setWidth(80)->setHeight(24);
 * foreach (['apple', 'banana', 'cherry'] as $f) {
 *     $model = $model->addItem(new StringItem($f));
 * }
 * echo $model->view();
 * ```
 *
 * @see https://github.com/treilik/bubblelister
 */
final class Model
{
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Upper bound for a single viewport dimension. Guards view()/lines() —
     * which lay out up to width × height of content — against a
     * memory-exhaustion DoS from an absurd dimension. Far larger than any
     * real terminal (a few thousand cells).
     */
    public const MAX_DIMENSION = 100000;

    /**
     * Upper bound for the viewport AREA (width × height) accepted by the fluent
     * setters. {@see MAX_DIMENSION} alone guards each axis independently, so
     * 100000×100000 would pass while a full repaint still tries to allocate
     * 10 billion characters — the per-axis cap does not achieve its stated
     * DoS purpose without a product cap. 4M cells ≈ 2000×2000,
     * far beyond any real terminal while keeping a full repaint comfortably cheap.
     */
    public const MAX_TOTAL_CELLS = 4_000_000;

    // Configuration is PRIVATE (audit M7 fix wave): every field below has a
    // guarded fluent setter and a bare accessor, so no write can bypass
    // assertDimension()/assertArea()/sgrCodes()/the filter bookkeeping. The
    // previous public surface let `$m->width = 1_000_000` skip the guards and
    // let `$m->filterFn = ...` desynchronise items/originalItems.

    private int $width  = 80;  // viewport width in cells
    private int $height = 24;  // viewport height in lines
    private int $cursorOffset = 5;  // gap between cursor and viewport edge
    private int $lineOffset = 5;    // how many lines before cursor to show
    private int $wrap = 0;          // max lines per item (0 = unlimited)

    /** @var \Closure(\Stringable, \Stringable): int|null */
    private ?\Closure $lessFunc = null;

    /** @var \Closure(\Stringable, \Stringable): bool|null */
    private ?\Closure $equalsFunc = null;

    private ?Prefixer $prefixer = null;
    private ?Suffixer $suffixer = null;

    /** Style for non-current items (ANSI string); set via setLineStyle(). */
    private string $lineStyle = '';

    /** Style for current item (ANSI string); set via setCurrentStyle(). */
    private string $currentStyle = '';

    /** @var \Closure(\Stringable): bool|null */
    private ?\Closure $filterFn = null;

    private ?FilterState $filterState = null;

    // -------------------------------------------------------------------------
    // Internal state
    // -------------------------------------------------------------------------

    /** @var list<Item> */
    private array $items = [];

    /** @var list<Item> */
    private array $originalItems = [];

    private int $cursorIndex = 0;
    /** Per-instance item ID counter. Cloned models inherit the counter's
     * current value, so different Model instances may produce items with
     * colliding IDs. Use itemIds() for stable in-model identity only. */
    private int $idCounter = 0;

    /**
     * Rows of the last frame emitted by view() — the row-granular delta memo.
     *
     * Each entry is one complete styled row (the exact bytes a full frame
     * would write on that screen line, SGR included). null means "no memo:
     * the next view() writes a full frame". This is render bookkeeping, not
     * item data: it never affects lines(), only the framing of view().
     *
     * @var list<string>|null
     */
    private ?array $previousRows = null;

    /** @var int|null Previous output width for resize detection */
    private ?int $prevWidth = null;

    /** @var int|null Previous output height for resize detection */
    private ?int $prevHeight = null;

    // -------------------------------------------------------------------------
    // Immutable mutation helper
    // -------------------------------------------------------------------------

    /**
     * Create a new instance by cloning and applying changes via $fn.
     *
     * Mirrors the sugar-style/Style.php mutate() pattern.
     *
     * @param callable(self): void $fn
     */
    private function mutate(callable $fn): self
    {
        $clone = clone $this;
        $fn($clone);
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Create a new Model with sane defaults.
     *
     * Default prefixer (DefaultPrefixer) and default suffixer (DefaultSuffixer)
     * are NOT set automatically — call setPrefixer/setSuffixer to enable them.
     */
    public static function new(): self
    {
        return new self();
    }

    // -------------------------------------------------------------------------
    // Public API — fluent setters
    // -------------------------------------------------------------------------

    public function setWidth(int $width): self
    {
        self::assertDimension($width, 'width');
        self::assertArea($width, $this->height);
        return $this->mutate(fn($m) => $m->width = $width);
    }

    public function setHeight(int $height): self
    {
        self::assertDimension($height, 'height');
        self::assertArea($this->width, $height);
        return $this->mutate(fn($m) => $m->height = $height);
    }

    public function setViewport(int $width, int $height): self
    {
        self::assertDimension($width, 'width');
        self::assertDimension($height, 'height');
        self::assertArea($width, $height);
        return $this->mutate(fn($m) => [$m->width, $m->height] = [$width, $height]);
    }

    /**
     * Reject an out-of-range viewport dimension.
     *
     * A negative dimension is invalid input; an absurdly large one would make
     * a full repaint lay out width×height characters and exhaust memory
     * (DoS). Zero is permitted — {@see lines()} rejects a zero viewport at
     * render time with a RuntimeException, which existing callers rely on.
     *
     * @throws \InvalidArgumentException when $n is negative or above MAX_DIMENSION.
     */
    private static function assertDimension(int $n, string $name): void
    {
        if ($n < 0 || $n > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be between 0 and %d, got %d.',
                $name,
                self::MAX_DIMENSION,
                $n,
            ));
        }
    }

    /**
     * Reject a viewport whose AREA exceeds {@see MAX_TOTAL_CELLS}.
     *
     * Complements the per-axis assertDimension(): two individually-valid axes
     * can still multiply into a memory-exhaustion allocation in
     * the renderer. Zero on either axis passes (no allocation; lines()
     * rejects a zero viewport at render time).
     *
     * @throws \InvalidArgumentException when width×height exceeds MAX_TOTAL_CELLS.
     */
    private static function assertArea(int $width, int $height): void
    {
        if ($width > 0 && $height > 0 && $width * $height > self::MAX_TOTAL_CELLS) {
            throw new \InvalidArgumentException(\sprintf(
                'Viewport area %dx%d (%d cells) exceeds MAX_TOTAL_CELLS (%d).',
                $width,
                $height,
                $width * $height,
                self::MAX_TOTAL_CELLS,
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Accessors (bare, per repo convention — audit M7/#11 fix wave)
    // -------------------------------------------------------------------------

    public function width(): int { return $this->width; }

    public function height(): int { return $this->height; }

    public function cursorOffset(): int { return $this->cursorOffset; }

    public function lineOffset(): int { return $this->lineOffset; }

    public function wrap(): int { return $this->wrap; }

    /** @return \Closure(\Stringable, \Stringable): int|null */
    public function lessFunc(): ?\Closure { return $this->lessFunc; }

    /** @return \Closure(\Stringable, \Stringable): bool|null */
    public function equalsFunc(): ?\Closure { return $this->equalsFunc; }

    public function prefixer(): ?Prefixer { return $this->prefixer; }

    public function suffixer(): ?Suffixer { return $this->suffixer; }

    public function lineStyle(): string { return $this->lineStyle; }

    public function currentStyle(): string { return $this->currentStyle; }

    /** @return \Closure(\Stringable): bool|null */
    public function filterFn(): ?\Closure { return $this->filterFn; }

    public function filterState(): ?FilterState { return $this->filterState; }

    /**
     * Install the comparison used by {@see sort()}.
     *
     * @param \Closure(\Stringable, \Stringable): int|null $fn
     */
    public function setLessFunc(?\Closure $fn): self
    {
        return $this->mutate(fn($m) => $m->lessFunc = $fn);
    }

    /**
     * Install the comparison used by {@see find()}.
     *
     * @param \Closure(\Stringable, \Stringable): bool|null $fn
     */
    public function setEqualsFunc(?\Closure $fn): self
    {
        return $this->mutate(fn($m) => $m->equalsFunc = $fn);
    }

    public function setCursorOffset(int $n): self
    {
        return $this->mutate(fn($m) => $m->cursorOffset = $n);
    }

    public function setWrap(int $maxLines): self
    {
        return $this->mutate(fn($m) => $m->wrap = $maxLines);
    }

    public function setPrefixer(Prefixer $p): self
    {
        return $this->mutate(fn($m) => $m->prefixer = $p);
    }

    public function setSuffixer(Suffixer $s): self
    {
        return $this->mutate(fn($m) => $m->suffixer = $s);
    }

    public function setLineStyle(string $ansiStyle): self
    {
        self::sgrCodes($ansiStyle); // reject non-SGR (escape-injection) input up front
        return $this->mutate(fn($m) => $m->lineStyle = $ansiStyle);
    }

    public function setCurrentStyle(string $ansiStyle): self
    {
        self::sgrCodes($ansiStyle); // reject non-SGR (escape-injection) input up front
        return $this->mutate(fn($m) => $m->currentStyle = $ansiStyle);
    }

    public function setLineOffset(int $n): self
    {
        return $this->mutate(fn($m) => $m->lineOffset = $n);
    }

    /**
     * Set a filter function and return a new Model with filtering active.
     *
     * The filterFn receives a \Stringable and returns bool (true = keep item).
     * Setting a filter transitions filterState to filtering; the items list
     * is immediately filtered.
     *
     * @param \Closure(\Stringable): bool $fn
     */
    public function withFilterFn(\Closure $fn): self
    {
        $clone = clone $this;
        $clone->filterFn = $fn;
        $clone->filterState = FilterState::filtering;
        // Capture the pristine list ONLY when transitioning unfiltered → filtering.
        // Re-applying a filter (filter-as-you-type) must keep the originals saved by
        // the first withFilterFn(); overwriting them with the already-filtered list
        // would make withoutFilter() restore a shrunken subset (audit M2 fix wave).
        if ($this->filterFn === null) {
            $clone->originalItems = $clone->items;
        }
        $clone->items = array_values(array_filter(
            $clone->items,
            fn(Item $item) => $fn($item->value),
        ));
        // Clamp cursor to new length
        $clone->cursorIndex = min($clone->cursorIndex, max(0, count($clone->items) - 1));
        // Reset diff state so the next view() emits a full frame, not a delta
        // against the pre-filter frame (which has a different line set).
        $clone->previousRows = null;
        $clone->prevWidth = null;
        $clone->prevHeight = null;
        return $clone;
    }

    /**
     * Clear the filter and return a new Model with unfiltered items.
     *
     * Restores the original item list and transitions filterState to unfiltered.
     *
     * Note: When no filter is active ($filterFn is null), this returns $this
     * directly rather than a clone via mutate(). This is intentional — cloning
     * to return an identical instance would add unnecessary overhead for a
     * guaranteed no-op. Callers checking referential identity will see $this
     * when the filter is already absent, which is the expected no-op behavior.
     */
    public function withoutFilter(): self
    {
        if ($this->filterFn === null) {
            return $this;
        }
        $clone = clone $this;
        $clone->filterFn = null;
        $clone->filterState = FilterState::unfiltered;
        $clone->items = $clone->originalItems;
        $clone->originalItems = [];
        // Clamp cursor
        $clone->cursorIndex = min($clone->cursorIndex, max(0, count($clone->items) - 1));
        // Reset diff state so the next view() emits a full frame, not a delta
        // against the pre-unfilter frame (which has a different line set).
        $clone->previousRows = null;
        $clone->prevWidth = null;
        $clone->prevHeight = null;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Add an item to the list.
     *
     * The id is minted on the clone inside mutate(), never on the receiver —
     * otherwise a forked call would silently advance the base model's counter
     * (breaking the "instances are immutable" contract) and desync its id
     * stream from later forks. Sibling forks of one base legitimately re-mint
     * the same id; ids are per-instance identity only (see class docblock).
     */
    public function addItem(\Stringable $value): self
    {
        return $this->mutate(fn($m) => $m->items[] = new Item($value, $m->idCounter++));
    }

    /**
     * Add multiple items in one call (one clone, multiple pushes).
     *
     * @param \Stringable ...$values
     */
    public function addItems(\Stringable ...$values): self
    {
        return $this->mutate(function ($m) use ($values) {
            foreach ($values as $value) {
                $m->items[] = new Item($value, $m->idCounter++);
            }
        });
    }

    /**
     * Add multiple items from a plain array (strings auto-wrapped in StringItem).
     *
     * @param array<\Stringable|string> $values
     */
    public function addItemsFromArray(array $values): self
    {
        return $this->mutate(function ($m) use ($values) {
            foreach ($values as $value) {
                $m->items[] = new Item(
                    $value instanceof \Stringable ? $value : new StringItem((string) $value),
                    $m->idCounter++
                );
            }
        });
    }

    /**
     * Remove an item by index. Cursor is clamped.
     *
     * Returns a new model instance in all cases — including no-op on
     * out-of-bounds index — so callers can always assume a new instance.
     */
    public function removeItem(int $index): self
    {
        if ($index < 0 || $index >= \count($this->items)) {
            // Return a no-op clone to maintain referential transparency
            return $this->mutate(fn($m) => null);
        }
        return $this->mutate(function ($m) use ($index) {
            \array_splice($m->items, $index, 1);
            $m->cursorIndex = \min($m->cursorIndex, \max(0, \count($m->items) - 1));
        });
    }

    /**
     * Clear all items and reset cursor.
     */
    public function clear(): self
    {
        return $this->mutate(fn($m) => [$m->items, $m->cursorIndex] = [[], 0]);
    }

    /**
     * Sort items using the configured LessFunc.
     *
     * Cursor relocation uses O(1) identity map (Item::$id) rather than
     * O(n) object-identity foreach after the sort.
     */
    public function sort(): self
    {
        if ($this->lessFunc === null) {
            return $this;
        }
        $selected = $this->items[$this->cursorIndex] ?? null;
        $selectedId = $selected?->id;
        $items = $this->items;
        \usort($items, fn(Item $a, Item $b) =>
            ($this->lessFunc)($a->value, $b->value)
        );
        // O(1) cursor relocation via identity map — build id=>index flip, then lookup
        $cursorIndex = $this->cursorIndex;
        if ($selected !== null) {
            $idMap = \array_flip(\array_map(fn(Item $item): int => $item->id, $items));
            $cursorIndex = $idMap[$selectedId] ?? $this->cursorIndex;
        }
        return $this->mutate(fn($m) => [$m->items, $m->cursorIndex] = [$items, $cursorIndex]);
    }

    // -------------------------------------------------------------------------
    // Cursor
    // -------------------------------------------------------------------------

    public function cursorIndex(): int
    {
        return $this->cursorIndex;
    }

    /**
     * Return the value at the cursor.
     *
     * Mirrors Go upstream's GetCursorItem.
     *
     * Error strategy: fail-fast (throws). Unlike view() which catches
     * exceptions and returns an error string for resilient TUI rendering,
     * cursorItem() is a query API that expects the caller to handle the
     * empty-list case explicitly. Throwing keeps the API predictable.
     *
     * @throws \RuntimeException if the list has no items
     */
    public function cursorItem(): \Stringable
    {
        if ($this->isEmpty()) {
            throw new \RuntimeException(Lang::t('list.no_items'));
        }
        return $this->items[$this->cursorIndex]->value;
    }

    public function setCursor(int $index): self
    {
        return $this->mutate(fn($m) => $m->cursorIndex = \max(0, \min($index, \count($m->items) - 1)));
    }

    public function cursorUp(int $n = 1): self
    {
        return $this->setCursor($this->cursorIndex - $n);
    }

    public function cursorDown(int $n = 1): self
    {
        return $this->setCursor($this->cursorIndex + $n);
    }

    /**
     * Move cursor up by one viewport height (page up).
     */
    public function cursorPageUp(int $pages = 1): self
    {
        return $this->setCursor($this->cursorIndex - ($this->height * $pages));
    }

    /**
     * Move cursor down by one viewport height (page down).
     */
    public function cursorPageDown(int $pages = 1): self
    {
        return $this->setCursor($this->cursorIndex + ($this->height * $pages));
    }

    /**
     * Move cursor to the first item (index 0).
     */
    public function cursorToStart(): self
    {
        return $this->setCursor(0);
    }

    /**
     * Move cursor to the last item.
     */
    public function cursorToEnd(): self
    {
        return $this->setCursor(\count($this->items) - 1);
    }

    /**
     * Return the item value at the given index.
     *
     * @throws \OutOfBoundsException if $index is out of range
     */
    public function itemAt(int $index): \Stringable
    {
        if ($index < 0 || $index >= \count($this->items)) {
            throw new \OutOfBoundsException(\sprintf(
                'Index %d is out of bounds (list has %d items)',
                $index,
                \count($this->items)
            ));
        }
        return $this->items[$index]->value;
    }

    /**
     * Return the item value at the given index, or null if out of range.
     */
    public function tryItemAt(int $index): ?\Stringable
    {
        if ($index < 0 || $index >= \count($this->items)) {
            return null;
        }
        return $this->items[$index]->value;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function length(): int
    {
        return \count($this->items);
    }

    /**
     * Return the IDs of all items in order.
     *
     * Useful for tests to verify item identity without reflection.
     *
     * @return list<int>
     */
    public function itemIds(): array
    {
        return \array_map(fn(Item $item): int => $item->id, $this->items);
    }

    /**
     * Find the index of an item whose EqualsFunc matches the given value.
     * Returns -1 if not found.
     */
    public function find(\Stringable $value): int
    {
        if ($this->equalsFunc === null) {
            foreach ($this->items as $i => $item) {
                if ((string) $item->value === (string) $value) {
                    return $i;
                }
            }
            return -1;
        }
        foreach ($this->items as $i => $item) {
            if (($this->equalsFunc)($item->value, $value)) {
                return $i;
            }
        }
        return -1;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the visible lines of the list within the viewport.
     *
     * Window geometry follows the standard scroll-end contract:
     * - the window aims to show cursorOffset lines above the cursor item,
     *   the cursor item, then as many following lines as fit;
     * - when the list runs out below, the window grows from ABOVE instead
     *   of shifting down (a shift could only leave holes), so the frame is
     *   filled with maximum context at the tail and a list shorter than the
     *   viewport renders in full;
     * - invariants: count(returned lines) <= height, and the cursor item's
     *   first line is always inside the returned window.
     *
     * Work stays bounded: only items inside (or adjacent to) the window are
     * rendered — a pass never walks the whole list.
     *
     * @return list<string> Visible lines
     * @throws \RuntimeException If the viewport has zero dimensions or the list is empty.
     */
    public function lines(): array
    {
        if ($this->isEmpty()) {
            throw new \RuntimeException(Lang::t('list.no_items'));
        }
        if ($this->width <= 0 || $this->height <= 0) {
            throw new \RuntimeException(Lang::t('list.zero_viewport'));
        }

        // A top gap that cannot fit beside the cursor row is clamped at render
        // time; setCursorOffset() accepts any sane int, so the frame geometry
        // must not depend on the caller having sized the gap against height.
        $topGap = \min($this->cursorOffset, $this->height - 1);

        // --- Lines above the cursor, collected nearest-first, capped at $topGap.
        // $walkIndex/$walkLine remember how far upward the window could still
        // grow, so the scroll-end backfill below continues this walk instead of
        // re-deriving it. ---
        $above = [];
        $walkIndex = $this->cursorIndex - 1;
        $walkLine = null; // null = all of $walkIndex still unconsumed
        while ($walkIndex >= 0 && \count($above) < $topGap) {
            $itemLines = $this->renderItem($walkIndex);
            $start = $walkLine ?? (\count($itemLines) - 1);
            $i = $start;
            for (; $i >= 0 && \count($above) < $topGap; $i--) {
                $above[] = $itemLines[$i];
            }
            if ($i >= 0) {
                $walkLine = $i; // walk stopped mid-item; $i is the next line to take
            } else {
                $walkLine = null;
                $walkIndex--;   // item consumed whole
            }
        }

        $allLines = \array_reverse($above);

        // --- Cursor item and everything below it, capped by the frame. ---
        $count = \count($this->items);
        for ($index = $this->cursorIndex; $index < $count && \count($allLines) < $this->height; $index++) {
            foreach ($this->renderItem($index) as $line) {
                if (\count($allLines) >= $this->height) {
                    break 2;
                }
                $allLines[] = $line;
            }
        }

        // --- Scroll-end backfill: the list ran out below the cursor, so the
        // frame is short. Extend the window upward (the capped walk above is
        // the only source of withheld lines) until the frame is full or the
        // list head is reached. This is what keeps the cursor from being
        // stranded on a one-line frame at the end of the list. ---
        while (\count($allLines) < $this->height && $walkIndex >= 0) {
            $itemLines = $this->renderItem($walkIndex);
            $available = ($walkLine ?? (\count($itemLines) - 1)) + 1;
            $take = \min($this->height - \count($allLines), $available);
            $firstLine = $available - $take; // first unconsumed line still needed
            $prepend = \array_slice($itemLines, $firstLine, $take);
            $allLines = \array_merge($prepend, $allLines);
            $walkLine = $firstLine > 0 ? $firstLine - 1 : null;
            if ($walkLine === null) {
                $walkIndex--;
            }
        }

        return $allLines;
    }

    /**
     * Generator wrapper over lines().
     *
     * HONEST SCOPE (audit #17): rendering is EAGER — lines() computes the
     * complete array before the first yield, so this provides no interleaving
     * points for event loops. It exists only for generator-shaped consumers;
     * output is identical to lines() when fully consumed.
     *
     * @return \Generator<int, string, mixed, void>
     */
    public function linesStream(): \Generator
    {
        foreach ($this->lines() as $index => $line) {
            yield $index => $line;
        }
    }

    /**
     * Render the list and return as a single newline-joined string.
     *
     * On the first render (or after a resize or a failed frame) emits the
     * FULL output. On subsequent renders with the same dimensions emits a
     * row-granular delta: each row whose bytes changed is rewritten at its
     * absolute screen position (CUP to column 1) followed by erase-to-end,
     * so stale content of any previous length on that row is cleared.
     * Unchanged rows cost nothing, which is what keeps SSH traffic small
     * for cursor moves; a style-only change (the cursor moving on a styled
     * list) repaints exactly the affected rows because rows are compared
     * as complete styled strings.
     *
     * The delta is deliberately ROW-granular rather than cell-granular.
     * sugar-cellbuffer's Cell/Style model cannot round-trip SGR spellings
     * other than RGB, and its cell grid has nowhere honest to put the
     * escape bytes a styled row carries — an earlier cell projection
     * leaked them as printable runes and corrupted every delta frame on
     * styled lists. Writing whole rows sidesteps the whole class of
     * problem: no style transport is needed, wide glyphs are never split,
     * and each repaint is positionally self-contained.
     *
     * @param LoggerInterface|null $logger If provided, errors are logged at WARNING level
     */
    public function view(?LoggerInterface $logger = null): string
    {
        try {
            // Detect window resize — reset the row memo so we emit a full frame.
            if ($this->prevWidth !== null && ($this->prevWidth !== $this->width || $this->prevHeight !== $this->height)) {
                $this->previousRows = null;
            }
            $this->prevWidth = $this->width;
            $this->prevHeight = $this->height;

            $lines = $this->lines();

            // First frame or resize: emit full output and memoise its rows.
            if ($this->previousRows === null) {
                $this->previousRows = $lines;
                return \implode("\n", $lines) . "\n";
            }

            // Subsequent frames with the same dimensions: rewrite only the
            // changed rows. Each write opens with an absolute CUP, so the
            // delta makes no assumption about where the cursor sits.
            $previous = $this->previousRows;
            $this->previousRows = $lines;

            $delta = '';
            $rowCount = \max(\count($lines), \count($previous));
            for ($row = 0; $row < $rowCount; $row++) {
                $newRow = $lines[$row] ?? '';
                if ($newRow === ($previous[$row] ?? '')) {
                    continue;
                }
                // CSI <row>;1 H homes the write; CSI K clears the stale tail.
                $delta .= Ansi::CSI . ($row + 1) . ';1H' . $newRow . Ansi::CSI . 'K' . "\n";
            }

            return $delta;
        } catch (\Throwable $e) {
            // A failed frame never reached the screen, so its rows must not
            // sit in the memo — a stale memo would make every later delta
            // skip rows the terminal never saw. Drop the memo (and the size
            // stamp) and the next successful view() repaints in full.
            $this->previousRows = null;
            $this->prevWidth = null;
            $this->prevHeight = null;
            $logger?->warning('View rendering failed: {message}', ['message' => $e->getMessage()]);
            // Exception prose is untrusted text and this return value is
            // frame content: it goes through the same marked-frames sweep
            // as item values so no control byte or forged zone sentinel
            // can ride the error sink.
            return Sanitize::untrustedForMarkedFrames($e->getMessage()) . "\n";
        }
    }

    // -------------------------------------------------------------------------
    // Internal rendering
    // -------------------------------------------------------------------------

    /**
     * Render styled lines for a single item — applies prefix, optional padded
     * suffix, and per-item style. Mirrors Go upstream's getItemLines.
     *
     * @return list<string>
     */
    private function renderItem(int $itemIndex): array
    {
        $item = $this->items[$itemIndex];

        $prefixWidth = 0;
        $suffixWidth = 0;
        if ($this->prefixer !== null) {
            $prefixWidth = $this->prefixer->initPrefixer(
                $item->value, $itemIndex, $this->cursorIndex,
                $this->lineOffset, $this->width, $this->height,
                \count($this->items),
            );
        }
        if ($this->suffixer !== null) {
            $suffixWidth = $this->suffixer->initSuffixer(
                $item->value, $itemIndex, $this->cursorIndex,
                $this->lineOffset, $this->width, $this->height,
            );
        }

        $contentWidth = $this->width - $prefixWidth - $suffixWidth;
        if ($contentWidth <= 0) {
            return [''];
        }

        // The item value is untrusted display data. Ansi::strip() alone removes
        // only ESC-initiated sequences, so bare C0 (NUL/BEL/BS/VT/FF/SO-US) and
        // DEL from the value would flow through renderItem() into BOTH view()
        // sinks. Sanitize::untrustedForMarkedFrames() is the single choke point:
        // it neutralizes C0/DEL/C1 AND sweeps the U+E000/U+E001 mouse-zone
        // sentinels, so a forged value can never open or steal a candy-mouse
        // zone on the consuming terminal. (Sanitize::untrusted() passes the
        // whole BMP private-use arena through BY CONTRACT — that is correct
        // for image-marker frames but wrong for a frame sink that composes
        // zone markers downstream; the marked-frames variant is the fleet cure.)
        // The legitimate SGR ESC is injected LATER by applyStyle(), after this
        // point, and therefore survives — item-value ANSI is stripped by design.
        $rawLines = $this->hardWrap(
            Sanitize::untrustedForMarkedFrames((string) $item->value),
            $contentWidth,
        );
        if ($this->wrap > 0 && \count($rawLines) > $this->wrap) {
            $rawLines = \array_slice($rawLines, 0, $this->wrap);
        }
        $total = \count($rawLines);

        $output = [];
        foreach ($rawLines as $lineIdx => $lineContent) {
            $linePrefix = $this->prefixer?->prefix($lineIdx, $total) ?? '';

            // Suffix is only emitted (and content right-padded) when the suffixer
            // actually produces a non-empty marker for this line. Matches Go upstream.
            $lineSuffix = '';
            if ($this->suffixer !== null) {
                $rawSuffix = $this->suffixer->suffix($lineIdx, $total);
                if ($rawSuffix !== '') {
                    $free = $contentWidth - $this->ansiWidth($lineContent);
                    $lineSuffix = \str_repeat(' ', \max(0, $free)) . $rawSuffix;
                }
            }

            $line  = $linePrefix . $lineContent . $lineSuffix;
            $style = ($itemIndex === $this->cursorIndex) ? $this->currentStyle : $this->lineStyle;
            if ($style !== '') {
                $line = $this->applyStyle($line, $style);
            }

            $output[] = $line;
        }

        return $output;
    }

    /**
     * Wrap text at contentWidth without breaking words mid-word.
     * Returns list of lines.
     *
     * @return list<string>
     */
    private function hardWrap(string $text, int $contentWidth): array
    {
        if ($contentWidth <= 0) {
            return [''];
        }

        $lines = [];
        foreach (\explode("\n", $text) as $paragraphLine) {
            $words = \preg_split('/\s+/', $paragraphLine) ?: [];
            $current = '';

            foreach ($words as $word) {
                $withWord = $current === '' ? $word : $current . ' ' . $word;
                if ($this->ansiWidth($withWord) <= $contentWidth) {
                    $current = $withWord;
                } else {
                    if ($current !== '') {
                        $lines[] = $current;
                    }
                    // If single word exceeds width, split it
                    if ($this->ansiWidth($word) > $contentWidth) {
                        $lines = \array_merge($lines, $this->splitOverWidth($word, $contentWidth));
                    } else {
                        $current = $word;
                    }
                }
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines !== [] ? $lines : [''];
    }

    /**
     * Split a word that exceeds maxWidth into cell-budgeted chunks.
     *
     * Chunks accumulate whole graphemes while their DISPLAY WIDTH (SugarCore
     * Width — CJK/emoji count 2 cells) fits maxWidth; the previous
     * grapheme-count chunking let a CJK word of width 16 through a 12-cell
     * viewport unsplitted (audit M5 fix wave). A grapheme wider than the whole
     * budget cannot be split further and keeps its own (overflowing) chunk.
     *
     * @return list<string>
     */
    private function splitOverWidth(string $word, int $maxWidth): array
    {
        $chunks = [];
        // grapheme_str_split() is unavailable on older bundled ext-intl, so
        // walk grapheme units explicitly via grapheme_substr.
        $total = \grapheme_strlen($word);

        for ($start = 0; $start < $total;) {
            $end = $start;
            $width = 0;
            while ($end < $total) {
                $graphemeWidth = \max(1, Width::string(\grapheme_substr($word, $end, 1)));
                if ($end > $start && $width + $graphemeWidth > $maxWidth) {
                    break;
                }
                $width += $graphemeWidth;
                $end++;
            }
            $chunks[] = \grapheme_substr($word, $start, $end - $start);
            $start = $end;
        }

        return $chunks ?: [''];
    }

    // -------------------------------------------------------------------------
    // Style helpers
    // -------------------------------------------------------------------------

    /** Apply ANSI SGR style codes to a string. */
    private function applyStyle(string $s, string $style): string
    {
        // Simple ANSI SGR: \e[Xm or \e[X;Y;Zm
        if ($style === '') {
            return $s;
        }
        // Re-validate at the splice point (defense in depth): setters already
        // reject non-SGR styles, but reflection into the now-private fields must
        // still not smuggle an OSC/APC/other escape into the output stream.
        $codes = self::sgrCodes($style);
        if ($codes === '') {
            return $s;
        }
        return Ansi::CSI . $codes . 'm' . $s . Ansi::reset();
    }

    /**
     * Validate an ANSI SGR style string and return its bare numeric parameters.
     *
     * The style may be wrapped (`"\e[1;31m"`) or bare (`"1;31"`); either form is
     * stripped to the parameter bytes. Anything outside the SGR grammar
     * (`[0-9;]`) is rejected so a caller-supplied style cannot smuggle an
     * OSC/APC/other escape sequence into the emitted stream — a terminal-escape
     * injection that could rewrite the title, run clipboard/hyperlink payloads,
     * or corrupt the display.
     *
     * @throws \InvalidArgumentException when $style contains non-SGR bytes.
     */
    private static function sgrCodes(string $style): string
    {
        if ($style === '') {
            return '';
        }
        // Lax by design: trim() also swallows stray [, ] and m spellings
        // ("m1;3", "[;1"), so those parse as the same SGR parameters. That is
        // cosmetic acceptance, never injection — anything beyond [0-9;] that
        // survives the trim is rejected below, which is what the README's
        // "reject non-SGR escapes" promise rests on.
        $codes = \trim($style, "\e\x1b[]m");
        if (\preg_match('/^[0-9;]*$/', $codes) !== 1) {
            throw new \InvalidArgumentException(
                'Style must contain only ANSI SGR parameters ([0-9;]); '
                . 'escape or control sequences are not allowed.',
            );
        }
        return $codes;
    }

    /** Compute printable (non-ANSI) cell width. */
    private function ansiWidth(string $s): int
    {
        return Width::string($s);
    }

    /**
     * Reset the previous-frame row memo, forcing the next view() to emit
     * a full frame (used on window resize or cursor-position-lost events).
     *
     * This method intentionally mutates the internal state for performance
     * reasons in the event-stream context. The alternative (returning a new
     * model with previousRows=null) would require callers to track the
     * returned instance, which breaks the typical event-handler pattern
     * where the same model instance is reused across frames. The mutation
     * is safe because it only affects delta framing, not item data.
     *
     * The memo travels with the instance lineage: mutate()-built clones
     * inherit it, so rendering DIFFERENT lineages alternately onto one
     * terminal without a reset here produces deltas against the other
     * lineage's rows. Call resetPreviousFrame() on the model you are about
     * to paint whenever you switch which instance owns the screen.
     */
    public function resetPreviousFrame(): void
    {
        $this->previousRows = null;
    }
}
