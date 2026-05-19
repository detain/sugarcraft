<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Sugar-dash immutable ANSI renderer Buffer (Sizer/Drawable pattern).
 * Intentionally distinct from \SugarCraft\Vt\Buffer\Buffer, which is
 * a mutable VT-output grid (resize/cell/put/each). Different role:
 * this composes layout output for inline-termui; the VT version
 * stores terminal state during emulation.
 *
 * See sugar-dash/CALIBER_LEARNINGS.md entry [pattern:dual-buffer-roles].
 */
final class Buffer implements Drawable, Sizer
{
    /**
     * @var list<list<Cell|null>>
     */
    public array $grid;

    private int $width;
    private int $height;

    /**
     * @param int $width  Fixed width of the buffer
     * @param int $height Fixed height of the buffer
     */
    public function __construct(int $width, int $height)
    {
        $this->width = $width;
        $this->height = $height;
        $this->grid = array_fill(0, $height, array_fill(0, $width, null));
    }

    /**
     * Create a new buffer with the given dimensions.
     */
    public static function new(int $width, int $height): self
    {
        return new self($width, $height);
    }

    /**
     * Get the Cell at the given coordinates.
     *
     * Returns a Cell with a space rune and default style if the cell
     * was never written to (null).
     *
     * @param int $x X coordinate
     * @param int $y Y coordinate
     * @return Cell The cell at the coordinates
     * @throws \OutOfBoundsException If coordinates are out of bounds
     */
    public function getCell(int $x, int $y): Cell
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw new \OutOfBoundsException("Coordinates ($x, $y) are out of bounds");
        }

        return $this->grid[$y][$x] ?? new Cell(' ', new Style());
    }

    /**
     * Set a cell at the given coordinates (wither).
     *
     * @param int  $x   X coordinate
     * @param int  $y   Y coordinate
     * @param Cell $cell The cell to set
     * @return self A new Buffer with the cell set
     * @throws \OutOfBoundsException If coordinates are out of bounds
     */
    public function setCell(int $x, int $y, Cell $cell): self
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw new \OutOfBoundsException("Coordinates ($x, $y) are out of bounds");
        }

        $clone = clone $this;
        $clone->grid = array_map(fn(array $row) => [...$row], $this->grid);
        $clone->grid[$y][$x] = $cell;

        return $clone;
    }

    /**
     * Fill a rectangular region with a cell (wither).
     *
     * @param Rect  $rect The rectangle to fill
     * @param Cell  $cell The cell to fill with
     * @return self A new Buffer with the region filled
     */
    public function fill(Rect $rect, Cell $cell): self
    {
        $clone = clone $this;
        $clone->grid = array_map(fn(array $row) => [...$row], $this->grid);

        for ($y = $rect->minY; $y <= $rect->maxY; $y++) {
            for ($x = $rect->minX; $x <= $rect->maxX; $x++) {
                if ($x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height) {
                    $clone->grid[$y][$x] = $cell;
                }
            }
        }

        return $clone;
    }

    /**
     * Set a string starting at the given coordinates (wither).
     *
     * Each character of the string becomes a Cell with the given style.
     * Characters that would exceed buffer boundaries are truncated.
     *
     * @param int    $x     X coordinate
     * @param int    $y     Y coordinate
     * @param string $str   The string to set
     * @param Style  $style The style to apply to each character
     * @return self A new Buffer with the string set
     */
    public function setString(int $x, int $y, string $str, ?Style $style = null): self
    {
        $style ??= new Style();

        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return $this;
        }

        $clone = clone $this;
        $clone->grid = array_map(fn(array $row) => [...$row], $this->grid);

        $chars = mb_str_split($str);
        foreach ($chars as $i => $char) {
            $posX = $x + $i;
            if ($posX >= $this->width) {
                break;
            }
            $clone->grid[$y][$posX] = new Cell($char, $style);
        }

        return $clone;
    }

    /**
     * Set the rectangle this buffer should fill (from Drawable).
     *
     * @param Rect $rect The rectangle to fill
     * @return $this
     */
    public function setRect(Rect $rect): self
    {
        $clone = clone $this;
        $clone->width = $rect->dx();
        $clone->height = $rect->dy();
        return $clone;
    }

    /**
     * Get the rectangle this buffer occupies (from Drawable).
     *
     * @return Rect The rectangle
     */
    public function getRect(): Rect
    {
        return new Rect(0, 0, $this->width - 1, $this->height - 1);
    }

    /**
     * Draw this buffer's content into the target buffer (from Drawable).
     *
     * Renders this buffer's content into the target buffer at this buffer's
     * Rect position (top-left corner).
     *
     * @param Buffer $buffer The target buffer to draw into
     * @throws \OutOfBoundsException If drawing would exceed target bounds
     */
    public function draw(Buffer $buffer): void
    {
        $rect = $this->getRect();

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->grid[$y][$x] ?? null;
                if ($cell === null) {
                    continue;
                }

                $targetX = $rect->minX + $x;
                $targetY = $rect->minY + $y;

                if ($targetX >= $buffer->width || $targetY >= $buffer->height) {
                    continue;
                }

                $buffer->grid[$targetY][$targetX] = $cell;
            }
        }
    }

    /**
     * Render the buffer as an ANSI string.
     *
     * @return string The rendered buffer with ANSI style sequences
     */
    public function render(): string
    {
        $output = '';

        for ($y = 0; $y < $this->height; $y++) {
            $inStyle = false;
            $currentStyle = null;

            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->grid[$y][$x] ?? null;

                if ($cell === null) {
                    $rune = ' ';
                    $style = new Style();
                } else {
                    $rune = $cell->rune;
                    $style = $cell->style;
                }

                // Close style if it changed
                if ($inStyle && $style != $currentStyle) {
                    $output .= "\x1b[0m";
                    $inStyle = false;
                }

                // Open new style if needed
                if (!$inStyle && ($style->foreground !== null || $style->background !== null
                        || $style->bold || $style->dim || $style->italic
                        || $style->underline || $style->reverse || $style->strike)) {
                    $output .= $style->toAnsi();
                    $inStyle = true;
                    $currentStyle = $style;
                }

                $output .= $rune;
            }

            // Close any open style at end of line
            if ($inStyle) {
                $output .= "\x1b[0m";
            }

            if ($y < $this->height - 1) {
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Get the inner dimensions of this buffer.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->width, $this->height];
    }

    /**
     * Clear the buffer (wither).
     *
     * @return self A new Buffer with all cells set to null
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->grid = array_fill(0, $this->height, array_fill(0, $this->width, null));
        return $clone;
    }

    /**
     * Set the allocated dimensions for this buffer.
     *
     * @param int $width  Allocated width
     * @param int $height Allocated height
     * @return Sizer
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Buffers are passive drawing surfaces — theme application is a pass-through.
     */
    public function withTheme(Theme $theme): self
    {
        return $this;
    }
}
