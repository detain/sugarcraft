<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Canvas;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\SizedItem;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A 2D pixel drawing canvas.
 *
 * Features:
 * - Draw pixels, lines, rectangles, and circles
 * - Fill regions with colors
 * - Clear with optional background
 * - ASCII art text rendering
 * - Layer-based drawing (painter's algorithm)
 *
 * Mirrors canvas drawing concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Canvas implements SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @var list<list<string|null>> null = transparent, string = character
     */
    private array $pixels;

    /**
     * @var list<list<string|null>> null = transparent, string = hex color
     */
    private array $fgColors;

    /**
     * @var list<list<string|null>>
     */
    private array $bgColors;

    public function __construct(
        private readonly int $widthConstraint = 40,
        private readonly int $heightConstraint = 20,
        private readonly ?Color $defaultFg = null,
        private readonly ?Color $defaultBg = null,
    ) {
        $this->pixels = $this->createLayer();
        $this->fgColors = $this->createColorLayer();
        $this->bgColors = $this->createColorLayer();
    }

    /**
     * Create a new canvas with default settings.
     */
    public static function new(int $width = 40, int $height = 20): self
    {
        return new self(
            widthConstraint: $width,
            heightConstraint: $height,
            defaultFg: Color::hex('#F9FAFB'),
            defaultBg: null,
        );
    }

    /**
     * Create an empty pixel layer.
     *
     * @return list<list<string|null>>
     */
    private function createLayer(): array
    {
        $layer = [];
        for ($y = 0; $y < $this->heightConstraint; $y++) {
            $layer[] = array_fill(0, $this->widthConstraint, null);
        }
        return $layer;
    }

    /**
     * Create an empty color layer.
     *
     * @return list<list<string|null>>
     */
    private function createColorLayer(): array
    {
        $layer = [];
        for ($y = 0; $y < $this->heightConstraint; $y++) {
            $layer[] = array_fill(0, $this->widthConstraint, null);
        }
        return $layer;
    }

    /**
     * Set the allocated dimensions for this canvas.
     *
     * Finding sugar-dashboard-079 (D-84): the allocation is now READ -
     * render() clips/pads to exactly this box and innerSize() reports it
     * (pixels in, pixels out). Unset, the natural constraints rule.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set a single pixel.
     *
     * Finding sugar-dashboard-078: the old body deep-copied ALL THREE grids
     * per pixel (O(area) work per dot). The clone is shallow now; the three
     * nested writes touch only the one row per grid that changes (copy-on-
     * write). Same values, same immutable facade, O(width+height) per write.
     */
    public function setPixel(int $x, int $y, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        if ($x < 0 || $y < 0 || $x >= $this->widthConstraint || $y >= $this->heightConstraint) {
            return $this;
        }

        $clone = clone $this;

        $clone->pixels[$y][$x] = $char;
        $clone->fgColors[$y][$x] = $fg?->toHex();
        $clone->bgColors[$y][$x] = $bg?->toHex();

        return $clone;
    }

    /**
     * Get a pixel character at the given coordinates.
     */
    public function pixel(int $x, int $y): ?string
    {
        if ($x < 0 || $y < 0 || $x >= $this->widthConstraint || $y >= $this->heightConstraint) {
            return null;
        }
        return $this->pixels[$y][$x];
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::pixel()} instead. REMOVED-IN: 2.0.0.
     */
    public function getPixel(int $x, int $y): ?string { return $this->pixel($x, $y); }

    /**
     * Draw a horizontal line.
     */
    public function drawLine(int $x1, int $y, int $x2, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::drawHLine(
            $x1, $y, $x2, $char, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Draw a vertical line.
     */
    public function drawVLine(int $x, int $y1, int $y2, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::drawVLine(
            $x, $y1, $y2, $char, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Draw a rectangle outline.
     */
    public function drawRect(int $x, int $y, int $w, int $h, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::drawRect(
            $x, $y, $w, $h, $char, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Fill a rectangle.
     */
    public function fillRect(int $x, int $y, int $w, int $h, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::fillRect(
            $x, $y, $w, $h, $char, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Draw a circle outline using the midpoint circle algorithm.
     */
    public function drawCircle(int $cx, int $cy, int $radius, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::drawCircle(
            $cx, $cy, $radius, $char, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Fill a circle.
     */
    public function fillCircle(int $cx, int $cy, int $radius, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::fillCircle(
            $cx, $cy, $radius, $char, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Draw ASCII text at the given position.
     */
    public function drawText(int $x, int $y, string $text, ?Color $fg = null, ?Color $bg = null): self
    {
        return DrawingOps::drawText(
            $x, $y, $text, $fg, $bg,
            $this,
            $this->widthConstraint,
            $this->heightConstraint,
        );
    }

    /**
     * Clear the canvas.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->pixels = $this->createLayer();
        $clone->fgColors = $this->createColorLayer();
        $clone->bgColors = $this->createColorLayer();
        return $clone;
    }

    /**
     * Render the canvas as a string.
     *
     * Finding sugar-dashboard-079 (D-84): the painter now reads its
     * allocation. Output is clipped/padded to the setSize width x height
     * (natural constraints when unset), defaultFg/defaultBg decorate
     * non-blank glyph cells only (explicit per-pixel colors win), and a
     * corrupt stored hex degrades honestly - the cell still paints, the
     * old background catch no longer adds a phantom extra space column
     * that broke the row-width contract (CALIBER no-swallow law).
     */
    public function render(): string
    {
        $useW = $this->width ?? $this->widthConstraint;
        $useH = $this->height ?? $this->heightConstraint;
        $output = '';

        for ($y = 0; $y < $useH; $y++) {
            for ($x = 0; $x < $useW; $x++) {
                if ($y >= $this->heightConstraint || $x >= $this->widthConstraint) {
                    $output .= ' '; // padding beyond the grid
                    continue;
                }

                $pixel = $this->pixels[$y][$x];
                $fgHex = $this->fgColors[$y][$x];
                $bgHex = $this->bgColors[$y][$x];

                if ($pixel === null && $fgHex === null && $bgHex === null) {
                    $output .= ' ';
                    continue;
                }

                $char = $pixel ?? ' ';

                $bgColor = null;
                if ($bgHex !== null) {
                    try {
                        $bgColor = Color::hex($bgHex);
                    } catch (\Throwable) {
                        // Finding 079 documented degradation: the corrupt
                        // background is skipped, the cell still paints -
                        // never a swallowed extra column.
                        $bgColor = $pixel !== null ? $this->defaultBg : null;
                    }
                } elseif ($pixel !== null && $this->defaultBg !== null) {
                    $bgColor = $this->defaultBg;
                }
                if ($bgColor !== null) {
                    $output .= $bgColor->toBg(ColorMode::profile());
                }

                $fgColor = null;
                if ($fgHex !== null) {
                    try {
                        $fgColor = Color::hex($fgHex);
                    } catch (\Throwable) {
                        // Corrupt foreground degrades to the default (or
                        // nothing) - the cell still paints, honest swallow.
                        $fgColor = $pixel !== null ? $this->defaultFg : null;
                    }
                } elseif ($pixel !== null && $this->defaultFg !== null) {
                    $fgColor = $this->defaultFg;
                }
                if ($fgColor !== null) {
                    $output .= $fgColor->toFg(ColorMode::profile());
                }

                $output .= $char;

                if ($fgColor !== null || $bgColor !== null) {
                    $output .= Ansi::reset();
                }
            }

            if ($y < $useH - 1) {
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Calculate the dimensions this canvas paints.
     *
     * Finding sugar-dashboard-079 (D-84): the setSize allocation is
     * reported when set - render() clips/pads to exactly this box, so
     * stacking parents no longer measure a canvas they will never see.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        return [$this->width ?? $this->widthConstraint, $this->height ?? $this->heightConstraint];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set a new width.
     */
    public function withWidth(int $width): self
    {
        $next = new self(
            widthConstraint: $width,
            heightConstraint: $this->heightConstraint,
            defaultFg: $this->defaultFg,
            defaultBg: $this->defaultBg,
        );
        // Finding 079 (D-84): carry the setSize allocation - the D-56/D-77
        // allocation-drop class, screened here per the D-98 wither doctrine.
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set a new height.
     */
    public function withHeight(int $height): self
    {
        $next = new self(
            widthConstraint: $this->widthConstraint,
            heightConstraint: $height,
            defaultFg: $this->defaultFg,
            defaultBg: $this->defaultBg,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the default foreground color.
     */
    public function withDefaultFg(?Color $color): self
    {
        $next = new self(
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            defaultFg: $color,
            defaultBg: $this->defaultBg,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the default background color.
     */
    public function withDefaultBg(?Color $color): self
    {
        $next = new self(
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            defaultFg: $this->defaultFg,
            defaultBg: $color,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }
}
