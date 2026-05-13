<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * ASCII art banner generator.
 *
 * Features:
 * - Multiple banner styles (classic, double, rounded, block, star, dash)
 * - Customizable border and text colors
 * - Title and subtitle support
 * - Customizable width
 * - Top and bottom decorative borders
 *
 * Mirrors ASCII banner/figlet patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class ASCIIBanner implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $title,
        private readonly ?string $subtitle = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $textColor = null,
        private readonly string $style = 'classic',
        private readonly int $padding = 1,
    ) {}

    /**
     * Create a new ASCII banner.
     */
    public static function new(string $title): self
    {
        return new self(
            title: $title,
            subtitle: null,
            borderColor: Color::hex('#874BFD'),
            textColor: Color::hex('#FFFFFF'),
            style: 'classic',
            padding: 1,
        );
    }

    /**
     * Create a banner with a title and subtitle.
     */
    public static function withTitleAndSubtitle(string $title, string $subtitle): self
    {
        return new self(
            title: $title,
            subtitle: $subtitle,
            borderColor: Color::hex('#874BFD'),
            textColor: Color::hex('#FFFFFF'),
            style: 'classic',
            padding: 1,
        );
    }

    /**
     * Create a classic ASCII banner.
     */
    public static function classic(string $title): self
    {
        return self::new($title)->withStyle('classic');
    }

    /**
     * Create a double-line banner.
     */
    public static function double(string $title): self
    {
        return self::new($title)->withStyle('double');
    }

    /**
     * Create a block-style banner.
     */
    public static function block(string $title): self
    {
        return self::new($title)->withStyle('block');
    }

    /**
     * Create a star-decorated banner.
     */
    public static function stars(string $title): self
    {
        return self::new($title)->withStyle('stars');
    }

    /**
     * Set the allocated dimensions for this banner.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style definition.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string, 6:string} top-left, top-right, bottom-left, bottom-right, horizontal, vertical, char
     */
    private function getStyleDefinition(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║', '═'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│', '─'],
            'block' => ['┏', '┓', '┗', '┛', '━', '┃', '━'],
            'stars' => ['*', '*', '*', '*', '*', '*', '*'],
            'dash' => ['+', '+', '+', '+', '-', '+', '-'],
            'classic' => ['+', '+', '+', '+', '-', '+', '-'],
            'thick' => ['█', '█', '█', '█', '█', ' ', '█'],
            default => ['+', '+', '+', '+', '-', '+', '-'],
        };
    }

    /**
     * Render the banner.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, mb_strlen($this->title, 'UTF-8') + 6);

        [
            $tl, $tr, $bl, $br, $h, $v, $char
        ] = $this->getStyleDefinition();

        $innerWidth = $useWidth - 2;
        $result = '';

        // Apply colors
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border
        $result .= $tl . str_repeat($h, $innerWidth) . $tr . "\n";

        // Title padding area
        for ($p = 0; $p < $this->padding; $p++) {
            $result .= $v . str_repeat(' ', $innerWidth) . $v . "\n";
        }

        // Title line
        if ($this->textColor !== null) {
            $result .= $v;
            $result .= str_repeat(' ', max(0, (int) (($innerWidth - mb_strlen($this->title, 'UTF-8')) / 2)));
            $result .= $this->textColor->toFg(ColorProfile::TrueColor);
            $result .= $this->title;
            $result .= Ansi::reset();
            if ($this->borderColor !== null) {
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $remaining = $innerWidth - mb_strlen($this->title, 'UTF-8') - (int) (($innerWidth - mb_strlen($this->title, 'UTF-8')) / 2);
            $result .= str_repeat(' ', max(0, $remaining));
            $result .= $v . "\n";
        } else {
            $titlePad = max(0, (int) (($innerWidth - mb_strlen($this->title, 'UTF-8')) / 2));
            $result .= $v . str_repeat(' ', $titlePad) . $this->title . str_repeat(' ', max(0, $innerWidth - $titlePad - mb_strlen($this->title, 'UTF-8'))) . $v . "\n";
        }

        // Subtitle line
        if ($this->subtitle !== null) {
            $subtitlePad = max(0, (int) (($innerWidth - mb_strlen($this->subtitle, 'UTF-8')) / 2));
            $result .= $v . str_repeat(' ', $subtitlePad) . $this->subtitle . str_repeat(' ', max(0, $innerWidth - $subtitlePad - mb_strlen($this->subtitle, 'UTF-8'))) . $v . "\n";
        }

        // Bottom padding area
        for ($p = 0; $p < $this->padding; $p++) {
            $result .= $v . str_repeat(' ', $innerWidth) . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $innerWidth) . $br;

        // Reset ANSI
        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural width based on title and subtitle.
     */
    private function calculateNaturalWidth(): int
    {
        $maxLen = mb_strlen($this->title, 'UTF-8');

        if ($this->subtitle !== null) {
            $maxLen = max($maxLen, mb_strlen($this->subtitle, 'UTF-8'));
        }

        return $maxLen + 6; // padding + borders
    }

    /**
     * Calculate the natural dimensions.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();

        $rows = 1; // top border
        $rows += $this->padding; // top padding
        $rows += 1; // title
        if ($this->subtitle !== null) {
            $rows += 1; // subtitle
        }
        $rows += $this->padding; // bottom padding
        $rows += 1; // bottom border

        return [$useWidth, $rows];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the subtitle.
     */
    public function withSubtitle(?string $subtitle): self
    {
        return new self(
            title: $this->title,
            subtitle: $subtitle,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $color,
            textColor: $this->textColor,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            textColor: $color,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the banner style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            style: $style,
            padding: $this->padding,
        );
    }

    /**
     * Set the padding.
     */
    public function withPadding(int $padding): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            style: $this->style,
            padding: max(0, $padding),
        );
    }
}
