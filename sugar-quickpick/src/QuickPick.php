<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\QuickPick\Support\AnsiWalk;
use SugarCraft\QuickPick\Support\UntrustedText;
use SugarCraft\QuickPick\Support\WidthMemo;
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
    /**
     * Maximum filter length in CODEPOINTS (256).
     *
     * The cap bounds two things at once: per-frame matcher work (every
     * keystroke re-filters and re-highlights the whole corpus) and the width
     * of the header row the filter is echoed into. Input beyond it is not
     * refused whole — {@see type()} clamps the appended run to the remaining
     * budget, so a paste at 255 lands exactly 256 and no more; once the
     * filter is AT the cap, further input is refused and the header edge
     * shows the overflow indicator (see {@see filterAtCap()}).
     */
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

    /**
     * How many full-substring hits the anchor rule alone rejected on the
     * last filter pass (-032). Recomputed with every keystroke on the
     * substring path; reset to 0 by the empty-text and ranker paths, which
     * carry no anchor rule. The renderer turns it into the 'hidden by
     * anchor bias' hint row.
     */
    private int $anchorDropped = 0;

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

    /**
     * Authoritative match indices from the last ranked filter pass, keyed by
     * the item object itself (a WeakMap so discarded items are collected).
     * The renderer consumes these instead of re-asking the ranker (-069) and
     * instead of ever looking at formatter output for match targets (-060).
     *
     * @var \WeakMap<Item, list<int>>
     */
    private \WeakMap $rankedHits;

    /**
     * Auto-width cache, shared by handle across every clone of this family
     * (see {@see WidthMemo}). Kept as an object, not a property, so that a
     * resize observed by ANY instance invalidates the view of ALL of them.
     */
    private WidthMemo $widthMemo;

    /** Top-left X offset for the overlay. */
    private int $xOffset = 0;

    /** Top-left Y offset for the overlay. */
    private int $yOffset = 0;

    /**
     * Optional size-provider seam for {@see ttySize()}. Null runs the sugar-core
     * Tty ladder. Hosts that already track their geometry (a TEA loop watching
     * its own resize events) can inject a zero-probe answer; tests inject
     * throwing and zero-answering doubles to pin the net (-014).
     *
     * @var \Closure(): array{cols: int, rows: int}|null
     */
    private ?\Closure $ttySizeProvider = null;

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
        $this->widthMemo     = new WidthMemo();
        $this->rankedHits    = new \WeakMap();
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
        $clone->widthMemo->invalidateItems();
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
        $clone->widthMemo->invalidate();
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
     * these chrome rows are pad-only — never intentionally truncated — so
     * on a frame narrower than the text they keep their full length: the
     * spill passes the border column over an empty background, and over a
     * background-backed row it pushes the surviving tail rightward instead
     * of overwriting it (clipping them to the frame would be a deliberate
     * behavior change and is not done).
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
        // Item widths feed computeWidth() through the formatter; the per-item
        // measurement table belongs to the old closure and must not survive
        // the swap (M10).
        $clone->widthMemo->invalidateItems();
        $clone->itemFormatter = $fn;
        return $clone;
    }

    public function setFilterFn(\Closure $fn): self
    {
        $clone = clone $this;
        $clone->widthMemo->invalidate();
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
     * Coupling rule (-050): ranking and emphasis are separate switches. A
     * ranker alone REORDERS and scores — nothing is painted until a match
     * style is set via setMatchStyle(); with a ranker installed but an empty
     * style, the scored indices simply go unrendered. Ship both together
     * (`->setRanker($m)->setMatchStyle("\e[33m")`) unless the reordering is
     * the whole point.
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
        $clone->widthMemo->invalidate();
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
     * {@see ttySize()} — `Tty::size()` on the `STDIN` resource, sugar-core's
     * full ladder (ioctl → COLUMNS/LINES env → /dev/tty → stty, last resort
     * 200×60; the 80×24 inside ttySize() is only the throw/zero net) — then
     * pushes it to the terminal with `ioctl(STDIN_DESCRIPTOR, TIOCSWINSZ)`.
     * `SignalForwarder`
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

        // Capture $this in a local variable so the static closure can dereference
        // the attach-time instance for its geometry read (ttySize() is pure I/O:
        // it queries the terminal, not model state). The width invalidation
        // deliberately does NOT go through $model — it routes through the shared
        // WidthMemo handle, so every clone in the family sees the resize.
        $model = $this;
        return SignalForwarder::attachSigwinchToFd(
            // A DESCRIPTOR, not `(int) \STDIN` — see self::STDIN_DESCRIPTOR for
            // why the cast silently targeted STDOUT instead.
            self::STDIN_DESCRIPTOR,
            static fn(): array => $model->ttySize(),
            $this->sigwinchHandler(),
        );
    }

    /**
     * The composed callback attachSigwinch() registers: invalidate the shared
     * width memo, then forward (cols, rows) to the attach-time instance's
     * $onResize. Exposed as a seam so the resize semantics are testable
     * without a terminal — SignalForwarder only fires on a real TIOCSWINSZ.
     */
    private function sigwinchHandler(): \Closure
    {
        $model = $this;
        $memo  = $this->widthMemo;
        return static function (int $cols, int $rows) use ($model, $memo): void {
            // mm3: an auto-width frame sized against the OLD geometry is stale
            // the moment the terminal changes shape. The memo is shared by
            // handle, so one invalidation reaches the live clone no matter
            // when attachSigwinch() ran relative to the fluent pipeline.
            $memo->invalidate();
            $cb = $model->onResize;
            if ($cb !== null) {
                $cb($cols, $rows);
            }
        };
    }

    /**
     * Query the TTY geometry through the provider seam (default: sugar-core's
     * {@see Tty} on `STDIN`).
     *
     * The ladder this answers for is sugar-core's, and it does NOT end at
     * 80×24: `Tty::size()` walks ioctl → `COLUMNS`/`LINES` env → `/dev/tty`
     * → `stty`, and its LAST step is `Backend::LAST_RESORT_SIZE` — **200×60**
     * (`sugar-core/src/Util/Tty/Backend.php:57`). A piped or otherwise
     * non-interactive STDIN therefore renders at the geometry that ladder
     * reports (commonly 200×60), not at a phantom 80×24.
     *
     * QuickPick's own `80×24` is only the THROW-ONLY net: it is reached when
     * the ladder raises (a closed/dead descriptor a probe cannot survive) or
     * when the provider answers a zero for a dimension. The `?: 80`/`?: 24`
     * legs absorb exactly that degenerate zero answer.
     *
     * @return array{cols: int, rows: int}
     */
    private function ttySize(): array
    {
        try {
            $size = $this->ttySizeProvider !== null
                ? ($this->ttySizeProvider)()
                : (new Tty(\STDIN))->size();
            return [
                'cols' => $size['cols'] ?: 80,
                'rows' => $size['rows'] ?: 24,
            ];
        } catch (\Throwable) {
            return ['cols' => 80, 'rows' => 24];
        }
    }

    /**
     * Install a geometry provider for {@see ttySize()} (see the property for
     * the semantics). Clone-returning like every other builder; the provider
     * is shared state for the clone family only in the sense that every
     * fluent clone carries the same closure handle.
     */
    public function withTtySizeProvider(?\Closure $provider): self
    {
        $clone = clone $this;
        $clone->widthMemo->invalidate();
        $clone->ttySizeProvider = $provider;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // State mutations (all return new instance)
    // -------------------------------------------------------------------------

    public function show(): self
    {
        $clone = clone $this;
        $clone->widthMemo->invalidate();
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
     * and multi-codepoint keypresses are appended whole — up to the cap.
     *
     * Editing is END-ONLY by design: there is no caret movement through the
     * filter string, the rendered `▏` cell always sits at the end of what is
     * typed. A mid-filter typo means backspace-and-retype (or clear()); full
     * left/right/home/end motion would be a different API and was
     * deliberately not shipped (gate G9).
     *
     * The {@see MAX_FILTER_LENGTH} budget (256 codepoints) is enforced by
     * CLAMPING the incoming run: only the first `remaining` codepoints are
     * appended, so a 10-char paste accepted at 255 lands exactly 256 — the
     * cap bounds both the per-frame work and the header width, and the
     * filter can never exceed it (-058: the old check ran BEFORE the append,
     * so a paste accepted at 255 landed whole past 256). At exactly 256 the
     * filter shows the overflow indicator and further input is refused;
     * `filterAtCap()` is the accessor for that state.
     */
    public function type(string $chars): self
    {
        // Counted in codepoints, not bytes: MAX_FILTER_LENGTH is a limit on the
        // text a user sees and that the matcher indexes, so charging a 4-byte
        // emoji the same 1 as 'a' would silently shrink the budget for non-Latin
        // input to a quarter.
        $remaining = self::MAX_FILTER_LENGTH - \mb_strlen($this->filterText, 'UTF-8');
        if ($remaining <= 0) {
            return $this; // at the cap — nothing to append into
        }
        $accepted = \mb_strlen($chars, 'UTF-8') > $remaining
            ? \mb_substr($chars, 0, $remaining, 'UTF-8')
            : $chars;
        $clone = clone $this;
        $clone->widthMemo->invalidate();
        $clone->filterText .= $accepted;
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = 0;
        return $clone;
    }

    /**
     * Drop the last codepoint from the filter text.
     *
     * The unit is a CODEPOINT, deliberately matching how the rest of the class
     * reads the filter: `highlightMatches()` compares folded text position by
     * `mb_strpos`/`mb_strlen` in UTF-8 units and `applyFilter()` matches it
     * against item values, so both sides of the comparison advance one
     * codepoint at a time. A byte cut here instead (`substr($s, 0, -1)`) left a dangling UTF-8
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
        $clone->widthMemo->invalidate();
        $clone->filterText = \mb_substr($clone->filterText, 0, -1, 'UTF-8');
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = \max(0, \min($clone->cursor, \count($clone->filteredItems) - 1));
        return $clone;
    }

    public function clear(): self
    {
        $clone = clone $this;
        $clone->widthMemo->invalidate();
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
     * True once the filter holds MAX_FILTER_LENGTH codepoints: appended
     * input is then refused and the header edge shows the overflow
     * indicator ({@see filterChrome()}).
     */
    public function filterAtCap(): bool
    {
        return \mb_strlen($this->filterText, 'UTF-8') >= self::MAX_FILTER_LENGTH;
    }

    /**
     * The chrome cell(s) drawn immediately after the filter text in the
     * header: `▏` is the end-of-filter caret, and while the filter sits at
     * the cap a `⋯` overflow indicator follows it.
     *
     * These two runes are HARDCODED here on purpose: the S-15 chrome-key
     * pass wraps every drawn chrome string in the Lang lookup table and
     * will pick them up at this one seat — until then they ship plain.
     */
    private function filterChrome(): string
    {
        return $this->filterAtCap() ? '▏⋯' : '▏';
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
            $innerWidth = $this->widthMemo->width ??= $this->autoWidth();
            $outerWidth = $border !== null ? $innerWidth + 2 : $innerWidth;
        }

        $bgLines = \explode("\n", $backgroundView);
        if (\end($bgLines) === '') {
            \array_pop($bgLines);
        }

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
        // The caret/overflow chrome is appended AFTER the clip and gets its
        // cells reserved before it, so an over-long filter still shows the
        // indicator AT THE FILTER EDGE instead of clipping it away — the
        // text side keeps the same clip+pad contract as every frame row (a
        // prompt+filter longer than the frame must not emit an over-wide
        // row; a pad-only header over an empty background bypassed even the
        // composite's width guarantee).
        $chrome     = $this->filterChrome();
        $headerText = UntrustedText::guard($prompt . $filter);
        $headerLine = Width::padRight(
            Width::truncateAnsi($headerText, \max(0, $winWidth - Width::of($chrome))) . $chrome,
            $winWidth
        );

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
            $isSelected  = ($actualIndex === $this->cursor);
            $rawValue    = $items[$actualIndex]->value();
            // Guard the raw value BEFORE handing it to the formatter: the
            // value may come from disk (FileHistory) or any other corpus
            // the caller does not vet, while the formatter's own SGR is
            // host styling applied after — it must survive.
            $guarded     = UntrustedText::guard($rawValue);
            $itemStr     = ($this->itemFormatter)($guarded, $isSelected, $items[$actualIndex]->number());

            // Emphasis is a two-key switch (-050): a filter must exist AND
            // the host must have asked for a style — a ranker alone only
            // reorders, so scored indices go unrendered without one.
            if ($filter !== '' && $this->matchStyle !== '') {
                $itemStr = $this->ranker !== null
                    ? $this->highlightFuzzy($itemStr, $guarded, $rawValue, $items[$actualIndex])
                    : $this->highlightMatches($itemStr, $guarded, $filter);
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

        // -032: the anchor rule is documented, but a silent drop still reads
        // as "the search is broken". Say what the bias hid — on a drop, not
        // on a rule. Chrome string hardcoded at this single seat; the S-15
        // Lang pass wraps every chrome row here together.
        if ($this->anchorDropped > 0) {
            $lines[] = Width::padRight(
                \sprintf('  %d hidden by anchor bias', $this->anchorDropped),
                $winWidth
            );
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
            $this->anchorDropped = 0; // no needle, no bias to drop anything
            $filtered = [];
            foreach ($this->allItems as $item) {
                if ($fn($item)) {
                    $filtered[] = $item;
                }
            }
            return $filtered;
        }
        if ($this->ranker !== null) {
            $this->anchorDropped = 0; // the ranker replaces the anchor rule entirely
            return $this->applyRankedFilter($this->ranker, $text);
        }
        // The anchor rule is computed in CODEPOINTS, not bytes: a multibyte
        // prefix must not push an early match past the halfway line, and the
        // haystack's folded spelling is measured in the same units as the
        // needle position (mb_* with an explicit UTF-8 encoding argument —
        // the ASCII fast path would leave 'ecl' unable to reach 'Éclair').
        $lower    = \mb_strtolower($text, 'UTF-8');
        $filtered = [];
        // -032 adds the drop census beside the memo walk: `substringHits`
        // counts every full-substring answer, `anchored` counts those the
        // bias accepted — their difference is what ONLY the anchor rule
        // rejected (a host-predicate rejection is not the bias's doing).
        $substringHits = 0;
        $anchored      = 0;
        // -009: the folded spelling and codepoint length are the corpus
        // memo built at install; a keystroke now pays only one mb_strpos
        // per item plus the host predicate — no per-keystroke fold of the
        // whole corpus.
        foreach ($this->foldedMemo as $order => $memo) {
            $pos = \mb_strpos($memo['folded'], $lower, 0, 'UTF-8');
            if ($pos === false) {
                continue;
            }
            $substringHits++;
            $isAnchored = $pos * 2 < $memo['length'];
            if ($isAnchored) {
                $anchored++;
                if ($fn($this->allItems[$order])) {
                    $filtered[] = $this->allItems[$order];
                }
            }
        }
        $this->anchorDropped = $substringHits - $anchored;
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
        // A fresh hit table per filter run: a ranker that answers for fewer
        // candidates than offered must not leave LAST run's indices readable
        // for a survivor it no longer scores.
        $hits = new \WeakMap();
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
            $indices = $result->indices();
            foreach ($survivors[$slot] as $order) {
                $scored[]                = [
                    'item'  => $this->allItems[$order],
                    'score' => $result->score,
                    'order' => $order,
                ];
                $hits[$this->allItems[$order]] = $indices;
            }
        }
        $this->rankedHits = $hits;

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
        $hits   = new \WeakMap();
        foreach ($this->allItems as $order => $item) {
            if ($fn($item) === false) {
                continue;
            }
            $result = $ranker->match($text, $item->value());
            if ($result === null || $result->isMatched() === false) {
                continue;
            }
            $scored[] = ['item' => $item, 'score' => $result->score, 'order' => $order];
            $hits[$item] = $result->indices();
        }
        $this->rankedHits = $hits;

        \usort(
            $scored,
            static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['order'] <=> $b['order']),
        );

        return \array_map(static fn(array $s): Item => $s['item'], $scored);
    }

    private function computeWidth(): int
    {
        // Split the cost: the header term (prompt + filter) is two short
        // Width::of calls and recomputes honestly every frame; the item term
        // walks per-item measurements cached in the shared memo, so a filter
        // keystroke — which only swaps the survivor SET — formats nothing
        // again (-010).
        //
        // Measurement happens in visible cells (ANSI-stripped, wide-rune
        // aware) so the auto width matches what view() actually renders —
        // which is true only because the formatter receives the SAME guarded
        // value here as on the render path below: guard() first, so a host
        // that echoes or caches the closure argument never sees the unguarded
        // string (review M8).
        $headerTerm = Width::of($this->prompt) + Width::of($this->filterText) + Width::of($this->filterChrome()) + self::WIDTH_PAD_HEADER;

        return \max($headerTerm, $this->measuredItemMaxWidth() + self::WIDTH_PAD_ITEM);
    }

    /**
     * Widest formatted survivor, max of the selected and unselected
     * decorations (-061: a formatter whose selected flag is WIDER must not
     * have the focused row clipped by a frame sized for the plain variant).
     * Measurements live in the shared memo keyed by item object and belong to
     * one formatter identity; swapping either replaces the table.
     */
    private function measuredItemMaxWidth(): int
    {
        $memo      = $this->widthMemo;
        $formatter = $this->itemFormatter;
        if ($memo->measuredWith?->get() !== $formatter) {
            $memo->measuredWith = \WeakReference::create($formatter);
            $memo->itemWidths   = new \WeakMap();
        }
        $widths = $memo->itemWidths;

        $max = 0;
        foreach ($this->filteredItems as $item) {
            $width = $widths[$item] ?? $this->measureItem($item, $widths);
            if ($width > $max) {
                $max = $width;
            }
        }
        return $max;
    }

    /**
     * Measure one item under both selection flags and remember the wider
     * answer. The formatter is pure with respect to these two calls: the
     * render path formats each visible row once with its actual flag.
     */
    private function measureItem(Item $item, \WeakMap $widths): int
    {
        $guarded = UntrustedText::guard($item->value());
        $number  = $item->number();

        $plain    = Width::of(($this->itemFormatter)($guarded, false, $number));
        $selected = Width::of(($this->itemFormatter)($guarded, true, $number));

        return $widths[$item] = \max($plain, $selected);
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
     * Highlight every contiguous occurrence of the filter text within a
     * formatted item string (the default substring strategy).
     *
     * Match targets are computed on the GUARDED VALUE only — the formatter's
     * chrome (marker, ordinal) is never a match, whatever it spells (-060).
     * Offsets are mapped into the formatted string through its occurrence(s)
     * of the value, counted in DISPLAY codepoints (escape sequences are
     * invisible), and re-emission keeps the formatter's own escape sequences
     * verbatim — see {@see AnsiWalk::paint()}.
     */
    private function highlightMatches(string $formatted, string $value, string $filter): string
    {
        // -068: fold the value and the filter ONCE (two C-level mb_* calls)
        // and walk occurrences with mb_strpos, instead of materialising the
        // row into per-codepoint arrays and slicing a window per offset.
        $foldedValue  = \mb_strtolower($value, 'UTF-8');
        $foldedFilter = \mb_strtolower($filter, 'UTF-8');
        $needleLen    = \mb_strlen($foldedFilter, 'UTF-8');
        $valueLen     = \mb_strlen($value, 'UTF-8');
        $matched      = [];

        $search = 0;
        while (($occurrence = \mb_strpos($formatted, $value, $search, 'UTF-8')) !== false) {
            $shift = \mb_strlen(
                AnsiWalk::plain(\mb_substr($formatted, 0, $occurrence, 'UTF-8')),
                'UTF-8'
            );
            for ($pos = \mb_strpos($foldedValue, $foldedFilter, 0, 'UTF-8');
                 $pos !== false;
                 $pos = \mb_strpos($foldedValue, $foldedFilter, $pos + $needleLen, 'UTF-8')) {
                // Non-overlapping forward scan, exactly the previous semantics
                // — but confined to the value's own cells.
                for ($j = 0; $j < $needleLen; $j++) {
                    $matched[$shift + $pos + $j] = true;
                }
            }
            $search = $occurrence + $valueLen;
        }

        return $matched === [] ? $formatted : AnsiWalk::paint($formatted, $matched, $this->matchStyle);
    }

    /**
     * Highlight the runes the ranker already answered for this item, using
     * the indices carried out of the LAST filter pass (-069: the renderer
     * must never re-run match()/matchAll() per visible row — applyRankedFilter
     * produced the authoritative MatchResults and threw them away).
     *
     * The indices address the RAW value (candidates are raw text); a row whose
     * guarded spelling differs never carries a carried answer, so it renders
     * unstyled rather than painting offsets computed against a different
     * string. When no carried answer exists — a ranker that stayed silent for
     * this candidate — the row is re-emitted unchanged, host styling included.
     */
    private function highlightFuzzy(string $formatted, string $guarded, string $raw, Item $item): string
    {
        if ($guarded !== $raw || !isset($this->rankedHits[$item])) {
            return $formatted;
        }

        $occurrence = \mb_strpos($formatted, $raw, 0, 'UTF-8');
        if ($occurrence === false) {
            return $formatted;
        }

        $shift = \mb_strlen(
            AnsiWalk::plain(\mb_substr($formatted, 0, $occurrence, 'UTF-8')),
            'UTF-8'
        );

        $matched = [];
        foreach ($this->rankedHits[$item] as $index) {
            $matched[$shift + $index] = true;
        }

        return AnsiWalk::paint($formatted, $matched, $this->matchStyle);
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
            if ($destY < 0 || $destY >= \count($bgLines)) {
                continue;
            }

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
