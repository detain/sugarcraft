<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

use SugarCraft\Style\Style;
use SugarCraft\Style\VAlign;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Output\Truncate;

/**
 * A general-purpose boxing/wrapping component that adds padding around content.
 *
 * Unlike Frame which has fancy border characters, Pad provides simpler
 * padding-based boxing (like CSS box model) with optional borders.
 *
 * Mirrors the box concept from bubbleboxer but adapted to PHP with
 * wither-style immutable setters.
 */
final class Pad implements \SugarCraft\Dashboard\Foundation\SizedItem, \SugarCraft\Dashboard\Foundation\Measurable
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dashboard\Foundation\Item $content,
        private readonly array $padding = [0, 0, 0, 0],
        private readonly bool $border = false,
        private readonly VAlign $verticalAlign = VAlign::Top,
    ) {}

    /**
     * Create a pad with default settings (no padding, no border).
     */
    public static function new(\SugarCraft\Dashboard\Foundation\Item $content): self
    {
        return new self(
            $content,
            [0, 0, 0, 0],
            false,
            VAlign::Top,
        );
    }

    /**
     * Set the allocated dimensions for this pad.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the pad with its content.
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

        // A ring that eats the box: yield padding before yielding the cell
        // (finding sugar-dashboard-032, Pad half). The old leg handed the
        // raw content back and threw the whole pad away; sugar-style's
        // Style blanks content outright when padding leaves it no room, so
        // the degenerate allocation assembles its (clamped) padding ring by
        // hand — the content keeps at least one live cell inside the
        // allocation and the box keeps its shape.
        if ($contentW <= 0 || $contentH <= 0) {
            return $this->renderDegenerate($w, $h);
        }

        // Build the styled content first
        $styledStyle = Style::new()
            ->padding($paddingTop, $paddingRight, $paddingBottom, $paddingLeft)
            ->width($contentW)
            // Style::height is the OUTER row bound with padding eating rows
            // inward; the bare content height silently lost the ring's rows
            // (see Frame::render, finding sugar-dashboard-032 family).
            ->height($contentH + $paddingTop + $paddingBottom)
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
    public function innerSize(): array
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
     * Chrome-inclusive natural extent (Measurable, finding 032): the
     * padding ring (and the one-cell border ring when border is on) on top
     * of the content's own allocation-free render.
     *
     * @return array{0:int,1:int}
     */
    public function naturalExtent(int $width): array
    {
        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;
        $lines = explode("\n", $this->content->render());

        $naturalWidth = 0;
        foreach ($lines as $line) {
            $naturalWidth = max($naturalWidth, Width::string($line));
        }

        $chromeX = $paddingLeft + $paddingRight + ($this->border ? 2 : 0);
        $chromeY = $paddingTop + $paddingBottom + ($this->border ? 2 : 0);

        return [
            $width > 0 ? $width : max(1, $naturalWidth + $chromeX),
            max(1, count($lines) + $chromeY),
        ];
    }

    /**
     * Assemble a clamped-padding ring by hand for an allocation the
     * configured ring cannot fit (finding sugar-dashboard-032). Each axis
     * keeps at least one live content cell; the slack comes off the larger
     * side first. Content beyond the surviving cell truncates through the
     * same renderContent authority the roomy path uses.
     */
    private function renderDegenerate(int $w, int $h): string
    {
        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;

        $halfW = intdiv(max(0, $w - 1), 2);
        $paddingLeft = min($paddingLeft, $halfW);
        $paddingRight = min($paddingRight, max(0, $w - 1 - $paddingLeft));

        $halfH = intdiv(max(0, $h - 1), 2);
        $paddingTop = min($paddingTop, $halfH);
        $paddingBottom = min($paddingBottom, max(0, $h - 1 - $paddingTop));

        $contentW = $w - $paddingLeft - $paddingRight;
        $contentH = $h - $paddingTop - $paddingBottom;

        $inner = $this->renderContent($contentW, $contentH);
        $lines = explode("\n", $inner);

        $out = [];
        for ($i = 0; $i < $paddingTop; $i++) {
            $out[] = str_repeat(' ', $w);
        }
        foreach (array_slice($lines, 0, $contentH) as $line) {
            $line = Truncate::truncate($line, $contentW, '');
            $out[] = str_repeat(' ', $paddingLeft) . $line . str_repeat(' ', $contentW - Width::string($line) + $paddingRight);
        }
        for ($i = count($out); $i < $paddingTop + $contentH + $paddingBottom; $i++) {
            $out[] = str_repeat(' ', $w);
        }

        return implode("\n", $out);
    }

    /**
     * Render the content at the given inner dimensions.
     */
    private function renderContent(int $innerW, int $innerH): string
    {
        if ($this->content instanceof \SugarCraft\Dashboard\Foundation\Sizer) {
            $sized = $this->content->setSize($innerW, $innerH);
            return $sized->render();
        }

        $rendered = $this->content->render();
        $lines = explode("\n", $rendered);

        $adjusted = [];
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth > $innerW) {
                $line = Truncate::truncate($line, $innerW, '');
            } else {
                $line = $line . str_repeat(' ', $innerW - $lineWidth);
            }
            $adjusted[] = $line;
        }

        $adjustedCount = count($adjusted);
        while ($adjustedCount < $innerH) {
            $adjusted[] = str_repeat(' ', $innerW);
            $adjustedCount++;
        }

        return implode("\n", array_slice($adjusted, 0, $innerH));
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
