<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Sprinkles\Align;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\VAlign;
use SugarCraft\Sprinkles\Border\TitleAnchor;
use SugarCraft\Pty\SignalForwarder;

/**
 * The Hermit — fuzzy finder / quick-fix overlay component.
 *
 * Renders a filterable list overlay on top of a background view.
 * Background continues to update while the overlay is shown.
 *
 * Port of Genekkion/theHermit.
 *
 * @see https://github.com/Genekkion/theHermit
 */
final class Hermit
{
    /** Maximum length of filter text before input is rejected. */
    public const MAX_FILTER_LENGTH = 256;

    /** Padding added to prompt+filter length when computing auto width. */
    private const WIDTH_PAD_HEADER = 5;

    /** Padding added to longest item length when computing auto width. */
    private const WIDTH_PAD_ITEM = 2;

    /** @var list<Item> */
    private array $allItems = [];

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
     * every item's value via candy-fuzzy (true subsequence matching) and the
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

    /** Border rune set for the overlay window (composed from candy-sprinkles). */
    private ?Border $border = null;

    /** Style for the overlay window (composed from candy-sprinkles). */
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

    public function __construct(
        array $items = [],
        ?\Closure $itemFormatter = null,
        ?\Closure $filterFn = null,
    ) {
        $this->itemFactory   = new ItemFactory();
        $this->allItems      = $this->coerceItems($items);
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

    public function withItems(array $items): self
    {
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->allItems = $clone->coerceItems($items);
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
        $clone->prompt = $prompt;
        return $clone;
    }

    public function setMatchStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->matchStyle = $ansiStyle;
        return $clone;
    }

    public function setWindowHeight(int $h): self
    {
        $clone = clone $this;
        $clone->windowHeight = $h;
        return $clone;
    }

    public function setWindowWidth(int $w): self
    {
        $clone = clone $this;
        $clone->windowWidth = $w;
        return $clone;
    }

    public function setOffset(int $x, int $y): self
    {
        $clone = clone $this;
        $clone->xOffset = $x;
        $clone->yOffset = $y;
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
     * Set (or clear, with null) a candy-fuzzy ranker. When set, a non-empty
     * filter text ranks items by descending fuzzy score (true subsequence match)
     * instead of the default contiguous-substring/anchor filter, and match
     * highlighting follows the scored indices. The custom filterFn still applies
     * as a predicate. Pass null to restore the default substring behaviour.
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
     * Apply a border from candy-sprinkles.
     */
    public function withBorder(?Border $border): self
    {
        $clone = clone $this;
        $clone->border = $border;
        return $clone;
    }

    /**
     * Apply a style from candy-sprinkles.
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
     * The callback receives (cols: int, rows: int).
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
     * Requires ext-pcntl. Queries SugarCraft\Core\Util\Tty::size() on \STDIN
     * to get the current terminal geometry, then invokes the $onResize closure
     * with the fresh dimensions. Falls back to 80x24 if the query fails (e.g.
     * non-interactive context). Returns true if the handler was installed;
     * false if pcntl/SIGWINCH is unavailable.
     *
     * Mirrors SignalForwarder::attachSigwinchToFd pattern.
     */
    public function attachSigwinch(): bool
    {
        if ($this->onResize === null) {
            return false;
        }

        // Capture $this in a local variable so the static closure can
        // dereference the live Hermit instance when invoked asynchronously
        // (the closure outlives the attachSigwinch() call frame).
        $hermit = $this;
        return SignalForwarder::attachSigwinchToFd(
            (int) \STDIN, // int fd, not resource (PHP 8+ casts resource to its fd number)
            static fn(): array => $hermit->ttySize(),
            static function (int $cols, int $rows) use ($hermit): void {
                $cb = $hermit->onResize;
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

    public function type(string $char): self
    {
        if (\strlen($this->filterText) >= self::MAX_FILTER_LENGTH) {
            return $this; // reject input when cap reached
        }
        $clone = clone $this;
        $clone->cachedComputedWidth = null;
        $clone->filterText .= $char;
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = 0;
        return $clone;
    }

    public function backspace(): self
    {
        $clone = clone $this;
        if ($clone->filterText === '') {
            return $clone;
        }
        $clone->cachedComputedWidth = null;
        $clone->filterText = \substr($clone->filterText, 0, -1);
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
        $clone = clone $this;
        $clone->cursor = \max(0, $clone->cursor - $n);
        return $clone;
    }

    public function cursorDown(int $n = 1): self
    {
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
     * Render the Hermit overlay and composite it over $backgroundView.
     *
     * @param string $backgroundView  The underlying view (e.g. current app output)
     * @return string  The composited output with Hermit overlay chars replacing background
     */
    public function View(string $backgroundView): string
    {
        if (!$this->isShown) {
            return $backgroundView;
        }

        $winWidth = $this->windowWidth > 0 ? $this->windowWidth : ($this->cachedComputedWidth ??= $this->computeWidth());

        $bgLines = \explode("\n", $backgroundView);
        if (\end($bgLines) === '') \array_pop($bgLines);
        $totalOverlayLines = \count($this->buildOverlayLines($winWidth));
        if ($totalOverlayLines > \count($bgLines)) {
            // Pad background with empty lines so compositeOver has room.
            $padding = \array_fill(0, $totalOverlayLines - \count($bgLines), '');
            $bgLines = \array_merge($bgLines, $padding);
            $backgroundView = \implode("\n", $bgLines);
        }

        $lines = $this->buildOverlayLines($winWidth);

        return $this->compositeOver($lines, $backgroundView, $winWidth, $bgLines);
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
        $headerLine = Width::padRight($prompt . $filter, $winWidth);

        $lines = [$headerLine];

        $sep = \str_repeat('─', $winWidth);
        $lines[] = $sep;

        $items = $this->filteredItems;
        $visibleRows = \max(0, $this->windowHeight - 2);
        $maxShow = \min(\count($items), $visibleRows);
        $top = $this->cursor < $visibleRows
            ? 0
            : \min($this->cursor - $visibleRows + 1, \count($items) - $visibleRows);
        $top = \max(0, $top);

        for ($i = 0; $i < $maxShow; $i++) {
            $actualIndex = $top + $i;
            $isSelected = ($actualIndex === $this->cursor);
            $itemStr    = ($this->itemFormatter)($items[$actualIndex]->value(), $isSelected, $items[$actualIndex]->number());

            if ($filter !== '' && $this->matchStyle !== '') {
                $itemStr = $this->ranker !== null
                    ? $this->highlightFuzzy($this->ranker, $itemStr, $filter)
                    : $this->highlightMatches($itemStr, $filter);
            }

            $itemStr = \str_replace(["\r\n", "\r", "\n"], ' ', $itemStr);
            $itemStr = Width::padRight(Width::truncateAnsi($itemStr, $winWidth), $winWidth);
            $lines[] = $itemStr;
        }

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
     * Filter allItems using the configured filter function.
     * When filterText is empty, returns all items.
     * Otherwise applies both the filterText (substring match with anchor bias)
     * and the custom filterFn.
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
        $lower = \strtolower($text);
        $filtered = [];
        foreach ($this->allItems as $item) {
            $value = $item->value();
            $pos = \strpos(\strtolower($value), $lower);
            $anchorOk = $pos !== false && $pos * 2 < \strlen($value);
            if ($anchorOk && $fn($item)) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }

    /**
     * Rank items by descending candy-fuzzy score for a non-empty filter text,
     * keeping only positive-scoring items that also pass the filterFn predicate.
     * Ties break on the items' original order so ranking is stable.
     *
     * @return list<Item>
     */
    private function applyRankedFilter(FuzzyMatcher $ranker, string $text): array
    {
        $fn = $this->filterFn;

        // Collect filtered items with their original indices for stable tie-breaking
        $candidates = [];
        $itemsByValue = [];
        $originalOrder = [];
        foreach ($this->allItems as $order => $item) {
            if (!$fn($item)) {
                continue;
            }
            $value = $item->value();
            $candidates[] = $value;
            $itemsByValue[$value] = $item;
            $originalOrder[$value] = $order;
        }

        if ($candidates === []) {
            return [];
        }

        // Use matchAll for batch scoring — aligned with step 15 (candy-hermit-15)
        $results = $ranker->matchAll($text, $candidates);

        /** @var list<array{item: Item, score: int, order: int}> $scored */
        $scored = [];
        foreach ($results as $result) {
            if ($result === null || !$result->isMatched()) {
                continue;
            }
            $item = $itemsByValue[$result->haystack] ?? null;
            if ($item === null) {
                continue;
            }
            $scored[] = [
                'item' => $item,
                'score' => $result->score,
                'order' => $originalOrder[$result->haystack],
            ];
        }

        // Fallback to per-item match() when matchAll returns empty.
        // Some rankers (e.g., test mocks) may have inconsistent match/matchAll.
        // This preserves backward compatibility with the original per-item loop.
        if ($scored === []) {
            foreach ($this->allItems as $order => $item) {
                if (!$fn($item)) {
                    continue;
                }
                $result = $ranker->match($text, $item->value());
                if ($result === null || !$result->isMatched()) {
                    continue;
                }
                $scored[] = ['item' => $item, 'score' => $result->score, 'order' => $order];
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

    private function computeWidth(): int
    {
        // Measure in visible cells (ANSI-stripped, wide-rune aware) so the auto
        // width matches what View() actually renders.
        $promptLen = Width::of($this->prompt);
        $filterLen = Width::of($this->filterText);
        $itemMax   = 0;
        foreach ($this->filteredItems as $item) {
            $itemLen = Width::of(($this->itemFormatter)($item->value(), false, $item->number()));
            if ($itemLen > $itemMax) $itemMax = $itemLen;
        }
        return \max($promptLen + $filterLen + self::WIDTH_PAD_HEADER, $itemMax + self::WIDTH_PAD_ITEM);
    }

    /**
     * Extract the printable text (runes only, ANSI control sequences stripped)
     * from a formatted item string, so a highlighter can re-style matched runs
     * by character index without disturbing escape sequences.
     */
    private function printableText(string $text): string
    {
        $charString = '';

        $handler = new class($charString) implements \SugarCraft\Ansi\Parser\Handler
        {
            /** @var string */
            private string $string;

            public function __construct(string &$string)
            {
                $this->string = &$string;
            }

            public function printChar(string $rune): void
            {
                $this->string .= $rune;
            }

            public function execute(int $byte): void
            {
            }

            public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
            {
            }

            public function escDispatch(int $final, int $intermediate): void
            {
            }

            public function oscDispatch(string $data): void
            {
            }

            public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void
            {
            }

            public function sosPmApcDispatch(string $kind, string $data): void
            {
            }
        };

        $parser = new \SugarCraft\Ansi\Parser\Parser($handler);
        $parser->feed($text);
        $parser->flush();

        return $charString;
    }

    /**
     * Highlight every contiguous occurrence of the filter text within a
     * formatted item string (the default substring strategy).
     */
    private function highlightMatches(string $text, string $filter): string
    {
        $charString = $this->printableText($text);

        $lower = \mb_strtolower($charString, 'UTF-8');
        $filterLower = \mb_strtolower($filter, 'UTF-8');
        $flen = \mb_strlen($filterLower, 'UTF-8');
        $charLen = \mb_strlen($charString, 'UTF-8');
        $result = '';
        $i = 0;

        while ($i < $charLen) {
            $matched = false;
            $remainingChars = $charLen - $i;
            if ($remainingChars >= $flen) {
                $charSubstr = \mb_substr($lower, $i, $flen, 'UTF-8');
                if ($charSubstr === $filterLower) {
                    $matchLenChars = $flen;
                    $result .= $this->matchStyle;
                    for ($j = 0; $j < $matchLenChars; $j++) {
                        $result .= \mb_substr($charString, $i + $j, 1, 'UTF-8');
                    }
                    $result .= Ansi::reset();
                    $i += $matchLenChars;
                    $matched = true;
                }
            }
            if (!$matched) {
                $result .= \mb_substr($charString, $i, 1, 'UTF-8');
                $i++;
            }
        }
        return $result;
    }

    /**
     * Highlight the candy-fuzzy-matched runes in a formatted item string,
     * following the ranker's scored indices (a subsequence match) rather than a
     * contiguous substring. Falls back to the plain printable text when the
     * ranker reports no match for the displayed string.
     */
    private function highlightFuzzy(FuzzyMatcher $ranker, string $text, string $filter): string
    {
        $charString = $this->printableText($text);
        $result = $ranker->match($filter, $charString);
        if ($result === null || $result->isEmpty()) {
            return $charString;
        }

        $style = $this->matchStyle;

        return (new Highlighter())->highlight(
            $result,
            static fn(string $matched): string => $style . $matched . Ansi::reset(),
        );
    }

    private function compositeOver(array $overlayLines, string $background, int $winWidth, array $bgLines): string
    {
        $x = $this->xOffset;
        $y = $this->yOffset;

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
     * Uses grapheme-aware functions (\grapheme_substr, \grapheme_strlen) to
     * correctly handle CJK characters, emoji with ZWJ, and other complex runes
     * that occupy a single visible cell but multiple bytes. For pure ASCII
     * input this is equivalent to byte-level replacement but preserves
     * visual integrity for international text.
     */
    private function replaceSegment(string $line, int $x, int $width, string $replacement): string
    {
        $lineLen = \grapheme_strlen($line);

        if ($lineLen === 0) {
            return $replacement;
        }

        $prefix = $x > 0 ? (\grapheme_substr($line, 0, $x) ?: '') : '';
        $suffixStart = $x + $width;
        $suffix = $suffixStart < $lineLen ? (\grapheme_substr($line, $suffixStart) ?: '') : '';

        $repLen = \grapheme_strlen($replacement);
        $replacementPart = \grapheme_substr($replacement, 0, $width) ?: '';

        if (\grapheme_strlen($replacementPart) < $width) {
            $replacementPart .= \str_repeat(' ', $width - \grapheme_strlen($replacementPart));
        }

        if ($suffixStart >= $lineLen && $repLen > $width) {
            $remainingChars = $repLen - $width;
            $suffix = \grapheme_substr($replacement, $width, $remainingChars) ?: '';
        }

        return $prefix . $replacementPart . $suffix;
    }
}
