<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A keyboard key display component.
 *
 * Features:
 * - Renders key names in a visually distinct keycap style
 * - Supports single keys, modifier combos, and sequences
 * - Customizable colors and border style
 * - Optional shadow effect
 *
 * Mirrors keyboard key display patterns adapted to PHP with wither-style
 * immutable setters.
 */
final class Kbd implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly array $keys,
        private readonly ?Color $bgColor = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $textColor = null,
        private readonly bool $showShadow = true,
        private readonly bool $rounded = true,
    ) {}

    /**
     * Create a new key display.
     *
     * @param list<string> $keys Key names like ['Ctrl', 'C'], ['Enter'], ['Ctrl', 'Shift', 'Esc']
     */
    public static function new(array $keys): self
    {
        return new self(
            keys: $keys,
            bgColor: Color::hex('#313244'),
            borderColor: Color::hex('#6C7086'),
            textColor: Color::hex('#CDD6F4'),
            showShadow: true,
            rounded: true,
        );
    }

    /**
     * Create a single key display.
     */
    public static function single(string $key): self
    {
        return self::new([$key]);
    }

    /**
     * Create a key combination display.
     */
    public static function combo(string ...$keys): self
    {
        return self::new($keys);
    }

    /**
     * Set the allocated dimensions for this key display.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this key display.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $keyWidth = 0;
        foreach ($this->keys as $key) {
            $keyWidth += mb_strlen($key, 'UTF-8') + 4; // padding
        }
        $keyWidth += (count($this->keys) - 1) * 2; // gaps between keys

        $height = 3; // top border, content, bottom border

        return [$keyWidth, $height];
    }

    /**
     * Render the key display.
     */
    public function render(): string
    {
        if ($this->keys === []) {
            return '';
        }

        $useWidth = $this->width ?? $this->getInnerSize()[0];

        // Build key strings
        $keyParts = [];
        foreach ($this->keys as $key) {
            $keyParts[] = $this->renderSingleKey($key);
        }

        // Join keys with separator
        $separator = ' + ';
        $content = implode($separator, $keyParts);

        // Apply colors to entire content if set
        if ($this->textColor !== null) {
            // Re-render without individual coloring for joined effect
            $plainContent = implode($separator, $this->keys);
            $width = $useWidth - 2; // account for borders
            $paddedContent = str_pad($plainContent, $width, ' ', STR_PAD_BOTH);

            $bgStr = $this->bgColor?->toBg(ColorProfile::TrueColor) ?? '';
            $borderStr = $borderChar = $this->rounded ? '╭╮╰╯' : '┌┐└┘';
            $top = ($this->rounded ? '╭' : '┌') . str_repeat($this->rounded ? '─' : '─', $width) . ($this->rounded ? '╮' : '┐');
            $bottom = ($this->rounded ? '╰' : '└') . str_repeat($this->rounded ? '─' : '─', $width) . ($this->rounded ? '╯' : '┘');

            return $bgStr . $top . "\n" . str_repeat(' ', $width) . "\n" . $bottom . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render a single key.
     */
    private function renderSingleKey(string $key): string
    {
        $keyLen = mb_strlen($key, 'UTF-8');
        $padding = 2;
        $innerWidth = $keyLen + (2 * $padding);

        $topLeft = $this->rounded ? '╭' : '┌';
        $topRight = $this->rounded ? '╮' : '┐';
        $bottomLeft = $this->rounded ? '╰' : '└';
        $bottomRight = $this->rounded ? '╯' : '┘';
        $hChar = $this->rounded ? '─' : '─';
        $vChar = '│';

        $top = $topLeft . str_repeat($hChar, $innerWidth) . $topRight;
        $bottom = $bottomLeft . str_repeat($hChar, $innerWidth) . $bottomRight;
        $middle = $vChar . str_repeat(' ', $padding) . $key . str_repeat(' ', $padding) . $vChar;

        $result = '';

        // Apply colors
        if ($this->bgColor !== null) {
            $result .= $this->bgColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->textColor !== null) {
            $result .= $this->textColor->toFg(ColorProfile::TrueColor);
        }

        // Shadow
        if ($this->showShadow) {
            $result .= $top . "\n";
            $result .= $middle . ' ';
            $result .= "\n" . str_repeat(' ', $innerWidth + 2) . ' '; // shadow
            $result .= $bottom;
        } else {
            $result .= $top . "\n";
            $result .= $middle;
            $result .= "\n" . $bottom;
        }

        $result .= Ansi::reset();

        return $result;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            keys: $this->keys,
            bgColor: $color,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            showShadow: $this->showShadow,
            rounded: $this->rounded,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            keys: $this->keys,
            bgColor: $this->bgColor,
            borderColor: $color,
            textColor: $this->textColor,
            showShadow: $this->showShadow,
            rounded: $this->rounded,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            keys: $this->keys,
            bgColor: $this->bgColor,
            borderColor: $this->borderColor,
            textColor: $color,
            showShadow: $this->showShadow,
            rounded: $this->rounded,
        );
    }

    /**
     * Show or hide shadow.
     */
    public function withShowShadow(bool $show): self
    {
        return new self(
            keys: $this->keys,
            bgColor: $this->bgColor,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            showShadow: $show,
            rounded: $this->rounded,
        );
    }

    /**
     * Set rounded corners.
     */
    public function withRounded(bool $rounded): self
    {
        return new self(
            keys: $this->keys,
            bgColor: $this->bgColor,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            showShadow: $this->showShadow,
            rounded: $rounded,
        );
    }
}
