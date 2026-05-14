<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Layout\VAlign;

/**
 * A full coverage layout component.
 *
 * Fills the entire allocated space with its content, stretching
 * or compressing the content to fit exactly. Unlike other layouts
 * that respect content's natural size, Cover forces content to
 * completely fill the allocated dimensions.
 *
 * Supports alignment for when content aspect ratio differs from
 * the allocated area.
 *
 * Mirrors cover layout concepts adapted to PHP with wither-style immutable setters.
 */
final class Cover implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly HAlign $horizontalAlign = HAlign::Center,
        private readonly VAlign $verticalAlign = VAlign::Middle,
    ) {}

    /**
     * Create a new cover layout with the given content.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            horizontalAlign: HAlign::Center,
            verticalAlign: VAlign::Middle,
        );
    }

    /**
     * Create a cover layout aligned to the top-left corner.
     */
    public static function topLeft(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            horizontalAlign: HAlign::Left,
            verticalAlign: VAlign::Top,
        );
    }

    /**
     * Create a cover layout aligned to the top-right corner.
     */
    public static function topRight(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            horizontalAlign: HAlign::Right,
            verticalAlign: VAlign::Top,
        );
    }

    /**
     * Create a cover layout aligned to the bottom-left corner.
     */
    public static function bottomLeft(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            horizontalAlign: HAlign::Left,
            verticalAlign: VAlign::Bottom,
        );
    }

    /**
     * Create a cover layout aligned to the bottom-right corner.
     */
    public static function bottomRight(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            horizontalAlign: HAlign::Right,
            verticalAlign: VAlign::Bottom,
        );
    }

    /**
     * Set the allocated dimensions for this cover layout.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the cover layout - content fills the entire space.
     */
    public function render(): string
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        // If no size set, return content at natural size
        if ($w <= 0 || $h <= 0) {
            return $this->content->render();
        }

        // Set content to fill the allocated size
        $sizedContent = $this->content;
        if ($sizedContent instanceof \SugarCraft\Dash\Foundation\Sizer) {
            $sizedContent = $sizedContent->setSize($w, $h);
        }

        $rendered = $sizedContent->render();
        $contentLines = explode("\n", $rendered);

        // Build output grid that fills exactly w x h
        $lines = [];
        for ($y = 0; $y < $h; $y++) {
            $contentLineIndex = $y;

            if ($contentLineIndex < count($contentLines)) {
                $line = $contentLines[$contentLineIndex];
                $lineWidth = Width::string($line);

                // Handle horizontal alignment and truncation/padding
                if ($lineWidth < $w) {
                    $padding = $w - $lineWidth;
                    $line = match ($this->horizontalAlign) {
                        HAlign::Left => $line . str_repeat(' ', $padding),
                        HAlign::Right => str_repeat(' ', $padding) . $line,
                        HAlign::Center => $this->centerAlign($line, $lineWidth, $w),
                    };
                } elseif ($lineWidth > $w) {
                    $line = $this->truncateToWidth($line, $w);
                }
            } else {
                $line = str_repeat(' ', $w);
            }

            $lines[$y] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Center-align a string within a width.
     */
    private function centerAlign(string $line, int $lineWidth, int $totalWidth): string
    {
        $padding = $totalWidth - $lineWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    /**
     * Truncate a string to fit within the given width.
     */
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
        if ($lo === 0) {
            return '';
        }
        return mb_substr($s, 0, $lo, 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this layout.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w > 0 && $h > 0) {
            return [$w, $h];
        }

        // Return content's natural size
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
            return $this->content->getInnerSize();
        }

        $rendered = $this->content->render();
        $lines = explode("\n", $rendered);
        $width = 0;
        foreach ($lines as $line) {
            $width = max($width, Width::string($line));
        }

        return [$width, count($lines)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the horizontal alignment.
     */
    public function withAlignment(HAlign $horizontalAlign): self
    {
        return new self(
            content: $this->content,
            horizontalAlign: $horizontalAlign,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the vertical alignment.
     */
    public function withVerticalAlign(VAlign $verticalAlign): self
    {
        return new self(
            content: $this->content,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $verticalAlign,
        );
    }

    /**
     * Set the content.
     */
    public function withContent(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            horizontalAlign: $this->horizontalAlign,
            verticalAlign: $this->verticalAlign,
        );
    }
}
