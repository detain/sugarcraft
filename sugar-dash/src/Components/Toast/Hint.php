<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A hint / caption text component.
 *
 * Features:
 * - Display supplementary text information
 * - Multiple styles (normal, muted, italic, bold)
 * - Customizable color
 * - Optional icon prefix
 * - Wraps long text within allocated width
 *
 * Mirrors hint/caption UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Hint implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $text,
        private readonly ?Color $color = null,
        private readonly string $style = 'normal',
        private readonly string $icon = '',
    ) {}

    /**
     * Create a new hint with default styling.
     *
     * Default: muted gray text.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#6B7280'),
            style: 'normal',
            icon: '',
        );
    }

    /**
     * Create a muted/secondary hint.
     */
    public static function muted(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#9CA3AF'),
            style: 'normal',
            icon: '',
        );
    }

    /**
     * Create an italic hint.
     */
    public static function italic(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#6B7280'),
            style: 'italic',
            icon: '',
        );
    }

    /**
     * Create an info-style hint.
     */
    public static function info(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#3B82F6'),
            style: 'normal',
            icon: 'ℹ',
        );
    }

    /**
     * Create a success-style hint.
     */
    public static function success(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#22C55E'),
            style: 'normal',
            icon: '✓',
        );
    }

    /**
     * Create a warning-style hint.
     */
    public static function warning(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#F59E0B'),
            style: 'normal',
            icon: '⚠',
        );
    }

    /**
     * Create a danger-style hint.
     */
    public static function danger(string $text): self
    {
        return new self(
            text: $text,
            color: Color::hex('#EF4444'),
            style: 'normal',
            icon: '✖',
        );
    }

    /**
     * Set the allocated dimensions for this hint.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the hint as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 80;

        $content = $this->icon !== '' ? $this->icon . ' ' . $this->text : $this->text;

        // Apply styling
        $styleCode = match ($this->style) {
            'italic' => Ansi::ITALIC,
            'bold' => Ansi::BOLD,
            'underline' => Ansi::UNDERLINE,
            default => null,
        };
        if ($styleCode !== null) {
            $styledContent = Ansi::sgr($styleCode) . $content . Ansi::reset();
        } else {
            $styledContent = $content;
        }

        // Apply color
        $result = '';
        if ($this->color !== null) {
            $result .= $this->color->toFg(ColorProfile::TrueColor);
        }

        // Word wrap if needed
        if ($useWidth > 0 && Width::string($content) > $useWidth) {
            $wrapped = $this->wrapText($content, $useWidth);
            $result .= implode("\n", $wrapped);
        } else {
            $result .= $styledContent;
        }

        // Reset ANSI
        if ($this->color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Wrap text to fit within a given width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        if ($text === '') {
            return [''];
        }

        $result = [];
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                if ($currentLine !== '') {
                    $currentLine .= ' ';
                    $currentWidth++;
                }
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $result[] = $currentLine;
        }

        return $result === [] ? [''] : $result;
    }

    /**
     * Calculate the natural dimensions of this hint.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $content = $this->icon !== '' ? $this->icon . ' ' . $this->text : $this->text;
        $useWidth = $this->width ?? 80;

        if ($useWidth > 0 && Width::string($content) > $useWidth) {
            $wrapped = $this->wrapText($content, $useWidth);
            return [$useWidth, count($wrapped)];
        }

        return [Width::string($content), 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the hint text.
     */
    public function withText(string $text): self
    {
        return new self(
            text: $text,
            color: $this->color,
            style: $this->style,
            icon: $this->icon,
        );
    }

    /**
     * Set the hint color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            color: $color,
            style: $this->style,
            icon: $this->icon,
        );
    }

    /**
     * Set the hint style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            text: $this->text,
            color: $this->color,
            style: $style,
            icon: $this->icon,
        );
    }

    /**
     * Set the icon prefix.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            text: $this->text,
            color: $this->color,
            style: $this->style,
            icon: $icon,
        );
    }
}