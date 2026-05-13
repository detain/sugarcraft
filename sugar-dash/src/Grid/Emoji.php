<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An emoji display component.
 *
 * Features:
 * - Display single emojis or emoji sequences
 * - Size scaling (1-3x)
 * - Color tinting support
 * - Label support
 * - Category-based factory methods
 *
 * Mirrors emoji display concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Emoji implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const SIZE_SMALL = 1;
    public const SIZE_MEDIUM = 2;
    public const SIZE_LARGE = 3;

    public function __construct(
        private readonly string $emoji,
        private readonly ?string $label = null,
        private readonly int $size = self::SIZE_MEDIUM,
        private readonly ?Color $tintColor = null,
        private readonly bool $showLabel = false,
    ) {
        // Clamp size to valid range
        if ($this->size < self::SIZE_SMALL || $this->size > self::SIZE_LARGE) {
            throw new \InvalidArgumentException(
                sprintf('Size must be between %d and %d, %d given', self::SIZE_SMALL, self::SIZE_LARGE, $this->size)
            );
        }
    }

    /**
     * Create a thumbs up emoji.
     */
    public static function thumbsUp(?string $label = null): self
    {
        return new self('👍', $label, self::SIZE_MEDIUM, null, $label !== null);
    }

    /**
     * Create a thumbs down emoji.
     */
    public static function thumbsDown(?string $label = null): self
    {
        return new self('👎', $label, self::SIZE_MEDIUM, null, $label !== null);
    }

    /**
     * Create a clap emoji.
     */
    public static function clap(?string $label = null): self
    {
        return new self('👏', $label, self::SIZE_MEDIUM, null, $label !== null);
    }

    /**
     * Create a fire emoji.
     */
    public static function fire(?string $label = null): self
    {
        return new self('🔥', $label, self::SIZE_MEDIUM, Color::ansi(9), $label !== null);
    }

    /**
     * Create a rocket emoji.
     */
    public static function rocket(?string $label = null): self
    {
        return new self('🚀', $label, self::SIZE_MEDIUM, Color::ansi(13), $label !== null);
    }

    /**
     * Create a star emoji.
     */
    public static function star(?string $label = null): self
    {
        return new self('⭐', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a sparkle emoji.
     */
    public static function sparkle(?string $label = null): self
    {
        return new self('✨', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a check mark emoji.
     */
    public static function check(?string $label = null): self
    {
        return new self('✅', $label, self::SIZE_MEDIUM, Color::ansi(2), $label !== null);
    }

    /**
     * Create an X mark emoji.
     */
    public static function x(?string $label = null): self
    {
        return new self('❌', $label, self::SIZE_MEDIUM, Color::ansi(1), $label !== null);
    }

    /**
     * Create a warning emoji.
     */
    public static function warning(?string $label = null): self
    {
        return new self('⚠️', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a info emoji.
     */
    public static function info(?string $label = null): self
    {
        return new self('ℹ️', $label, self::SIZE_MEDIUM, Color::ansi(4), $label !== null);
    }

    /**
     * Create a question mark emoji.
     */
    public static function question(?string $label = null): self
    {
        return new self('❓', $label, self::SIZE_MEDIUM, Color::ansi(4), $label !== null);
    }

    /**
     * Create an exclamation emoji.
     */
    public static function exclamation(?string $label = null): self
    {
        return new self('❗', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a light bulb emoji.
     */
    public static function lightbulb(?string $label = null): self
    {
        return new self('💡', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a trophy emoji.
     */
    public static function trophy(?string $label = null): self
    {
        return new self('🏆', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a medal emoji.
     */
    public static function medal(?string $label = null): self
    {
        return new self('🎖️', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a smiley face emoji.
     */
    public static function smile(?string $label = null): self
    {
        return new self('😊', $label, self::SIZE_MEDIUM, Color::ansi(11), $label !== null);
    }

    /**
     * Create a sad face emoji.
     */
    public static function sad(?string $label = null): self
    {
        return new self('😢', $label, self::SIZE_MEDIUM, Color::ansi(9), $label !== null);
    }

    /**
     * Create a heart emoji.
     */
    public static function heart(?string $label = null): self
    {
        return new self('❤️', $label, self::SIZE_MEDIUM, Color::ansi(1), $label !== null);
    }

    /**
     * Create a broken heart emoji.
     */
    public static function brokenHeart(?string $label = null): self
    {
        return new self('💔', $label, self::SIZE_MEDIUM, Color::ansi(1), $label !== null);
    }

    /**
     * Set the allocated dimensions for this emoji.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the emoji as a string.
     */
    public function render(): string
    {
        $output = '';

        if ($this->tintColor !== null) {
            $output .= $this->tintColor->toFg(ColorProfile::TrueColor);
        }

        // Render emoji with size scaling
        $output .= str_repeat($this->emoji, $this->size);

        // Optionally show label
        if ($this->showLabel && $this->label !== null) {
            $output .= ' ' . $this->label;
        }

        if ($this->tintColor !== null || $this->showLabel) {
            $output .= Ansi::reset();
        }

        return $output;
    }

    /**
     * Calculate the natural dimensions of this emoji.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $emojiWidth = Width::string($this->emoji) * $this->size;
        $labelWidth = ($this->label !== null && $this->showLabel) ? Width::string($this->label) + 1 : 0;
        $width = $emojiWidth + $labelWidth;
        $height = 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the emoji.
     */
    public function withEmoji(string $emoji): self
    {
        return new self(
            emoji: $emoji,
            label: $this->label,
            size: $this->size,
            tintColor: $this->tintColor,
            showLabel: $this->showLabel,
        );
    }

    /**
     * Set the label.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            emoji: $this->emoji,
            label: $label,
            size: $this->size,
            tintColor: $this->tintColor,
            showLabel: $this->showLabel,
        );
    }

    /**
     * Set the size.
     */
    public function withSize(int $size): self
    {
        return new self(
            emoji: $this->emoji,
            label: $this->label,
            size: match ($size) {
                self::SIZE_SMALL, self::SIZE_MEDIUM, self::SIZE_LARGE => $size,
                default => self::SIZE_MEDIUM,
            },
            tintColor: $this->tintColor,
            showLabel: $this->showLabel,
        );
    }

    /**
     * Set the tint color.
     */
    public function withTintColor(?Color $color): self
    {
        return new self(
            emoji: $this->emoji,
            label: $this->label,
            size: $this->size,
            tintColor: $color,
            showLabel: $this->showLabel,
        );
    }

    /**
     * Set whether to show the label.
     */
    public function withShowLabel(bool $show): self
    {
        return new self(
            emoji: $this->emoji,
            label: $this->label,
            size: $this->size,
            tintColor: $this->tintColor,
            showLabel: $show,
        );
    }
}
