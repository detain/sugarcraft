<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\QuickPick\Support\AnsiWalk;
use SugarCraft\QuickPick\Support\UntrustedText;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Pty\SignalForwarder;

/**
 * The QuickPick — fuzzy finder / quick-pick overlay component.
 *
 * Renders a filterable list overlay on top of a background view.
 * Background continues to update while the overlay is shown.
 *
 * The component itself reads no input and decodes no keys: it is state and
 * rendering only. The host wires terminal events onto type()/backspace()/
 * cursor*()/selected()/hide() (see README “Wiring keyboard input”).
 *
 * Inspired by Genekkion/theHermit; developed as a native PHP project.
 *
 * @see https://github.com/Genekkion/theHermit
 */
final class QuickPick
{
    /** Maximum length of filter text before input is rejected. */
    public const MAX_FILTER_LENGTH = 256;

    /**
     * The genuine file descriptor behind PHP's `STDIN` constant.
     *
     * `attachSigwinch()` has to hand `ioctl(TIOCSWINSZ)` a DESCRIPTOR number,
     * and an `(int)` cast of a PHP stream yields something else entirely: its
     * RESOURCE ID. MEASURED on PHP 8.3.6 in a fresh CLI process — `(int) STDIN`
     * is 1, `(int) STDOUT` is 2, `(int) STDERR` is 3, over descriptors 0, 1
     * and 2. So the former `(int) \STDIN` argument addressed descriptor 1, which
     * is STDOUT: the ioctl reached the terminal only while stdout happened to BE
     * that terminal, and silently no-op'd (rc != 0, so `$onResize` never fired)
     * as soon as stdout was redirected to a pipe or file. Same defect class
     * sugar-core addresses in `Tty\PosixBackend::descriptorForStream()` — which
     * is documented as internal to sugar-core, so it is not callable from here.
     *
     * The READ side was never wrong: `ttySize()` passes the `STDIN` *resource*
     * to `Tty`, which resolves the descriptor itself. This constant exists so
     * both sides name one stream — the write targets the descriptor whose
     * geometry the read queries.
     */
    private const STDIN_DESCRIPTOR = 0;

    /** Padding added to prompt+filter length when computing auto width. */
    private const WIDTH_PAD_HEADER = 5;

    /** Padding added to longest item length when computing auto width. */
    private const WIDTH_PAD_ITEM = 2;

    /** @var list<Item> */
    private array $allItems = [];

    /**
     * -009/-037 corpus memos, rebuilt ONLY where $allItems is replaced
     * (ctor, withItems) — never on a keystroke path:
     *  - folded: per-item lowercase spelling + codepoint length (anchor pass)
     *  - valueSlots: distinct-value slots with the orders that carry each
     *  - valueToSlot / orderToSlot: the hash maps feeding both.
     *
     * @var list<array{folded: string, length: int}>
     */
    private array $foldedMemo = [];

    /** @var list<array{value: string, orders: list<int>}> */
    private array $valueSlots = [];

    /** @var array<string, int> candidate value => slot index */
    private array $valueToSlot = [];

    /** @var list<int> item order => slot index */
    private array $orderToSlot = [];

    /**
     * Batch-SUPPORT probe cache (-037), keyed by ranker identity; a WeakMap
     * survives the fluent clones (object properties copy by handle) and
     * releases entries with the ranker itself.
     *
     * @var \WeakMap<FuzzyMatcher, bool>|null
     */
    private ?\WeakMap $batchSupport = null;

    /** @var list<Item> */
    private array $filteredItems = [];

    private bool $isShown = false;

    /** 0-based cursor index within filteredItems. */
    private int $cursor = 0;

    private string $filterText = '';

    private string $prompt = '> ';

    /** @var \Closure(string $item, bool $isSelected, int $number = 0): string */
    private \Closure $itemFormatter;

    /** @var \Closure(Item $item): bool Filter function applied to items. */
    private \Closure $filterFn;

    private ItemFactory $itemFactory;

    /**
     * Optional fuzzy ranker. When set, a non-empty filter text is scored against
     * every item's value via sugar-fuzzy (true subsequence matching) and the
     * filtered list is ordered by descending score instead of the default
     * substring/anchor filter; match highlighting follows the scored indices.
     */
    private ?FuzzyMatcher $ranker = null;

    /** Match highlight style (ANSI SGR codes, e.g. "\e[33m"). */
    private string $matchStyle = '';

    /** Height of the overlay list window. */
    private int $windowHeight = 10;

    /** Width of the overlay window (0 = auto from prompt). */
    private int $windowWidth = 0;

    /** Cached auto-computed width (invalidated when filter or items change). */
    private ?int $cachedComputedWidth = null;

    /** Top-left X offset for the overlay. */
    private int $xOffset = 0;

    /** Top-left Y offset for the overlay. */
    private int $yOffset = 0;

    /** Border rune set for the overlay window (composed from sugar-style). */
    private ?Border $border = null;

    /** Style for the overlay window (composed from sugar-style). */
    private ?Style $style = null;

    /** Help bar rendered below the filter list. */
    private ?HelpBar $helpBar = null;

    /** Status bar rendered at the bottom of the overlay. */
    private ?StatusBar $statusBar = null;

    /**
     * Callback invoked after a SIGWINCH-resize event.
     * Receives (cols: int, rows: int) of the new terminal size.
     *
     * @var \Closure(int, int): void|null
     */
    private ?\Closure $onResize = null;

    /**
     * The third parameter is the only constructor-level way to seed a custom
     * filter predicate; the fluent path is setFilterFn() and new() exposes neither.
     *
     * @param array<array-key, string|Item> $items
     * @param \Closure(string $item, bool $isSelected, int $number = 0): string|null $itemFormatter
     * @param \Closure(Item $item): bool|null $filterFn
     */
    public function __construct(
        array $items = [],
        ?\Closure $itemFormatter = null,
        ?\Closure $filterFn = null,
    ) {
        $this->itemFactory   = new ItemFactory();
        $this->allItems      = $this->coerceItems($items);
        $this->installCorpusMemos();
        $this->filteredItems = $this->allItems;
        $this->itemFormatter = $itemFormatter
            ?? fn(string $item, bool $selected, int $number = 0): string =>
                ($selected ? '● ' : '  ') . ($number > 0 ? "$number. " : '') . $item;
        $this->filterFn = $filterFn
            ?? fn(Item $item): bool => true;
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public static function new(array $items = [], ?\Closure $itemFormatter = null): self
    {
        return new self($items, $itemFormatter);
    }

    // -------------------------------------------------------------------------
    // Configuration (with* fluent setters)
    // -------------------------------------------------------------------------

    /**
     * Replace the item list.
     *
     * The current filter text is KEPT — the new list is immediately
     * re-filtered with it (withItems() is not “clear the filter”). The
     * cursor resets to 0.
     */
    public function withItems(array $items): self
    {
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->allItems = $clone->coerceItems($items);
        $clone->installCorpusMemos();
        // Early exit when no filter active — avoid applyFilter() overhead for the common empty-text case.
        $clone->filteredItems = $clone->filterText === ''
            ? $clone->allItems
            : $clone->applyFilter($clone->filterText);
        $clone->cursor = 0;
        return $clone;
    }

    public function setPrompt(string $prompt): self
    {
        $clone = clone $this;
        // The prompt feeds computeWidth(); a stale cache would keep the old
        // header-sized frame after a longer prompt arrives (M10).
        $clone->cachedComputedWidth = null;
        $clone->prompt = $prompt;
        return $clone;
    }

    public function setMatchStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->matchStyle = $ansiStyle;
        return $clone;
    }

    /**
     * Set the overlay window height in ROWS of the frame — header and
     * separator included, so the visible item count is $h - 2, minus one
     * more row when the overflow marker is needed. The marker shows only
     * when it fits inside the window: if reserving its row would empty
     * the list band, the marker is suppressed rather than growing the
     * frame (-002).
     *
     * The windowed region — header, separator, item rows, chrome — is
     * emitted as EXACTLY $h rows: padded with blanks when short, clamped
     * when any row would overflow, never grown past $h.
     *
     * Three chrome states live in the item area: the overflow marker
     * '  ↕ showing %d-%d of %d', '  no matches' when a non-empty filter
     * matches nothing, and '  no items' when the corpus itself is empty
     * (-028). Item and header rows are truncateAnsi-clipped to the frame;
     * these chrome rows are pad-only, so on a frame narrower than the
     * text they spill past the right border rather than clipping (cosmetic
     * overflow; clipping would change behavior — owner call).
     *
     * Clamped to a minimum of 2: below that no item row can ever exist,
     * and at exactly 2 the frame is header + separator only — no chrome
     * row survives the clamp. helpBar/statusBar rows are appended BELOW
     * this height — the composited frame may exceed $h when they are
     * visible.
     */
    public function setWindowHeight(int $h): self
    {
        $clone = clone $this;
        $clone->windowHeight = \max(2, $h);
        return $clone;
    }

    /**
     * Set the overlay width in cells.
     *
     * The domain rule (-027): negatives are meaningless and clamp to 0 =
     * auto (width computed from prompt + items, clamped to the terminal).
     * A positive value is the OUTER width — when a border is attached via
     * withBorder(), two of those cells go to the side runes, and the
     * render clamps such a frame to a border-consumed minimum of 3 cells
     * (two runes + one content cell) so the border is never sliced.
     * An over-wide positive value is clamped to the live terminal's
     * columns at compose time — a frame wider than the screen would
     * wrap, breaking the composite's width contract.
     */
    public function setWindowWidth(int $w): self
    {
        $clone = clone $this;
        // -027: negatives used to reach view() verbatim; only `> 0` hid
        // them. Normalise at the boundary so the field is always trusted
        // downstream (0 = auto, positive = outer width).
        $clone->windowWidth = \max(0, $w);
        return $clone;
    }

    /**
     * Position the overlay at (x, y) cells into the background. Pure
     * placement: it does NOT show or hide the overlay (M4).
     *
     * Negative offsets would index the background from the wrong side and
     * silently smear the composite; they clamp to 0 (mm8). The column is
     * clamped at compose time so the overlay never starts past the live
     * terminal's right edge (-063: setOffset(1000, 0) used to emit
     * 1023-cell rows).
     */
    public function setOffset(int $x, int $y): self
    {
        $clone = clone $this;
        $clone->xOffset = \max(0, $x);
        $clone->yOffset = \max(0, $y);
        return $clone;
    }

    /**
     * Set the formatter for item display lines.
     *
     * The closure receives the item's value (string), a bool indicating
     * whether it is currently selected, and optionally the item's number
     * (int, default 0) — it always returns the formatted display string.
     */
    public function setItemFormatter(\Closure $fn): self
    {
        $clone = clone $this;
        // Item widths feed computeWidth() through the formatter; the cache
        // must not survive a formatter swap (M10).
        $clone->cachedComputedWidth = null;
        $clone->itemFormatter = $fn;
        return $clone;
    }

    public function setFilterFn(\Closure $fn): self
    {
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->filterFn = $fn;
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = 0;
        return $clone;
    }

    /**
     * Set (or clear, with null) a sugar-fuzzy ranker. When set, a non-empty
     * filter text ranks items by descending fuzzy score (true subsequence match)
     * instead of the default contiguous-substring/anchor filter, and match
     * highlighting follows the scored indices. The custom filterFn still applies
     * as a predicate. Pass null to restore the default substring behaviour.
     *
     * Cost: the ranker re-scores the ENTIRE visible corpus on every keystroke
     * (distinct values only — duplicates share one scoring pass). Measured on a
     * 20k-item corpus that is several hundred milliseconds per key against low
     * double-digit milliseconds for the substring path, both machine-dependent.
     * On large corpora keep the ranking opt-in per surface, or debounce key
     * delivery host-side before calling type().
     *
     * The anchor bias below doubles as the escape hatch for the substring path:
     * when it rejects a legitimate late match (needle only occurs in the second
     * half of a label), setRanker() replaces the whole rule with subsequence
     * scoring.
     */
    public function setRanker(?FuzzyMatcher $matcher): self
    {
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->ranker = $matcher;
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = 0;
        return $clone;
    }

    /**
     * Apply a border from sugar-style.
     *
     * Frames every rendered row: top/bottom rules, side runes on interior
     * rows, and the header separator joins with the border's T-runes. An
     * explicit setWindowWidth() counts the frame (content shrinks by two);
     * auto width adds the frame on top of the computed content width.
     * Pass null to render borderless.
     */
    public function withBorder(?Border $border): self
    {
        $clone = clone $this;
        $clone->border = $border;
        return $clone;
    }

    /**
     * Apply a style from sugar-style.
     *
     * Each rendered frame row is passed through Style::render(), so the
     * style's SGR attributes paint the whole overlay window (including the
     * border runes when one is set). Pass null to render unstyled.
     */
    public function withStyle(?Style $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }

    /**
     * Attach a help bar below the filter list.
     */
    public function withHelpBar(?HelpBar $helpBar): self
    {
        $clone = clone $this;
        $clone->helpBar = $helpBar;
        return $clone;
    }

    /**
     * Attach a status bar at the bottom of the overlay.
     */
    public function withStatusBar(?StatusBar $statusBar): self
    {
        $clone = clone $this;
        $clone->statusBar = $statusBar;
        return $clone;
    }

    /**
     * Register a callback to invoke after SIGWINCH resize events.
     * The callback receives (cols: int, rows: int). Pass null to detach a
     * previously registered callback (attachSigwinch() then returns false).
     */
    public function withOnResize(?\Closure $callback): self
    {
        $clone = clone $this;
        $clone->onResize = $callback;
        return $clone;
    }

    /**
     * Attach a SIGWINCH handler via SignalForwarder that queries the live
     * TTY dimensions and forwards (cols, rows) to the stored $onResize callback.
     *
     * Requires ext-pcntl. Returns true if the handler was installed; false if
     * pcntl/SIGWINCH is unavailable or no $onResize callback is registered.
     *
     * ## What fires the callback, precisely
     *
     * On each SIGWINCH the installed handler reads the geometry through
     * {@see ttySize()} — `Tty::size()` on the `STDIN` resource, falling back to
     * 80x24 when the query fails (non-interactive context) — then pushes it to
     * the terminal with `ioctl(STDIN_DESCRIPTOR, TIOCSWINSZ)`. `SignalForwarder`
     * invokes `$onResize` only when that ioctl returns 0, so a failed resize is
     * silent by design. That coupling is why the descriptor must be genuine:
     * pointing it at a non-terminal makes every resize a no-op and the callback
     * unreachable, with no error surfaced anywhere.
     *
     * Delegates to {@see SignalForwarder::attachSigwinchToFd()}; signal
     * dispositions are process-global, so detach them with
     * `SignalForwarder::reset()` at session teardown.
     */
    public function attachSigwinch(): bool
    {
        if ($this->onResize === null) {
            return false;
        }

        // Capture $this in a local variable so the static closure can
        // dereference the live QuickPick instance when invoked asynchronously
        // (the closure outlives the attachSigwinch() call frame).
        $model = $this;
        return SignalForwarder::attachSigwinchToFd(
            // A DESCRIPTOR, not `(int) \STDIN` — see self::STDIN_DESCRIPTOR for
            // why the cast silently targeted STDOUT instead.
            self::STDIN_DESCRIPTOR,
            static fn(): array => $model->ttySize(),
            static function (int $cols, int $rows) use ($model): void {
                // mm3: an auto-width frame sized against the OLD geometry is
                // stale the moment the terminal changes shape. Invalidate the
                // cache on the instance this handler was attached to; clones
                // made later never bound a handler (documented caveat — the
                // README warns attachSigwinch() binds the attach-time object).
                $model->invalidateAutoWidth();
                $cb = $model->onResize;
                if ($cb !== null) {
                    $cb($cols, $rows);
                }
            },
        );
    }

    /**
     * Query the TTY geometry via SugarCraft\Core\Util\Tty.
     * Falls back to 80×24 if the query fails (non-interactive context).
     *
     * @return array{cols: int, rows: int}
     */
    private function ttySize(): array
    {
        try {
            $size = (new Tty(\STDIN))->size();
            return [
                'cols' => $size['cols'] ?: 80,
                'rows' => $size['rows'] ?: 24,
            ];
        } catch (\Throwable) {
            return ['cols' => 80, 'rows' => 24];
        }
    }

    // -------------------------------------------------------------------------
    // State mutations (all return new instance)
    // -------------------------------------------------------------------------

    public function show(): self
    {
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->isShown = true;
        $clone->cursor  = 0;
        $clone->filterText = '';
        $clone->filteredItems = $clone->allItems;
        return $clone;
    }

    public function hide(): self
    {
        $clone = clone $this;
        $clone->isShown = false;
        return $clone;
    }

    /**
     * Append one or more characters to the filter text.
     *
     * The parameter is a string, not a single character: paste-shaped input
     * and multi-codepoint keypresses are appended whole.
     *
     * Refusal is silent at the cap: once the filter already holds
     * {@see MAX_FILTER_LENGTH} (256) codepoints, further input is refused
     * and $this is returned unchanged — no exception, no notice. Hosts that
     * must surface the cap compare filterText() before and after.
     */
    public function type(string $char): self
    {
        // Counted in codepoints, not bytes: MAX_FILTER_LENGTH is a limit on the
        // text a user sees and that the matcher indexes, so charging a 4-byte
        // emoji the same 1 as 'a' would silently shrink the budget for non-Latin
        // input to a quarter.
        if (\mb_strlen($this->filterText, 'UTF-8') >= self::MAX_FILTER_LENGTH) {
            return $this; // reject input when cap reached
        }
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->filterText .= $char;
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = 0;
        return $clone;
    }

    /**
     * Drop the last codepoint from the filter text.
     *
     * The unit is a CODEPOINT, deliberately matching how the rest of the class
     * reads the filter: `highlightMatches()` splits it with
     * `mb_str_split(..., 1, 'UTF-8')` and `applyFilter()` matches it against
     * item values, so both sides of the comparison advance one codepoint at a
     * time. A byte cut here instead (`substr($s, 0, -1)`) left a dangling UTF-8
     * lead byte behind — typing 'é' then backspacing produced the invalid
     * string `"\xc3"`, which corrupted the filter, the downstream match, and
     * the highlighting, and desynchronised the list from what was displayed.
     *
     * Trade-off stated honestly: a multi-codepoint grapheme such as '👍🏽'
     * (thumbs-up + skin-tone modifier) takes two backspaces to clear, because
     * the modifier is its own codepoint and the matcher indexes it as one.
     *
     * Cursor behaviour is deliberately asymmetric with type(): backspace
     * CLAMPS the cursor to the new list end (narrowing preserves your
     * place), while type() RESETS it to 0 (widening starts from the top).
     */
    public function backspace(): self
    {
        $clone = clone $this;
        if ($clone->filterText === '') {
            return $clone;
        }
        $clone->cachedComputedWidth = null;
        $clone->filterText = \mb_substr($clone->filterText, 0, -1, 'UTF-8');
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = \max(0, \min($clone->cursor, \count($clone->filteredItems) - 1));
        return $clone;
    }

    public function clear(): self
    {
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->filterText = '';
        $clone->filteredItems = $clone->allItems;
        $clone->cursor = 0;
        return $clone;
    }

    public function cursorUp(int $n = 1): self
    {
        // -025: a negative step silently reversed direction and parked the
        // cursor out of range (cursorUp(-99) on 5 items landed at 99, where
        // selected() answers null). Hiding a host bug is worse than an
        // exception — refuse it loudly.
        if ($n < 1) {
            throw new \InvalidArgumentException("cursorUp() requires a step of 1 or more, got $n");
        }
        $clone = clone $this;
        $clone->cursor = \max(0, $clone->cursor - $n);
        return $clone;
    }

    public function cursorDown(int $n = 1): self
    {
        // -025: mirror of cursorUp — a negative step is a host bug, refuse
        // it instead of silently moving the wrong way.
        if ($n < 1) {
            throw new \InvalidArgumentException("cursorDown() requires a step of 1 or more, got $n");
        }
        $clone = clone $this;
        $max = \count($clone->filteredItems) - 1;
        $clone->cursor = \min($max >= 0 ? $max : 0, $clone->cursor + $n);
        return $clone;
    }

    public function cursorTop(): self
    {
        $clone = clone $this;
        $clone->cursor = 0;
        return $clone;
    }

    public function cursorBottom(): self
    {
        $clone = clone $this;
        $clone->cursor = \max(0, \count($clone->filteredItems) - 1);
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function isShown(): bool
    {
        return $this->isShown;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function filterText(): string
    {
        return $this->filterText;
    }

    /**
     * Returns the currently selected item, or null if the cursor is out of
     * bounds (e.g., empty filtered list).
     */
    public function selected(): ?Item
    {
        $items = $this->filteredItems;
        $idx   = $this->cursor;
        return $items[$idx] ?? null;
    }

    /** @return list<Item> */
    public function items(): array
    {
        return $this->filteredItems;
    }

    public function itemCount(): int
    {
        return \count($this->filteredItems);
    }

    public function allCount(): int
    {
        return \count($this->allItems);
    }

    public function border(): ?Border
    {
        return $this->border;
    }

    public function style(): ?Style
    {
        return $this->style;
    }

    public function helpBar(): ?HelpBar
    {
        return $this->helpBar;
    }

    public function statusBar(): ?StatusBar
    {
        return $this->statusBar;
    }

    /**
     * @return \Closure(int, int): void|null
     */
    public function onResize(): ?\Closure
    {
        return $this->onResize;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the QuickPick overlay and composite it over $backgroundView.
     *
     * Named `view()` (lowercase) to honour the TEA/Bubble-Tea render contract
     * this library ports, the sibling {@see Model::view()} it is handed to, and
     * PSR-1's lowerCamelCase method rule. The rename is NOT a break for callers:
     * PHP resolves method names case-insensitively, so existing `$hermit->View()`
     * call sites and docs keep working and resolve here. A capitalised `View()`
     * shim à la sugar-core's `Program::getModel()` is therefore both unnecessary
     * and impossible — declaring it would be a redeclare fatal.
     *
     * @param string $backgroundView  The underlying view (e.g. current app output)
     * @return string  The composited output with QuickPick overlay chars replacing background
     */
    public function view(string $backgroundView): string
    {
        if ($this->isShown === false) {
            return $backgroundView;
        }

        $border = $this->border;

        if ($this->windowWidth > 0) {
            // The caller chose the OUTER width; clamp it to the live
            // terminal (-027: an over-cols width used to compose wrapping
            // rows). A border frame consumes two of its cells for the side
            // runes and needs a third for content — never slice the runes.
            $cols       = $this->ttySize()['cols'];
            $outerWidth = \min($this->windowWidth, $cols);
            if ($border !== null) {
                $outerWidth = \max(3, $outerWidth);
                $innerWidth = $outerWidth - 2;
            } else {
                $innerWidth = $outerWidth;
            }
        } else {
            // Auto width sizes the CONTENT; the border is added on top so
            // item text never loses two cells to the frame.
            $innerWidth = $this->cachedComputedWidth ??= $this->autoWidth();
            $outerWidth = $border !== null ? $innerWidth + 2 : $innerWidth;
        }

        $bgLines = \explode("\n", $backgroundView);
        if (\end($bgLines) === '') \array_pop($bgLines);

        // Build the overlay once — the previous code built it twice (once just to
        // count lines for padding, once to composite).
        $lines = $this->buildOverlayLines($innerWidth);
        if ($border !== null) {
            $lines = $this->applyBorder($lines, $border, $innerWidth);
        }
        if ($this->style !== null) {
            $style = $this->style;
            $lines = \array_map(static fn(string $line): string => $style->render($line), $lines);
        }

        $totalOverlayLines = \count($lines);
        if ($totalOverlayLines > \count($bgLines)) {
            // Pad background with empty lines so compositeOver has room.
            $padding = \array_fill(0, $totalOverlayLines - \count($bgLines), '');
            $bgLines = \array_merge($bgLines, $padding);
        }

        return $this->compositeOver($lines, $outerWidth, $bgLines);
    }

    /**
     * Frame a list of overlay lines with a sugar-style Border.
     *
     * The header separator row (index 1) uses the border's top rule with
     * left/right T-junctions (├────┤) instead of the borderless plain
     * ──── rule, so the frame reads as one connected rectangle.
     *
     * @param list<string> $lines      Rows already padded to $innerWidth
     * @param Border       $border     Host-configured runes (trusted input)
     * @param int          $innerWidth Content width between the side runes
     * @return list<string>            Framed rows (count + 2)
     */
    private function applyBorder(array $lines, Border $border, int $innerWidth): array
    {
        if (\count($lines) >= 2) {
            $lines[1] = \str_repeat($border->top, $innerWidth);
        }
        foreach ($lines as $i => $line) {
            [$l, $r] = $i === 1
                ? [$border->middleLeft, $border->middleRight]
                : [$border->left, $border->right];
            $lines[$i] = $l . $line . $r;
        }
        \array_unshift(
            $lines,
            $border->topLeft . \str_repeat($border->top, $innerWidth) . $border->topRight
        );
        $lines[] = $border->bottomLeft . \str_repeat($border->bottom, $innerWidth) . $border->bottomRight;

        return $lines;
    }

    /**
     * Build the array of overlay lines for the current state at the given width.
     *
     * @return list<string>
     */
    private function buildOverlayLines(int $winWidth): array
    {
        $prompt   = $this->prompt;
        $filter   = $this->filterText;
        // The header echoes the filter text typed at runtime and the prompt
        // (which hosts commonly seed from a previously selected history
        // entry). Both are untrusted: guard before they reach the frame.
        // The header rides the same clip+pad contract as every other frame
        // row: a prompt+filter longer than the frame must not emit a row
        // wider than $winWidth (a pad-only header over an empty background
        // bypassed even the composite's width guarantee).
        $headerLine = Width::padRight(Width::truncateAnsi(UntrustedText::guard($prompt . $filter), $winWidth), $winWidth);

        $lines = [$headerLine];

        $sep = \str_repeat('─', $winWidth);
        $lines[] = $sep;

        $items = $this->filteredItems;
        $count = \count($items);
        $visibleRows = \max(0, $this->windowHeight - 2);
        // M13: when the corpus overflows the window the list used to slide
        // silently — the user could not tell where in a 10k-item history they
        // were. Reserve one row for a position marker. -002: the marker is
        // suppressed when reserving its row would empty the list band — a
        // frame that silently grew a row, or emitted the inverted range
        // 'showing 2-1 of 3', is worse than no marker at all.
        $markerNeeded = $count > $visibleRows;
        if ($markerNeeded) {
            if ($visibleRows > 1) {
                $visibleRows--;
            } else {
                $markerNeeded = false;
            }
        }
        $maxShow = \min($count, $visibleRows);
        $top = $this->cursor < $visibleRows
            ? 0
            : \min($this->cursor - $visibleRows + 1, $count - $visibleRows);
        $top = \max(0, $top);

        for ($i = 0; $i < $maxShow; $i++) {
            $actualIndex = $top + $i;
            $isSelected = ($actualIndex === $this->cursor);
            // Guard the raw value BEFORE handing it to the formatter: the
            // value may come from disk (FileHistory) or any other corpus
            // the caller does not vet, while the formatter's own SGR is
            // host styling applied after — it must survive.
            $itemStr    = ($this->itemFormatter)(UntrustedText::guard($items[$actualIndex]->value()), $isSelected, $items[$actualIndex]->number());

            if ($filter !== '' && $this->matchStyle !== '') {
                $itemStr = $this->ranker !== null
                    ? $this->highlightFuzzy($this->ranker, $itemStr, $filter)
                    : $this->highlightMatches($itemStr, $filter);
            }

            $itemStr = \str_replace(["\r\n", "\r", "\n"], ' ', $itemStr);
            $itemStr = Width::padRight(Width::truncateAnsi($itemStr, $winWidth), $winWidth);
            $lines[] = $itemStr;
        }

        if ($markerNeeded) {
            $lines[] = Width::padRight(
                \sprintf('  ↕ showing %d-%d of %d', $top + 1, $top + $maxShow, $count),
                $winWidth
            );
        } elseif ($count === 0) {
            // M12: an empty result set used to render as blank rows —
            // indistinguishable from a frozen UI. Say so, and say WHY:
            // -028 an empty corpus with no filter active prints 'no
            // items' (nothing to pick); a filter that matched nothing
            // prints 'no matches' (the filter is the problem).
            $lines[] = Width::padRight($filter !== '' ? '  no matches' : '  no items', $winWidth);
        }

        // -002 invariant: the windowed region never emits more than
        // $windowHeight rows (padded up to exactly that below); help/status
        // chrome appends under it by design and is outside this budget.
        $lines = \array_slice($lines, 0, $this->windowHeight);

        while (\count($lines) < $this->windowHeight) {
            $lines[] = \str_repeat(' ', $winWidth);
        }

        if ($this->helpBar !== null && $this->helpBar->isVisible()) {
            $helpLine = $this->helpBar->render();
            if ($helpLine !== '') {
                $helpLine = Width::padRight(Width::truncateAnsi($helpLine, $winWidth), $winWidth);
                $lines[] = $helpLine;
            }
        }

        if ($this->statusBar !== null && $this->statusBar->isVisible()) {
            $statusLine = $this->statusBar->render();
            if ($statusLine !== '') {
                $statusLine = Width::padRight(Width::truncateAnsi($statusLine, $winWidth), $winWidth);
                $lines[] = $statusLine;
            }
        }

        return $lines;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Coerce a mixed input array into a list of Item objects.
     *
     * Delegates to ItemFactory::coerce().
     *
     * @param array<Item|string> $items
     * @return list<Item>
     */
    private function coerceItems(array $items): array
    {
        return $this->itemFactory->coerce($items);
    }

    /**
     * Build the fold/slot memos for the current $allItems. Called wherever
     * the corpus is installed — the fold was previously recomputed for the
     * entire corpus on EVERY keystroke (-009).
     */
    private function installCorpusMemos(): void
    {
        $folded      = [];
        $slots       = [];
        $valueToSlot = [];
        $orderToSlot = [];

        foreach ($this->allItems as $order => $item) {
            $value = $item->value();
            // The anchor rule measures the FOLDED spelling (fold can change
            // codepoint count for special cases like 'İ'), so memoize that.
            $fold            = \mb_strtolower($value, 'UTF-8');
            $folded[$order]  = ['folded' => $fold, 'length' => \mb_strlen($fold, 'UTF-8')];

            $slot            = $valueToSlot[$value] ?? ($valueToSlot[$value] = \count($slots));
            $slots[$slot]  ??= ['value' => $value, 'orders' => []];
            $slots[$slot]['orders'][] = $order;
            $orderToSlot[$order]      = $slot;
        }

        $this->foldedMemo  = $folded;
        $this->valueSlots  = $slots;
        $this->valueToSlot = $valueToSlot;
        $this->orderToSlot = $orderToSlot;
    }

    /**
     * Filter allItems using the configured filter function.
     * When filterText is empty, returns all items.
     * Otherwise applies both the filterText (contiguous substring match whose
     * FIRST occurrence must start in the first half of the label — measured in
     * Unicode codepoints — the "anchor bias") and the custom filterFn.
     * Consequence: a needle that occurs only in the second half is rejected
     * ('ry' misses 'cherry', 'e' misses 'apple'); setRanker() swaps this rule
     * for full fuzzy subsequence scoring.
     *
     * @return list<Item>
     */
    private function applyFilter(string $text): array
    {
        $fn = $this->filterFn;
        if ($text === '') {
            // Single-pass collect — avoids array_filter + array_values creating two intermediate arrays.
            $filtered = [];
            foreach ($this->allItems as $item) {
                if ($fn($item)) {
                    $filtered[] = $item;
                }
            }
            return $filtered;
        }
        if ($this->ranker !== null) {
            return $this->applyRankedFilter($this->ranker, $text);
        }
        // The anchor rule is computed in CODEPOINTS, not bytes: a multibyte
        // prefix must not push an early match past the halfway line, and the
        // haystack's folded spelling is measured in the same units as the
        // needle position (mb_* with an explicit UTF-8 encoding argument —
        // the ASCII fast path would leave 'ecl' unable to reach 'Éclair').
        $lower    = \mb_strtolower($text, 'UTF-8');
        $filtered = [];
        // -009: the folded spelling and codepoint length are the corpus
        // memo built at install; a keystroke now pays only one mb_strpos
        // per item plus the host predicate — no per-keystroke fold of the
        // whole corpus.
        foreach ($this->foldedMemo as $order => $memo) {
            $pos = \mb_strpos($memo['folded'], $lower, 0, 'UTF-8');
            if ($pos !== false && $pos * 2 < $memo['length'] && $fn($this->allItems[$order])) {
                $filtered[] = $this->allItems[$order];
            }
        }
        return $filtered;
    }

    /**
     * Rank items by descending sugar-fuzzy score for a non-empty filter text,
     * keeping only positive-scoring items that also pass the filterFn predicate.
     * Ties break on the items' original order so ranking is stable.
     *
     * @return list<Item>
     */
    private function applyRankedFilter(FuzzyMatcher $ranker, string $text): array
    {
        $fn = $this->filterFn;

        // -037: an empty batch answer is a REAL answer — "nothing scored".
        // The per-item fallback is reserved for rankers that CANNOT batch:
        // probed once per ranker identity (the WeakMap rides the fluent
        // clones) instead of re-scoring the whole corpus on every empty
        // keystroke.
        if ($this->batchSupport !== null && $this->batchSupport->offsetExists($ranker) && $this->batchSupport[$ranker] === false) {
            return $this->applyRankedFilterPerItem($ranker, $text, $fn);
        }

        // Duplicate values must keep duplicate items: a value-keyed lookup
        // would alias every repeat of a value onto ONE Item, so the cursor,
        // selected() and the rendered numbers could all land on the wrong
        // object. Score each DISTINCT value once (the ranker's work stays
        // proportional to unique text), then re-expand the hits across every
        // SURVIVING order that carries that value. -009: the dedup groups
        // are the install-time slot memos — a keystroke never hashes the
        // corpus again, only reads ints.
        $survivors = [];
        foreach ($this->allItems as $order => $item) {
            if ($fn($item) === false) {
                continue;
            }
            $survivors[$this->orderToSlot[$order]][] = $order;
        }

        if ($survivors === []) {
            return [];
        }

        $candidates = [];
        foreach (\array_keys($survivors) as $slot) {
            $candidates[] = $this->valueSlots[$slot]['value'];
        }

        try {
            $results = $ranker->matchAll($text, $candidates);
            $this->batchSupport ??= new \WeakMap();
            $this->batchSupport[$ranker] = true;
        } catch (\Throwable) {
            // Batch half unsupported on this ranker: fall back per-item now,
            // and skip the batch probe for its identity forever after.
            $this->batchSupport ??= new \WeakMap();
            $this->batchSupport[$ranker] = false;
            return $this->applyRankedFilterPerItem($ranker, $text, $fn);
        }

        /** @var list<array{item: Item, score: int, order: int}> $scored */
        $scored = [];
        foreach ($results as $result) {
            if ($result === null || $result->isMatched() === false) {
                continue;
            }
            $slot = $this->valueToSlot[$result->haystack] ?? null;
            if ($slot === null || !isset($survivors[$slot])) {
                // A result naming a candidate we never offered is contract
                // noise — same drop the old `?? []` applied to it.
                continue;
            }
            foreach ($survivors[$slot] as $order) {
                $scored[] = [
                    'item'  => $this->allItems[$order],
                    'score' => $result->score,
                    'order' => $order,
                ];
            }
        }

        // Re-sort by score desc, then original order asc for stable tie-breaking
        // (matchAll may use alphabetical tie-break which differs from original order)
        \usort(
            $scored,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['order'] <=> $b['order']),
        );

        return \array_map(static fn(array $s): Item => $s['item'], $scored);
    }

    /**
     * Per-item fallback for rankers whose matchAll() half is unsupported
     * (-037) — the only half of the contract that can answer for every
     * candidate on its own. NOT consulted when a supported batch answer is
     * merely empty.
     *
     * @return list<Item>
     */
    private function applyRankedFilterPerItem(FuzzyMatcher $ranker, string $text, \Closure $fn): array
    {
        /** @var list<array{item: Item, score: int, order: int}> $scored */
        $scored = [];
        foreach ($this->allItems as $order => $item) {
            if ($fn($item) === false) {
                continue;
            }
            $result = $ranker->match($text, $item->value());
            if ($result === null || $result->isMatched() === false) {
                continue;
            }
            $scored[] = ['item' => $item, 'score' => $result->score, 'order' => $order];
        }

        \usort(
            $scored,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['order'] <=> $b['order']),
        );

        return \array_map(static fn(array $s): Item => $s['item'], $scored);
    }

    private function computeWidth(): int
    {
        // Measure in visible cells (ANSI-stripped, wide-rune aware) so the auto
        // width matches what view() actually renders — which is true only
        // because the formatter receives the SAME guarded value here as on the
        // render path below: guard() first, so a host that echoes or caches
        // the closure argument never sees the unguarded string (review M8).
        $promptLen = Width::of($this->prompt);
        $filterLen = Width::of($this->filterText);
        $itemMax   = 0;
        foreach ($this->filteredItems as $item) {
            $itemLen = Width::of(($this->itemFormatter)(UntrustedText::guard($item->value()), false, $item->number()));
            if ($itemLen > $itemMax) $itemMax = $itemLen;
        }
        return \max($promptLen + $filterLen + self::WIDTH_PAD_HEADER, $itemMax + self::WIDTH_PAD_ITEM);
    }

    /**
     * Natural auto width of the frame, clamped to the terminal.
     *
     * M11: computeWidth() used to grow with the longest item without
     * bound — a single 300-character history entry produced a 300-cell
     * frame that wrapped the terminal and smeared the composite. The
     * clamp keeps the frame inside the live column count (falling back
     * to the 80-column default geometry); -059 removed the 20-cell
     * comfort ceiling, which on a real sub-20-column terminal exceeded
     * the screen itself and wrapped — Width::truncateAnsi() already
     * guards the rows at any width.
     */
    private function autoWidth(): int
    {
        $cols = $this->ttySize()['cols'];

        // -059: the old clamp was min(computed, max(20, cols)) — on a real
        // terminal narrower than 20 columns the 20-cell comfort ceiling
        // exceeded the screen itself, so an "auto" frame wrapped. The width
        // can never exceed the live terminal; degenerate zero-width probes
        // are already absorbed by ttySize()'s `?: 80` fallback.
        return \min($this->computeWidth(), $cols);
    }

    /**
     * Drop the memoised auto-width so the next view() re-measures against
     * the current terminal and item set. Called by the SIGWINCH handler.
     */
    private function invalidateAutoWidth(): void
    {
        $this->cachedComputedWidth = null;
    }

    /**
     * Highlight every contiguous occurrence of the filter text within a
     * formatted item string (the default substring strategy).
     *
     * Match indices address DISPLAY codepoints only (escape sequences are
     * invisible to the filter), and re-emission keeps the formatter's own
     * escape sequences verbatim — see {@see AnsiWalk::paint()}.
     */
    private function highlightMatches(string $text, string $filter): string
    {
        $chars = AnsiWalk::chars($text);
        $lowerChars = \array_map(
            static fn(string $c): string => \mb_strtolower($c, 'UTF-8'),
            $chars
        );
        $filterLower = \mb_strtolower($filter, 'UTF-8');
        $fArr = \mb_str_split($filterLower, 1, 'UTF-8');
        $flen = \count($fArr);
        $charLen = \count($chars);
        $matched = [];

        for ($i = 0; $i + $flen <= $charLen; $i++) {
            if (\array_slice($lowerChars, $i, $flen) === $fArr) {
                for ($j = 0; $j < $flen; $j++) {
                    $matched[$i + $j] = true;
                }
                // Non-overlapping, exactly like the previous forward scan
                // that resumed after each emitted run.
                $i += $flen - 1;
            }
        }

        return AnsiWalk::paint($text, $matched, $this->matchStyle);
    }

    /**
     * Highlight the sugar-fuzzy-matched runes in a formatted item string,
     * following the ranker's scored indices (a subsequence match) rather than a
     * contiguous substring. When the ranker reports no match for the displayed
     * string the row is re-emitted unchanged — host styling included.
     */
    private function highlightFuzzy(FuzzyMatcher $ranker, string $text, string $filter): string
    {
        $result = $ranker->match($filter, AnsiWalk::plain($text));
        if ($result === null || $result->isEmpty()) {
            return $text;
        }

        $matched = [];
        foreach ($result->indices() as $index) {
            $matched[$index] = true;
        }

        return AnsiWalk::paint($text, $matched, $this->matchStyle);
    }

    private function compositeOver(array $overlayLines, int $winWidth, array $bgLines): string
    {
        // -063: an extreme x offset used to compose rows as wide as
        // xOffset+width (setOffset(1000, 0) emitted 1023-cell lines).
        // Clamp the placement column to the live terminal's right edge;
        // y is bounded by the background itself (rows past it are the
        // host's own padding), so only x needs the guard.
        $cols = $this->ttySize()['cols'];
        $x    = \min($this->xOffset, \max(0, $cols - $winWidth));
        $y    = $this->yOffset;

        foreach ($overlayLines as $lineIdx => $line) {
            $destY = $y + $lineIdx;
            if ($destY < 0 || $destY >= \count($bgLines)) continue;

            // Replace segment of background line with overlay chars
            $bgLine = $bgLines[$destY];
            $bgLine = $this->replaceSegment($bgLine, $x, $winWidth, $line);
            $bgLines[$destY] = $bgLine;
        }

        return \implode("\n", $bgLines);
    }

    /**
     * Replace a segment of a line with overlay characters.
     *
     * Slices by visible CELL columns via Width::truncateAnsi()/dropAnsi(),
     * never by grapheme count: the overlay carries ANSI styling whose escape
     * bytes are not columns, and grapheme cluster counts for emoji-with-
     * modifier differ between PHP 8.3 and 8.4 ICU behaviour — grapheme_substr
     * on a styled string cut INSIDE the SGR sequence on 8.4.
     *
     * The replacement arrives pre-sized: buildOverlayLines() clips and pads
     * every row to the frame width, so there is no re-measure/re-clamp pass
     * here — the caller's $width is the truth it already enforced.
     */
    private function replaceSegment(string $line, int $x, int $width, string $replacement): string
    {
        // The prefix must occupy exactly $x display columns even when the
        // background line ends earlier (or is empty): a short row would
        // otherwise drag the whole overlay left of its column, and the
        // former empty-line early return landed it at column 0 outright.
        $prefix = $x > 0 ? Width::padRight(Width::truncateAnsi($line, $x), $x) : '';
        $suffix = Width::dropAnsi($line, $x + $width);

        return $prefix . $replacement . $suffix;
    }
}
