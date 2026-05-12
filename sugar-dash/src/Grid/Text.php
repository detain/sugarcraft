<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Width;

/**
 * Word-wrapped text content component.
 *
 * Handles:
 * - Word-wrap text to a given width (via setSize or maxWidth)
 * - Horizontal alignment within the allocated width
 * - Whitespace trimming and collapsing
 * - Height computation based on wrapped content
 *
 * Mirrors the text rendering from bubble-tea/lipgloss but adapted to PHP
 * with wither-style immutable setters.
 */
final class Text implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $text,
        private readonly ?int $maxWidth = null,
        private readonly bool $trim = true,
        private readonly HAlign $horizontalAlign = HAlign::Left,
    ) {}

    /**
     * Create a new text component.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            maxWidth: null,
            trim: true,
            horizontalAlign: HAlign::Left,
        );
    }

    /**
     * Set the allocated dimensions for this text.
     *
     * If width is provided via setSize, text will be word-wrapped to fit.
     * If no width is set, maxWidth from constructor is used if available.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the text, word-wrapped to the allocated width.
     */
    public function render(): string
    {
        $wrapWidth = $this->getWrapWidth();

        if ($wrapWidth <= 0) {
            return $this->text;
        }

        $lines = $this->wrapText($this->text, $wrapWidth);

        // Apply horizontal alignment to each line
        $aligned = [];
        foreach ($lines as $line) {
            $aligned[] = $this->alignLine($line, $wrapWidth);
        }

        return implode("\n", $aligned);
    }

    /**
     * Get the width to use for word-wrapping.
     */
    private function getWrapWidth(): int
    {
        // Priority: explicit setSize width > constructor maxWidth
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->maxWidth ?? 0;
    }

    /**
     * Calculate the natural dimensions of this text when rendered.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $wrapWidth = $this->getWrapWidth();

        if ($wrapWidth <= 0) {
            // No width constraint - use longest line
            $lines = explode("\n", $this->text);
            $maxWidth = 0;
            foreach ($lines as $line) {
                $w = Width::string($this->trim ? trim($line) : $line);
                if ($w > $maxWidth) {
                    $maxWidth = $w;
                }
            }
            return [$maxWidth, count($lines)];
        }

        $wrapped = $this->wrapText($this->text, $wrapWidth);
        return [$wrapWidth, count($wrapped)];
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

        // Normalize whitespace if trim is enabled
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

                // If a single word is wider than the width, handle it specially
                if ($wordWidth > $width) {
                    // If we have current content, flush it first
                    if ($currentLine !== '') {
                        $result[] = $currentLine;
                        $currentLine = '';
                        $currentWidth = 0;
                    }
                    // Break the word across lines
                    $result = array_merge($result, $this->wrapLongWord($word, $width));
                    continue;
                }

                // Would adding this word exceed the width?
                if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                    // Start a new line
                    $result[] = $currentLine;
                    $currentLine = $word;
                    $currentWidth = $wordWidth;
                } else {
                    // Add to current line
                    if ($currentLine !== '') {
                        $currentLine .= ' ';
                        $currentWidth++;
                    }
                    $currentLine .= $word;
                    $currentWidth += $wordWidth;
                }
            }

            // Don't forget the last line
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
            // Find how many characters fit
            $remaining = $len - $pos;
            $chunkLen = $remaining;

            // Binary search for max chars that fit
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
            // First check if line is empty (preserve empty lines for paragraph breaks)
            if ($line === '') {
                $lines[] = '';
                continue;
            }
            // Trim each line and collapse internal spaces
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $trimmed = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
                $lines[] = $trimmed;
            } else {
                // Whitespace-only line becomes empty to preserve paragraph structure
                $lines[] = '';
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Align a single line within the given width using horizontal alignment.
     */
    private function alignLine(string $line, int $width): string
    {
        // Empty lines stay empty (paragraph breaks)
        if ($line === '') {
            return '';
        }

        $lineWidth = Width::string($line);

        if ($lineWidth >= $width) {
            return $line;
        }

        $padding = $width - $lineWidth;

        return match ($this->horizontalAlign) {
            HAlign::Left => $line . str_repeat(' ', $padding),
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
        $right = $padding - $left; // Extra space goes to right

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set a maximum width for word-wrapping.
     *
     * This is used when the text is not placed in a Sizer container.
     */
    public function withMaxWidth(int $maxWidth): self
    {
        return new self(
            text: $this->text,
            maxWidth: $maxWidth,
            trim: $this->trim,
            horizontalAlign: $this->horizontalAlign,
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
        );
    }
}
