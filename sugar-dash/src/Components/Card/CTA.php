<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A call-to-action button component.
 *
 * Displays a prominent CTA button with label, optional description,
 * and customizable styling for urgency/importance.
 *
 * Mirrors CTA button concepts adapted to PHP with wither-style immutable setters.
 */
final class CTA implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $label = 'Get Started',
        private readonly string $description = '',
        private readonly ?Color $bgColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $borderChar = '═',
        private readonly string $style = 'rounded',
        private readonly bool $showArrow = true,
    ) {}

    /**
     * Create a new CTA button with default styling.
     */
    public static function new(string $label, string $description = ''): self
    {
        return new self(
            label: $label,
            description: $description,
            bgColor: Color::hex('#7C3AED'),
            textColor: Color::hex('#FFFFFF'),
            borderColor: Color::hex('#6D28D9'),
            borderChar: '═',
            style: 'rounded',
            showArrow: true,
        );
    }

    /**
     * Create a primary CTA button.
     */
    public static function primary(string $label, string $description = ''): self
    {
        return self::new($label, $description)
            ->withBgColor(Color::hex('#2563EB'))
            ->withBorderColor(Color::hex('#1D4ED8'));
    }

    /**
     * Create a success CTA button.
     */
    public static function success(string $label, string $description = ''): self
    {
        return self::new($label, $description)
            ->withBgColor(Color::hex('#059669'))
            ->withBorderColor(Color::hex('#047857'));
    }

    /**
     * Create a danger/warning CTA button.
     */
    public static function danger(string $label, string $description = ''): self
    {
        return self::new($label, $description)
            ->withBgColor(Color::hex('#DC2626'))
            ->withBorderColor(Color::hex('#B91C1C'));
    }

    /**
     * Create an outline style CTA button.
     */
    public static function outline(string $label, string $description = ''): self
    {
        return new self(
            label: $label,
            description: $description,
            bgColor: null,
            textColor: Color::hex('#FAFAFA'),
            borderColor: Color::hex('#3F3F46'),
            borderChar: '─',
            style: 'square',
            showArrow: true,
        );
    }

    /**
     * Set the allocated dimensions for this CTA.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the CTA as a string.
     */
    public function render(): string
    {
        $useWidth = $this->getWidth();
        $lines = [];

        $labelWidth = Width::string($this->label);
        $contentWidth = $labelWidth + ($this->showArrow ? 4 : 0);
        $buttonContent = $this->label . ($this->showArrow ? ' →' : '');

        if ($this->description !== '') {
            $descWidth = Width::string($this->description);
            $contentWidth = max($contentWidth, $descWidth);
        }

        $totalWidth = max($useWidth, $contentWidth + 4); // +4 for padding

        // Top border
        $topBorder = '';
        if ($this->borderColor !== null) {
            $topBorder .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $topBorder .= str_repeat($this->borderChar, $totalWidth);
        $topBorder .= Ansi::reset();
        $lines[] = $topBorder;

        // Description line (if present)
        if ($this->description !== '') {
            $descLine = '';
            if ($this->textColor !== null) {
                $descLine .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $descLine .= str_pad($this->description, $totalWidth);
            $descLine .= Ansi::reset();
            $lines[] = $descLine;
        }

        // Button line
        $buttonLine = '';
        if ($this->bgColor !== null) {
            $buttonLine .= $this->bgColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->textColor !== null) {
            $buttonLine .= $this->textColor->toFg(ColorProfile::TrueColor);
        }
        $buttonLine .= ' ';
        $buttonLine .= str_pad($buttonContent, $totalWidth - 2);
        $buttonLine .= ' ';
        $buttonLine .= Ansi::reset();
        $lines[] = $buttonLine;

        // Bottom border
        $bottomBorder = '';
        if ($this->borderColor !== null) {
            $bottomBorder .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $bottomBorder .= str_repeat($this->borderChar, $totalWidth);
        $bottomBorder .= Ansi::reset();
        $lines[] = $bottomBorder;

        return implode("\n", $lines);
    }

    /**
     * Calculate the natural dimensions of this CTA.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        $labelWidth = Width::string($this->label) + ($this->showArrow ? 4 : 0);
        $contentWidth = $labelWidth;

        if ($this->description !== '') {
            $contentWidth = max($contentWidth, Width::string($this->description));
        }

        $height = $this->description !== '' ? 4 : 3; // borders + content (+ description)

        return [max($width, $contentWidth + 4), $height];
    }

    /**
     * Get the width to use for this CTA.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        $labelWidth = Width::string($this->label) + ($this->showArrow ? 4 : 0);
        $contentWidth = $labelWidth;

        if ($this->description !== '') {
            $contentWidth = max($contentWidth, Width::string($this->description));
        }

        return $contentWidth + 4;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the button label.
     */
    public function withLabel(string $label): self
    {
        return new self(
            label: $label,
            description: $this->description,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            style: $this->style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Set the description text.
     */
    public function withDescription(string $description): self
    {
        return new self(
            label: $this->label,
            description: $description,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            style: $this->style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            description: $this->description,
            bgColor: $color,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            style: $this->style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            description: $this->description,
            bgColor: $this->bgColor,
            textColor: $color,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            style: $this->style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            description: $this->description,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            borderColor: $color,
            borderChar: $this->borderChar,
            style: $this->style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Set the border character.
     */
    public function withBorderChar(string $char): self
    {
        return new self(
            label: $this->label,
            description: $this->description,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            borderChar: $char,
            style: $this->style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Set the button style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            label: $this->label,
            description: $this->description,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            style: $style,
            showArrow: $this->showArrow,
        );
    }

    /**
     * Show or hide the arrow.
     */
    public function withShowArrow(bool $show): self
    {
        return new self(
            label: $this->label,
            description: $this->description,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            style: $this->style,
            showArrow: $show,
        );
    }
}
