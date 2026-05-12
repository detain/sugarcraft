<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Width;

/**
 * A 2D text buffer for TUI rendering.
 *
 * Holds a rectangular grid of strings that can be written to at specific
 * coordinates. Useful for building up complex terminal output where
 * multiple elements need to be placed at specific (x, y) positions.
 *
 * Mirrors the buffer concept from charmbracelet/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Buffer implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @var list<list<string>>
     */
    private array $cells = [];

    public function __construct(
        private readonly int $widthConstraint,
        private readonly int $heightConstraint,
    ) {
        $this->initializeCells();
    }

    /**
     * Create a new empty buffer with the given dimensions.
     */
    public static function new(int $width, int $height): self
    {
        return new self(
            widthConstraint: $width,
            heightConstraint: $height,
        );
    }

    /**
     * Initialize the cell grid with null values.
     * null means "never written to" - render outputs space, getCell returns ''.
     * This allows distinguishing between "empty" and "explicitly filled with space".
     */
    private function initializeCells(): void
    {
        $this->cells = [];
        for ($y = 0; $y < $this->heightConstraint; $y++) {
            $row = [];
            for ($x = 0; $x < $this->widthConstraint; $x++) {
                $row[] = null;
            }
            $this->cells[] = $row;
        }
    }

    /**
     * Set the allocated dimensions for this buffer.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set a string at the given coordinates.
     *
     * The string is written horizontally starting at (x, y).
     * Characters that would exceed the buffer boundaries are truncated.
     */
    public function setString(int $x, int $y, string $str): self
    {
        if ($x < 0 || $y < 0 || $x >= $this->widthConstraint || $y >= $this->heightConstraint) {
            return $this; // Out of bounds - no-op
        }

        $clone = clone $this;
        $clone->cells = array_map(fn(array $row) => [...$row], $this->cells);

        $chars = mb_str_split($str);
        foreach ($chars as $i => $char) {
            $posX = $x + $i;
            if ($posX >= $this->widthConstraint) {
                break; // Stop at right edge
            }
            $clone->cells[$y][$posX] = $char;
        }

        return $clone;
    }

    /**
     * Set a full line at the given y coordinate.
     *
     * The line is aligned within the buffer width using the specified alignment.
     */
    public function setLine(int $y, string $line, HAlign $align = HAlign::Left): self
    {
        if ($y < 0 || $y >= $this->heightConstraint) {
            return $this; // Out of bounds - no-op
        }

        $lineWidth = Width::string($line);

        // Determine padding based on alignment
        $padding = max(0, $this->widthConstraint - $lineWidth);
        $offsetX = match ($align) {
            HAlign::Left => 0,
            HAlign::Right => $padding,
            HAlign::Center => (int) floor($padding / 2),
        };

        return $this->setString($offsetX, $y, $line);
    }

    /**
     * Fill a region with a specific character.
     */
    public function fill(int $x, int $y, int $width, int $height, string $char = ' '): self
    {
        if ($x < 0 || $y < 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->cells = array_map(fn(array $row) => [...$row], $this->cells);

        for ($dy = 0; $dy < $height; $dy++) {
            for ($dx = 0; $dx < $width; $dx++) {
                $posX = $x + $dx;
                $posY = $y + $dy;
                if ($posX >= 0 && $posX < $this->widthConstraint && $posY >= 0 && $posY < $this->heightConstraint) {
                    $clone->cells[$posY][$posX] = $char;
                }
            }
        }

        return $clone;
    }

    /**
     * Fill an entire row with a character.
     */
    public function fillRow(int $y, string $char = ' '): self
    {
        return $this->fill(0, $y, $this->widthConstraint, 1, $char);
    }

    /**
     * Fill an entire column with a character.
     */
    public function fillColumn(int $x, string $char = ' '): self
    {
        return $this->fill($x, 0, 1, $this->heightConstraint, $char);
    }

    /**
     * Get the character at the given coordinates.
     * Returns '' for cells that are null (never written to).
     */
    public function getCell(int $x, int $y): string
    {
        if ($x < 0 || $y < 0 || $x >= $this->widthConstraint || $y >= $this->heightConstraint) {
            return '';
        }
        return $this->cells[$y][$x] ?? '';
    }

    /**
     * Get the width constraint.
     */
    public function getWidthConstraint(): int
    {
        return $this->widthConstraint;
    }

    /**
     * Get the height constraint.
     */
    public function getHeightConstraint(): int
    {
        return $this->heightConstraint;
    }

    /**
     * Clear the buffer (reset all cells to empty strings).
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->cells = [];
        $clone->initializeCells();
        return $clone;
    }

    /**
     * Render the buffer as a multi-line string.
     * Null cells (never written to) render as spaces.
     */
    public function render(): string
    {
        $lines = [];
        for ($y = 0; $y < $this->heightConstraint; $y++) {
            $line = '';
            for ($x = 0; $x < $this->widthConstraint; $x++) {
                $line .= $this->cells[$y][$x] ?? ' ';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Calculate the natural dimensions of this buffer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->widthConstraint, $this->heightConstraint];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set new width and height constraints (creates a new buffer).
     */
    public function withSize(int $width, int $height): self
    {
        return new self(
            widthConstraint: $width,
            heightConstraint: $height,
        );
    }
}
