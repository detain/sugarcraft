<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Point in 2D space.
 */
final readonly class CanvasPoint
{
    public function __construct(
        public int $x,
        public int $y,
    ) {}
}

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
final class Canvas implements Sizer
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
     */
    public function setPixel(int $x, int $y, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        if ($x < 0 || $y < 0 || $x >= $this->widthConstraint || $y >= $this->heightConstraint) {
            return $this;
        }

        $clone = clone $this;
        $clone->pixels = array_map(fn(array $row) => [...$row], $this->pixels);
        $clone->fgColors = array_map(fn(array $row) => [...$row], $this->fgColors);
        $clone->bgColors = array_map(fn(array $row) => [...$row], $this->bgColors);

        $clone->pixels[$y][$x] = $char;
        $clone->fgColors[$y][$x] = $fg?->toHex();
        $clone->bgColors[$y][$x] = $bg?->toHex();

        return $clone;
    }

    /**
     * Get a pixel character at the given coordinates.
     */
    public function getPixel(int $x, int $y): ?string
    {
        if ($x < 0 || $y < 0 || $x >= $this->widthConstraint || $y >= $this->heightConstraint) {
            return null;
        }
        return $this->pixels[$y][$x];
    }

    /**
     * Draw a horizontal line.
     */
    public function drawLine(int $x1, int $y, int $x2, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        $canvas = $this;
        $minX = min($x1, $x2);
        $maxX = max($x1, $x2);

        for ($x = $minX; $x <= $maxX; $x++) {
            $canvas = $canvas->setPixel($x, $y, $char, $fg, $bg);
        }

        return $canvas;
    }

    /**
     * Draw a vertical line.
     */
    public function drawVLine(int $x, int $y1, int $y2, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        $canvas = $this;
        $minY = min($y1, $y2);
        $maxY = max($y1, $y2);

        for ($y = $minY; $y <= $maxY; $y++) {
            $canvas = $canvas->setPixel($x, $y, $char, $fg, $bg);
        }

        return $canvas;
    }

    /**
     * Draw a rectangle outline.
     */
    public function drawRect(int $x, int $y, int $w, int $h, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        $canvas = $this;

        // Top and bottom
        $canvas = $canvas->drawLine($x, $y, $x + $w - 1, $char, $fg, $bg);
        $canvas = $canvas->drawLine($x, $y + $h - 1, $x + $w - 1, $char, $fg, $bg);

        // Left and right (avoiding corners)
        $canvas = $canvas->drawVLine($x, $y + 1, $y + $h - 2, $char, $fg, $bg);
        $canvas = $canvas->drawVLine($x + $w - 1, $y + 1, $y + $h - 2, $char, $fg, $bg);

        return $canvas;
    }

    /**
     * Fill a rectangle.
     */
    public function fillRect(int $x, int $y, int $w, int $h, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        $canvas = $this;

        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $w; $dx++) {
                $canvas = $canvas->setPixel($x + $dx, $y + $dy, $char, $fg, $bg);
            }
        }

        return $canvas;
    }

    /**
     * Draw a circle outline using the midpoint circle algorithm.
     */
    public function drawCircle(int $cx, int $cy, int $radius, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        if ($radius <= 0) {
            return $this;
        }

        $canvas = $this;
        $x = $radius;
        $y = 0;
        $dx = 1 - ($radius << 1);
        $dy = 1;
        $err = 0;

        while ($x >= $y) {
            // 8 octants
            $canvas = $canvas->setPixel($cx + $x, $cy + $y, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx + $y, $cy + $x, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx - $y, $cy + $x, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx - $x, $cy + $y, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx - $x, $cy - $y, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx - $y, $cy - $x, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx + $y, $cy - $x, $char, $fg, $bg);
            $canvas = $canvas->setPixel($cx + $x, $cy - $y, $char, $fg, $bg);

            $y++;
            $err += $dy;
            $dy += 2;

            if ((2 * $err) + $dx > 0) {
                $x--;
                $err += $dx;
                $dx += 2;
            }
        }

        return $canvas;
    }

    /**
     * Fill a circle.
     */
    public function fillCircle(int $cx, int $cy, int $radius, string $char = '█', ?Color $fg = null, ?Color $bg = null): self
    {
        if ($radius <= 0) {
            return $this;
        }

        $canvas = $this;

        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                if (($dx * $dx) + ($dy * $dy) <= ($radius * $radius)) {
                    $canvas = $canvas->setPixel($cx + $dx, $cy + $dy, $char, $fg, $bg);
                }
            }
        }

        return $canvas;
    }

    /**
     * Draw ASCII text at the given position.
     */
    public function drawText(int $x, int $y, string $text, ?Color $fg = null, ?Color $bg = null): self
    {
        $canvas = $this;
        $chars = mb_str_split($text);

        foreach ($chars as $i => $char) {
            $canvas = $canvas->setPixel($x + $i, $y, $char, $fg, $bg);
        }

        return $canvas;
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
     */
    public function render(): string
    {
        $output = '';

        for ($y = 0; $y < $this->heightConstraint; $y++) {
            for ($x = 0; $x < $this->widthConstraint; $x++) {
                $pixel = $this->pixels[$y][$x];
                $fgHex = $this->fgColors[$y][$x];
                $bgHex = $this->bgColors[$y][$x];

                if ($pixel === null && $fgHex === null && $bgHex === null) {
                    $output .= ' ';
                    continue;
                }

                $char = $pixel ?? ' ';

                if ($bgHex !== null) {
                    try {
                        $bgColor = Color::hex($bgHex);
                        $output .= $bgColor->toBg(ColorProfile::TrueColor);
                    } catch (\Throwable) {
                        $output .= ' ';
                    }
                }

                if ($fgHex !== null) {
                    try {
                        $fgColor = Color::hex($fgHex);
                        $output .= $fgColor->toFg(ColorProfile::TrueColor);
                    } catch (\Throwable) {
                        // use default
                    }
                }

                $output .= $char;

                if ($fgHex !== null || $bgHex !== null) {
                    $output .= Ansi::reset();
                }
            }

            if ($y < $this->heightConstraint - 1) {
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Calculate the natural dimensions of this canvas.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->widthConstraint, $this->heightConstraint];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set a new width.
     */
    public function withWidth(int $width): self
    {
        return new self(
            widthConstraint: $width,
            heightConstraint: $this->heightConstraint,
            defaultFg: $this->defaultFg,
            defaultBg: $this->defaultBg,
        );
    }

    /**
     * Set a new height.
     */
    public function withHeight(int $height): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            heightConstraint: $height,
            defaultFg: $this->defaultFg,
            defaultBg: $this->defaultBg,
        );
    }

    /**
     * Set the default foreground color.
     */
    public function withDefaultFg(?Color $color): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            defaultFg: $color,
            defaultBg: $this->defaultBg,
        );
    }

    /**
     * Set the default background color.
     */
    public function withDefaultBg(?Color $color): self
    {
        return new self(
            widthConstraint: $this->widthConstraint,
            heightConstraint: $this->heightConstraint,
            defaultFg: $this->defaultFg,
            defaultBg: $color,
        );
    }
}
