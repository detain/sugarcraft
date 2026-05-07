<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

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
    /** @var list<string> */
    private array $allItems = [];

    /** @var list<string> */
    private array $filteredItems = [];

    private bool $isShown = false;

    /** 0-based cursor index within filteredItems. */
    private int $cursor = 0;

    private string $filterText = '';

    private string $prompt = '> ';

    /** @var \Closure(string $item, bool $isSelected): string */
    private \Closure $itemFormatter;

    /** Match highlight style (ANSI SGR codes, e.g. "\e[33m"). */
    private string $matchStyle = '';

    /** Height of the overlay list window. */
    private int $windowHeight = 10;

    /** Width of the overlay window (0 = auto from prompt). */
    private int $windowWidth = 0;

    /** Top-left X offset for the overlay. */
    private int $xOffset = 0;

    /** Top-left Y offset for the overlay. */
    private int $yOffset = 0;

    public function __construct(
        array $items = [],
        ?\Closure $itemFormatter = null,
    ) {
        $this->allItems     = \array_values(\array_map('strval', $items));
        $this->filteredItems = $this->allItems;
        $this->itemFormatter = $itemFormatter
            ?? fn(string $item, bool $selected): string =>
                ($selected ? '● ' : '  ') . $item;
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
        $clone->allItems = \array_values(\array_map('strval', $items));
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
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
        $clone->isShown = true;
        return $clone;
    }

    public function setItemFormatter(\Closure $fn): self
    {
        $clone = clone $this;
        $clone->itemFormatter = $fn;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // State mutations (all return new instance)
    // -------------------------------------------------------------------------

    public function show(): self
    {
        $clone = clone $this;
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
        $clone = clone $this;
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
        $clone->filterText = \substr($clone->filterText, 0, -1);
        $clone->filteredItems = $clone->applyFilter($clone->filterText);
        $clone->cursor = \min($clone->cursor, \count($clone->filteredItems) - 1);
        return $clone;
    }

    public function clear(): self
    {
        $clone = clone $this;
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
        $clone->cursor = \count($clone->filteredItems) - 1;
        if ($clone->cursor < 0) $clone->cursor = 0;
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

    public function selected(): ?string
    {
        $items = $this->filteredItems;
        $idx   = $this->cursor;
        return $items[$idx] ?? null;
    }

    /** @return list<string> */
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

        $prompt   = $this->prompt;
        $filter   = $this->filterText;
        $winWidth = $this->windowWidth > 0 ? $this->windowWidth : $this->computeWidth();

        // Build the overlay string
        $headerLine = $prompt . $filter;
        $headerLen  = \strlen($headerLine);

        // Pad header to window width
        $headerLine = \str_pad($headerLine, $winWidth, ' ');

        $lines = [$headerLine];

        // Separator
        $sep = \str_repeat('─', $winWidth);
        $lines[] = $sep;

        // Item lines
        $items = $this->filteredItems;
        $maxShow = \min(\count($items), $this->windowHeight - 2);

        for ($i = 0; $i < $maxShow; $i++) {
            $isSelected = ($i === $this->cursor);
            $itemStr    = ($this->itemFormatter)($items[$i], $isSelected);

            if ($filter !== '' && $this->matchStyle !== '') {
                $itemStr = $this->highlightMatches($itemStr, $filter);
            }

            $itemStr = \str_pad(\substr($itemStr, 0, $winWidth), $winWidth, ' ');
            $lines[] = $itemStr;
        }

        // Fill remaining lines if fewer items than window height
        while (\count($lines) < $this->windowHeight) {
            $lines[] = \str_repeat(' ', $winWidth);
        }

        // Composite onto background
        return $this->compositeOver($lines, $backgroundView);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Fuzzy filter allItems case-insensitively with an anchor bias: the
     * filter must appear as a contiguous substring AND the match must
     * start in the first half of the item. Keeps deeply-buried matches
     * (e.g. 'b' in 'elderberry' at position 5/10) from polluting results
     * for short queries.
     *
     * @return list<string>
     */
    private function applyFilter(string $text): array
    {
        if ($text === '') {
            return $this->allItems;
        }
        $lower = \strtolower($text);
        return \array_values(
            \array_filter(
                $this->allItems,
                function (string $item) use ($lower): bool {
                    $pos = \strpos(\strtolower($item), $lower);
                    return $pos !== false && $pos * 2 < \strlen($item);
                }
            )
        );
    }

    private function computeWidth(): int
    {
        $promptLen = \strlen($this->prompt);
        $filterLen = \strlen($this->filterText);
        $itemMax   = 0;
        foreach ($this->filteredItems as $item) {
            $itemLen = \strlen(($this->itemFormatter)($item, false));
            if ($itemLen > $itemMax) $itemMax = $itemLen;
        }
        return \max($promptLen + $filterLen + 5, $itemMax + 2);
    }

    private function highlightMatches(string $text, string $filter): string
    {
        $lower = \strtolower($text);
        $flen  = \strlen($filter);
        $result = '';
        $i = 0;
        $len = \strlen($text);

        while ($i < $len) {
            $matched = false;
            // Check if text[i:] starts with filter (case-insensitive)
            if (\strncasecmp($text[$i], $filter, \min($flen, $len - $i)) === 0) {
                // Highlight all matched chars
                $matchLen = \min($flen, $len - $i);
                $result .= $this->matchStyle;
                for ($j = 0; $j < $matchLen; $j++) {
                    $result .= $text[$i + $j];
                }
                $result .= "\x1b[0m";
                $i += $matchLen;
                $matched = true;
            }
            if (!$matched) {
                $result .= $text[$i];
                $i++;
            }
        }
        return $result;
    }

    private function compositeOver(array $overlayLines, string $background): string
    {
        $bgLines = \explode("\n", $background);
        if (\end($bgLines) === '') \array_pop($bgLines);

        $x = $this->xOffset;
        $y = $this->yOffset;
        $winWidth = $this->windowWidth > 0 ? $this->windowWidth : $this->computeWidth();

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

    private function replaceSegment(string $line, int $x, int $width, string $replacement): string
    {
        $len = \strlen($line);
        $result = '';

        for ($i = 0; $i < $len; $i++) {
            if ($i >= $x && $i < $x + $width) {
                $repIdx = $i - $x;
                if ($repIdx < \strlen($replacement)) {
                    $result .= $replacement[$repIdx];
                } else {
                    $result .= ' ';
                }
            } else {
                $result .= $line[$i];
            }
        }

        // If overlay extends beyond background line, pad
        $remaining = $x + $width - $len;
        if ($remaining > 0) {
            $result .= \substr($replacement, $len - $x, $remaining);
        }

        return $result;
    }
}
