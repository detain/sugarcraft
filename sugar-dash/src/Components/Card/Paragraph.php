<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Layout\VAlign;

/**
 * A paragraph component with styled line spacing and margins.
 *
 * Builds on Text with:
 * - Leading (line spacing multiplier)
 * - Margin top/bottom
 * - Vertical alignment within allocated height
 *
 * Mirrors paragraph styling from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Paragraph implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Whether maxWidth was explicitly set (enables alignment).
     * When setSize is called without maxWidth, alignment is disabled.
     */
    private bool $maxWidthSet = false;

    public function __construct(
        private readonly string $text,
        private readonly ?int $maxWidth = null,
        private readonly bool $trim = true,
        private readonly HAlign $horizontalAlign = HAlign::Left,
        private readonly float $leading = 1.0,
        private readonly int $marginTop = 0,
        private readonly int $marginBottom = 0,
        private readonly VAlign $verticalAlign = VAlign::Top,
    ) {
        $this->maxWidthSet = $maxWidth !== null;
    }

    /**
     * Create a new paragraph with default styling.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            maxWidth: null,
            trim: true,
            horizontalAlign: HAlign::Left,
            leading: 1.0,
            marginTop: 0,
            marginBottom: 0,
            verticalAlign: VAlign::Top,
        );
    }

    /**
     * Set the allocated dimensions for this paragraph.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the paragraph with styling applied.
     */
    public function render(): string
    {
        $wrapWidth = $this->getWrapWidth();

        // When no width is set and maxWidth is not set, render raw text with styling
        if ($wrapWidth === -1 && $this->width === null && !$this->maxWidthSet) {
            $text = $this->trim ? $this->collapseWhitespace($this->text) : $this->text;
            $rawLines = $text !== '' ? explode("\n", $text) : [''];

            // Apply leading (add blank lines between content lines)
            if ($this->leading > 1.0 && count($rawLines) > 1) {
                $spaced = [];
                foreach ($rawLines as $index => $line) {
                    $spaced[] = $line;
                    if ($index < count($rawLines) - 1) {
                        $spacingLines = max(1, (int) floor($this->leading - 1.0));
                        for ($i = 0; $i < $spacingLines; $i++) {
                            $spaced[] = '';
                        }
                    }
                }
                $rawLines = $spaced;
            }

            // Apply margins
            $result = $rawLines;
            if ($this->marginTop > 0) {
                $result = array_merge(array_fill(0, $this->marginTop, ''), $result);
            }
            if ($this->marginBottom > 0) {
                $result = array_merge($result, array_fill(0, $this->marginBottom, ''));
            }

            return implode("\n", $result);
        }

        // Zero maxWidth means nothing to render
        if ($wrapWidth === 0) {
            return '';
        }

        // Process text content - trim if needed, split into lines
        $text = $this->trim ? $this->collapseWhitespace($this->text) : $this->text;
        $rawLines = $text !== '' ? explode("\n", $text) : [''];

        // If we have a width constraint, wrap the text
        if ($wrapWidth > 0) {
            $wrapped = [];
            foreach ($rawLines as $line) {
                if ($line === '') {
                    $wrapped[] = '';
                    continue;
                }
                $words = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                if ($words === false || $words === []) {
                    continue;
                }

                $currentLine = '';
                $currentWidth = 0;

                foreach ($words as $word) {
                    $wordWidth = Width::string($word);

                    if ($wordWidth > $wrapWidth) {
                        if ($currentLine !== '') {
                            $wrapped[] = $currentLine;
                            $currentLine = '';
                            $currentWidth = 0;
                        }
                        $wrapped = array_merge($wrapped, $this->wrapLongWord($word, $wrapWidth));
                        continue;
                    }

                    if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $wrapWidth) {
                        $wrapped[] = $currentLine;
                        $currentLine = $word;
                        $currentWidth = $wordWidth;
                    } else {
                        if ($currentLine !== '') {
                            $currentLine .= ' ';
                            $currentWidth++;
                        }
                        $currentLine .= $word;
                        $currentWidth += $wordWidth;
                    }
                }

                if ($currentLine !== '') {
                    $wrapped[] = $currentLine;
                }
            }
            $rawLines = $wrapped;
        }

        // Apply horizontal alignment to each line (only when maxWidth was explicitly set)
        $aligned = [];
        foreach ($rawLines as $line) {
            $aligned[] = ($wrapWidth > 0 && $this->maxWidthSet) ? $this->alignLine($line, $wrapWidth) : $line;
        }

        // Apply leading (add blank lines between content lines)
        if ($this->leading > 1.0 && count($aligned) > 1) {
            $spaced = [];
            foreach ($aligned as $index => $line) {
                $spaced[] = $line;
                // Add blank lines between content lines (not after last)
                if ($index < count($aligned) - 1) {
                    $spacingLines = max(1, (int) floor($this->leading - 1.0));
                    for ($i = 0; $i < $spacingLines; $i++) {
                        $spaced[] = '';
                    }
                }
            }
            $aligned = $spaced;
        }

        // Apply vertical alignment
        if ($this->height !== null && $this->height > count($aligned)) {
            $padding = $this->height - count($aligned);
            $aligned = $this->applyVerticalAlign($aligned, $padding);
        }

        // Apply margins
        $result = $aligned;
        if ($this->marginTop > 0) {
            $result = array_merge(
                array_fill(0, $this->marginTop, ''),
                $result
            );
        }
        if ($this->marginBottom > 0) {
            $result = array_merge(
                $result,
                array_fill(0, $this->marginBottom, '')
            );
        }

        return implode("\n", $result);
    }

    /**
     * Get the width to use for word-wrapping.
     * Returns -1 when no width is set (meaning render raw text without wrapping).
     */
    private function getWrapWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->maxWidth ?? -1;
    }

    /**
     * Calculate the natural dimensions of this paragraph when rendered.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $wrapWidth = $this->getWrapWidth();

        // -1 means no width constraint (use natural text width)
        if ($wrapWidth === -1) {
            $text = $this->trim ? $this->collapseWhitespace($this->text) : $this->text;
            $lines = $text !== '' ? explode("\n", $text) : [''];
            $maxWidth = 0;
            foreach ($lines as $line) {
                $w = Width::string($line);
                if ($w > $maxWidth) {
                    $maxWidth = $w;
                }
            }
            $contentHeight = count($lines);
            return [$maxWidth, $this->computeHeight($contentHeight)];
        }

        // 0 means nothing to render
        if ($wrapWidth === 0) {
            return [0, $this->marginTop + $this->marginBottom];
        }

        // Wrap text and compute dimensions
        $text = $this->trim ? $this->collapseWhitespace($this->text) : $this->text;
        $rawLines = $text !== '' ? explode("\n", $text) : [''];

        $wrapped = [];
        foreach ($rawLines as $line) {
            if ($line === '') {
                $wrapped[] = '';
                continue;
            }
            $words = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false || $words === []) {
                continue;
            }

            $currentLine = '';
            $currentWidth = 0;

            foreach ($words as $word) {
                $wordWidth = Width::string($word);

                if ($wordWidth > $wrapWidth) {
                    if ($currentLine !== '') {
                        $wrapped[] = $currentLine;
                        $currentLine = '';
                        $currentWidth = 0;
                    }
                    $wrapped = array_merge($wrapped, $this->wrapLongWord($word, $wrapWidth));
                    continue;
                }

                if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $wrapWidth) {
                    $wrapped[] = $currentLine;
                    $currentLine = $word;
                    $currentWidth = $wordWidth;
                } else {
                    if ($currentLine !== '') {
                        $currentLine .= ' ';
                        $currentWidth++;
                    }
                    $currentLine .= $word;
                    $currentWidth += $wordWidth;
                }
            }

            if ($currentLine !== '') {
                $wrapped[] = $currentLine;
            }
        }

        $contentHeight = count($wrapped);

        // Account for leading spacing
        if ($this->leading > 1.0 && $contentHeight > 1) {
            $spacingLines = max(1, (int) floor($this->leading - 1.0));
            $contentHeight = $contentHeight + (($contentHeight - 1) * $spacingLines);
        }

        // Account for margins
        $contentHeight += $this->marginTop + $this->marginBottom;

        return [$wrapWidth, $contentHeight];
    }

    /**
     * Compute total height including leading spacing.
     */
    private function computeHeight(int $contentLines): int
    {
        if ($contentLines <= 1) {
            return $contentLines + $this->marginTop + $this->marginBottom;
        }

        $spacingLines = max(1, (int) floor($this->leading - 1.0));
        $spacedHeight = $contentLines + (($contentLines - 1) * $spacingLines);

        return $spacedHeight + $this->marginTop + $this->marginBottom;
    }

    /**
     * Apply vertical alignment by adding padding to the content.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function applyVerticalAlign(array $lines, int $padding): array
    {
        if ($lines === []) {
            return array_fill(0, $padding, '');
        }

        return match ($this->verticalAlign) {
            VAlign::Top => array_merge($lines, array_fill(0, $padding, '')),
            VAlign::Bottom => array_merge(array_fill(0, $padding, ''), $lines),
            VAlign::Middle => $this->centerAlignVertical($lines, $padding),
        };
    }

    /**
     * Center-align content vertically within the height.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function centerAlignVertical(array $lines, int $padding): array
    {
        $top = (int) floor($padding / 2);
        $bottom = $padding - $top;
        return array_merge(
            array_fill(0, $top, ''),
            $lines,
            array_fill(0, $bottom, '')
        );
    }

    /**
     * Word-wrap text to fit within the given width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        $content = $this->trim ? $this->collapseWhitespace($text) : $text;

        if ($content === '') {
            return [''];
        }

        $result = [];
        $paragraphs = explode("\n\n", $content);

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $result[] = '';
                continue;
            }

            $words = preg_split('/\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
            if ($words === false || $words === []) {
                continue;
            }

            $currentLine = '';
            $currentWidth = 0;

            foreach ($words as $word) {
                $wordWidth = Width::string($word);

                if ($wordWidth > $width) {
                    if ($currentLine !== '') {
                        $result[] = $currentLine;
                        $currentLine = '';
                        $currentWidth = 0;
                    }
                    $result = array_merge($result, $this->wrapLongWord($word, $width));
                    continue;
                }

                if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                    $result[] = $currentLine;
                    $currentLine = $word;
                    $currentWidth = $wordWidth;
                } else {
                    if ($currentLine !== '') {
                        $currentLine .= ' ';
                        $currentWidth++;
                    }
                    $currentLine .= $word;
                    $currentWidth += $wordWidth;
                }
            }

            if ($currentLine !== '') {
                $result[] = $currentLine;
            }
        }

        return $result === [] ? [''] : $result;
    }

    /**
     * Wrap a single word that's longer than the line width.
     *
     * @return list<string>
     */
    private function wrapLongWord(string $word, int $width): array
    {
        $result = [];
        $len = mb_strlen($word, 'UTF-8');
        $pos = 0;

        while ($pos < $len) {
            $remaining = $len - $pos;
            $chunkLen = $remaining;

            $lo = 1;
            $hi = min($width, $remaining);
            while ($lo < $hi) {
                $mid = (int) (($lo + $hi + 1) / 2);
                $chunk = mb_substr($word, $pos, $mid, 'UTF-8');
                if (Width::string($chunk) <= $width) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }

            $chunk = mb_substr($word, $pos, $lo, 'UTF-8');
            $result[] = $chunk;
            $pos += $lo;
        }

        return $result;
    }

    /**
     * Collapse multiple whitespace characters to single spaces.
     */
    private function collapseWhitespace(string $text): string
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                $lines[] = '';
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $trimmed = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
                $lines[] = $trimmed;
            } else {
                $lines[] = '';
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Align a single line within the given width using horizontal alignment.
     * Note: HAlign::Left does NOT add trailing padding - it only ensures
     * content is left-aligned within each line without stretching to width.
     */
    private function alignLine(string $line, int $width): string
    {
        if ($line === '') {
            return '';
        }

        $lineWidth = Width::string($line);

        if ($lineWidth >= $width) {
            return $line;
        }

        $padding = $width - $lineWidth;

        return match ($this->horizontalAlign) {
            // Left alignment: no trailing padding, content stays left-justified
            HAlign::Left => $line,
            HAlign::Right => str_repeat(' ', $padding) . $line,
            HAlign::Center => $this->centerAlign($line, $lineWidth, $width),
        };
    }

    /**
     * Center-align a line within the given width.
     */
    private function centerAlign(string $line, int $lineWidth, int $width): string
    {
        $padding = $width - $lineWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set a maximum width for word-wrapping.
     */
    public function withMaxWidth(int $maxWidth): self
    {
        return new self(
            text: $this->text,
            maxWidth: $maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
            leading: $this->leading,
            marginTop: $this->marginTop,
            marginBottom: $this->marginBottom,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Enable or disable whitespace trimming.
     */
    public function withTrim(bool $trim): self
    {
        return new self(
            text: $this->text,
            maxWidth: $this->maxWidth,
            trim: $trim,
            horizontalAlign: $this->horizontalAlign,
            leading: $this->leading,
            marginTop: $this->marginTop,
            marginBottom: $this->marginBottom,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withHorizontalAlign(HAlign $align): self
    {
        return new self(
            text: $this->text,
            maxWidth: $this->maxWidth,
            trim: $this->trim,
            horizontalAlign: $align,
            leading: $this->leading,
            marginTop: $this->marginTop,
            marginBottom: $this->marginBottom,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the leading (line spacing multiplier).
     *
     * 1.0 = single spacing, 1.5 = 1.5x spacing, 2.0 = double spacing.
     */
    public function withLeading(float $leading): self
    {
        return new self(
            text: $this->text,
            maxWidth: $this->maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
            leading: max(1.0, $leading),
            marginTop: $this->marginTop,
            marginBottom: $this->marginBottom,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the top margin (number of blank lines before content).
     */
    public function withMarginTop(int $margin): self
    {
        return new self(
            text: $this->text,
            maxWidth: $this->maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
            leading: $this->leading,
            marginTop: max(0, $margin),
            marginBottom: $this->marginBottom,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the bottom margin (number of blank lines after content).
     */
    public function withMarginBottom(int $margin): self
    {
        return new self(
            text: $this->text,
            maxWidth: $this->maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
            leading: $this->leading,
            marginTop: $this->marginTop,
            marginBottom: max(0, $margin),
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the vertical alignment within allocated height.
     */
    public function withVerticalAlign(VAlign $align): self
    {
        return new self(
            text: $this->text,
            maxWidth: $this->maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
            leading: $this->leading,
            marginTop: $this->marginTop,
            marginBottom: $this->marginBottom,
            verticalAlign: $align,
        );
    }

    /**
     * Set new text content.
     */
    public function withText(string $text): self
    {
        return new self(
            text: $text,
            maxWidth: $this->maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
            leading: $this->leading,
            marginTop: $this->marginTop,
            marginBottom: $this->marginBottom,
            verticalAlign: $this->verticalAlign,
        );
    }
}
