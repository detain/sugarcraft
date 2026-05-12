<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A group of chips displayed in a row.
 *
 * Features:
 * - Display multiple chips in a horizontal row
 * - Configurable gap between chips
 * - Support wrapping to multiple lines
 * - Support for selected/active states
 *
 * Mirrors chip-group UI concepts adapted to PHP with wither-style immutable setters.
 */
final class ChipGroup implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<Chip> $chips
     */
    public function __construct(
        private readonly array $chips = [],
        private readonly int $gap = 1,
        private readonly bool $wrap = false,
    ) {}

    /**
     * Create a new chip group from a list of labels.
     *
     * @param list<string> $labels
     */
    public static function fromLabels(array $labels): self
    {
        $chips = array_map(
            fn(string $label): Chip => Chip::new($label),
            $labels
        );

        return new self(
            chips: $chips,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled chip group with primary chips.
     *
     * @param list<string> $labels
     */
    public static function primary(array $labels): self
    {
        $chips = array_map(
            fn(string $label): Chip => Chip::primary($label),
            $labels
        );

        return new self(
            chips: $chips,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled chip group with success chips.
     *
     * @param list<string> $labels
     */
    public static function success(array $labels): self
    {
        $chips = array_map(
            fn(string $label): Chip => Chip::success($label),
            $labels
        );

        return new self(
            chips: $chips,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled chip group with warning chips.
     *
     * @param list<string> $labels
     */
    public static function warning(array $labels): self
    {
        $chips = array_map(
            fn(string $label): Chip => Chip::warning($label),
            $labels
        );

        return new self(
            chips: $chips,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Create a styled chip group with danger chips.
     *
     * @param list<string> $labels
     */
    public static function danger(array $labels): self
    {
        $chips = array_map(
            fn(string $label): Chip => Chip::danger($label),
            $labels
        );

        return new self(
            chips: $chips,
            gap: 1,
            wrap: false,
        );
    }

    /**
     * Set the allocated dimensions for this chip group.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the chip group as a string.
     */
    public function render(): string
    {
        if ($this->chips === []) {
            return '';
        }

        if ($this->wrap) {
            return $this->renderWrapped();
        }

        return $this->renderLinear();
    }

    /**
     * Render chips in a single horizontal row.
     */
    private function renderLinear(): string
    {
        $result = '';

        for ($i = 0; $i < count($this->chips); $i++) {
            $result .= $this->chips[$i]->render();

            if ($i < count($this->chips) - 1) {
                $result .= str_repeat(' ', max(0, $this->gap));
            }
        }

        return $result;
    }

    /**
     * Render chips with wrapping to multiple lines.
     */
    private function renderWrapped(): string
    {
        $availableWidth = $this->width ?? $this->calculateNaturalWidth();
        $result = '';
        $currentLine = '';
        $currentLineWidth = 0;
        $isFirstChipOnLine = true;

        for ($i = 0; $i < count($this->chips); $i++) {
            $chip = $this->chips[$i];
            [$chipWidth, ] = $chip->getInnerSize();
            $chipRendered = $chip->render();

            // Check if we need to wrap to a new line
            if (!$isFirstChipOnLine && $currentLineWidth + $this->gap + $chipWidth > $availableWidth) {
                // Finish current line
                $result .= $currentLine . "\n";
                $currentLine = '';
                $currentLineWidth = 0;
                $isFirstChipOnLine = true;
            }

            // Add gap before chip (except for first chip on line)
            if (!$isFirstChipOnLine) {
                $currentLine .= str_repeat(' ', max(0, $this->gap));
                $currentLineWidth += $this->gap;
            }

            $currentLine .= $chipRendered;
            $currentLineWidth += $chipWidth;
            $isFirstChipOnLine = false;
        }

        // Append the last line
        if ($currentLine !== '') {
            $result .= $currentLine;
        }

        // Trim trailing newline
        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural width without wrapping.
     */
    private function calculateNaturalWidth(): int
    {
        if ($this->chips === []) {
            return 0;
        }

        $totalWidth = 0;
        for ($i = 0; $i < count($this->chips); $i++) {
            [$w, ] = $this->chips[$i]->getInnerSize();
            $totalWidth += $w;
            if ($i < count($this->chips) - 1) {
                $totalWidth += $this->gap;
            }
        }

        return $totalWidth;
    }

    /**
     * Calculate the natural dimensions of this chip group.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if ($this->chips === []) {
            return [0, 0];
        }

        if ($this->wrap) {
            return $this->calculateWrappedSize();
        }

        $width = $this->calculateNaturalWidth();
        $height = 1; // Chips are always single-line

        return [$width, $height];
    }

    /**
     * Calculate size when wrapping is enabled.
     *
     * @return array{0:int,1:int} [width, height]
     */
    private function calculateWrappedSize(): array
    {
        $availableWidth = $this->width ?? $this->calculateNaturalWidth();
        $lineCount = 1;
        $currentLineWidth = 0;
        $maxLineWidth = 0;
        $isFirstChipOnLine = true;

        for ($i = 0; $i < count($this->chips); $i++) {
            [$w, ] = $this->chips[$i]->getInnerSize();

            if (!$isFirstChipOnLine && $currentLineWidth + $this->gap + $w > $availableWidth) {
                $maxLineWidth = max($maxLineWidth, $currentLineWidth);
                $lineCount++;
                $currentLineWidth = $w;
                $isFirstChipOnLine = false;
            } else {
                if (!$isFirstChipOnLine) {
                    $currentLineWidth += $this->gap;
                }
                $currentLineWidth += $w;
                $isFirstChipOnLine = false;
            }
        }

        $maxLineWidth = max($maxLineWidth, $currentLineWidth);

        return [$maxLineWidth, $lineCount];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the chips in this group.
     *
     * @param list<Chip> $chips
     */
    public function withChips(array $chips): self
    {
        return new self(
            chips: $chips,
            gap: $this->gap,
            wrap: $this->wrap,
        );
    }

    /**
     * Add a chip to this group.
     */
    public function withAppended(Chip $chip): self
    {
        return new self(
            chips: [...$this->chips, $chip],
            gap: $this->gap,
            wrap: $this->wrap,
        );
    }

    /**
     * Set the gap between chips.
     */
    public function withGap(int $gap): self
    {
        return new self(
            chips: $this->chips,
            gap: max(0, $gap),
            wrap: $this->wrap,
        );
    }

    /**
     * Set the wrap behavior.
     */
    public function withWrap(bool $wrap): self
    {
        return new self(
            chips: $this->chips,
            gap: $this->gap,
            wrap: $wrap,
        );
    }
}
