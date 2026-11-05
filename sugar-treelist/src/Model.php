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
 * List view model. Key properties:
 * - Items are stored as {@see Item} wrappers around \Stringable values
 * - CursorOffset keeps the cursor N lines from the TOP edge of the viewport
 *   (the gap is enforced above the cursor only, render-clamped to height - 1)
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

    // Configuration is PRIVATE: every field below has a guarded fluent setter
    // and a bare accessor, so no write can bypass
    // assertDimension()/assertArea()/sgrCodes()/the filter bookkeeping. A
    // public surface would let `$m->width = 1_000_000` skip the guards and
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
    // Accessors (bare, per repo convention)
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

    /**
     * Desired number of context lines kept above the cursor.
     *
     * Validated like every other geometry knob (negative values are a caller
     * bug, not a render decision). The renderer additionally clamps the gap
     * to height-1, so an offset larger than the viewport can never strand
     * the cursor outside the frame.
     *
     * @throws \InvalidArgumentException on negative $n
     */
    public function setCursorOffset(int $n): self
    {
        self::assertDimension($n, 'cursorOffset');
        return $this->mutate(fn($m) => $m->cursorOffset = $n);
    }

    /**
     * Maximum rendered lines per item (0 = unlimited, subject to the viewport).
     *
     * @throws \InvalidArgumentException on negative $maxLines
     */
    public function setWrap(int $maxLines): self
    {
        self::assertDimension($maxLines, 'wrap');
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

    /**
     * Context-line offset handed to the prefixer/suffixer on every init call
     * (relative positioning input for gutter hooks — not a viewport knob).
     *
     * @throws \InvalidArgumentException on negative $n
     */
    public function setLineOffset(int $n): self
    {
        self::assertDimension($n, 'lineOffset');
        return $this->mutate(fn($m) => $m->lineOffset = $n);
    }

    /**
     * Set a filter function and return a new Model with filtering active.
     *
     * The filterFn receives a \Stringable and returns bool (true = keep item).
     * Setting a filter transitions filterState to filtering; the items list
     * is immediately filtered. While a filter is active the model keeps the
     * pristine list authoritative — see {@see reapplyFilter()} — so the
     * filter behaves as a live VIEW over the data: mutations under an active
     * filter are not lost, and re-applying a wider predicate (or calling
     * withoutFilter()) reveals everything that was really there.
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
        // would make withoutFilter() restore a shrunken subset.
        if ($this->filterFn === null) {
            $clone->originalItems = $clone->items;
        }
        $clone->reapplyFilter();
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

    /**
     * Recompute the visible list from the pristine list while filtering.
     *
     * While a filter is active, `items` is a PROJECTION of `originalItems`,
     * not a second source of truth: every mutation lands on the pristine list
     * first and this runs immediately after, so nothing added, removed,
     * cleared or sorted under a filter can be silently lost, resurrected, or
     * undone when the filter lifts. The cursor is clamped into the projection.
     *
     * Call only on a clone (inside mutate()) or during a filter transition.
     */
    private function reapplyFilter(): void
    {
        if ($this->filterFn === null) {
            return;
        }
        $fn = $this->filterFn;
        $this->items = array_values(array_filter(
            $this->originalItems,
            static fn(Item $item): bool => (bool) $fn($item->value),
        ));
        $this->cursorIndex = min($this->cursorIndex, max(0, count($this->items) - 1));
    }

    /**
     * Put the cursor back on the same logical item after a list edit.
     *
     * Clamping the raw index silently hands the cursor to a DIFFERENT item
     * whenever something above it is removed. Re-anchoring by Item::$id keeps
     * it on the row the user was looking at; when the cursor item itself was
     * removed, the cursor stays at the same position (which now holds the
     * following item), clamped into range.
     *
     * @param array<array-key, Item> $items
     */
    private static function relocateCursor(array $items, ?int $cursorId, int $preferredIndex): int
    {
        if ($cursorId !== null) {
            $idMap = \array_flip(\array_map(static fn(Item $item): int => $item->id, $items));
            if (isset($idMap[$cursorId])) {
                return (int) $idMap[$cursorId];
            }
        }
        return max(0, min($preferredIndex, count($items) - 1));
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
     *
     * While a filter is active the item joins the pristine list and is then
     * projected through the predicate: an item that does not match stays
     * invisible but is never lost — withoutFilter() (or a widened predicate)
     * reveals it.
     */
    public function addItem(\Stringable $value): self
    {
        return $this->mutate(function ($m) use ($value) {
            $item = new Item($value, $m->idCounter++);
            if ($m->filterFn !== null) {
                $m->originalItems[] = $item;
            } else {
                $m->items[] = $item;
            }
            $m->reapplyFilter();
        });
    }

    /**
     * Add multiple items in one call (one clone, multiple pushes).
     *
     * Filtered models follow the {@see addItem()} rule: pushes go to the
     * pristine list, the projection is recomputed once.
     *
     * @param \Stringable ...$values
     */
    public function addItems(\Stringable ...$values): self
    {
        return $this->mutate(function ($m) use ($values) {
            foreach ($values as $value) {
                $item = new Item($value, $m->idCounter++);
                if ($m->filterFn !== null) {
                    $m->originalItems[] = $item;
                } else {
                    $m->items[] = $item;
                }
            }
            $m->reapplyFilter();
        });
    }

    /**
     * Add multiple items from a plain array (strings auto-wrapped in StringItem).
     *
     * Filtered models follow the {@see addItem()} rule.
     *
     * @param array<\Stringable|string> $values
     */
    public function addItemsFromArray(array $values): self
    {
        return $this->mutate(function ($m) use ($values) {
            foreach ($values as $value) {
                $item = new Item(
                    $value instanceof \Stringable ? $value : new StringItem((string) $value),
                    $m->idCounter++
                );
                if ($m->filterFn !== null) {
                    $m->originalItems[] = $item;
                } else {
                    $m->items[] = $item;
                }
            }
            $m->reapplyFilter();
        });
    }

    /**
     * Remove an item by its VISIBLE index. Cursor is re-anchored by identity.
     *
     * The index addresses the projected list the user sees; removal is
     * applied to the authoritative storage (pristine list while filtering),
     * the projection is recomputed, and the cursor follows the logical item it
     * was on — removing a row above the cursor no longer silently renames the
     * selected item.
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
        $cursorId = $this->items[$this->cursorIndex]->id;
        $preferred = $this->cursorIndex;
        return $this->mutate(function ($m) use ($index, $cursorId, $preferred) {
            $targetId = $m->items[$index]->id;
            if ($m->filterFn !== null) {
                $m->originalItems = \array_values(\array_filter(
                    $m->originalItems,
                    static fn(Item $item): bool => $item->id !== $targetId,
                ));
                $m->reapplyFilter();
            } else {
                \array_splice($m->items, $index, 1);
            }
            $m->cursorIndex = self::relocateCursor($m->items, $cursorId, $preferred);
        });
    }

    /**
     * Clear all items and reset cursor.
     *
     * While a filter is active the pristine list is cleared too — otherwise
     * lifting the filter would resurrect everything this call just deleted.
     */
    public function clear(): self
    {
        return $this->mutate(function ($m) {
            $m->items = [];
            $m->originalItems = [];
            $m->cursorIndex = 0;
        });
    }

    /**
     * Sort items using the configured LessFunc.
     *
     * With no LessFunc configured there is nothing to order by: the call is an
     * identity return and hands back $this unchanged (no clone is minted).
     *
     * The cursor follows the logical item it was on (Item::$id), so a sort is
     * never silently a de-select. While a filter is active the pristine list
     * is the thing ordered, so the new order survives withoutFilter() instead
     * of being un-sorted by the stale pristine copy.
     */
    public function sort(): self
    {
        if ($this->lessFunc === null) {
            return $this;
        }
        $cursorId = ($this->items[$this->cursorIndex] ?? null)?->id;
        $preferred = $this->cursorIndex;
        return $this->mutate(function ($m) use ($cursorId, $preferred) {
            $list = $m->filterFn !== null ? $m->originalItems : $m->items;
            \usort($list, fn(Item $a, Item $b): int => ($this->lessFunc)($a->value, $b->value));
            if ($m->filterFn !== null) {
                $m->originalItems = $list;
                $m->reapplyFilter();
            } else {
                $m->items = $list;
            }
            $m->cursorIndex = self::relocateCursor($m->items, $cursorId, $preferred);
        });
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

    /**
     * Return the value at the cursor, or null when the list is empty.
     *
     * The non-throwing sibling of {@see cursorItem()} — mirrors tryItemAt()'s
     * relationship to itemAt(). Prefer it in render/keystroke paths where an
     * empty list is a normal state, not a caller bug.
     */
    public function tryCursorItem(): ?\Stringable
    {
        return ($this->items[$this->cursorIndex] ?? null)?->value;
    }

    public function setCursor(int $index): self
    {
        return $this->mutate(fn($m) => $m->cursorIndex = \max(0, \min($index, \count($m->items) - 1)));
    }

    /**
     * Move the cursor up by $n items (negative $n moves down).
     *
     * Saturating: $n at either end of the integer range can overflow the
     * subtraction into a float (which strict_types would then reject), so
     * unreachable magnitudes clamp straight to the list bounds instead of
     * being arithmetically evaluated.
     */
    public function cursorUp(int $n = 1): self
    {
        $count = \count($this->items);
        if ($n > $this->cursorIndex) {
            return $this->setCursor(0);
        }
        if ($n < -$count) {
            // Negative beyond the whole list moving "up" = past the end.
            return $this->setCursor($count - 1);
        }
        return $this->setCursor($this->cursorIndex - $n);
    }

    /**
     * Move the cursor down by $n items (negative $n moves up).
     *
     * Saturating — see {@see cursorUp()} for why raw addition is unsafe.
     */
    public function cursorDown(int $n = 1): self
    {
        $count = \count($this->items);
        if ($n > $count) {
            return $this->setCursor($count - 1);
        }
        if ($n < -$this->cursorIndex) {
            // Negative beyond the whole list moving "down" = past the top.
            return $this->setCursor(0);
        }
        return $this->setCursor($this->cursorIndex + $n);
    }

    /**
     * Page size for cursorPageUp()/cursorPageDown(): one viewport height worth
     * of ITEMS (not displayed lines — multi-line items advance by one per page
     * unit; this is the documented upstream semantic).
     */
    private function pageSpan(int $pages): int
    {
        // height*$pages overflows to float at extreme $pages; saturate first.
        // intdiv() is exact at the boundary where float division is not.
        $factor = max(1, $this->height);
        if ($pages > \intdiv(\PHP_INT_MAX, $factor)) {
            return \PHP_INT_MAX;
        }
        if ($pages < \intdiv(\PHP_INT_MIN, $factor)) {
            return \PHP_INT_MIN;
        }
        return $this->height * $pages;
    }

    /**
     * Move cursor up by one viewport height (page up).
     */
    public function cursorPageUp(int $pages = 1): self
    {
        return $this->cursorUp($this->pageSpan($pages));
    }

    /**
     * Move cursor down by one viewport height (page down).
     */
    public function cursorPageDown(int $pages = 1): self
    {
        return $this->cursorDown($this->pageSpan($pages));
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
     * HONEST SCOPE: rendering is EAGER — lines() computes the
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
        // Row budget: no item can usefully contribute more rows than the
        // viewport shows (wrap() caps tighter still), so the wrap loop stops
        // there — a pathological 200k-line value now costs O(budget) per
        // frame instead of O(value).
        $rawLines = $this->hardWrap(
            Sanitize::untrustedForMarkedFrames((string) $item->value),
            $contentWidth,
            $this->wrap > 0 ? $this->wrap : $this->height,
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
     * Wrap text at contentWidth, preserving whitespace runs (2026-11-03).
     *
     * The old splitter broke on /\s+/ and rejoined with single spaces, which
     * silently ate indentation and double-spaced columns — exactly the tree
     * data a list renderer is for. Wrapping now tokenizes into word and
     * whitespace runs: a leading run (indent) stays attached to its line, an
     * interior run survives inside a line, a run straddling a wrap boundary
     * is consumed, and an empty logical line renders as an empty row
     * (paragraph breaks are content, not noise). An indent run wider than
     * the content column cannot be honoured and is dropped, matching the
     * old collapse.
     *
     * A word alone wider than the budget is split at DISPLAY-width boundaries
     * (SugarCore Width — CJK/emoji count 2 cells); a grapheme wider than the
     * whole budget cannot be split further and keeps its own (overflowing)
     * chunk.
     *
     * @param int|null $maxLines Stop after this many rows (row budget: one
     *                           pathological value costs O(budget) per frame,
     *                           not O(value)).
     *
     * @return list<string>
     */
    private function hardWrap(string $text, int $contentWidth, ?int $maxLines = null): array
    {
        if ($contentWidth <= 0) {
            return [''];
        }
        $budget = $maxLines ?? \PHP_INT_MAX;

        $lines = [];
        // Full split: a bounded explode() would fuse the tail paragraphs on
        // their newlines into one "line" and smuggle raw \n into a rendered
        // row. The savings that matter come from stopping the wrap loop at
        // the budget below, not from the split.
        foreach (\explode("\n", $text) as $paragraphLine) {
            // HTAB is a layout command, not content: the host terminal would
            // expand it to a tab stop no width calculation here can see, so
            // the frame could exceed its column budget. Expand to spaces
            // (run length preserved) instead of smuggling a jump into a row.
            // (The old /\s+/ splitter collapsed tabs with everything else;
            // CR never reaches here — the sanitize choke strips it, pinned.)
            $paragraphLine = \strtr($paragraphLine, "\t", ' ');
            if ($paragraphLine === '') {
                $lines[] = '';
                if (\count($lines) >= $budget) {
                    return $lines;
                }
                continue;
            }

            // Alternate: word, run, word, run, … (first element may be ''
            // when the line opens with whitespace).
            $tokens = \preg_split('/(\s+)/u', $paragraphLine, -1, \PREG_SPLIT_DELIM_CAPTURE)
                ?: [$paragraphLine];

            $current = '';
            $pending = '';
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (\preg_match('/\A\s+\z/u', $token) === 1) {
                    $pending = $token;
                    continue;
                }

                if ($this->ansiWidth($token) > $contentWidth) {
                    // Unbreakable word: flush, then chunk it with the pending
                    // run still attached (the indent rides the first chunk).
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                        if (\count($lines) >= $budget) {
                            return $lines;
                        }
                    }
                    $chunks = $this->splitOverWidth(
                        $pending . $token,
                        $contentWidth,
                        $budget - \count($lines),
                    );
                    $pending = '';
                    foreach ($chunks as $chunk) {
                        $lines[] = $chunk;
                        if (\count($lines) >= $budget) {
                            return $lines;
                        }
                    }
                    continue;
                }

                if ($current === '' && $this->ansiWidth($pending) > $contentWidth) {
                    $pending = ''; // an indent wider than the column cannot fit
                }

                $candidate = $current . $pending . $token;
                if ($current !== '' && $this->ansiWidth($candidate) > $contentWidth) {
                    $lines[] = $current; // the run straddling the break is consumed
                    if (\count($lines) >= $budget) {
                        return $lines;
                    }
                    $current = $token;
                } else {
                    $current = $candidate;
                }
                $pending = '';
            }

            if ($current !== '') {
                $lines[] = $current;
                if (\count($lines) >= $budget) {
                    return $lines;
                }
            }
        }

        return $lines !== [] ? $lines : [''];
    }

    /**
     * Split a word that exceeds maxWidth into cell-budgeted chunks.
     *
     * Chunks accumulate whole graphemes while their DISPLAY WIDTH (SugarCore
     * Width — CJK/emoji count 2 cells) fits maxWidth; grapheme-count
     * chunking let a CJK word of width 16 through a 12-cell viewport
     * unsplitted. A grapheme wider than the whole budget cannot be split
     * further and keeps its own (overflowing) chunk.
     *
     * @param int|null $maxChunks Stop after this many chunks (row budget).
     *
     * @return list<string>
     */
    private function splitOverWidth(string $word, int $maxWidth, ?int $maxChunks = null): array
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
            if ($maxChunks !== null && $maxChunks > 0 && \count($chunks) >= $maxChunks) {
                break; // row budget reached: the remainder is never displayed
            }
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
                'Style must contain only ANSI SGR parameters (digits and semicolons); '
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
