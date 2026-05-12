<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\VAlign;
use SugarCraft\Core\Util\Width;

/**
 * A general-purpose boxing/wrapping component that adds padding around content.
 *
 * Unlike Frame which has fancy border characters, Boxer provides simpler
 * padding-based boxing (like CSS box model) with optional borders.
 *
 * Mirrors the box concept from bubbleboxer but adapted to PHP with
 * wither-style immutable setters.
 */
final class Boxer implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly Item $content,
        private readonly array $padding = [0, 0, 0, 0],
        private readonly bool $border = false,
        private readonly VAlign $verticalAlign = VAlign::Top,
    ) {}

    /**
     * Create a boxer with default settings (no padding, no border).
     */
    public static function new(Item $content): self
    {
        return new self(
            $content,
            [0, 0, 0, 0],
            false,
            VAlign::Top,
        );
    }

    /**
     * Set the allocated dimensions for this boxer.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the boxer with its content.
     */
    public function render(): string
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        // If no size allocated, just render content
        if ($w <= 0 || $h <= 0) {
            return $this->content->render();
        }

        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;

        // Content area dimensions (outside padding, outside any border)
        $contentW = $w - $paddingLeft - $paddingRight;
        $contentH = $h - $paddingTop - $paddingBottom;

        // If content area is zero or negative after padding, return raw content
        if ($contentW <= 0 || $contentH <= 0) {
            return $this->content->render();
        }

        // Build the styled content first
        $styledStyle = Style::new()
            ->padding($paddingTop, $paddingRight, $paddingBottom, $paddingLeft)
            ->width($contentW)
            ->height($contentH)
            ->verticalAlign($this->verticalAlign);

        $styledContent = $styledStyle->render($this->renderContent($contentW, $contentH));

        // If border is enabled, wrap with simple ASCII border
        if ($this->border) {
            $borderStyle = Style::new()
                ->borderTop(true)
                ->borderBottom(true)
                ->borderLeft(true)
                ->borderRight(true)
                ->width($w)
                ->height($h);
            return $borderStyle->render($styledContent);
        }

        return $styledContent;
    }

    /**
     * Calculate the inner area available for content after padding
     * is subtracted (but before any border).
     *
     * @return array{0:int,1:int}
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return [0, 0];
        }

        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;

        $innerW = $w - $paddingLeft - $paddingRight;
        $innerH = $h - $paddingTop - $paddingBottom;

        return [max(0, $innerW), max(0, $innerH)];
    }

    /**
     * Render the content at the given inner dimensions.
     */
    private function renderContent(int $innerW, int $innerH): string
    {
        if ($this->content instanceof Sizer) {
            $sized = $this->content->setSize($innerW, $innerH);
            return $sized->render();
        }

        $rendered = $this->content->render();
        $lines = explode("\n", $rendered);

        $adjusted = [];
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth > $innerW) {
                $line = $this->truncateToWidth($line, $innerW);
            } else {
                $line = $line . str_repeat(' ', $innerW - $lineWidth);
            }
            $adjusted[] = $line;
        }

        while (count($adjusted) < $innerH) {
            $adjusted[] = str_repeat(' ', $innerW);
        }

        return implode("\n", array_slice($adjusted, 0, $innerH));
    }

    private function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (Width::string($s) <= $width) {
            return $s;
        }
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (Width::string($candidate) <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return mb_substr($s, 0, max(1, $lo), 'UTF-8');
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set uniform padding on all sides.
     */
    public function withPadding(int $n): self
    {
        return new self(
            content: $this->content,
            padding: [$n, $n, $n, $n],
            border: $this->border,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set padding with separate vertical and horizontal values.
     */
    public function withPaddingXY(int $vertical, int $horizontal): self
    {
        return new self(
            content: $this->content,
            padding: [$vertical, $horizontal, $vertical, $horizontal],
            border: $this->border,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set padding for each side individually.
     *
     * @param array{int,int,int,int} $padding [top, right, bottom, left]
     */
    public function withPaddingArray(array $padding): self
    {
        return new self(
            content: $this->content,
            padding: $padding,
            border: $this->border,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Enable or disable the border.
     */
    public function withBorder(bool $border): self
    {
        return new self(
            content: $this->content,
            padding: $this->padding,
            border: $border,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the vertical alignment.
     */
    public function withVerticalAlign(VAlign $align): self
    {
        return new self(
            content: $this->content,
            padding: $this->padding,
            border: $this->border,
            verticalAlign: $align,
        );
    }
}
