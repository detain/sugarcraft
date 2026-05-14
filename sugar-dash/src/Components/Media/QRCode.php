<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A QR code display component.
 *
 * Renders a QR code as a grid of ANSI-colored cells.
 * Uses a simple encoding approach for demonstration -
 * production use should integrate a proper QR library.
 *
 * Mirrors QR code display concepts adapted to PHP with wither-style immutable setters.
 */
final class QRCode implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * Simple QR-like matrix pattern generator using modulo arithmetic.
     * This creates deterministic patterns based on the content.
     */
    private const CELL_FILLED = '██';
    private const CELL_EMPTY = '  ';

    public function __construct(
        private readonly string $content,
        private readonly int $size = 8,
        private readonly bool $bordered = true,
        private readonly ?Color $filledColor = null,
        private readonly ?Color $emptyColor = null,
    ) {}

    /**
     * Create a new QR code with default styling.
     */
    public static function new(string $content): self
    {
        return new self(
            content: $content,
            size: 8,
            bordered: true,
            filledColor: Color::hex('#000000'),
            emptyColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this QR code.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Generate a simple matrix pattern from the content.
     *
     * Uses a hash-based approach to create a deterministic pattern
     * that resembles a QR code structure with finder patterns.
     */
    private function generateMatrix(int $size): array
    {
        $matrix = [];
        $hash = crc32($this->content);

        // Create matrix with a simple pseudo-random pattern based on content
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                // Add finder patterns (corners) for realistic QR look
                if ($this->isFinderPosition($x, $y, $size)) {
                    $row[] = true;
                } elseif ($this->isFinderSeparator($x, $y, $size)) {
                    $row[] = false;
                } else {
                    // Use hash-based pseudo-random for data area
                    $seed = ($hash + $x * 31 + $y * 37) % 100;
                    $row[] = ($seed % 2) === 0;
                }
            }
            $matrix[] = $row;
        }

        return $matrix;
    }

    /**
     * Check if position is part of a finder pattern (QR corner markers).
     */
    private function isFinderPosition(int $x, int $y, int $size): bool
    {
        $finderSize = max(3, (int) floor($size / 5));

        // Top-left finder
        if ($x < $finderSize && $y < $finderSize) {
            return true;
        }
        // Top-right finder
        if ($x >= $size - $finderSize && $y < $finderSize) {
            return true;
        }
        // Bottom-left finder
        if ($x < $finderSize && $y >= $size - $finderSize) {
            return true;
        }

        return false;
    }

    /**
     * Check if position is in the finder separator zone.
     */
    private function isFinderSeparator(int $x, int $y, int $size): bool
    {
        $finderSize = max(3, (int) floor($size / 5));
        $separatorSize = 1;

        // Separator below/right of finder patterns
        if ($x === $finderSize && $y < $finderSize + $separatorSize) {
            return true;
        }
        if ($y === $finderSize && $x < $finderSize + $separatorSize) {
            return true;
        }
        if ($x === $size - $finderSize - 1 && $y < $finderSize + $separatorSize) {
            return true;
        }
        if ($y === $size - $finderSize - 1 && $x > $size - $finderSize - $separatorSize - 1) {
            return true;
        }
        if ($x === $finderSize && $y > $size - $finderSize - $separatorSize - 1) {
            return true;
        }

        return false;
    }

    /**
     * Render the QR code.
     */
    public function render(): string
    {
        $size = $this->size;
        if ($size < 5) {
            $size = 5;
        }

        $matrix = $this->generateMatrix($size);

        $result = '';
        foreach ($matrix as $y => $row) {
            $rowStr = '';
            foreach ($row as $x => $filled) {
                if ($filled) {
                    if ($this->filledColor !== null) {
                        $rowStr .= $this->filledColor->toFg(ColorProfile::TrueColor);
                    }
                    $rowStr .= self::CELL_FILLED;
                    if ($this->filledColor !== null) {
                        $rowStr .= Ansi::reset();
                    }
                } else {
                    if ($this->emptyColor !== null) {
                        $rowStr .= $this->emptyColor->toFg(ColorProfile::TrueColor);
                    }
                    $rowStr .= self::CELL_EMPTY;
                    if ($this->emptyColor !== null) {
                        $rowStr .= Ansi::reset();
                    }
                }
            }
            $result .= $rowStr . "\n";
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this QR code.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $size = max(5, $this->size);
        // Each cell is 2 chars wide, rows are 1 char tall
        return [$size * 2, $size];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the QR code size (grid dimensions).
     */
    public function withSize(int $size): self
    {
        return new self(
            content: $this->content,
            size: max(5, $size),
            bordered: $this->bordered,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Show or hide the border.
     */
    public function withBordered(bool $bordered): self
    {
        return new self(
            content: $this->content,
            size: $this->size,
            bordered: $bordered,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Set the color for filled cells.
     */
    public function withFilledColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            size: $this->size,
            bordered: $this->bordered,
            filledColor: $color,
            emptyColor: $this->emptyColor,
        );
    }

    /**
     * Set the color for empty cells.
     */
    public function withEmptyColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            size: $this->size,
            bordered: $this->bordered,
            filledColor: $this->filledColor,
            emptyColor: $color,
        );
    }

    /**
     * Set new content.
     */
    public function withContent(string $content): self
    {
        return new self(
            content: $content,
            size: $this->size,
            bordered: $this->bordered,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
        );
    }
}
