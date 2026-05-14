<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A dot matrix display component.
 *
 * Renders a message using a dot matrix pattern, similar to LED displays
 * or scrolling marquee boards. Each character is composed of a grid of dots.
 * Supports custom dot sizes, scrolling, and color coding.
 *
 * Mirrors dot matrix/LED display concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class DotMatrix implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    /**
     * 5x5 dot matrix font for characters A-Z, 0-9, and common symbols.
     * Each character is represented as 5 rows of 5 bits.
     */
    private const FONT = [
        'A' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
        ],
        'B' => [
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
        ],
        'C' => [
            [0, 1, 1, 1, 1],
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
            [0, 1, 1, 1, 1],
        ],
        'D' => [
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
        ],
        'E' => [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0],
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 0],
            [1, 1, 1, 1, 1],
        ],
        'F' => [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0],
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
        ],
        'G' => [
            [0, 1, 1, 1, 1],
            [1, 0, 0, 0, 0],
            [1, 0, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        'H' => [
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
        ],
        'I' => [
            [1, 1, 1, 1, 1],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [1, 1, 1, 1, 1],
        ],
        'J' => [
            [0, 0, 0, 1, 1],
            [0, 0, 0, 0, 1],
            [0, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        'K' => [
            [1, 0, 0, 0, 1],
            [1, 0, 0, 1, 0],
            [1, 1, 1, 0, 0],
            [1, 0, 0, 1, 0],
            [1, 0, 0, 0, 1],
        ],
        'L' => [
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
            [1, 1, 1, 1, 1],
        ],
        'M' => [
            [1, 0, 0, 0, 1],
            [1, 1, 0, 1, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
        ],
        'N' => [
            [1, 0, 0, 0, 1],
            [1, 1, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 1, 1],
            [1, 0, 0, 0, 1],
        ],
        'O' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        'P' => [
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 0],
            [1, 0, 0, 0, 0],
        ],
        'Q' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [0, 1, 1, 1, 1],
        ],
        'R' => [
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
            [1, 0, 0, 1, 0],
            [1, 0, 0, 0, 1],
        ],
        'S' => [
            [0, 1, 1, 1, 1],
            [1, 0, 0, 0, 0],
            [0, 1, 1, 1, 0],
            [0, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
        ],
        'T' => [
            [1, 1, 1, 1, 1],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
        ],
        'U' => [
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        'V' => [
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [0, 1, 0, 1, 0],
            [0, 0, 1, 0, 0],
        ],
        'W' => [
            [1, 0, 0, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 1, 0, 1, 1],
            [1, 0, 0, 0, 1],
        ],
        'X' => [
            [1, 0, 0, 0, 1],
            [0, 1, 0, 1, 0],
            [0, 0, 1, 0, 0],
            [0, 1, 0, 1, 0],
            [1, 0, 0, 0, 1],
        ],
        'Y' => [
            [1, 0, 0, 0, 1],
            [0, 1, 0, 1, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
        ],
        'Z' => [
            [1, 1, 1, 1, 1],
            [0, 0, 0, 1, 0],
            [0, 0, 1, 0, 0],
            [0, 1, 0, 0, 0],
            [1, 1, 1, 1, 1],
        ],
        '0' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 1, 1],
            [1, 0, 1, 0, 1],
            [1, 1, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        '1' => [
            [0, 0, 1, 0, 0],
            [0, 1, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 1, 1, 1, 0],
        ],
        '2' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [0, 0, 1, 1, 0],
            [0, 1, 0, 0, 0],
            [1, 1, 1, 1, 1],
        ],
        '3' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [0, 0, 1, 1, 0],
            [0, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        '4' => [
            [0, 0, 0, 1, 0],
            [0, 0, 1, 1, 0],
            [0, 1, 0, 1, 0],
            [1, 1, 1, 1, 1],
            [0, 0, 0, 1, 0],
        ],
        '5' => [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0],
            [1, 1, 1, 1, 0],
            [0, 0, 0, 0, 1],
            [1, 1, 1, 1, 0],
        ],
        '6' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 0],
            [1, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        '7' => [
            [1, 1, 1, 1, 1],
            [0, 0, 0, 0, 1],
            [0, 0, 0, 1, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
        ],
        '8' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        '9' => [
            [0, 1, 1, 1, 0],
            [1, 0, 0, 0, 1],
            [0, 1, 1, 1, 1],
            [0, 0, 0, 0, 1],
            [0, 1, 1, 1, 0],
        ],
        ' ' => [
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
        ],
        '!' => [
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 1, 0, 0],
        ],
        '.' => [
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 1, 0, 0],
        ],
        '-' => [
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
            [1, 1, 1, 1, 1],
            [0, 0, 0, 0, 0],
            [0, 0, 0, 0, 0],
        ],
        '+' => [
            [0, 0, 0, 0, 0],
            [0, 0, 1, 0, 0],
            [1, 1, 1, 1, 1],
            [0, 0, 1, 0, 0],
            [0, 0, 0, 0, 0],
        ],
        ':' => [
            [0, 0, 0, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 0, 0, 0],
            [0, 0, 1, 0, 0],
            [0, 0, 0, 0, 0],
        ],
        '*' => [
            [0, 0, 0, 0, 0],
            [1, 0, 1, 0, 1],
            [0, 1, 1, 1, 0],
            [1, 0, 1, 0, 1],
            [0, 0, 0, 0, 0],
        ],
    ];

    /**
     * Dot character for filled pixels.
     */
    private const DOT_ON = '●';

    /**
     * Character for spacing between characters.
     */
    private const CHAR_SPACING = 1;

    public function __construct(
        private readonly string $content,
        private readonly int $cellSize = 1,
        private readonly bool $showFrame = false,
        private readonly ?Color $onColor = null,
        private readonly ?Color $offColor = null,
    ) {}

    /**
     * Create a new dot matrix display with default styling.
     *
     * Default: purple dots, cell size 1, no frame.
     */
    public static function new(string $content): self
    {
        return new self(
            content: $content,
            cellSize: 1,
            showFrame: false,
            onColor: Color::hex('#874BFD'),
            offColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this display.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Get the dot matrix pattern for a character.
     */
    private function getCharPattern(string $char): array
    {
        $char = strtoupper($char);
        return self::FONT[$char] ?? self::FONT[' '];
    }

    /**
     * Render a single dot cell.
     */
    private function renderDot(bool $isOn, ColorProfile $profile): string
    {
        if ($isOn) {
            if ($this->onColor !== null) {
                return $this->onColor->toFg($profile) . str_repeat(self::DOT_ON, $this->cellSize) . Ansi::reset();
            }
            return str_repeat(self::DOT_ON, $this->cellSize);
        }

        // Off dots are not rendered (transparent)
        return str_repeat(' ', $this->cellSize);
    }

    /**
     * Render the dot matrix display.
     */
    public function render(): string
    {
        $profile = ColorProfile::TrueColor;
        $chars = mb_str_split(mb_strtoupper($this->content, 'UTF-8'), 1, 'UTF-8');

        // Each character is 5 dots tall, and 5 + spacing dots wide
        $charWidth = 5 + self::CHAR_SPACING;
        $height = 5;

        // Build rows
        $rows = array_fill(0, $height * $this->cellSize, '');

        foreach ($chars as $char) {
            $pattern = $this->getCharPattern($char);

            // Render each cell in the character
            for ($row = 0; $row < 5; $row++) {
                for ($cellRow = 0; $cellRow < $this->cellSize; $cellRow++) {
                    $rowContent = '';
                    for ($col = 0; $col < 5; $col++) {
                        $isOn = ($pattern[$row][$col] === 1);
                        $rowContent .= $this->renderDot($isOn, $profile);
                    }
                    // Add spacing after character
                    $rowContent .= str_repeat(' ', self::CHAR_SPACING);
                    $rows[($row * $this->cellSize) + $cellRow] .= $rowContent;
                }
            }
        }

        // Apply frame if enabled
        if ($this->showFrame) {
            $width = mb_strlen($rows[0], 'UTF-8');
            $frameTop = '┌' . str_repeat('─', $width) . '┐';
            $frameBottom = '└' . str_repeat('─', $width) . '┘';

            for ($i = 0; $i < count($rows); $i++) {
                $rows[$i] = '│' . $rows[$i] . '│';
            }

            array_unshift($rows, $frameTop);
            $rows[] = $frameBottom;
        }

        $result = implode("\n", $rows);

        // Ensure color reset at end if colors were used
        if ($this->onColor !== null || $this->offColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this display.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $charCount = mb_strlen($this->content, 'UTF-8');
        $charWidth = (5 + self::CHAR_SPACING) * $this->cellSize;
        $width = ($charCount * $charWidth) + (($charCount - 1) * self::CHAR_SPACING * $this->cellSize);
        $height = 5 * $this->cellSize;

        if ($this->showFrame) {
            $width += 2;
            $height += 2;
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the cell size (scale factor for each dot).
     */
    public function withCellSize(int $size): self
    {
        return new self(
            content: $this->content,
            cellSize: max(1, $size),
            showFrame: $this->showFrame,
            onColor: $this->onColor,
            offColor: $this->offColor,
        );
    }

    /**
     * Show or hide the frame.
     */
    public function withShowFrame(bool $show): self
    {
        return new self(
            content: $this->content,
            cellSize: $this->cellSize,
            showFrame: $show,
            onColor: $this->onColor,
            offColor: $this->offColor,
        );
    }

    /**
     * Set the "on" (lit) dot color.
     */
    public function withOnColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            cellSize: $this->cellSize,
            showFrame: $this->showFrame,
            onColor: $color,
            offColor: $this->offColor,
        );
    }

    /**
     * Set the "off" (unlit) dot color.
     */
    public function withOffColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            cellSize: $this->cellSize,
            showFrame: $this->showFrame,
            onColor: $this->onColor,
            offColor: $color,
        );
    }

    /**
     * Set new content.
     */
    public function withContent(string $content): self
    {
        return new self(
            content: $content,
            cellSize: $this->cellSize,
            showFrame: $this->showFrame,
            onColor: $this->onColor,
            offColor: $this->offColor,
        );
    }
}
