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
 * // one rebinding rule for everything below: every with*() returns a NEW
 * // Model — chain or rebind, the receiver is never touched.
 * $model = Model::tree() // Default gutters: join column + visible cursor marker
 *     ->withViewport(80, 24)
 *     ->withItemsFromArray(['apple', 'banana', 'cherry']);
 * echo $model->view();
 * ```
 *
 * ## Immutability
 *
 * Every fluent `with*()` setter returns a NEW Model; the receiver is never
 * touched, so rebind (or chain) each step. The carve-out is the render
 * bookkeeping: `view()` updates, and `resetPreviousFrame()` drops, the
 * row-delta memo in place — frames travel down one instance lineage, and
 * that memo is state about the SCREEN, not about item or configuration data.
 * The older `set*()`/`add*()`/`cursor*()` names survive as `@deprecated`
 * one-way aliases delegating to the `with*()` surface (removed in 2.0.0).
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

    /**
     * Hard ceiling on how many bytes of a single item value one frame will
     * scan (256 KB). The row budget bounds the WRAP loop, and renderItem()
     * additionally sizes its raw scan window to the budget (every displayed
     * cell costs at least one raw byte, so rows × (width + 1) + 1 is
     * provably enough) — but a huge viewport must not widen the scan without
     * limit, so this ceiling stands behind it. Without any bound, the
     * sanitize/split/expand sweeps ran O(value) no matter how small the
     * viewport: a pathological Stringable (a 4.4 MB log tail) froze the
     * frame at ~161 ms/view. When raw bytes fall outside the window the
     * item renders the window plus a loud truncation marker row.
     */
    public const MAX_ITEM_RENDER_BYTES = 262_144;

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

    /** Slot optional; once installed the comparison itself must return int. */
    private ?\Closure $lessFunc = null;

    /** Slot optional; once installed the comparison itself must return bool. */
    private ?\Closure $equalsFunc = null;

    private ?Prefixer $prefixer = null;
    private ?Suffixer $suffixer = null;

    /** Style for non-current items (ANSI string); set via withLineStyle(). */
    private string $lineStyle = '';

    /** Style for current item (ANSI string); set via withCurrentStyle(). */
    private string $currentStyle = '';

    /** Slot optional; once installed the predicate itself must return bool. */
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
     * are NOT set automatically — call withPrefixer/withSuffixer to enable them.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * The decorated happy path: {@see self::new()} plus the Default
     * prefixer/suffixer pair that nearly every real list wants (join
     * column + cursor marker, so the selection is actually visible).
     *
     * Plain new() stays deliberately undecorated — custom-gutter
     * consumers must not get gutters they then remove — but repeating
     * `setPrefixer(new DefaultPrefixer())->setSuffixer(new DefaultSuffixer())`
     * at every call site while an unconfigured list renders cursor-less
     * and silent was the lib's biggest friction (sugar-treelist-021).
     */
    public static function tree(): self
    {
        return (new self())->withTreeDefaults();
    }

    /**
     * Install the Default prefixer/suffixer pair in one clone — the
     * instance-side spelling of {@see self::tree()}, so configuration
     * already in flight can pick the defaults up mid-chain. An explicit
     * withPrefixer()/withSuffixer() still overrides afterwards.
     */
    public function withTreeDefaults(): self
    {
        return $this->mutate(function ($m): void {
            $m->prefixer = new DefaultPrefixer();
            $m->suffixer = new DefaultSuffixer();
        });
    }

    // -------------------------------------------------------------------------
    // Public API — fluent setters
    // -------------------------------------------------------------------------

    public function withWidth(int $width): self
    {
        self::assertDimension($width, 'width');
        self::assertArea($width, $this->height);
        return $this->mutate(fn($m) => $m->width = $width);
    }

    /**
     * @deprecated Renamed to {@see self::withWidth()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setWidth(int $width): self { return $this->withWidth($width); }

    public function withHeight(int $height): self
    {
        self::assertDimension($height, 'height');
        self::assertArea($this->width, $height);
        return $this->mutate(fn($m) => $m->height = $height);
    }

    /**
     * @deprecated Renamed to {@see self::withHeight()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setHeight(int $height): self { return $this->withHeight($height); }

    public function withViewport(int $width, int $height): self
    {
        self::assertDimension($width, 'width');
        self::assertDimension($height, 'height');
        self::assertArea($width, $height);
        return $this->mutate(fn($m) => [$m->width, $m->height] = [$width, $height]);
    }

    /**
     * @deprecated Renamed to {@see self::withViewport()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setViewport(int $width, int $height): self { return $this->withViewport($width, $height); }

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

    /** @return (\Closure(\Stringable, \Stringable): int)|null */
    public function lessFunc(): ?\Closure { return $this->lessFunc; }

    /** @return (\Closure(\Stringable, \Stringable): bool)|null */
    public function equalsFunc(): ?\Closure { return $this->equalsFunc; }

    public function prefixer(): ?Prefixer { return $this->prefixer; }

    public function suffixer(): ?Suffixer { return $this->suffixer; }

    public function lineStyle(): string { return $this->lineStyle; }

    public function currentStyle(): string { return $this->currentStyle; }

    /** @return (\Closure(\Stringable): bool)|null */
    public function filterFn(): ?\Closure { return $this->filterFn; }

    public function filterState(): ?FilterState { return $this->filterState; }

    /**
     * Install the comparison used by {@see sort()}.
     *
     * One stated policy for callback slots (sugar-treelist-011/025): the
     * slot is optional, but an installed comparison must return int — a
     * null verdict is a misconfiguration and sort() refuses it loudly with
     * a LogicException naming lessFunc, never a silent TypeError from usort.
     *
     * @param \Closure(\Stringable, \Stringable): int|null $fn  (null return refused at call time)
     */
    public function withLessFunc(?\Closure $fn): self
    {
        return $this->mutate(fn($m) => $m->lessFunc = $fn);
    }

    /**
     * @deprecated Renamed to {@see self::withLessFunc()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setLessFunc(?\Closure $fn): self { return $this->withLessFunc($fn); }

    /**
     * Install the comparison used by {@see find()}.
     *
     * Same stated policy as {@see self::withLessFunc()}: find() refuses a
     * null verdict with a LogicException naming equalsFunc instead of
     * silently reading it as "not equal".
     *
     * @param \Closure(\Stringable, \Stringable): bool|null $fn  (null return refused at call time)
     */
    public function withEqualsFunc(?\Closure $fn): self
    {
        return $this->mutate(fn($m) => $m->equalsFunc = $fn);
    }

    /**
     * @deprecated Renamed to {@see self::withEqualsFunc()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setEqualsFunc(?\Closure $fn): self { return $this->withEqualsFunc($fn); }

    /**
     * Desired number of context lines kept above the cursor.
     *
     * DO NOT CONFUSE: this is the viewport scroll knob. The near-homophone
     * {@see self::withLineOffset()} does not move the viewport at all — it
     * is a pass-through context value for gutter hooks (sugar-treelist-028).
     *
     * Validated like every other geometry knob (negative values are a caller
     * bug, not a render decision). The renderer additionally clamps the gap
     * to height-1, so an offset larger than the viewport can never strand
     * the cursor outside the frame.
     *
     * @throws \InvalidArgumentException on negative $n
     */
    public function withCursorOffset(int $n): self
    {
        self::assertDimension($n, 'cursorOffset');
        return $this->mutate(fn($m) => $m->cursorOffset = $n);
    }

    /**
     * @deprecated Renamed to {@see self::withCursorOffset()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setCursorOffset(int $n): self { return $this->withCursorOffset($n); }

    /**
     * Maximum rendered lines per item (0 = unlimited, subject to the viewport).
     *
     * @throws \InvalidArgumentException on negative $maxLines
     */
    public function withWrap(int $maxLines): self
    {
        self::assertDimension($maxLines, 'wrap');
        return $this->mutate(fn($m) => $m->wrap = $maxLines);
    }

    /**
     * @deprecated Renamed to {@see self::withWrap()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setWrap(int $maxLines): self { return $this->withWrap($maxLines); }

    public function withPrefixer(Prefixer $p): self
    {
        return $this->mutate(fn($m) => $m->prefixer = $p);
    }

    /**
     * @deprecated Renamed to {@see self::withPrefixer()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setPrefixer(Prefixer $p): self { return $this->withPrefixer($p); }

    public function withSuffixer(Suffixer $s): self
    {
        return $this->mutate(fn($m) => $m->suffixer = $s);
    }

    /**
     * @deprecated Renamed to {@see self::withSuffixer()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setSuffixer(Suffixer $s): self { return $this->withSuffixer($s); }

    public function withLineStyle(string $ansiStyle): self
    {
        self::sgrCodes($ansiStyle); // reject non-SGR (escape-injection) input up front
        return $this->mutate(fn($m) => $m->lineStyle = $ansiStyle);
    }

    /**
     * @deprecated Renamed to {@see self::withLineStyle()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setLineStyle(string $ansiStyle): self { return $this->withLineStyle($ansiStyle); }

    public function withCurrentStyle(string $ansiStyle): self
    {
        self::sgrCodes($ansiStyle); // reject non-SGR (escape-injection) input up front
        return $this->mutate(fn($m) => $m->currentStyle = $ansiStyle);
    }

    /**
     * @deprecated Renamed to {@see self::withCurrentStyle()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setCurrentStyle(string $ansiStyle): self { return $this->withCurrentStyle($ansiStyle); }

    /**
     * Context-line offset handed to the prefixer/suffixer on every init call
     * (relative positioning input for gutter hooks — not a viewport knob).
     *
     * DO NOT CONFUSE: scrolling is cursor-driven; the viewport knob is
     * {@see self::withCursorOffset()}, not this pass-through (sugar-treelist-028).
     *
     * @throws \InvalidArgumentException on negative $n
     */
    public function withLineOffset(int $n): self
    {
        self::assertDimension($n, 'lineOffset');
        return $this->mutate(fn($m) => $m->lineOffset = $n);
    }

    /**
     * @deprecated Renamed to {@see self::withLineOffset()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setLineOffset(int $n): self { return $this->withLineOffset($n); }

    /**
     * Set a filter function and return a new Model with filtering active.
     *
     * The filterFn receives a \Stringable and returns bool (true = keep item);
     * a null verdict is refused with a LogicException (same stated policy as
     * sort()/find() — it no longer silently drops the item).
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
        // Same identity re-anchor as reapplyFilter() (sugar-treelist-007):
        // widening the view must keep the selection on the item shown, not
        // leave the cursor index pointing at whatever now occupies it.
        $cursorId       = $clone->items[$clone->cursorIndex]->id ?? null;
        $clone->items   = $clone->originalItems;
        $clone->originalItems = [];
        $clone->cursorIndex = self::relocateCursor($clone->items, $cursorId, $clone->cursorIndex);
        // Reset diff state so the next view() emits a full frame, not a delta
        // against the pre-unfilter frame (which has a different line set).
        $clone->previousRows = null;
        $clone->prevWidth = null;
        $clone->prevHeight = null;
        return $clone;
    }

    /**
     * Filter items by fuzzy relevance to a query, and return a new Model.
     *
     * Sugar for {@see self::withFilterFn()} over the FuzzyMatch shim in strict
     * mode (full-query coverage): an item survives while every query
     * character appears in it IN ORDER (case-folded), so a two-letter query
     * no longer keeps half the list on one incidental character — a local
     * alignment can score a stray single letter above zero. The list keeps
     * everything else exactly as the filter door does — the pristine list
     * stays authoritative, so calling this once per keystroke re-projects
     * from the WHOLE original list (widening a query as the user backspaces
     * reveals everything again without hand-wiring a capture).
     * An empty query clears the filter; {@see self::withoutFuzzyFilter()}
     * is the explicit counterpart. Matching is not ranking: the surviving
     * items keep their list order (the filter door cannot reorder) — use
     * {@see FuzzyMatch::match()} directly when score-descending order is the
     * product requirement.
     *
     * Costs scale with list size (~63 us/item on the canonical profile):
     * over a few thousand items, pre-screen with a cheap str_contains filter
     * and fuzzy-rank the survivors — see the README narrow-first recipe.
     *
     * @param string              $query   The search query (empty clears the filter)
     * @param ScoringProfile|null $profile Scoring weights to filter with (null = canonical)
     */
    public function withFuzzyFilter(string $query, ?ScoringProfile $profile = null): self
    {
        if ($query === '') {
            return $this->withoutFuzzyFilter();
        }

        $matcher = (new FuzzyMatch($profile))->withStrictMode();

        return $this->withFilterFn(
            static fn(\Stringable $item): bool => $matcher->score($query, (string) $item) > 0
        );
    }

    /**
     * Clear a filter installed by {@see self::withFuzzyFilter()}.
     *
     * The filter is a single slot, so this clears ANY active filter — fuzzy
     * or hand-wired — exactly like {@see self::withoutFilter()}, which it
     * delegates to (restore of the pristine list, cursor re-anchor by
     * identity, full-frame reset included).
     */
    public function withoutFuzzyFilter(): self
    {
        return $this->withoutFilter();
    }

    /**
     * Recompute the visible list from the pristine list while filtering.
     *
     * While a filter is active, `items` is a PROJECTION of `originalItems`,
     * not a second source of truth: every mutation lands on the pristine list
     * first and this runs immediately after, so nothing added, removed,
     * cleared or sorted under a filter can be silently lost, resurrected, or
     * undone when the filter lifts. The cursor re-anchors by item identity,
     * clamping by position only when the selected item itself is filtered out.
     *
     * Call only on a clone (inside mutate()) or during a filter transition.
     */
    private function reapplyFilter(): void
    {
        if ($this->filterFn === null) {
            return;
        }
        $fn = $this->filterFn;
        // Identity re-anchor (sugar-treelist-007): as the projection shrinks
        // the cursor must stay on the item the user was looking at, exactly
        // as remove/sort re-anchor — clamping the raw position teleports the
        // selection to an unrelated row (items aa,ab,ba,bb with the cursor on
        // ba, filtered to !a*, used to land on bb). The position clamp is the
        // fallback only when the selected item itself was filtered out.
        $cursorId = $this->items[$this->cursorIndex]->id ?? null;
        $this->items = array_values(array_filter(
            $this->originalItems,
            // Same stated policy as sort()/find() (sugar-treelist-011/025):
            // a null verdict is a misconfigured predicate; refusing it beats
            // the old silent drop where (bool) null quietly excluded items.
            static function (Item $item) use ($fn): bool {
                $keep = $fn($item->value);
                if ($keep === null) {
                    throw new \LogicException('The filterFn callback returned null; it must return a bool (true keeps the item).');
                }
                return (bool) $keep;
            },
        ));
        $this->cursorIndex = self::relocateCursor($this->items, $cursorId, $this->cursorIndex);
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
     * INTAKE CONTRACT (sugar-treelist-022/036): a plain string is a
     * first-class item value — `withItem('file.php')` just works and is
     * normalized to a {@see StringItem} internally. Anything that is neither
     * string nor \Stringable (null, bool, int, float, array, plain object)
     * is refused with an InvalidArgumentException naming the door; the old
     * array door CAST such values instead, so ['ok',null,1,true,2.5] became
     * ['ok','','1','1','2.5'] and a blank `$row['name'] ?? null` row looked
     * like library corruption. The @param docblock carries the accepted
     * union for static analysis; the runtime door parses it.
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
     *
     * COST NOTE (sugar-treelist-014): under an active filter each withItem()
     * call re-derives the whole projection, so streaming results in one at a
     * time is quadratic — measured 2000→4000 appends at 697→2801 ms (ratio
     * 4.0). Batch with withItemsFromArray()/withItems(): they append many
     * items in one clone and re-apply the filter ONCE per call.
     *
     * @param string|\Stringable $value
     * @throws \InvalidArgumentException if the value is neither string nor \Stringable
     */
    public function withItem(mixed $value): self
    {
        $value = self::normalizeItem($value, 'Model::withItem()', 'the value');

        return $this->mutate(function ($m) use ($value) {
            $item = Item::new($value, $m->idCounter++);
            if ($m->filterFn !== null) {
                $m->originalItems[] = $item;
            } else {
                $m->items[] = $item;
            }
            $m->reapplyFilter();
        });
    }

    /**
     * @deprecated Renamed to {@see self::withItem()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function addItem(mixed $value): self { return $this->withItem($value); }

    /**
     * Add multiple items in one call (one clone, multiple pushes).
     *
     * Intake follows the {@see withItem()} contract: every element must be
     * string or \Stringable, refused by position otherwise.
     *
     * Filtered models follow the {@see withItem()} rule: pushes go to the
     * pristine list, the projection is recomputed once.
     *
     * @param string|\Stringable ...$values
     * @throws \InvalidArgumentException if an element is neither string nor \Stringable
     */
    public function withItems(mixed ...$values): self
    {
        $normalized = [];
        foreach ($values as $k => $value) {
            $normalized[] = self::normalizeItem(
                $value,
                'Model::withItems()',
                \is_int($k) ? 'argument #' . ($k + 1) : "argument \$$k",
            );
        }

        return $this->mutate(function ($m) use ($normalized) {
            foreach ($normalized as $value) {
                $item = Item::new($value, $m->idCounter++);
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
     * @deprecated Renamed to {@see self::withItems()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function addItems(mixed ...$values): self { return $this->withItems(...$values); }

    /**
     * Add multiple items from a plain array.
     *
     * INTAKE CONTRACT (sugar-treelist-022/036): elements may be string or
     * \Stringable; strings are normalized to StringItem exactly as at the
     * scalar doors. Non-string/non-Stringable elements (null, bool, int,
     * float — the shapes the old door silently CAST into '', '1', '2.5')
     * are refused with an InvalidArgumentException naming the element's
     * position and actual type.
     *
     * Filtered models follow the {@see withItem()} rule.
     *
     * @param array<array-key, \Stringable|string> $values
     * @throws \InvalidArgumentException if an element is neither string nor \Stringable
     */
    public function withItemsFromArray(array $values): self
    {
        $normalized = [];
        foreach ($values as $k => $value) {
            $normalized[] = self::normalizeItem(
                $value,
                'Model::withItemsFromArray()',
                \is_int($k) ? "element $k" : "element '$k'",
            );
        }

        return $this->mutate(function ($m) use ($normalized) {
            foreach ($normalized as $value) {
                $item = Item::new($value, $m->idCounter++);
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
     * @deprecated Renamed to {@see self::withItemsFromArray()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function addItemsFromArray(array $values): self { return $this->withItemsFromArray($values); }

    /**
     * The single intake ruling every item door parses through (accept the
     * two honest shapes, refuse the rest by position), so the coercion
     * contract lives in exactly one place and Item can keep demanding a
     * \Stringable internally.
     *
     * @throws \InvalidArgumentException
     */
    private static function normalizeItem(mixed $value, string $door, string $position): \Stringable
    {
        if ($value instanceof \Stringable) {
            return $value;
        }
        if (\is_string($value)) {
            return new StringItem($value);
        }
        throw new \InvalidArgumentException(\sprintf(
            '%s: %s must be a string or a %s object, %s given.',
            $door,
            $position,
            '\\Stringable',
            \get_debug_type($value),
        ));
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
     *
     * @throws \LogicException if the installed lessFunc returns null for a
     *         comparison (the slot being unset is fine — see withLessFunc()).
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
            // One stated policy for callback returns (sugar-treelist-011/025):
            // null is a misconfigured comparison, not a sortable value —
            // naming the slot here beats a TypeError surfacing from inside
            // usort far from the setter.
            \usort($list, function (Item $a, Item $b): int {
                $order = ($this->lessFunc)($a->value, $b->value);
                if ($order === null) {
                    throw new \LogicException('Model::sort(): the lessFunc comparison returned null; it must return an int (negative, zero, or positive).');
                }
                return $order;
            });
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

    public function withCursor(int $index): self
    {
        return $this->mutate(fn($m) => $m->cursorIndex = \max(0, \min($index, \count($m->items) - 1)));
    }

    /**
     * @deprecated Renamed to {@see self::withCursor()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function setCursor(int $index): self { return $this->withCursor($index); }

    /**
     * Move the cursor up by $n items (negative $n moves down).
     *
     * Saturating: $n at either end of the integer range can overflow the
     * subtraction into a float (which strict_types would then reject), so
     * unreachable magnitudes clamp straight to the list bounds instead of
     * being arithmetically evaluated.
     */
    public function withCursorUp(int $n = 1): self
    {
        $count = \count($this->items);
        if ($n > $this->cursorIndex) {
            return $this->withCursor(0);
        }
        if ($n < -$count) {
            // Negative beyond the whole list moving "up" = past the end.
            return $this->withCursor($count - 1);
        }
        return $this->withCursor($this->cursorIndex - $n);
    }

    /**
     * @deprecated Renamed to {@see self::withCursorUp()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function cursorUp(int $n = 1): self { return $this->withCursorUp($n); }

    /**
     * Move the cursor down by $n items (negative $n moves up).
     *
     * Saturating — see {@see withCursorUp()} for why raw addition is unsafe.
     */
    public function withCursorDown(int $n = 1): self
    {
        $count = \count($this->items);
        if ($n > $count) {
            return $this->withCursor($count - 1);
        }
        if ($n < -$this->cursorIndex) {
            // Negative beyond the whole list moving "down" = past the top.
            return $this->withCursor(0);
        }
        return $this->withCursor($this->cursorIndex + $n);
    }

    /**
     * @deprecated Renamed to {@see self::withCursorDown()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function cursorDown(int $n = 1): self { return $this->withCursorDown($n); }

    /**
     * Page size for withCursorPageUp()/withCursorPageDown(): one viewport height worth
     * of ITEMS (not displayed lines — multi-line items advance by one per page
     * unit; this is the documented upstream semantic). A zero-height viewport —
     * legal before any resize, only the render doors refuse it — pages by one
     * item, the same max(1, height) floor the overflow guards use, so the
     * movers never silently freeze while Up/Down still walk the list.
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
        return $factor * $pages;
    }

    /**
     * Move cursor up by one viewport height (page up).
     *
     * CONCRETE SHAPE (sugar-treelist-031): a page spans `height` ITEMS, not
     * displayed rows — the upstream semantic. On a height-10 viewport whose
     * items each wrap to two rows, one PageUp moves the cursor ten items:
     * twenty visible rows, a landing place arbitrary in row terms. Hosts
     * that page by displayed rows must map rows back to items themselves.
     */
    public function withCursorPageUp(int $pages = 1): self
    {
        return $this->withCursorUp($this->pageSpan($pages));
    }

    /**
     * @deprecated Renamed to {@see self::withCursorPageUp()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function cursorPageUp(int $pages = 1): self { return $this->withCursorPageUp($pages); }

    /**
     * Move cursor down by one viewport height (page down).
     *
     * Item-not-row semantics exactly as {@see self::withCursorPageUp()}
     * (sugar-treelist-031): a PgDn over wrapped multi-line items jumps a
     * visually arbitrary number of rows.
     */
    public function withCursorPageDown(int $pages = 1): self
    {
        return $this->withCursorDown($this->pageSpan($pages));
    }

    /**
     * @deprecated Renamed to {@see self::withCursorPageDown()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function cursorPageDown(int $pages = 1): self { return $this->withCursorPageDown($pages); }

    /**
     * Move cursor to the first item (index 0).
     */
    public function withCursorToStart(): self
    {
        return $this->withCursor(0);
    }

    /**
     * @deprecated Renamed to {@see self::withCursorToStart()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function cursorToStart(): self { return $this->withCursorToStart(); }

    /**
     * Move cursor to the last item.
     */
    public function withCursorToEnd(): self
    {
        return $this->withCursor(\count($this->items) - 1);
    }

    /**
     * @deprecated Renamed to {@see self::withCursorToEnd()} for the fluent house
     *             vocabulary; returns a new Model, receiver unchanged.
     *             REMOVED-IN: 2.0.0
     */
    public function cursorToEnd(): self { return $this->withCursorToEnd(); }

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
     * Returns -1 if not found — the {@see tryFind()} companion returns null
     * and completes this lib's try* absence idiom (sugar-treelist-032).
     *
     * Intake follows the {@see withItem()} contract: a plain string finds
     * its own value; anything neither string nor \Stringable is refused.
     *
     * @param string|\Stringable $value
     * @throws \InvalidArgumentException if the value is neither string nor \Stringable
     * @throws \LogicException if the installed equalsFunc returns null for a
     *         comparison (unset slot falls back to string equality).
     */
    public function find(mixed $value): int
    {
        return $this->locate($value, 'find') ?? -1;
    }

    /**
     * Non-throwing absence sibling of {@see find()}: null when nothing
     * matches, instead of the -1 sentinel that forces `if ($i !== -1)` next
     * to every tryItemAt()/tryCursorItem() call site (sugar-treelist-032).
     *
     * Programmer errors still throw — a junk needle or a null-returning
     * equalsFunc is a misconfiguration, not an absence. find() stays for
     * upstream parity.
     *
     * @param string|\Stringable $value
     * @throws \InvalidArgumentException if the value is neither string nor \Stringable
     * @throws \LogicException if the installed equalsFunc returns null for a
     *         comparison (unset slot falls back to string equality).
     */
    public function tryFind(mixed $value): ?int
    {
        return $this->locate($value, 'tryFind');
    }

    /**
     * Shared search walk behind find()/tryFind(); $door keeps every error
     * message naming the door actually called.
     */
    private function locate(mixed $value, string $door): ?int
    {
        $value = self::normalizeItem($value, 'Model::' . $door . '()', 'the value');

        if ($this->equalsFunc === null) {
            foreach ($this->items as $i => $item) {
                if ((string) $item->value === (string) $value) {
                    return $i;
                }
            }
            return null;
        }
        foreach ($this->items as $i => $item) {
            $matches = ($this->equalsFunc)($item->value, $value);
            if ($matches === null) {
                // Same stated policy as sort()/reapplyFilter()
                // (sugar-treelist-011/025): null names a misconfiguration,
                // it does not silently read as "not equal".
                throw new \LogicException('Model::' . $door . '(): the equalsFunc comparison returned null; it must return a bool.');
            }
            if ($matches) {
                return $i;
            }
        }
        return null;
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
     * @throws \RuntimeException If the viewport has zero dimensions, the list
     *   is empty, or the gutter reservations leave no content column.
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
        // time; withCursorOffset() accepts any sane int, so the frame geometry
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
     * Renders the list and yields the laid-out rows one at a time.
     *
     * HONEST SCOPE (sugar-treelist-029): rendering is EAGER — lines()
     * computes the complete array before the first yield, so this provides
     * no interleaving points for event loops. The name says ITERATE, not
     * stream; it exists for generator-shaped consumers and is byte-identical
     * to lines() when fully consumed.
     *
     * Inherits lines() throws contract verbatim: RuntimeException on an
     * empty list, a zero viewport, or gutters consuming every column.
     *
     * @return \Generator<int, string, mixed, void>
     * @throws \RuntimeException on the same conditions as lines()
     */
    public function eachLine(): \Generator
    {
        foreach ($this->lines() as $index => $line) {
            yield $index => $line;
        }
    }

    /**
     * @deprecated Renamed to {@see self::eachLine()} — the stream name
     *             promised a laziness this eager renderer never had
     *             (sugar-treelist-029). Returns the identical generator.
     *             REMOVED-IN: 2.0.0
     */
    public function linesStream(): \Generator
    {
        return $this->eachLine();
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
            // A failed frame never reached the screen as CONTENT, so its rows
            // must not sit in the memo — a stale memo would make every later
            // delta skip rows the terminal never saw. Drop the memo (and the
            // size stamp) and the next successful view() repaints in full.
            $this->previousRows = null;
            $this->prevWidth = null;
            $this->prevHeight = null;
            $logger?->warning('View rendering failed: {message}', ['message' => $e->getMessage()]);
            // Exception prose is untrusted text and this return value is
            // frame content: it goes through the same frame sweep (sanitize
            // + CR fold) as item values so no control byte, forged zone
            // sentinel, or cursor-return overwrite can ride the error sink.
            $messageLines = \explode("\n", self::sweepForFrame($e->getMessage()));
            // A caught frame must be as tall as the viewport it replaces:
            // the old one-line return left rows 2..height of the previous
            // frame untouched, so a list that shrinks to empty (clear(), or
            // a filter matching nothing) painted its message over a ghost
            // of the old rows. Emit exactly $height rows reusing the delta
            // path's per-row addressing — CUP homes the row, swept message
            // (or nothing) writes it, CSI K erases the stale tail — so every
            // viewport row is rewritten or cleared regardless of where the
            // cursor sits. With no usable height (a zero viewport is itself
            // a refusal) the message stands alone.
            if ($this->height < 1) {
                return \implode("\n", $messageLines) . "\n";
            }
            $messageCount = \min(\count($messageLines), $this->height);
            $frame = '';
            for ($row = 1; $row <= $this->height; $row++) {
                $content = $row <= $messageCount ? $messageLines[$row - 1] : '';
                $frame .= Ansi::CSI . $row . ';1H' . $content . Ansi::CSI . 'K' . "\n";
            }
            return $frame;
        }
    }

    // -------------------------------------------------------------------------
    // Internal rendering
    // -------------------------------------------------------------------------

    /**
     * Frame-content sweep: sugar-core's marked-frames sanitization, then a
     * CR→LF fold. {@see Sanitize::untrustedForMarkedFrames()} deliberately
     * keeps TAB, LF and CR (they are layout characters, not control damage) —
     * but a bare CR inside an ANSI frame returns the cursor to column 1 and
     * overwrites earlier content, so it must never ride a full-frame or
     * CUP+EL delta sink. Folding AFTER the sweep turns every CR into an
     * ordinary line break that hardWrap() already understands: content
     * SPLITS at a carriage return, it never splices. (Sanitize::
     * untrustedForDisplay() also folds CR, but it drops the zone-sentinel
     * sweep, so it cannot be this choke point.)
     */
    private static function sweepForFrame(string $text): string
    {
        return \str_replace(["\r\n", "\r"], "\n", Sanitize::untrustedForMarkedFrames($text));
    }

    /**
     * Frame-content sweep for a fragment spliced INSIDE an already-wrapped
     * row: a Prefixer/Suffixer return. Identical sanitization to
     * sweepForFrame(), then every line break is DROPPED rather than folded —
     * unlike item content, a gutter fragment has no row to break onto, and an
     * embedded LF here would fragment the frame row itself. The same choke,
     * then, with a stricter tail: control bytes, ANSI, and zone sentinels
     * stripped and no break survives, so an extension can neither forge a
     * frame nor split one.
     */
    private static function sweepForInline(string $text): string
    {
        return \str_replace(["\n", "\r"], '', self::sweepForFrame($text));
    }

    /**
     * Cut a raw value to the render window (bytes) on a codepoint boundary:
     * back over any trailing continuation bytes, then drop an orphaned or
     * truncated lead byte. A split codepoint would hand the /u-mode tokenizer
     * in hardWrap() a malformed chunk and silently drop the row
     * (sugar-treelist-012's failure shape) — the cut must never manufacture
     * that state at its own seam. A lone trailing CR is dropped for the same
     * reason on the fold leg: with its LF outside the window it is not a line
     * ending yet, and sweepForFrame() would invent one.
     */
    private static function cutRenderWindow(string $raw, int $window): string
    {
        $cut = \substr($raw, 0, $window);
        $end = \strlen($cut) - 1;
        while ($end >= 0 && (\ord($cut[$end]) & 0xC0) === 0x80) {
            $end--; // continuation bytes of a sequence whose lead survived
        }
        if ($end >= 0 && (\ord($cut[$end]) & 0xC0) === 0xC0) {
            $lead  = \ord($cut[$end]);
            $want  = $lead >= 0xF0 ? 4 : ($lead >= 0xE0 ? 3 : 2);
            if (\strlen($cut) - $end < $want) {
                $end--; // lead truncated mid-sequence: drop it too
            }
        }
        if ($end >= 0 && $cut[$end] === "\r") {
            $end--; // CR whose LF never made the window
        }
        return \substr($cut, 0, $end + 1);
    }

    /**
     * Replace every maximal malformed UTF-8 subsequence with the substitute
     * character '?' (ASCII, one display cell).
     *
     * Well-formed input is untouched byte for byte (validated: € and emoji
     * survive intact), and the repair can only shrink or preserve length —
     * each invalid subsequence is at least one byte and yields exactly one —
     * so the render-window invariant "every displayed cell costs at least
     * one raw byte" keeps holding after repair. The durable core-side cure
     * is blocked (sugar-core Sanitize::repairUtf8 is private, an Outside
     * item), so the repair lives at this lib's own choke.
     */
    private static function repairUtf8(string $text): string
    {
        return \mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * Viewport guarantee for an assembled row (sugar-treelist-006): the
     * reservation a Prefixer/Suffixer DECLARED at init time is what wrap and
     * suffix padding are charged against; whatever the extension actually
     * returned may be narrower, but it must never push the row past the
     * viewport — a component embedded in a multi-pane TUI cannot be allowed
     * to corrupt its siblings. The SUFFIX falls first (it is the outermost
     * decoration), then the PREFIX, and only after both gutters are spent
     * does the item CONTENT give cells; the loop always terminates with a
     * row that fits. Grapheme-safe: no codepoint is cut at its seam.
     */
    private function clampRowToViewport(string $prefix, string $content, string $suffix): string
    {
        $line = $prefix . $content . $suffix;
        $over = $this->ansiWidth($line) - $this->width;
        if ($over <= 0) {
            return $line;
        }
        // Fall order: suffix, prefix, content — the earlier legs only pay
        // while cells remain in that leg.
        $hadContent = $content !== '';
        [$suffix, $dropped] = $this->dropRightCells($suffix, $over);
        $over -= $dropped;
        if ($over > 0) {
            [$prefix, $dropped] = $this->dropRightCells($prefix, $over);
            $over -= $dropped;
        }
        if ($over > 0) {
            [$content, ] = $this->dropRightCells($content, $over);
            if ($content === '' && $hadContent) {
                // Strict arm of the width-1 contract (sugar-treelist-039):
                // a content leg eaten whole by the clamp — the single 2-cell
                // grapheme in a 1-cell column — is substituted with a 1-cell
                // marker, never left as a silently EMPTY row (the t06 clamp
                // had traded the old over-wide row for data loss).
                $content = '?';
            }
        }
        return $prefix . $content . $suffix;
    }

    /**
     * Drop whole graphemes off the RIGHT of $s until at least $cells display
     * cells are released (never past the start of the string). Returns
     * [remainder, cellsReleased].
     *
     * @return array{0: string, 1: int}
     */
    private function dropRightCells(string $s, int $cells): array
    {
        if ($s === '' || $cells <= 0) {
            return [$s, 0];
        }
        $length = \grapheme_strlen($s);
        if ($length === null) {
            // Malformed bytes carry zero display width (Width::string agrees),
            // so there are no cells this field can honestly release; keeping
            // the string whole matches what the clamp already measures.
            return [$s, 0];
        }
        $keep   = $length;
        $released = 0;
        while ($keep > 0 && $released < $cells) {
            $released += \max(1, Width::string(\grapheme_substr($s, $keep - 1, 1)));
            $keep--;
        }
        return [\grapheme_substr($s, 0, $keep), $released];
    }

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
            // Gutter overrun is a refusal, not a blank: with shipped defaults
            // the reservation (~7 cells) exceeds narrow side-pane widths, and
            // every row used to render invisible while the cursor navigated
            // happily — the list LOOKED empty and nothing said why. The
            // adjacent degenerate geometry (zero viewport) already refused
            // loudly; both now share the localized-throw contract, and
            // view()'s height-tall error frame carries the sentence.
            throw new \RuntimeException(Lang::t('list.no_content_width'));
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
        // sweepForFrame() additionally folds CR to LF after the sanitization:
        // a mid-content bare CR survives untrusted() BY DESIGN and would
        // otherwise ride the CUP+EL delta as a cursor-return overwrite.
        // The legitimate SGR ESC is injected LATER by applyStyle(), after this
        // point, and therefore survives — item-value ANSI is stripped by design.
        // Row budget + scan window: no item can usefully contribute more rows
        // than the viewport shows (wrap() caps tighter still), so hardWrap()
        // extracts paragraphs lazily and stops at the budget — a pathological
        // 200k-line value costs O(budget), not O(value). The one shape
        // laziness cannot bound is a single huge paragraph, so the RAW scan
        // window is sized to the budget too: every displayed cell needs at
        // least one raw byte (repair, sanitize and CR-fold never grow the
        // value), so rowsNeeded × (contentWidth + 1) + 1 bytes is provably
        // enough, and anything beyond it is undisplaysize. The window is
        // additionally hard-capped at MAX_ITEM_RENDER_BYTES — a huge viewport
        // must not widen the scan past a fixed ceiling either. Whenever raw
        // bytes remain outside the window the tail was dropped and the frame
        // carries a loud truncation marker row: prefix content is never a
        // silent lie.
        // Malformed UTF-8 is repaired HERE, before any grapheme-aware step:
        // Sanitize is byte-oriented and preserves invalid sequences, and
        // grapheme_strlen() returns NULL for them — an over-long malformed
        // word used to collapse splitOverWidth() to one silently EMPTY row
        // while a SHORT malformed value emitted its raw bytes verbatim (the
        // same damage, shape-dependent). One repair path covers both.
        $rawValue = self::repairUtf8((string) $item->value);
        $rawLength = \strlen($rawValue);
        $rowCap    = $this->wrap > 0 ? $this->wrap : $this->height;
        $window    = min(
            $rawLength,
            self::MAX_ITEM_RENDER_BYTES,
            $rowCap * ($contentWidth + 1) + 1,
        );
        $valueTruncated = $window < $rawLength;
        $sweepSource    = $valueTruncated ? self::cutRenderWindow($rawValue, $window) : $rawValue;
        $rawLines = $this->hardWrap(
            self::sweepForFrame($sweepSource),
            $contentWidth,
            $rowCap,
        );
        if ($this->wrap > 0 && \count($rawLines) > $this->wrap) {
            $rawLines = \array_slice($rawLines, 0, $this->wrap);
        }
        if ($valueTruncated) {
            $marker = Lang::t('list.render_truncated');
            if (\count($rawLines) >= $rowCap) {
                $rawLines[$rowCap - 1] = $marker; // the cap row announces the cut
            } else {
                $rawLines[] = $marker;
            }
        }
        $total = \count($rawLines);

        $output = [];
        foreach ($rawLines as $lineIdx => $lineContent) {
            // Extension returns splice into the row unswept otherwise: the
            // Model sweeps them to plain inline text (PB-TREELIST-M10) so a
            // prefixer/suffixer can forge neither a frame nor a row break.
            $linePrefix = self::sweepForInline($this->prefixer?->prefix($lineIdx, $total) ?? '');

            // Suffix is only emitted (and content right-padded) when the suffixer
            // actually produces a non-empty marker for this line. Matches Go upstream.
            // Padding is measured against the ACTUAL swept gutter widths so the
            // marker lands flush at the viewport edge and an extension that
            // returns more cells than it declared cannot push the row past the
            // viewport through the padding leg either (sugar-treelist-006).
            $lineSuffix = '';
            if ($this->suffixer !== null) {
                $rawSuffix = self::sweepForInline($this->suffixer->suffix($lineIdx, $total));
                if ($rawSuffix !== '') {
                    $free = $this->width
                        - $this->ansiWidth($linePrefix)
                        - $this->ansiWidth($lineContent)
                        - $this->ansiWidth($rawSuffix);
                    $lineSuffix = \str_repeat(' ', \max(0, $free)) . $rawSuffix;
                }
            }

            $line  = $linePrefix . $lineContent . $lineSuffix;
            // Viewport guarantee (sugar-treelist-006): a custom gutter that
            // returns more cells than its declared reservation used to flow
            // through verbatim — one hostile prefixer corrupted sibling panes
            // of a multi-pane TUI. The assembled row is clamped to the
            // viewport: the suffix gives cells first, then the prefix, and
            // only a spent gutter makes the value itself fall.
            $line = $this->clampRowToViewport($linePrefix, $lineContent, $lineSuffix);
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
     * @param int|null $maxLines Stop after this many rows (row budget: the
     *                           paragraph walk stops there, so one pathological
     *                           value costs O(budget) per frame, not O(value)).
     *
     * @return list<string>
     */
    private function hardWrap(string $text, int $contentWidth, ?int $maxLines = null): array
    {
        if ($contentWidth <= 0) {
            // Mirror of renderItem()'s gutter-overrun refusal: a zero-width
            // wrap budget must never degrade to a silent blank row for any
            // future caller (renderItem refuses before reaching here today).
            throw new \RuntimeException(Lang::t('list.no_content_width'));
        }
        $budget = $maxLines ?? \PHP_INT_MAX;

        $lines = [];
        // Lazy paragraph extraction: a strpos-driven walk, so the scan stops
        // at the row budget instead of materialising all 200k lines of a huge
        // value before the first budget check can fire. (A bounded explode()
        // would fuse the tail paragraphs on their newlines into one "line"
        // and smuggle raw \n into a rendered row; taking one whole paragraph
        // at a time does not.) The walk mirrors explode()'s trailing-paragraph
        // semantics exactly: "a\n" yields ["a", ""].
        $offset = 0;
        $length = \strlen($text);
        do {
            $breakAt       = \strpos($text, "\n", $offset);
            $end           = $breakAt === false ? $length : $breakAt;
            $paragraphLine = \substr($text, $offset, $end - $offset);
            $offset        = $end + 1;
            // HTAB is a layout command, not content: the host terminal would
            // expand it to a tab stop no width calculation here can see, so
            // the frame could exceed its column budget. Expand to spaces
            // (run length preserved) instead of smuggling a jump into a row.
            // (The old /\s+/ splitter collapsed tabs with everything else;
            // CR never reaches here either — sweepForFrame() folds it into
            // an LF upstream of the split.)
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

            $current      = '';
            $currentWidth = 0;
            $pending      = '';
            $pendingWidth = 0;
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (\preg_match('/\A\s+\z/u', $token) === 1) {
                    $pending      = $token;
                    $pendingWidth = $this->ansiWidth($token);
                    continue;
                }

                $tokenWidth = $this->ansiWidth($token);

                if ($tokenWidth > $contentWidth) {
                    // Unbreakable word: flush, then chunk it with the pending
                    // run still attached (the indent rides the first chunk).
                    if ($current !== '') {
                        $lines[] = $current;
                        $current      = '';
                        $currentWidth = 0;
                        if (\count($lines) >= $budget) {
                            return $lines;
                        }
                    }
                    $chunks = $this->splitOverWidth(
                        $pending . $token,
                        $contentWidth,
                        $budget - \count($lines),
                    );
                    $pending      = '';
                    $pendingWidth = 0;
                    foreach ($chunks as $chunk) {
                        $lines[] = $chunk;
                        if (\count($lines) >= $budget) {
                            return $lines;
                        }
                    }
                    continue;
                }

                if ($current === '' && $pendingWidth > $contentWidth) {
                    $pending      = ''; // an indent wider than the column cannot fit
                    $pendingWidth = 0;
                }

                // Carried widths replace the whole-concat re-measure that made
                // this loop O(row-length²) on wide viewports (a 1500-word line
                // in a 5000-col panel measured 220 ms). The sum is exact
                // unless the token FUSES into the last cluster of the carried
                // row, and a cluster extension (combining mark, ZWJ, variation
                // selector) is never ASCII: an open non-ASCII token spliced
                // directly onto a carried row is re-measured whole at the seam.
                // A pending whitespace run absorbs any leading mark (ASCII
                // seams are additive), and an overcount can only flush a row
                // early, which is safe. Plain word/whitespace rows never pay a
                // re-measure.
                $candidateWidth = $currentWidth + $pendingWidth + $tokenWidth;
                if ($current !== '' && $pending === ''
                    && $candidateWidth <= $contentWidth && \ord($token[0]) >= 0x80) {
                    $candidateWidth = $this->ansiWidth($current . $token);
                }

                if ($current !== '' && $candidateWidth > $contentWidth) {
                    $lines[] = $current; // the run straddling the break is consumed
                    if (\count($lines) >= $budget) {
                        return $lines;
                    }
                    $current      = $token;
                    $currentWidth = $tokenWidth;
                } else {
                    $current      = $current . $pending . $token;
                    $currentWidth = $candidateWidth;
                }
                $pending      = '';
                $pendingWidth = 0;
            }

            if ($current !== '') {
                $lines[] = $current;
                if (\count($lines) >= $budget) {
                    return $lines;
                }
            }
        } while ($offset <= $length);

        return $lines !== [] ? $lines : [''];
    }

    /**
     * Split a word that exceeds maxWidth into cell-budgeted chunks.
     *
     * Chunks accumulate whole graphemes while their DISPLAY WIDTH (SugarCore
     * Width — CJK/emoji count 2 cells) fits maxWidth; grapheme-count
     * chunking let a CJK word of width 16 through a 12-cell viewport
     * unsplitted. A grapheme wider than the whole budget cannot be split
     * further and keeps its own chunk here; the row-composition clamp
     * (clampRowToViewport) then substitutes a 1-cell marker for content it
     * would otherwise erase — the strict arm of the sugar-treelist-039
     * contract: geometry is never bought by silently dropping caller data.
     * Pinned by ModelTest::testUnsplittableWideGraphemeIsSubstitutedNotErasedAtWidthOne.
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
