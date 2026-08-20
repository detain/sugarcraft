<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Width;

/**
 * Immutable value object representing a split pane layout.
 *
 * Encapsulates the two pane contents, split direction, and proportional
 * sizing. Proportions are stored as rational values (numerator/denominator)
 * so they can be maintained across arbitrary resize events without
 * floating-point drift.
 *
 * Layout persists across terminal resizes; panes expand proportionally
 * based on their stored ratio.
 *
 * @see SplitDirection
 */
final class SplitLayout
{
    /**
     * @param string          $topOrLeftContent  Content of the first pane (top for horizontal, left for vertical).
     * @param string          $bottomOrRightContent Content of the second pane.
     * @param SplitDirection  $direction         Split orientation.
     * @param int             $numerator         Proportion of the top/left pane (default 1).
     * @param int             $denominator       Total proportion units (default 2).
     */
    public function __construct(
        public readonly string $topOrLeftContent,
        public readonly string $bottomOrRightContent,
        public readonly SplitDirection $direction,
        public readonly int $numerator = 1,
        public readonly int $denominator = 2,
    ) {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Denominator must be positive');
        }
        if ($numerator < 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException('Numerator must be between 0 and denominator inclusive');
        }
    }

    /**
     * Create a horizontal (top/bottom) split.
     */
    public static function horizontal(string $top, string $bottom, int $topNumerator = 1, int $totalDenominator = 2): self
    {
        return new self($top, $bottom, SplitDirection::Horizontal, $topNumerator, $totalDenominator);
    }

    /**
     * Create a vertical (left/right) split.
     */
    public static function vertical(string $left, string $right, int $leftNumerator = 1, int $totalDenominator = 2): self
    {
        return new self($left, $right, SplitDirection::Vertical, $leftNumerator, $totalDenominator);
    }

    /**
     * Returns a new SplitLayout with updated proportions.
     *
     * @throws \InvalidArgumentException If numerator/denominator are invalid.
     */
    public function withProportions(int $numerator, int $denominator): self
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Denominator must be positive');
        }
        if ($numerator < 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException('Numerator must be between 0 and denominator inclusive');
        }

        return $this->mutate([
            'numerator' => $numerator,
            'denominator' => $denominator,
        ]);
    }

    /**
     * Returns a new SplitLayout with updated content.
     */
    public function withContent(string $topOrLeft, string $bottomOrRight): self
    {
        return $this->mutate([
            'topOrLeftContent' => $topOrLeft,
            'bottomOrRightContent' => $bottomOrRight,
        ]);
    }

    /**
     * Create a new instance with the given changes merged in.
     *
     * Mirrors the repo-wide immutable+fluent `mutate()` convention (see
     * candy-sprinkles/src/Style.php, candy-buffer/src/Style.php) so every
     * `with*()` builds through a single point instead of hand-listing all
     * constructor params.
     *
     * @param array<string, mixed> $changes Key-value pairs to change
     */
    private function mutate(array $changes): static
    {
        return new static(...array_merge([
            'topOrLeftContent' => $this->topOrLeftContent,
            'bottomOrRightContent' => $this->bottomOrRightContent,
            'direction' => $this->direction,
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
        ], $changes));
    }

    /**
     * Calculate the split position in cells based on total available size.
     *
     * For horizontal splits, returns the row position.
     * For vertical splits, returns the column position.
     */
    public function splitPosition(int $totalSize): int
    {
        if ($totalSize <= 0) {
            return 0;
        }

        $position = (int) round(($this->numerator / $this->denominator) * $totalSize);

        // Clamp to valid range accounting for divider
        $minSize = 1;
        $maxSize = $totalSize - $minSize - 1;

        return max($minSize, min($position, $maxSize));
    }

    /**
     * Returns the actual pane sizes (width for vertical, height for horizontal).
     *
     * @return array{0: int, 1: int} First and second pane size in cells.
     */
    public function paneSizes(int $totalSize): array
    {
        $dividerSize = 1;
        $available = $totalSize - $dividerSize;

        if ($available <= 0) {
            return [0, 0];
        }

        $splitAt = $this->splitPosition($totalSize);

        $first = $splitAt;
        $second = $totalSize - $splitAt - $dividerSize;

        return [
            max(0, $first),
            max(0, $second),
        ];
    }

    /**
     * Render the split layout with a divider between panes.
     *
     * Each pane content is rendered at its calculated size. Content that
     * exceeds the pane boundary is truncated; content that is shorter
     * is not padded (the remaining space is implicit empty cells).
     *
     * The divider uses Box Drawing characters appropriate to the direction.
     */
    public function render(int $availableWidth, int $availableHeight): string
    {
        if ($this->direction->isHorizontal()) {
            return $this->renderHorizontal($availableWidth, $availableHeight);
        }

        return $this->renderVertical($availableWidth, $availableHeight);
    }

    /**
     * Render a horizontal (top/bottom) split.
     */
    private function renderHorizontal(int $width, int $height): string
    {
        [$topHeight, $bottomHeight] = $this->paneSizes($height);

        // Truncate each pane's content to its allocated height
        $topLines = $this->truncateToHeight($this->topOrLeftContent, $topHeight);
        $bottomLines = $this->truncateToHeight($this->bottomOrRightContent, $bottomHeight);

        // Build divider line
        $divider = str_repeat($this->direction->divider(), $width);

        // Compose: top pane, divider, bottom pane
        $output = $topLines;

        if ($topHeight > 0 && $bottomHeight > 0) {
            $output .= "\n" . $divider . "\n" . $bottomLines;
        } elseif ($bottomHeight > 0) {
            $output .= "\n" . $bottomLines;
        }

        return $output;
    }

    /**
     * Render a vertical (left/right) split.
     */
    private function renderVertical(int $width, int $height): string
    {
        [$leftWidth, $rightWidth] = $this->paneSizes($width);

        // Truncate each pane's content to its allocated width and height
        $leftContent = $this->truncateToWidthAndHeight($this->topOrLeftContent, $leftWidth, $height);
        $rightContent = $this->truncateToWidthAndHeight($this->bottomOrRightContent, $rightWidth, $height);

        // If either pane is zero-width, return just the non-empty one
        if ($leftWidth === 0) {
            return $rightContent;
        }
        if ($rightWidth === 0) {
            return $leftContent;
        }

        // Split each pane's content into lines
        $leftLines = explode("\n", $leftContent);
        $rightLines = explode("\n", $rightContent);

        $maxLines = max(count($leftLines), count($rightLines));
        $divider = $this->direction->divider();

        $outputLines = [];
        for ($i = 0; $i < $maxLines; $i++) {
            $leftLine = $leftLines[$i] ?? '';
            $rightLine = $rightLines[$i] ?? '';

            // Truncate each line to its pane width
            $leftLine = $this->truncateToWidth($leftLine, $leftWidth);
            $rightLine = $this->truncateToWidth($rightLine, $rightWidth);

            // Pad left line to full width, then add divider and right line.
            //
            // Width::padRight(), not str_pad(): the pad has to be counted in
            // the same unit the truncation above already counts in, and
            // str_pad() counts BYTES. Every multibyte character therefore ate
            // pad it did not occupy and dragged the divider left -- two
            // columns for "ee" spelled with acute accents (2 cells, 4 bytes),
            // one for a CJK glyph (2 cells, 3 bytes). Note that the accented
            // case is multibyte WITHOUT being wide, so reaching for
            // mb_strlen() here would fix it and leave the CJK case broken;
            // only a cell-width measure fixes both.
            //
            // The error was always UNDER-padding (bytes >= cells for UTF-8),
            // so no row was ever over-wide -- and padRight() is a no-op once
            // the line already meets the width, so widening the pad cannot
            // make one now. That matters: the diff renderer paints one line
            // per terminal row, so an over-wide row corrupts the frame.
            $outputLines[] = Width::padRight($leftLine, $leftWidth)
                . $divider
                . $rightLine;
        }

        return implode("\n", $outputLines);
    }

    /**
     * Truncate content to a maximum number of lines.
     */
    private function truncateToHeight(string $content, int $maxLines): string
    {
        if ($maxLines <= 0) {
            return '';
        }

        $lines = explode("\n", $content);
        $truncated = array_slice($lines, 0, $maxLines);

        return implode("\n", $truncated);
    }

    /**
     * Truncate a single line to a maximum width.
     */
    private function truncateToWidth(string $line, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        // Use visual width calculation for proper Unicode handling
        $visualWidth = $this->visualWidth($line);
        if ($visualWidth <= $maxWidth) {
            return $line;
        }

        // Work backwards from the end to leave room for ellipsis
        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = '';
        $w = 0;

        foreach ($chars as $char) {
            $charWidth = $this->charWidth($char);
            if ($w + $charWidth > $maxWidth - 1) { // leave room for "…"
                break;
            }
            $result .= $char;
            $w += $charWidth;
        }

        return $result . "\u{2026}";
    }

    /**
     * Truncate content to a max width AND height.
     */
    private function truncateToWidthAndHeight(string $content, int $maxWidth, int $maxHeight): string
    {
        if ($maxWidth <= 0 || $maxHeight <= 0) {
            return '';
        }

        $lines = explode("\n", $content);
        $truncated = array_slice($lines, 0, $maxHeight);

        $result = [];
        foreach ($truncated as $line) {
            $result[] = $this->truncateToWidth($line, $maxWidth);
        }

        return implode("\n", $result);
    }

    /**
     * Compute visual width of a string in terminal cells.
     *
     * Delegates to {@see Width::string()} rather than summing this class's own
     * table so that measuring, truncating and PADDING all answer to one
     * authority. They did not: the pad used to count bytes, and the two
     * surviving tables still disagreed on real codepoints -- this class scored
     * an emoji 1 where Width scores 2, and scored a combining accent 1 where
     * Width scores 0. A layout whose truncator and padder use different tables
     * cannot keep a column aligned no matter how careful either one is.
     *
     * Width::string() also strips ANSI before measuring, which this loop never
     * did. That fixes the fast path below. The slow path still walks raw
     * codepoints, so a line long enough to need truncating AND carrying SGR
     * can still be cut mid-sequence -- a known seam, left as-is because the
     * two call sites feed it unstyled pane text.
     */
    private function visualWidth(string $text): int
    {
        return Width::string($text);
    }

    /**
     * Visual width of a single codepoint: 0 for control, 1 for regular, 2 for wide.
     */
    private function charWidth(string $char): int
    {
        return Width::string($char);
    }
}
