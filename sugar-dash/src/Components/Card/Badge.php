<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Rect;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A badge/tag component for displaying labels and statuses.
 *
 * Features:
 * - Compact badge display with text
 * - Multiple styles (solid, outline, subtle)
 * - Different sizes (sm, md, lg)
 * - Customizable colors
 * - Optional icon prefix
 * - Theme-aware: withTheme() uses the theme's primary color
 *
 * Mirrors badge/tag patterns adapted to PHP with wither-style immutable setters.
 */
final class Badge implements \SugarCraft\Dash\Foundation\Sizer, Drawable
{
    private ?int $width = null;
    private ?int $height = null;

    public const StyleSolid = 'solid';
    public const StyleOutline = 'outline';
    public const StyleSubtle = 'subtle';

    public const SizeSm = 'sm';
    public const SizeMd = 'md';
    public const SizeLg = 'lg';

    public function __construct(
        private readonly string $text,
        private readonly ?Color $bgColor = null,
        private readonly ?Color $textColor = null,
        private readonly string $style = self::StyleSolid,
        private readonly string $size = self::SizeMd,
        private readonly ?string $icon = null,
    ) {}

    /**
     * Create a new badge.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            bgColor: Color::hex('#313244'),
            textColor: Color::hex('#CDD6F4'),
            style: self::StyleSolid,
            size: self::SizeMd,
            icon: null,
        );
    }

    /**
     * Create a success badge.
     */
    public static function success(string $text): self
    {
        return new self(
            text: $text,
            bgColor: Color::hex('#A6E3A1'),
            textColor: Color::hex('#1E1E1E'),
            style: self::StyleSolid,
            size: self::SizeMd,
            icon: '✓',
        );
    }

    /**
     * Create a warning badge.
     */
    public static function warning(string $text): self
    {
        return new self(
            text: $text,
            bgColor: Color::hex('#F9E2AF'),
            textColor: Color::hex('#1E1E1E'),
            style: self::StyleSolid,
            size: self::SizeMd,
            icon: '⚠',
        );
    }

    /**
     * Create an error badge.
     */
    public static function error(string $text): self
    {
        return new self(
            text: $text,
            bgColor: Color::hex('#F38BA8'),
            textColor: Color::hex('#1E1E1E'),
            style: self::StyleSolid,
            size: self::SizeMd,
            icon: '✗',
        );
    }

    /**
     * Create a danger badge.
     */
    public static function danger(string $text): self
    {
        return new self(
            text: $text,
            bgColor: Color::hex('#F38BA8'),
            textColor: Color::hex('#1E1E1E'),
            style: self::StyleSolid,
            size: self::SizeMd,
            icon: '!',
        );
    }

    /**
     * Create an info badge.
     */
    public static function info(string $text): self
    {
        return new self(
            text: $text,
            bgColor: Color::hex('#89B4FA'),
            textColor: Color::hex('#1E1E1E'),
            style: self::StyleSolid,
            size: self::SizeMd,
            icon: 'ℹ',
        );
    }

    /**
     * Create a boolean badge: true → success (Yes), false → error (No),
     * null → a subtle "Unknown" badge.
     *
     * Replaces the Yes/No/Unknown tristate text duplicated across the
     * candy-query admin panels (ServerStatusPage, PerfSchema toggles).
     */
    public static function bool(
        ?bool $value,
        string $yes = 'Yes',
        string $no = 'No',
        string $unknown = 'Unknown',
    ): self {
        return match ($value) {
            true => self::success($yes),
            false => self::error($no),
            null => new self(
                text: $unknown,
                bgColor: Color::hex('#313244'),
                textColor: Color::hex('#6C7086'),
                style: self::StyleSubtle,
                size: self::SizeMd,
                icon: null,
            ),
        };
    }

    /**
     * Create a tri-state checkbox glyph badge: true → "[x]" (green),
     * false → "[ ]" (subdued), null → "[~]" (amber). Rendered as the bare
     * glyph (subtle style) so it can sit inline in a toggle list.
     *
     * Replaces the `[x]`/`[ ]`/`[~]` glyph rendering in
     * PerfSchemaPage::renderTristate.
     */
    public static function tristate(?bool $value): self
    {
        [$glyph, $color] = match ($value) {
            true => ['[x]', Color::hex('#A6E3A1')],
            false => ['[ ]', Color::hex('#6C7086')],
            null => ['[~]', Color::hex('#F9E2AF')],
        };

        return new self(
            text: $glyph,
            bgColor: null,
            textColor: $color,
            style: self::StyleSubtle,
            size: self::SizeSm,
            icon: null,
        );
    }

    /**
     * Set the allocated dimensions for this badge.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this badge.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $sizeMap = [
            self::SizeSm => 1,
            self::SizeMd => 1,
            self::SizeLg => 1,
        ];

        $iconLen = $this->icon !== null ? mb_strlen($this->icon, 'UTF-8') + 1 : 0;
        $textLen = mb_strlen($this->text, 'UTF-8');
        $width = $iconLen + $textLen + 4; // padding

        $height = $sizeMap[$this->size] ?? 1;

        return [$width, $height];
    }

    /**
     * Render the badge.
     */
    public function render(): string
    {
        $text = $this->icon !== null ? $this->icon . ' ' . $this->text : $this->text;

        $width = $this->width ?? $this->getInnerSize()[0];

        return match ($this->style) {
            self::StyleOutline => $this->renderOutline($text, $width),
            self::StyleSubtle => $this->renderSubtle($text, $width),
            default => $this->renderSolid($text, $width),
        };
    }

    /**
     * Render solid style badge.
     */
    private function renderSolid(string $text, int $width): string
    {
        $bgStr = $this->bgColor?->toBg(ColorProfile::TrueColor) ?? '';
        $textStr = $this->textColor?->toFg(ColorProfile::TrueColor) ?? '';

        $padded = str_pad($text, $width - 2, ' ', STR_PAD_BOTH);

        return $bgStr . $textStr . '[' . $padded . ']' . Ansi::reset();
    }

    /**
     * Render outline style badge.
     */
    private function renderOutline(string $text, int $width): string
    {
        $borderStr = $this->textColor?->toFg(ColorProfile::TrueColor) ?? '';
        $textStr = $this->textColor?->toFg(ColorProfile::TrueColor) ?? '';

        $padded = str_pad($text, $width - 4, ' ', STR_PAD_BOTH);

        $top = '┌' . str_repeat('─', $width - 2) . '┐';
        $middle = '│' . $padded . '│';
        $bottom = '└' . str_repeat('─', $width - 2) . '┘';

        return $borderStr . $top . "\n" . $middle . "\n" . $bottom . Ansi::reset();
    }

    /**
     * Render subtle style badge.
     */
    private function renderSubtle(string $text, int $width): string
    {
        $textStr = $this->textColor?->toFg(ColorProfile::TrueColor) ?? '';
        $padded = str_pad($text, $width - 2, ' ', STR_PAD_BOTH);

        return $textStr . ' ' . $padded . ' ' . Ansi::reset();
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            bgColor: $color,
            textColor: $this->textColor,
            style: $this->style,
            size: $this->size,
            icon: $this->icon,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            bgColor: $this->bgColor,
            textColor: $color,
            style: $this->style,
            size: $this->size,
            icon: $this->icon,
        );
    }

    /**
     * Set the style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            text: $this->text,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            style: $style,
            size: $this->size,
            icon: $this->icon,
        );
    }

    /**
     * Set the size.
     */
    public function withSize(string $size): self
    {
        return new self(
            text: $this->text,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            style: $this->style,
            size: $size,
            icon: $this->icon,
        );
    }

    /**
     * Set the icon.
     */
    public function withIcon(?string $icon): self
    {
        return new self(
            text: $this->text,
            bgColor: $this->bgColor,
            textColor: $this->textColor,
            style: $this->style,
            size: $this->size,
            icon: $icon,
        );
    }

    /**
     * Apply a theme to this badge, using the theme's primary color.
     */
    public function withTheme(Theme $theme): self
    {
        return new self(
            text: $this->text,
            bgColor: $theme->primary(),
            textColor: $theme->foreground(),
            style: $this->style,
            size: $this->size,
            icon: $this->icon,
        );
    }

    // ─── Drawable implementation ──────────────────────────────────

    public function getRect(): Rect
    {
        [$w, $h] = $this->getInnerSize();
        return new Rect(0, 0, $w - 1, $h - 1);
    }

    public function setRect(Rect $rect): self
    {
        return $this;
    }

    public function draw(Buffer $buffer): void
    {
        // Badges render to strings, not into Buffer grids
    }
}
