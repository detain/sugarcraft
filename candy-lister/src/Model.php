<?php

declare(strict_types=1);

namespace CandyCore\Lister;

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

    /** @var callable(\Stringable, \Stringable): int|null */
    public ?callable $lessFunc = null;

    /** @var callable(\Stringable, \Stringable): bool|null */
    public ?callable $equalsFunc = null;

    public ?Prefixer $prefixer = null;
    public ?Suffixer $suffixer = null;

    /** Style for non-current items (ANSI string). */
    public string $lineStyle = '';

    /** Style for current item (ANSI string). */
    public string $currentStyle = '';

    // -------------------------------------------------------------------------
    // Internal state
    // -------------------------------------------------------------------------

    /** @var list<Item> */
    private array $items = [];

    private int $cursorIndex = 0;
    private int $idCounter = 0;

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
        $this->width = $width;
        return $this;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;
        return $this;
    }

    public function setViewport(int $width, int $height): self
    {
        $this->width  = $width;
        $this->height = $height;
        return $this;
    }

    public function setCursorOffset(int $n): self
    {
        $this->cursorOffset = $n;
        $this->lineOffset   = $n;
        return $this;
    }

    public function setWrap(int $maxLines): self
    {
        $this->wrap = $maxLines;
        return $this;
    }

    public function setPrefixer(Prefixer $p): self
    {
        $this->prefixer = $p;
        return $this;
    }

    public function setSuffixer(Suffixer $s): self
    {
        $this->suffixer = $s;
        return $this;
    }

    public function setLineStyle(string $ansiStyle): self
    {
        $this->lineStyle = $ansiStyle;
        return $this;
    }

    public function setCurrentStyle(string $ansiStyle): self
    {
        $this->currentStyle = $ansiStyle;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Add an item to the list.
     */
    public function addItem(\Stringable $value): self
    {
        $this->items[] = new Item($value, $this->idCounter++);
        return $this;
    }

    /**
     * Remove an item by index. Cursor is clamped.
     */
    public function removeItem(int $index): self
    {
        if ($index < 0 || $index >= \count($this->items)) {
            return $this;
        }
        \array_splice($this->items, $index, 1);
        $this->cursorIndex = \min($this->cursorIndex, \max(0, \count($this->items) - 1));
        return $this;
    }

    /**
     * Clear all items and reset cursor.
     */
    public function clear(): self
    {
        $this->items = [];
        $this->cursorIndex = 0;
        return $this;
    }

    /**
     * Sort items using the configured LessFunc.
     */
    public function sort(): self
    {
        if ($this->lessFunc === null) {
            return $this;
        }
        \usort($this->items, fn(Item $a, Item $b) =>
            ($this->lessFunc)($a->value, $b->value)
        );
        return $this;
    }

    // -------------------------------------------------------------------------
    // Cursor
    // -------------------------------------------------------------------------

    public function cursorIndex(): int
    {
        return $this->cursorIndex;
    }

    public function setCursor(int $index): self
    {
        $this->cursorIndex = \max(0, \min($index, \count($this->items) - 1));
        return $this;
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
            throw new \RuntimeException('NoItems: list has no items');
        }
        if ($this->width <= 0 || $this->height <= 0) {
            throw new \RuntimeException("Can't display with zero width or height of viewport");
        }

        $allLines = [];

        // Lines before cursor (above)
        $beforeCursor = $this->collectLinesBeforeCursor();

        // Lines after cursor (below)
        $afterCursor = $this->collectLinesAfterCursor();

        // Interleave: [before in reverse] + [cursor item] + [after]
        $beforeReversed = \array_reverse($beforeCursor);
        foreach ($beforeReversed as $l) { $allLines[] = $l; }
        $allLines[] = $this->renderItemLines($this->cursorIndex);
        foreach ($afterCursor as $l) { $allLines[] = $l; }

        // Trim to viewport height
        if (\count($allLines) > $this->height) {
            $allLines = \array_slice($allLines, 0, $this->height);
        }

        return $allLines;
    }

    /**
     * Render the list and return as a single newline-joined string.
     */
    public function View(): string
    {
        try {
            return \implode("\n", $this->lines()) . "\n";
        } catch (\RuntimeException $e) {
            return $e->getMessage() . "\n";
        }
    }

    // -------------------------------------------------------------------------
    // Internal rendering
    // -------------------------------------------------------------------------

    /**
     * Collect lines for all items above the cursor, limited by lineOffset.
     *
     * @return list<array{0: int, 1: string}> (itemIndex, renderedLines) pairs
     */
    private function collectLinesBeforeCursor(): array
    {
        $result = [];
        $linesNeeded = $this->lineOffset;

        for ($c = 1; $c <= $this->cursorIndex && $linesNeeded > 0; $c++) {
            $itemIndex = $this->cursorIndex - $c;
            $lines = $this->getItemLines($itemIndex);
            $linesCount = \count($lines);

            // Add as many as fit
            $toAdd = \array_slice($lines, 0, $linesNeeded);
            foreach (\array_reverse($toAdd) as $line) {
                $result[] = [$itemIndex, $line];
            }
            $linesNeeded -= \count($toAdd);
        }

        return $result;
    }

    /**
     * Collect lines for all items below the cursor, filling the viewport.
     *
     * @return list<array{0: int, 1: string}> (itemIndex, renderedLines) pairs
     */
    private function collectLinesAfterCursor(): array
    {
        $result = [];
        $linesNeeded = $this->height - 1; // -1 for cursor item

        for ($i = $this->cursorIndex + 1; $i < \count($this->items) && $linesNeeded > 0; $i++) {
            $lines = $this->getItemLines($i);
            $toAdd = \array_slice($lines, 0, $linesNeeded);
            foreach ($toAdd as $line) {
                $result[] = [$i, $line];
            }
            $linesNeeded -= \count($toAdd);
        }

        return $result;
    }

    /**
     * Render all lines for a single item (with pre/suffix), applying cursor style.
     *
     * @return string Single string (may contain \n if item is multi-line)
     */
    private function renderItemLines(int $itemIndex): string
    {
        $item  = $this->items[$itemIndex];
        $lines = $this->itemLines($item, $itemIndex);
        $total = \count($lines);

        $outputLines = [];
        foreach ($lines as $lineIdx => $lineContent) {
            // Prefix
            $prefix = '';
            if ($this->prefixer !== null) {
                $prefix = $this->prefixer->prefix($lineIdx, $total);
            }

            // Content width (for suffix padding)
            $contentWidth = $this->ansiWidth($lineContent);

            // Suffix
            $suffix = '';
            $suffixWidth = 0;
            if ($this->suffixer !== null) {
                $suffix     = $this->suffixer->suffix($lineIdx, $total);
                $suffixWidth = $this->ansiWidth($suffix);
            }

            // Right-pad with spaces to fill viewport
            $filled = $lineContent;
            $used   = $this->ansiWidth($prefix) + $contentWidth + $suffixWidth;
            if ($used < $this->width) {
                $filled .= \str_repeat(' ', $this->width - $used);
            }

            $styled = $prefix . $filled . $suffix;
            if ($itemIndex === $this->cursorIndex && $this->currentStyle !== '') {
                $styled = $this->applyStyle($styled, $this->currentStyle);
            } elseif ($this->lineStyle !== '') {
                $styled = $this->applyStyle($styled, $this->lineStyle);
            }

            $outputLines[] = $styled;
        }

        return \implode("\n", $outputLines);
    }

    /**
     * Get the raw (unprefixed) lines for an item, respecting wrap limit.
     *
     * @return list<string>
     */
    private function itemLines(Item $item, int $itemIndex): array
    {
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

        // Word-wrap the item string to contentWidth
        $raw = (string) $item->value;
        $wrapped = $this->hardWrap($raw, $contentWidth);
        if ($this->wrap > 0 && \count($wrapped) > $this->wrap) {
            $wrapped = \array_slice($wrapped, 0, $this->wrap);
        }

        return $wrapped;
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

    /**
     * Wrap item lines with prefix/suffix for external consumption (before stylng).
     *
     * @return array{0: int, 1: list<string>} (itemIndex, prefixed lines)
     */
    private function getItemLines(int $itemIndex): array
    {
        $item  = $this->items[$itemIndex];
        $rawLines = $this->itemLines($item, $itemIndex);
        $total = \count($rawLines);

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
        $lines = [];

        foreach ($rawLines as $lineIdx => $lineContent) {
            $prefix = $this->prefixer?->prefix($lineIdx, $total) ?? '';
            $suffix = $this->suffixer?->suffix($lineIdx, $total) ?? '';
            $lines[] = $prefix . $lineContent . $suffix;
        }

        return $lines;
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
        return "\x1b[{$codes}m{$s}\x1b[0m";
    }

    /** Compute printable (non-ANSI) width. */
    private function ansiWidth(string $s): int
    {
        return \strlen(\preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $s) ?: '');
    }
}
