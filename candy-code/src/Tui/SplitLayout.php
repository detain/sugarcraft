<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Layout;

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
     * sugar-style/src/Style.php, sugar-cellbuffer/src/Style.php) so every
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
        $divider = Layout::tile($this->direction->divider(), $width);

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

        // Width::padRight (inside the join) counts cells, never bytes: str_pad
        // ate pad it did not occupy for every multibyte glyph and dragged the
        // divider left. The left pane is padded to its ALLOCATED width first
        // so the divider column holds even when its content is narrower.
        $leftBlock = implode("\n", array_map(
            static fn (string $line): string => Width::padRight($line, $leftWidth),
            explode("\n", $leftContent),
        ));

        // Layout's divider-aware join (rider R13); the rational resize algebra
        // that chose the widths stays here. The library drops the rule beside
        // an EMPTY block (a collapsed pane leaves no orphan rule); a split
        // whose pane has width keeps its divider even with nothing in it, so
        // an empty right pane is joined to an explicit rule column instead.
        if ($rightContent === '') {
            $rule = implode("\n", array_fill(0, Layout::height($leftBlock), $this->direction->divider()));

            return Layout::joinHorizontal(0.0, $leftBlock, $rule);
        }

        return Layout::joinHorizontalWithDivider(0.0, $this->direction->divider(), $leftBlock, $rightContent);
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
     * Truncate a single line to a maximum width, in terminal CELLS.
     *
     * A line that overruns its pane keeps its first (maxWidth - 1) cells and
     * gains an ellipsis. The cut is delegated to the canonical {@see
     * Width::truncateAnsi()}: escape sequences pass through whole — a cut may
     * never split an SGR run — and the clip lands on a grapheme-cluster
     * boundary, so a joined emoji family is kept or dropped, never severed
     * mid-joiner. Styled rows already reach production splits (the band
     * arrives pre-clipped only by the caller's discipline), so this is the
     * seam that keeps a colour from bleeding across the divider.
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

        // Leave room for the ellipsis: the canonical ANSI-aware cluster
        // truncator cuts the first (maxWidth - 1) cells whole.
        return Width::truncateAnsi($line, $maxWidth - 1) . "\u{2026}";
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
     * did. That fixes the fast path below, and the slow path now delegates its
     * cut to the cluster-aware {@see Width::truncateAnsi()} — styled content
     * survives a truncation whole-sequence either way. The production band
     * arrives STYLED (pre-clipped upstream by its caller); the claim that its
     * call sites feed this class unstyled text was false to disk, and the
     * truncator no longer depends on that discipline to stay honest.
     */
    private function visualWidth(string $text): int
    {
        return Width::string($text);
    }
}
