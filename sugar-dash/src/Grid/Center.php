<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Width;

/**
 * A centered content layout component.
 *
 * Centers its content both horizontally and vertically within
 * the allocated space. If no size is set, renders content at
 * its natural dimensions.
 *
 * Mirrors center layout concepts adapted to PHP with wither-style immutable setters.
 */
final class Center implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly Item $content,
        private readonly int $minWidth = 0,
        private readonly int $minHeight = 0,
    ) {}

    /**
     * Create a new centered layout with the given content.
     */
    public static function new(Item $content): self
    {
        return new self(
            content: $content,
            minWidth: 0,
            minHeight: 0,
        );
    }

    /**
     * Create a new centered layout with minimum dimensions.
     */
    public static function withMin(Item $content, int $minWidth, int $minHeight): self
    {
        return new self(
            content: $content,
            minWidth: max(0, $minWidth),
            minHeight: max(0, $minHeight),
        );
    }

    /**
     * Set the allocated dimensions for this center layout.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the centered content.
     */
    public function render(): string
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        // If no size set, return content at natural size
        if ($w <= 0 || $h <= 0) {
            return $this->content->render();
        }

        // Measure content
        $contentSize = $this->measureContent();
        $contentWidth = $contentSize['width'];
        $contentHeight = $contentSize['height'];

        // Calculate centering offsets
        $offsetX = (int) floor(max(0, $w - $contentWidth) / 2);
        $offsetY = (int) floor(max(0, $h - $contentHeight) / 2);

        // Render content at allocated size
        $sizedContent = $this->content;
        if ($sizedContent instanceof Sizer) {
            $sizedContent = $sizedContent->setSize($contentWidth, $contentHeight);
        }
        $rendered = $sizedContent->render();
        $contentLines = explode("\n", $rendered);

        // Build output grid
        $lines = [];
        for ($y = 0; $y < $h; $y++) {
            $line = str_repeat(' ', $w);

            // Check if this line has content
            $contentLineIndex = $y - $offsetY;
            if ($contentLineIndex >= 0 && $contentLineIndex < count($contentLines)) {
                $contentLine = $contentLines[$contentLineIndex];
                $contentLineWidth = Width::string($contentLine);

                // Center the content line horizontally
                $contentOffsetX = (int) floor(max(0, $w - $contentLineWidth) / 2);

                // Truncate if needed
                if ($contentLineWidth > $w) {
                    $contentLine = $this->truncateToWidth($contentLine, $w);
                    $contentLineWidth = $w;
                    $contentOffsetX = 0;
                }

                // Place content in line
                $line = str_repeat(' ', $contentOffsetX)
                    . $contentLine
                    . str_repeat(' ', max(0, $w - $contentOffsetX - $contentLineWidth));
            }

            $lines[$y] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Measure content to get its natural dimensions.
     *
     * @return array{width:int,height:int}
     */
    private function measureContent(): array
    {
        if ($this->content instanceof Sizer) {
            [$w, $h] = $this->content->getInnerSize();
            if ($w > 0 && $h > 0) {
                return ['width' => $w, 'height' => $h];
            }
        }

        $rendered = $this->content->render();
        $lines = explode("\n", $rendered);
        $width = 0;
        foreach ($lines as $line) {
            $width = max($width, Width::string($line));
        }

        return [
            'width' => max($this->minWidth, $width),
            'height' => max($this->minHeight, count($lines)),
        ];
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

        $size = $this->measureContent();
        return [$size['width'], $size['height']];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the minimum width.
     */
    public function withMinWidth(int $minWidth): self
    {
        return new self(
            content: $this->content,
            minWidth: max(0, $minWidth),
            minHeight: $this->minHeight,
        );
    }

    /**
     * Set the minimum height.
     */
    public function withMinHeight(int $minHeight): self
    {
        return new self(
            content: $this->content,
            minWidth: $this->minWidth,
            minHeight: max(0, $minHeight),
        );
    }

    /**
     * Set the content.
     */
    public function withContent(Item $content): self
    {
        return new self(
            content: $content,
            minWidth: $this->minWidth,
            minHeight: $this->minHeight,
        );
    }
}
