<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\VAlign;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * A bordered frame that wraps any Item.
 *
 * Provides:
 * - Configurable border characters (rounded, normal, thick, double, block)
 * - Optional title displayed on the top border
 * - Per-side border color overrides
 * - Configurable padding inside the border
 * - Fluent setters for all properties
 *
 * Mirrors the frame concept from bubble-grid but adapted to PHP with
 * wither-style immutable setters.
 */
final class Frame implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly ?Border $border = null,
        private readonly ?Color $borderColor = null,
        private readonly array $padding = [1, 1, 1, 1],
        private readonly ?string $title = null,
        private readonly VAlign $verticalAlign = VAlign::Top,
    ) {}

    /**
     * Create a frame with the default rounded border and purple accent.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            $content,
            Border::rounded(),
            Color::hex('#874BFD'),
            [1, 1, 1, 1],
            null,
            VAlign::Top,
        );
    }

    /**
     * Set the allocated dimensions for this frame.
     *
     * @return $this
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the frame with its content.
     *
     * If width or height is not set, returns a simple render of the content.
     */
    public function render(): string
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return $this->content->render();
        }

        // Create border with title if needed (before building boxStyle)
        $border = ($this->border ?? Border::rounded());
        if ($this->title !== null) {
            $border = $border->withTitle($this->title);
        }
        $color = $this->borderColor ?? Color::hex('#874BFD');

        // Space inside borders (before padding)
        $borderOnlyW = $w - 2;
        $borderOnlyH = $h - 2;
        if ($borderOnlyW <= 0 || $borderOnlyH <= 0) {
            return $this->content->render();
        }

        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;

        // Content area dimensions (inside border AND inside padding)
        $contentW = $borderOnlyW - $paddingLeft - $paddingRight;
        $contentH = $borderOnlyH - $paddingTop - $paddingBottom;
        $contentW = max(0, $contentW);
        $contentH = max(0, $contentH);

        // If content area is zero or negative after padding, return raw content
        if ($contentW <= 0 || $contentH <= 0) {
            return $this->content->render();
        }

        // Build the styled content first
        $paddedStyle = Style::new()
            ->padding($paddingTop, $paddingRight, $paddingBottom, $paddingLeft)
            ->width($contentW)
            ->height($contentH)
            ->verticalAlign($this->verticalAlign);

        $styledContent = $paddedStyle->render($this->renderContent($contentW, $contentH));

        // Wrap in a bordered box
        $boxStyle = Style::new()
            ->border($border)
            ->borderForeground($color)
            ->width($w)
            ->height($h);

        return $boxStyle->render($styledContent);
    }

    /**
     * Calculate the inner area available for content after borders
     * are subtracted (before padding).
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

        // Only subtract the frame border characters (2 cells each axis)
        return [max(0, $w - 2), max(0, $h - 2)];
    }

    /**
     * Render the content at the given inner dimensions.
     */
    private function renderContent(int $innerW, int $innerH): string
    {
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
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

    public function withBorder(Border $border): self
    {
        return new self(
            content: $this->content,
            border: $border,
            borderColor: $this->borderColor,
            padding: $this->padding,
            title: $this->title,
            verticalAlign: $this->verticalAlign,
        );
    }

    public function withBorderColor(Color $color): self
    {
        return new self(
            content: $this->content,
            border: $this->border,
            borderColor: $color,
            padding: $this->padding,
            title: $this->title,
            verticalAlign: $this->verticalAlign,
        );
    }

    public function withPadding(int $n): self
    {
        return new self(
            content: $this->content,
            border: $this->border,
            borderColor: $this->borderColor,
            padding: [$n, $n, $n, $n],
            title: $this->title,
            verticalAlign: $this->verticalAlign,
        );
    }

    public function withPaddingXY(int $vertical, int $horizontal): self
    {
        return new self(
            content: $this->content,
            border: $this->border,
            borderColor: $this->borderColor,
            padding: [$vertical, $horizontal, $vertical, $horizontal],
            title: $this->title,
            verticalAlign: $this->verticalAlign,
        );
    }

    public function withTitle(string $title): self
    {
        return new self(
            content: $this->content,
            border: $this->border,
            borderColor: $this->borderColor,
            padding: $this->padding,
            title: $title,
            verticalAlign: $this->verticalAlign,
        );
    }

    public function withVerticalAlign(VAlign $align): self
    {
        return new self(
            content: $this->content,
            border: $this->border,
            borderColor: $this->borderColor,
            padding: $this->padding,
            title: $this->title,
            verticalAlign: $align,
        );
    }
}
