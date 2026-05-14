<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Form;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A star rating component.
 *
 * Features:
 * - Display-only rating display
 * - Configurable star characters
 * - Partial star support (display only)
 * - Custom colors for filled/empty stars
 *
 * Mirrors rating UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Rating implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly int $maxStars = 5,
        private readonly float $value = 0.0,
        private readonly ?Color $filledColor = null,
        private readonly ?Color $emptyColor = null,
        private readonly string $filledChar = '★',
        private readonly string $emptyChar = '☆',
    ) {}

    /**
     * Create a new rating with default styling.
     */
    public static function new(int $maxStars = 5, float $value = 0.0): self
    {
        return new self(
            maxStars: $maxStars,
            value: $value,
            filledColor: Color::hex('#F59E0B'),
            emptyColor: Color::hex('#D1D5DB'),
            filledChar: '★',
            emptyChar: '☆',
        );
    }

    /**
     * Create a rating with a specific value.
     */
    public static function of(float $value, int $maxStars = 5): self
    {
        return self::new($maxStars, $value);
    }

    /**
     * Set the allocated dimensions for this rating.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the rating as a string.
     */
    public function render(): string
    {
        $safeValue = max(0.0, min($this->value, (float) $this->maxStars));

        $result = '';

        if ($this->filledColor !== null) {
            $result .= $this->filledColor->toFg(ColorProfile::TrueColor);
        }

        $fullStars = (int) $safeValue;
        $hasPartial = $safeValue - $fullStars > 0.001;

        // Render filled stars
        $result .= str_repeat($this->filledChar, $fullStars);

        // Render partial star if needed
        if ($hasPartial && $fullStars < $this->maxStars) {
            // Use half-fill character or just show empty
            $result .= $this->emptyChar;
        }

        // Render remaining empty stars
        $remainingEmpty = $this->maxStars - $fullStars - ($hasPartial ? 1 : 0);
        if ($this->emptyColor !== null && $remainingEmpty > 0) {
            $result .= Ansi::reset();
            $result .= $this->emptyColor->toFg(ColorProfile::TrueColor);
            $result .= str_repeat($this->emptyChar, $remainingEmpty);
        } else {
            $result .= str_repeat($this->emptyChar, $remainingEmpty);
        }

        // Reset ANSI
        if ($this->filledColor !== null || $this->emptyColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this rating.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->maxStars * max(
            Width::string($this->filledChar),
            Width::string($this->emptyChar)
        );

        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the maximum number of stars.
     */
    public function withMaxStars(int $maxStars): self
    {
        return new self(
            maxStars: max(1, $maxStars),
            value: min($this->value, (float) max(1, $maxStars)),
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the rating value.
     */
    public function withValue(float $value): self
    {
        return new self(
            maxStars: $this->maxStars,
            value: max(0.0, min($value, (float) $this->maxStars)),
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the filled star color.
     */
    public function withFilledColor(?Color $color): self
    {
        return new self(
            maxStars: $this->maxStars,
            value: $this->value,
            filledColor: $color,
            emptyColor: $this->emptyColor,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set the empty star color.
     */
    public function withEmptyColor(?Color $color): self
    {
        return new self(
            maxStars: $this->maxStars,
            value: $this->value,
            filledColor: $this->filledColor,
            emptyColor: $color,
            filledChar: $this->filledChar,
            emptyChar: $this->emptyChar,
        );
    }

    /**
     * Set custom star characters.
     */
    public function withChars(string $filled, string $empty): self
    {
        return new self(
            maxStars: $this->maxStars,
            value: $this->value,
            filledColor: $this->filledColor,
            emptyColor: $this->emptyColor,
            filledChar: $filled,
            emptyChar: $empty,
        );
    }
}
