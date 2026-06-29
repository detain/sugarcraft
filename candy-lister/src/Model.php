<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
use SugarCraft\Lister\Lang;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Core list model — stores items, renders visible lines within a viewport.
 *
 * Port of treilik/bubblelister Model. Key properties:
 * - Items are stored as {@see Item} wrappers around \Stringable values
 * - CursorOffset keeps the cursor N lines from the visible viewport edge
 * - Wrap limits how many physical lines a multi-line item may produce
 * - Prefixer/Suffixer hooks customise per-line prefix and suffix strings
 * - LessFunc/EqualsFunc plug in external sorting/equality logic
 *
 * Usage:
 * ```php
 * $model = Model::new();
 * $model->setWidth(80)->setHeight(24);
 * foreach (['apple', 'banana', 'cherry'] as $f) {
 *     $model->addItem(new StringItem($f));
 * }
 * echo $model->View();
 * ```
 *
 * @see https://github.com/treilik/bubblelister
 */
final class Model
{
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public int $width  = 80;  // viewport width in cells
    public int $height = 24;  // viewport height in lines
    public int $cursorOffset = 5;  // gap between cursor and viewport edge
    public int $lineOffset = 5;    // how many lines before cursor to show
    public int $wrap = 0;          // max lines per item (0 = unlimited)

    /** @var \Closure(\Stringable, \Stringable): int|null */
    public ?\Closure $lessFunc = null;

    /** @var \Closure(\Stringable, \Stringable): bool|null */
    public ?\Closure $equalsFunc = null;

    public ?Prefixer $prefixer = null;
    public ?Suffixer $suffixer = null;

    /** Style for non-current items (ANSI string). */
    public string $lineStyle = '';

    /** Style for current item (ANSI string). */
    public string $currentStyle = '';

    /** @var \Closure(\Stringable): bool|null */
    public ?\Closure $filterFn = null;

    public ?FilterState $filterState = null;

    // -------------------------------------------------------------------------
    // Internal state
    // -------------------------------------------------------------------------

    /** @var list<Item> */
    private array $items = [];

    /** @var list<Item> */
    private array $originalItems = [];

    private int $cursorIndex = 0;
    private int $idCounter = 0;

    /** @var Buffer|null Previous rendered frame for diff-based emission */
    private ?Buffer $previousFrame = null;

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
     * Mirrors the candy-sprinkles/Style.php mutate() pattern.
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
        return $this->mutate(fn($m) => $m->width = $width);
    }

    public function setHeight(int $height): self
    {
        return $this->mutate(fn($m) => $m->height = $height);
    }

    public function setViewport(int $width, int $height): self
    {
        return $this->mutate(fn($m) => [$m->width, $m->height] = [$width, $height]);
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
        return $this->mutate(fn($m) => $m->lineStyle = $ansiStyle);
    }

    public function setCurrentStyle(string $ansiStyle): self
    {
        return $this->mutate(fn($m) => $m->currentStyle = $ansiStyle);
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
        $clone->originalItems = $clone->items;
        $clone->items = array_values(array_filter(
            $clone->items,
            fn(Item $item) => $fn($item->value),
        ));
        // Clamp cursor to new length
        $clone->cursorIndex = min($clone->cursorIndex, max(0, count($clone->items) - 1));
        // Reset diff state so the next View() emits a full frame, not a delta
        // against the pre-filter frame (which has a different line set).
        $clone->previousFrame = null;
        $clone->prevWidth = null;
        $clone->prevHeight = null;
        return $clone;
    }

    /**
     * Clear the filter and return a new Model with unfiltered items.
     *
     * Restores the original item list and transitions filterState to unfiltered.
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
        // Reset diff state so the next View() emits a full frame, not a delta
        // against the pre-unfilter frame (which has a different line set).
        $clone->previousFrame = null;
        $clone->prevWidth = null;
        $clone->prevHeight = null;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Add an item to the list.
     */
    public function addItem(\Stringable $value): self
    {
        $id = $this->idCounter++;
        return $this->mutate(fn($m) => $m->items[] = new Item($value, $id));
    }

    /**
     * Remove an item by index. Cursor is clamped.
     */
    public function removeItem(int $index): self
    {
        if ($index < 0 || $index >= \count($this->items)) {
            return $this;
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
     */
    public function sort(): self
    {
        if ($this->lessFunc === null) {
            return $this;
        }
        $selected = $this->items[$this->cursorIndex] ?? null;
        $items = $this->items;
        \usort($items, fn(Item $a, Item $b) =>
            ($this->lessFunc)($a->value, $b->value)
        );
        $cursorIndex = $this->cursorIndex;
        if ($selected !== null) {
            foreach ($items as $i => $item) {
                if ($item === $selected) {
                    $cursorIndex = $i;
                    break;
                }
            }
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

        $count = \count($this->items);

        // Collect lines for items above the cursor, walking outward, capped by cursorOffset.
        // We collect them bottom-up (closest to cursor first), then reverse for display order.
        $before = [];
        for ($c = 1; $this->cursorIndex - $c >= 0 && $c <= $this->cursorOffset; $c++) {
            $index = $this->cursorIndex - $c;
            $itemLines = $this->renderItem($index);
            for ($i = \count($itemLines) - 1; $i >= 0 && \count($before) < $this->cursorOffset; $i--) {
                $before[] = $itemLines[$i];
            }
            if (\count($before) >= $this->cursorOffset) {
                break;
            }
        }

        $allLines = [];
        for ($c = \count($before) - 1; $c >= 0; $c--) {
            $allLines[] = $before[$c];
        }

        // Lines from the cursor downward, capped by viewport height.
        $cursorLineIndex = \count($allLines); // 0-based line index of cursor item's first line
        for ($index = $this->cursorIndex; $index < $count && \count($allLines) < $this->height; $index++) {
            foreach ($this->renderItem($index) as $line) {
                if (\count($allLines) >= $this->height) {
                    break 2;
                }
                $allLines[] = $line;
            }
        }

        // Viewport-follow: if cursor is within cursorOffset of the bottom while more lines
        // remain below, shift the window down so the cursor stays cursorOffset from the edge.
        // This mirrors the bubblelister "keep selection visible top+bottom" contract.
        $cursorLineInWindow = $cursorLineIndex;
        $bottomGap = \count($allLines) - 1 - $cursorLineInWindow; // lines below cursor in current window
        if ($bottomGap < $this->cursorOffset && \count($allLines) < $this->height) {
            // Not enough lines below cursor; cursor would be too close to bottom edge.
            // Shift window: drop $shift = cursorOffset - $bottomGap lines from the top
            // and pull more lines from below if available.
            $shift = $this->cursorOffset - $bottomGap;
            if ($shift > 0 && \count($allLines) > $shift) {
                $allLines = \array_slice($allLines, $shift);
                // Try to fill back up to height with lines from items AFTER the last rendered one.
                $linesAdded = \count($allLines);
                for ($index = $this->cursorIndex + 1; $index < $count && $linesAdded < $this->height; $index++) {
                    foreach ($this->renderItem($index) as $line) {
                        if ($linesAdded >= $this->height) {
                            break 2;
                        }
                        $allLines[] = $line;
                        $linesAdded++;
                    }
                }
            }
        }

        return $allLines;
    }

    /**
     * Render the list and return as a single newline-joined string.
     *
     * On the first render (or after a resize), emits the full output.
     * On subsequent renders with the same dimensions, emits only the
     * delta via Buffer::diff() + DiffEncoder for reduced SSH bandwidth.
     */
    public function View(): string
    {
        try {
            // Detect window resize — reset diff state so we emit a full frame.
            if ($this->prevWidth !== null && ($this->prevWidth !== $this->width || $this->prevHeight !== $this->height)) {
                $this->previousFrame = null;
            }
            $this->prevWidth = $this->width;
            $this->prevHeight = $this->height;

            $lines = $this->lines();
            $fullOutput = \implode("\n", $lines) . "\n";

            // First frame or resize: emit full output and store as previousFrame.
            if ($this->previousFrame === null) {
                $this->previousFrame = $this->bufferFromOutput($fullOutput, $this->width, $this->height);
                return $fullOutput;
            }

            // Subsequent frames with same dimensions: compute diff and emit delta.
            $currentFrame = $this->bufferFromOutput($fullOutput, $this->width, $this->height);
            $ops = $currentFrame->diff($this->previousFrame);
            $this->previousFrame = $currentFrame;

            $encoder = new DiffEncoder();
            return $encoder->encode($ops);
        } catch (\RuntimeException $e) {
            return $e->getMessage() . "\n";
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

        $rawLines = $this->hardWrap((string) $item->value, $contentWidth);
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
     * Split a word that exceeds maxWidth into chunks.
     *
     * @return list<string>
     */
    private function splitOverWidth(string $word, int $maxWidth): array
    {
        $chunks = [];
        $len = \strlen($word);
        for ($i = 0; $i < $len; $i += $maxWidth) {
            $chunks[] = \substr($word, $i, $maxWidth);
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
        $codes = \trim($style, "\e\x1b[]m");
        return Ansi::CSI . $codes . 'm' . $s . Ansi::reset();
    }

    /** Compute printable (non-ANSI) cell width. */
    private function ansiWidth(string $s): int
    {
        return Width::string($s);
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * All cells are created with null style — the diff algorithm will
     * still work correctly for detecting changed character positions.
     *
     * @param string $output Multi-line string from View()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $buffer = Buffer::new($width, $height);
        $lines = \explode("\n", $output);

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            for ($col = 0; $col < $width; $col++) {
                $char = isset($line[$col]) ? \mb_substr($line, $col, 1) : ' ';
                $cell = Cell::new($char, null, null, 1);
                $buffer = $buffer->withCellAt($col, $row, $cell);
            }
        }

        return $buffer;
    }

    /**
     * Reset the previous-frame buffer, forcing the next View to emit
     * a full frame (used on window resize or cursor-position-lost events).
     */
    public function resetPreviousFrame(): void
    {
        $this->previousFrame = null;
    }
}
