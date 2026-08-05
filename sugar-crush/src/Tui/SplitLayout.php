<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

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

        return new self(
            $this->topOrLeftContent,
            $this->bottomOrRightContent,
            $this->direction,
            $numerator,
            $denominator,
        );
    }

    /**
     * Returns a new SplitLayout with updated content.
     */
    public function withContent(string $topOrLeft, string $bottomOrRight): self
    {
        return new self(
            $topOrLeft,
            $bottomOrRight,
            $this->direction,
            $this->numerator,
            $this->denominator,
        );
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

            // Pad left line to full width, then add divider and right line
            $outputLines[] = str_pad($leftLine, $leftWidth, ' ', STR_PAD_RIGHT)
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
     * Compute visual width of a string (wide / combining characters count as 2).
     */
    private function visualWidth(string $text): int
    {
        $width = 0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $width += $this->charWidth($char);
        }

        return $width;
    }

    /**
     * Visual width of a single codepoint: 0 for control, 1 for regular, 2 for wide.
     */
    private function charWidth(string $char): int
    {
        $code = mb_ord($char, 'UTF-8');
        if ($code === false || $code < 32) {
            return 0;
        }

        if (
            ($code >= 0x1100 && $code <= 0x115F)
            || ($code >= 0x2329 && $code <= 0x232A)
            || ($code >= 0x2E80 && $code <= 0x303E)
            || ($code >= 0x3040 && $code <= 0xA4CF)
            || ($code >= 0xAC00 && $code <= 0xD7A3)
            || ($code >= 0xF900 && $code <= 0xFAFF)
            || ($code >= 0xFE10 && $code <= 0xFE1F)
            || ($code >= 0xFE30 && $code <= 0xFE6F)
            || ($code >= 0xFF00 && $code <= 0xFFE5)
            || ($code >= 0x20000 && $code <= 0x2FFFD)
            || ($code >= 0x30000 && $code <= 0x3FFFD)
        ) {
            return 2;
        }

        return 1;
    }
}
