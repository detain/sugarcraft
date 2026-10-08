<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Braille;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\SizedItem;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A canvas that renders to braille characters (2x4 dots per cell).
 *
 * Provides 2x horizontal and 4x vertical resolution compared to
 * standard character cells. Uses Bresenham's line algorithm for
 * anti-aliased line drawing.
 *
 * Mirrors termui/drawille_drawille.go:7-83
 */
final class BrailleCanvas implements SizedItem
{
    /** @var list<list<int>> accumulated dot bits per cell */
    private array $cells = [];

    /** @var list<list<\SugarCraft\Core\Util\Color|null>> */
    private array $colors = [];

    private int $pixelWidth;
    private int $pixelHeight;
    private int $cellWidth;
    private int $cellHeight;

    /**
     * 101-entry value→color lookup (index = percent 0..100), or null when
     * no gradient is attached. Expanded once in withGradient() so a value
     * change is an array index, never a re-blend of the stops.
     *
     * @var list<\SugarCraft\Core\Util\Color>|null
     */
    private ?array $gradientMemo = null;

    /** Maps the current sample value onto 0..1; null = identity. */
    private ?\Closure $gradientScale = null;

    private int|float $value = 0;

    /**
     * Ramp color for the current value, resolved whenever the gradient or
     * value changes so setLine()'s per-point setPoint() calls neither
     * re-run the scale closure nor re-index the memo.
     */
    private ?\SugarCraft\Core\Util\Color $gradientColor = null;

    public function __construct(int $pixelWidth, int $pixelHeight)
    {
        $this->pixelWidth = $pixelWidth;
        $this->pixelHeight = $pixelHeight;
        $this->cellWidth = BrailleMatrix::cellWidth($pixelWidth);
        $this->cellHeight = BrailleMatrix::cellHeight($pixelHeight);

        // Initialize grids with 0 bits (empty cells)
        $this->cells = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, 0));
        $this->colors = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, null));
    }

    public static function new(int $pixelWidth, int $pixelHeight): self
    {
        return new self($pixelWidth, $pixelHeight);
    }

    /**
     * Attach a value→color ramp. Once attached, setPoint()/setLine() with a
     * null color paint the dot from the ramp at the canvas' current value
     * (see withValue()); an explicit color still wins.
     *
     * The stops are expanded here, once, into a 101-entry memo — the same
     * shape as btop's per-theme `std::array<string,101>` gradient cache —
     * using btop's own integer law per channel,
     * `start + (i - offset) * (end - start) / range` with C++ truncating
     * division, so every entry is byte-identical to btop's (a rounding
     * blend drifts by one on odd deltas, e.g. 127 vs 128 at the midpoint).
     * With more than two stops the 0..100 range is split into equal
     * segments; for three stops that is btop's start/mid/end split at 50.
     *
     * `$scale` maps a raw sample onto 0..1 (result is clamped); it is where
     * a caller puts btop's `(v + offset) * 100 / max_value` percent law,
     * keeping the canvas value-agnostic. Defaults to identity, i.e. values
     * are expected to already be 0..1.
     *
     * Mirrors aristocratos/btop Theme::generateGradients + Draw::Graph's
     * `Theme::g(color_gradient).at(clamp(value, 0, 100))` coloring.
     *
     * @param list<\SugarCraft\Core\Util\Color> $stops ordered low→high, at least 2
     * @param (\Closure(int|float):(int|float))|null $scale
     * @throws \InvalidArgumentException on fewer than 2 stops or a non-Color stop
     */
    public function withGradient(array $stops, ?\Closure $scale = null): self
    {
        $stops = array_values($stops);
        if (count($stops) < 2) {
            throw new \InvalidArgumentException(sprintf(
                'BrailleCanvas gradient needs at least 2 color stops, got %d',
                count($stops),
            ));
        }
        foreach ($stops as $i => $stop) {
            if (!$stop instanceof \SugarCraft\Core\Util\Color) {
                throw new \InvalidArgumentException(sprintf(
                    'BrailleCanvas gradient stop %d must be a %s, got %s',
                    $i,
                    \SugarCraft\Core\Util\Color::class,
                    get_debug_type($stop),
                ));
            }
        }

        $segments = count($stops) - 1;
        $memo = [$stops[0]];
        $from = 0;
        for ($k = 0; $k < $segments; $k++) {
            $to = (int) round(($k + 1) * 100 / $segments);
            $range = $to - $from;
            $a = $stops[$k];
            $b = $stops[$k + 1];
            // Index $from already holds this segment's start (the previous
            // segment's end), so adjacent segments share their boundary.
            for ($i = $from + 1; $i <= $to; $i++) {
                $step = $i - $from;
                // intdiv truncates toward zero, matching C++ int division
                // on the negative deltas of a descending channel.
                $memo[] = \SugarCraft\Core\Util\Color::rgb(
                    $a->r + intdiv($step * ($b->r - $a->r), $range),
                    $a->g + intdiv($step * ($b->g - $a->g), $range),
                    $a->b + intdiv($step * ($b->b - $a->b), $range),
                );
            }
            $from = $to;
        }

        return $this->mutate(['gradientMemo' => $memo, 'gradientScale' => $scale])->resolveGradient();
    }

    /**
     * Detach the ramp: null-color points go back to keeping the cell's
     * current color. The current value is kept for a later withGradient().
     */
    public function withoutGradient(): self
    {
        return $this->mutate(['gradientMemo' => null, 'gradientScale' => null, 'gradientColor' => null]);
    }

    /**
     * Set the sample value that subsequent null-color points are painted
     * with when a gradient is attached. Ignored for coloring otherwise.
     */
    public function withValue(int|float $value): self
    {
        return $this->mutate(['value' => $value])->resolveGradient();
    }

    /**
     * The expanded 101-entry ramp (index = percent), or null when no
     * gradient is attached.
     *
     * @return list<\SugarCraft\Core\Util\Color>|null
     */
    public function gradient(): ?array
    {
        return $this->gradientMemo;
    }

    public function value(): int|float
    {
        return $this->value;
    }

    /**
     * Set a single dot at pixel coordinates (x, y).
     *
     * @param int $x Pixel X coordinate
     * @param int $y Pixel Y coordinate
     * @param \SugarCraft\Core\Util\Color|null $color Color for this dot (null = ramp color at the
     *        current value when a gradient is attached, else keep the cell's current color)
     */
    public function setPoint(int $x, int $y, ?\SugarCraft\Core\Util\Color $color = null): self
    {
        if ($x < 0 || $y < 0 || $x >= $this->pixelWidth || $y >= $this->pixelHeight) {
            return $this; // Out of bounds - no-op
        }

        $clone = clone $this;
        $clone->cells = array_map(fn(array $row) => [...$row], $this->cells);
        $clone->colors = array_map(fn(array $row) => [...$row], $this->colors);

        $cellX = BrailleMatrix::cellX($x);
        $cellY = BrailleMatrix::cellY($y);
        $dotBit = BrailleMatrix::dotBit($x, $y);

        $clone->cells[$cellY][$cellX] |= $dotBit;
        $color ??= $this->gradientColor;
        if ($color !== null) {
            $clone->colors[$cellY][$cellX] = $color;
        }

        return $clone;
    }

    /**
     * Draw a line from (x1,y1) to (x2,y2) using Bresenham's algorithm.
     *
     * @param int $x1 Start X
     * @param int $y1 Start Y
     * @param int $x2 End X
     * @param int $y2 End Y
     * @param \SugarCraft\Core\Util\Color|null $color Color for the line
     */
    public function setLine(int $x1, int $y1, int $x2, int $y2, ?\SugarCraft\Core\Util\Color $color = null): self
    {
        $clone = $this;

        $dx = abs($x2 - $x1);
        $dy = abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx - $dy;

        while (true) {
            $clone = $clone->setPoint($x1, $y1, $color);

            if ($x1 === $x2 && $y1 === $y2) {
                break;
            }

            $e2 = 2 * $err;

            if ($e2 > -$dy) {
                $err -= $dy;
                $x1 += $sx;
            }

            if ($e2 < $dx) {
                $err += $dx;
                $y1 += $sy;
            }
        }

        return $clone;
    }

    /**
     * Clear all dots from the canvas.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->cells = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, 0));
        $clone->colors = array_fill(0, $this->cellHeight, array_fill(0, $this->cellWidth, null));
        return $clone;
    }

    /**
     * Get accumulated bits for a cell.
     */
    public function getCell(int $cellX, int $cellY): int
    {
        if ($cellX < 0 || $cellY < 0 || $cellX >= $this->cellWidth || $cellY >= $this->cellHeight) {
            return 0;
        }
        return $this->cells[$cellY][$cellX];
    }

    /**
     * Iterate over all cells that have dots set.
     *
     * Yields arrays of [cellX, cellY, bits, ?Color] for each cell
     * where at least one dot bit is set.
     *
     * @return \Generator<array{0:int, 1:int, 2:int, 3:\SugarCraft\Core\Util\Color|null}>
     */
    public function cells(): \Generator
    {
        for ($cellY = 0; $cellY < $this->cellHeight; $cellY++) {
            for ($cellX = 0; $cellX < $this->cellWidth; $cellX++) {
                $bits = $this->cells[$cellY][$cellX];
                if ($bits !== 0) {
                    yield [$cellX, $cellY, $bits, $this->colors[$cellY][$cellX]];
                }
            }
        }
    }

    /**
     * Render the canvas as a string of braille characters.
     */
    public function render(?ColorProfile $profile = null): string
    {
        $profile ??= ColorProfile::detect();

        $lines = [];

        for ($cellY = 0; $cellY < $this->cellHeight; $cellY++) {
            $line = '';
            for ($cellX = 0; $cellX < $this->cellWidth; $cellX++) {
                $bits = $this->cells[$cellY][$cellX];
                if ($bits === 0) {
                    $line .= ' ';
                } else {
                    $rune = BrailleMatrix::rune($bits);
                    $color = $this->colors[$cellY][$cellX];
                    if ($color !== null) {
                        $line .= $color->toFg($profile) . $rune . Ansi::reset();
                    } else {
                        $line .= $rune . ' ';
                    }
                }
            }
            // Only rtrim if line has non-space content, to preserve empty canvas rendering
            $trimmed = rtrim($line);
            $lines[] = $trimmed === '' ? $line : $trimmed;
        }

        return implode("\n", $lines);
    }

    public function setSize(int $width, int $height): Sizer
    {
        // The ramp, scale and value are geometry-independent, so a resize
        // keeps them; only the dot/color grids start over.
        $resized = new self($width, $height);
        $resized->gradientMemo = $this->gradientMemo;
        $resized->gradientScale = $this->gradientScale;
        $resized->value = $this->value;
        $resized->gradientColor = $this->gradientColor;
        return $resized;
    }

    /** Re-resolve the cached ramp color; called on the fresh clone only. */
    private function resolveGradient(): self
    {
        if ($this->gradientMemo === null) {
            $this->gradientColor = null;
            return $this;
        }
        $t = $this->gradientScale === null
            ? (float) $this->value
            : (float) ($this->gradientScale)($this->value);
        // NaN would survive max/min and break the int cast's intent.
        $t = is_nan($t) ? 0.0 : max(0.0, min(1.0, $t));
        $this->gradientColor = $this->gradientMemo[(int) round($t * 100)];
        return $this;
    }

    /**
     * @param array<string, mixed> $props
     */
    private function mutate(array $props): self
    {
        $clone = clone $this;
        foreach ($props as $name => $value) {
            $clone->{$name} = $value;
        }
        return $clone;
    }

    public function getInnerSize(): array
    {
        return [$this->cellWidth, $this->cellHeight];
    }

    public function getPixelSize(): array
    {
        return [$this->pixelWidth, $this->pixelHeight];
    }
}
