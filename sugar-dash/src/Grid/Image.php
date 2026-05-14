<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An image display component with fallback support.
 *
 * Features:
 * - Display image URL with placeholder representation
 * - Fallback display when no image URL provided
 * - Size and aspect ratio control
 * - Alt text support for accessibility
 * - Border and padding options
 *
 * Note: Since TUIs cannot render actual images, this component
 * renders a visual representation (placeholder blocks or ASCII art)
 * that indicates the presence and dimensions of an image.
 *
 * Mirrors image display concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Image implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const FORMAT_BOX = 'box';
    public const FORMAT_BLOCK = 'block';
    public const FORMAT_ASCII = 'ascii';

    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $alt = null,
        private readonly ?int $maxWidth = null,
        private readonly ?int $maxHeight = null,
        private readonly string $format = self::FORMAT_BOX,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $altColor = null,
    ) {}

    /**
     * Create a new image with a URL.
     */
    public static function fromUrl(string $url, ?string $alt = null): self
    {
        return new self(
            url: $url,
            alt: $alt,
            maxWidth: null,
            maxHeight: null,
            format: self::FORMAT_BOX,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#1E1E2E'),
            altColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Create a placeholder image (no actual image URL).
     */
    public static function placeholder(?string $alt = null): self
    {
        return new self(
            url: null,
            alt: $alt,
            maxWidth: null,
            maxHeight: null,
            format: self::FORMAT_BOX,
            borderColor: Color::hex('#45475A'),
            backgroundColor: Color::hex('#313244'),
            altColor: Color::hex('#6B7280'),
        );
    }

    /**
     * Set the allocated dimensions for this image.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the image as a string.
     */
    public function render(): string
    {
        $w = $this->width ?? $this->maxWidth ?? 20;
        $h = $this->height ?? $this->maxHeight ?? 10;

        if ($w <= 0 || $h <= 0) {
            return '';
        }

        return match ($this->format) {
            self::FORMAT_BLOCK => $this->renderBlockFormat($w, $h),
            self::FORMAT_ASCII => $this->renderAsciiFormat($w, $h),
            default => $this->renderBoxFormat($w, $h),
        };
    }

    /**
     * Render in box format with border.
     */
    private function renderBoxFormat(int $w, int $h): string
    {
        $output = '';

        // Apply colors
        if ($this->backgroundColor !== null) {
            $output .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // Top border
        $borderLine = str_repeat('─', $w);
        if ($this->borderColor !== null) {
            $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $output .= '┌' . $borderLine . '┐' . "\n";

        // Image area
        $innerWidth = $w;
        for ($row = 0; $row < $h - 2; $row++) {
            if ($this->borderColor !== null) {
                $output .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $output .= '│';

            if ($this->url !== null) {
                // Show a visual representation of the image
                $placeholder = $this->renderImagePlaceholder($innerWidth);
                $output .= $placeholder;
            } else {
                // Show alt text or placeholder
                $placeholder = $this->renderAltPlaceholder($innerWidth);
                $output .= $placeholder;
            }

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
        if ($this->backgroundColor !== null) {
            $output .= Ansi::reset();
        }

        return $output;
    }

    /**
     * Render image placeholder pattern.
     */
    private function renderImagePlaceholder(int $width): string
    {
        // Create a simple visual pattern to represent an image
        $pattern = ['▓', '░', '▒'];
        $line = '';

        for ($i = 0; $i < $width; $i++) {
            $line .= $pattern[$i % count($pattern)];
        }

        return $line;
    }

    /**
     * Render alt text or placeholder when no image.
     */
    private function renderAltPlaceholder(int $width): string
    {
        if ($this->alt !== null && $this->alt !== '') {
            $altWidth = Width::string($this->alt);
            if ($altWidth <= $width - 2) {
                $padding = (int) floor(($width - $altWidth) / 2);
                return str_repeat(' ', $padding) . $this->alt . str_repeat(' ', $width - $padding - $altWidth);
            }
            // Truncate alt text if too wide
            $truncated = mb_substr($this->alt, 0, $width - 2, 'UTF-8');
            return $truncated . '..';
        }

        // Default placeholder
        $placeholder = '[image]';
        $placeholderWidth = Width::string($placeholder);
        if ($placeholderWidth <= $width - 2) {
            $padding = (int) floor(($width - $placeholderWidth) / 2);
            return str_repeat(' ', $padding) . $placeholder . str_repeat(' ', $width - $padding - $placeholderWidth);
        }

        return str_repeat(' ', $width);
    }

    /**
     * Render in block format (solid fill).
     */
    private function renderBlockFormat(int $w, int $h): string
    {
        $output = '';

        if ($this->backgroundColor !== null) {
            $output .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        for ($row = 0; $row < $h; $row++) {
            $output .= str_repeat('█', $w) . "\n";
        }

        if ($this->backgroundColor !== null) {
            $output .= Ansi::reset();
        }

        return rtrim($output, "\n");
    }

    /**
     * Render in ASCII art format.
     */
    private function renderAsciiFormat(int $w, int $h): string
    {
        $output = '';

        if ($this->backgroundColor !== null) {
            $output .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // ASCII art representation
        $lines = [
            '  ___________',
            ' /           \ ',
            '|  .     .   |',
            '|    _____   |',
            '|   [_____]  |',
            ' \  .     . /',
            '  ---------',
        ];

        // Scale lines to fit width
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth > $w) {
                $line = mb_substr($line, 0, $w, 'UTF-8');
            } elseif ($lineWidth < $w) {
                $padding = (int) floor(($w - $lineWidth) / 2);
                $line = str_repeat(' ', $padding) . $line . str_repeat(' ', $w - $padding - $lineWidth);
            }
            $output .= $line . "\n";
        }

        if ($this->backgroundColor !== null) {
            $output .= Ansi::reset();
        }

        return rtrim($output, "\n");
    }

    /**
     * Calculate the natural dimensions of this image.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? $this->maxWidth ?? 20;
        $h = $this->height ?? $this->maxHeight ?? 10;

        return [$w, $h];
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
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            format: $this->format,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            altColor: $this->altColor,
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
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            format: $this->format,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            altColor: $this->altColor,
        );
    }

    /**
     * Set the maximum width.
     */
    public function withMaxWidth(?int $maxWidth): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            maxWidth: $maxWidth,
            maxHeight: $this->maxHeight,
            format: $this->format,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            altColor: $this->altColor,
        );
    }

    /**
     * Set the maximum height.
     */
    public function withMaxHeight(?int $maxHeight): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            maxWidth: $this->maxWidth,
            maxHeight: $maxHeight,
            format: $this->format,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            altColor: $this->altColor,
        );
    }

    /**
     * Set the format.
     */
    public function withFormat(string $format): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            format: $format,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            altColor: $this->altColor,
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
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            format: $this->format,
            borderColor: $color,
            backgroundColor: $this->backgroundColor,
            altColor: $this->altColor,
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
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            format: $this->format,
            borderColor: $this->borderColor,
            backgroundColor: $color,
            altColor: $this->altColor,
        );
    }

    /**
     * Set the alt text color.
     */
    public function withAltColor(?Color $color): self
    {
        return new self(
            url: $this->url,
            alt: $this->alt,
            maxWidth: $this->maxWidth,
            maxHeight: $this->maxHeight,
            format: $this->format,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            altColor: $color,
        );
    }
}
