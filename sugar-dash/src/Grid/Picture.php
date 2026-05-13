<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A picture display component with fallback support.
 *
 * Features:
 * - Display image URL with visual representation
 * - Fallback to initials, icon, or emoji when image unavailable
 * - Aspect ratio control
 * - Caption support
 * - Loading and error states
 *
 * Combines Image and Avatar concepts to provide a full-featured
 * picture component with rich fallback options.
 *
 * Mirrors picture display concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Picture implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const FALLBACK_INITIALS = 'initials';
    public const FALLBACK_ICON = 'icon';
    public const FALLBACK_EMOJI = 'emoji';
    public const FALLBACK_PLACEHOLDER = 'placeholder';

    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $alt = null,
        private readonly ?string $caption = null,
        private readonly int $widthHint = 20,
        private readonly int $heightHint = 10,
        private readonly string $fallbackType = self::FALLBACK_PLACEHOLDER,
        private readonly ?string $fallbackValue = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $captionColor = null,
    ) {}

    /**
     * Create a picture with an image URL.
     */
    public static function fromUrl(string $url, ?string $alt = null): self
    {
        return new self(
            url: $url,
            alt: $alt,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: self::FALLBACK_PLACEHOLDER,
            fallbackValue: null,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#1E1E2E'),
            captionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create a picture with initials fallback (like Avatar).
     */
    public static function withInitials(string $name, ?string $url = null): self
    {
        return new self(
            url: $url,
            alt: $name,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: self::FALLBACK_INITIALS,
            fallbackValue: $name,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#3B82F6'),
            captionColor: Color::hex('#FFFFFF'),
        );
    }

    /**
     * Create a picture with icon fallback.
     */
    public static function withIcon(string $icon, ?string $url = null): self
    {
        return new self(
            url: $url,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: self::FALLBACK_ICON,
            fallbackValue: $icon,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#313244'),
            captionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create a picture with emoji fallback.
     */
    public static function withEmoji(string $emoji, ?string $url = null): self
    {
        return new self(
            url: $url,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: self::FALLBACK_EMOJI,
            fallbackValue: $emoji,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#313244'),
            captionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create a placeholder picture (no image URL).
     */
    public static function placeholder(): self
    {
        return new self(
            url: null,
            alt: null,
            caption: null,
            widthHint: 20,
            heightHint: 10,
            fallbackType: self::FALLBACK_PLACEHOLDER,
            fallbackValue: null,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#313244'),
            captionColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Set the allocated dimensions for this picture.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the picture as a string.
     */
    public function render(): string
    {
        $w = $this->width ?? $this->widthHint;
        $h = $this->height ?? $this->heightHint;

        if ($w <= 0 || $h <= 0) {
            return '';
        }

        return $this->renderPicture($w, $h);
    }

    /**
     * Render the picture with borders and optional caption.
     */
    private function renderPicture(int $w, int $h): string
    {
        $output = '';

        // Determine if we have an image or need fallback
        $hasImage = $this->url !== null;

        // Apply background
        if ($this->backgroundColor !== null) {
            $output .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // Top border
        $borderLine = str_repeat('─', $w);
        if ($this->borderColor !== null) {
            $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $output .= '┌' . $borderLine . '┐' . "\n";

        // Content area (h - 3 to account for top, middle, bottom rows with caption)
        $contentHeight = $this->caption !== null ? $h - 2 : $h - 1;

        for ($row = 0; $row < $contentHeight; $row++) {
            if ($this->borderColor !== null) {
                $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $output .= '│';

            $content = $this->renderContentRow($w, $row, $contentHeight);
            $output .= $content;

            if ($this->borderColor !== null) {
                $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $output .= '│' . "\n";
        }

        // Caption row if present
        if ($this->caption !== null) {
            if ($this->borderColor !== null) {
                $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $output .= '│';

            $captionText = ' ' . $this->caption . ' ';
            $captionWidth = Width::string($captionText);
            $padding = $w - $captionWidth;
            if ($padding < 0) {
                $captionText = mb_substr($captionText, 0, $w - 1, 'UTF-8') . '…';
                $captionWidth = Width::string($captionText);
                $padding = max(0, $w - $captionWidth);
            }
            $leftPad = (int) floor($padding / 2);

            if ($this->captionColor !== null) {
                $output .= $this->captionColor->toFg(ColorProfile::TrueColor);
            }
            $output .= str_repeat(' ', $leftPad) . $captionText;
            $output .= str_repeat(' ', $padding - $leftPad);

            if ($this->borderColor !== null) {
                $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $output .= '│' . "\n";
        }

        // Bottom border
        if ($this->borderColor !== null) {
            $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $output .= '└' . $borderLine . '┘';

        // Reset colors
        if ($this->backgroundColor !== null || $this->captionColor !== null) {
            $output .= Ansi::reset();
        }

        return rtrim($output, "\n");
    }

    /**
     * Render a single content row.
     */
    private function renderContentRow(int $width, int $row, int $totalRows): string
    {
        if ($this->url !== null) {
            return $this->renderImageRow($width, $row, $totalRows);
        }

        return $this->renderFallbackRow($width, $row, $totalRows);
    }

    /**
     * Render an image placeholder row.
     */
    private function renderImageRow(int $width, int $row, int $totalRows): string
    {
        // Create a visual pattern for the image
        $pattern = ['▓', '░', '▒', '▓', '░', '▒'];
        $line = '';

        // Vary the pattern based on row position
        $offset = $row % count($pattern);

        for ($col = 0; $col < $width; $col++) {
            $line .= $pattern[($col + $offset) % count($pattern)];
        }

        return $line;
    }

    /**
     * Render a fallback row based on fallback type.
     */
    private function renderFallbackRow(int $width, int $row, int $totalRows): string
    {
        return match ($this->fallbackType) {
            self::FALLBACK_INITIALS => $this->renderInitialsRow($width, $row, $totalRows),
            self::FALLBACK_ICON => $this->renderIconRow($width, $row, $totalRows),
            self::FALLBACK_EMOJI => $this->renderEmojiRow($width, $row, $totalRows),
            default => $this->renderPlaceholderRow($width, $row, $totalRows),
        };
    }

    /**
     * Render initials fallback row.
     */
    private function renderInitialsRow(int $width, int $row, int $totalRows): string
    {
        $initials = $this->getInitials();
        $initialsWidth = Width::string($initials);

        // Center the initials vertically and horizontally
        if ($row === (int) floor($totalRows / 2)) {
            $padding = max(0, (int) floor(($width - $initialsWidth) / 2));
            return str_repeat(' ', $padding) . $initials . str_repeat(' ', max(0, $width - $padding - $initialsWidth));
        }

        return str_repeat(' ', $width);
    }

    /**
     * Render icon fallback row.
     */
    private function renderIconRow(int $width, int $row, int $totalRows): string
    {
        $icon = $this->fallbackValue ?? '📷';
        $iconWidth = Width::string($icon);

        // Center the icon
        if ($row === (int) floor($totalRows / 2)) {
            $padding = max(0, (int) floor(($width - $iconWidth) / 2));
            return str_repeat(' ', $padding) . $icon . str_repeat(' ', max(0, $width - $padding - $iconWidth));
        }

        return str_repeat(' ', $width);
    }

    /**
     * Render emoji fallback row.
     */
    private function renderEmojiRow(int $width, int $row, int $totalRows): string
    {
        $emoji = $this->fallbackValue ?? '🖼️';
        $emojiWidth = Width::string($emoji);

        // Center the emoji
        if ($row === (int) floor($totalRows / 2)) {
            $padding = max(0, (int) floor(($width - $emojiWidth) / 2));
            return str_repeat(' ', $padding) . $emoji . str_repeat(' ', max(0, $width - $padding - $emojiWidth));
        }

        return str_repeat(' ', $width);
    }

    /**
     * Render placeholder fallback row.
     */
    private function renderPlaceholderRow(int $width, int $row, int $totalRows): string
    {
        // Use alt text if provided, otherwise default to [picture]
        $placeholder = ($this->alt !== null && $this->alt !== '') ? $this->alt : '[picture]';
        $placeholderWidth = Width::string($placeholder);

        if ($row === (int) floor($totalRows / 2) && $placeholderWidth <= $width) {
            $padding = max(0, (int) floor(($width - $placeholderWidth) / 2));
            return str_repeat(' ', $padding) . $placeholder . str_repeat(' ', max(0, $width - $padding - $placeholderWidth));
        }

        return str_repeat(' ', $width);
    }

    /**
     * Get initials from the fallback value (name).
     */
    private function getInitials(): string
    {
        $name = $this->fallbackValue ?? '';
        if ($name === '') {
            return '??';
        }

        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return substr($name, 0, 2);
        }

        if (count($parts) === 1) {
            return substr($parts[0], 0, 2);
        }

        $first = substr($parts[0], 0, 1);
        $second = substr($parts[count($parts) - 1], 0, 1);

        return strtoupper($first . $second);
    }

    /**
     * Calculate the natural dimensions of this picture.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? $this->widthHint;
        $h = $this->height ?? $this->heightHint;

        // Add extra row for caption if present
        if ($this->caption !== null) {
            $h++;
        }

        // Add 2 for top and bottom borders
        return [$w + 2, $h + 2];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the image URL.
     */
    public function withUrl(?string $url): self
    {
        return new self(
            url: $url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the alt text.
     */
    public function withAlt(?string $alt): self
    {
        return new self(
            url: $this->url,
            alt: $alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the caption.
     */
    public function withCaption(?string $caption): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the width hint.
     */
    public function withWidthHint(int $width): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: max(1, $width),
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the height hint.
     */
    public function withHeightHint(int $height): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: max(1, $height),
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the fallback type.
     */
    public function withFallbackType(string $type): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $type,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the fallback value.
     */
    public function withFallbackValue(?string $value): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $value,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $color,
            backgroundColor: $this->backgroundColor,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $color,
            captionColor: $this->captionColor,
        );
    }

    /**
     * Set the caption color.
     */
    public function withCaptionColor(?Color $color): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            caption: $this->caption,
            widthHint: $this->widthHint,
            heightHint: $this->heightHint,
            fallbackType: $this->fallbackType,
            fallbackValue: $this->fallbackValue,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            captionColor: $color,
        );
    }
}
