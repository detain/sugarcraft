<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Media;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A user avatar component with fallback support.
 *
 * Features:
 * - Display user avatar image or initials
 * - Fallback to initials when no image provided
 * - Size variants (small, medium, large)
 * - Color customization for background
 * - Optional status indicator
 *
 * Mirrors avatar UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Avatar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const SIZE_SMALL = 1;
    public const SIZE_MEDIUM = 2;
    public const SIZE_LARGE = 3;

    public function __construct(
        private readonly ?string $imageUrl = null,
        private readonly ?string $name = null,
        private readonly int $size = self::SIZE_MEDIUM,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $foregroundColor = null,
        private readonly ?string $status = null,
    ) {}

    /**
     * Create a new avatar with a name (displays initials as fallback).
     */
    public static function fromName(string $name): self
    {
        return new self(
            imageUrl: null,
            name: $name,
            size: self::SIZE_MEDIUM,
            backgroundColor: Color::hex('#3B82F6'),
            foregroundColor: Color::hex('#FFFFFF'),
            status: null,
        );
    }

    /**
     * Create a new avatar with an image URL.
     */
    public static function withImage(string $imageUrl, ?string $name = null): self
    {
        return new self(
            imageUrl: $imageUrl,
            name: $name,
            size: self::SIZE_MEDIUM,
            backgroundColor: null,
            foregroundColor: null,
            status: null,
        );
    }

    /**
     * Create a small avatar.
     */
    public static function small(string $name): self
    {
        return self::fromName($name)->withSize(self::SIZE_SMALL);
    }

    /**
     * Create a large avatar.
     */
    public static function large(string $name): self
    {
        return self::fromName($name)->withSize(self::SIZE_LARGE);
    }

    /**
     * Get the pixel width for a size constant.
     */
    public static function sizeToPixels(int $size): int
    {
        return match ($size) {
            self::SIZE_SMALL => 3,
            self::SIZE_MEDIUM => 5,
            self::SIZE_LARGE => 7,
            default => 5,
        };
    }

    /**
     * Set the allocated dimensions for this avatar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the avatar as a string.
     */
    public function render(): string
    {
        $size = $this->width ?? self::sizeToPixels($this->size);

        if ($this->imageUrl !== null && $this->name === null) {
            // Image-only avatar: show a placeholder representation
            // In a real TUI context, images aren't supported, so we show a placeholder
            return $this->renderImagePlaceholder($size);
        }

        // Render initials-based avatar
        return $this->renderInitials($size);
    }

    /**
     * Render an avatar with initials.
     */
    private function renderInitials(int $size): string
    {
        $initials = $this->getInitials();
        $initialsWidth = Width::string($initials);

        // Calculate padding to center the initials
        $horizontalPad = $size - $initialsWidth;
        $leftPad = (int) floor($horizontalPad / 2);
        $rightPad = $horizontalPad - $leftPad;

        $leftStr = str_repeat(' ', $leftPad);
        $rightStr = str_repeat(' ', $rightPad);

        $result = '';

        // Apply background color
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // Apply foreground color
        if ($this->foregroundColor !== null) {
            $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $leftStr . $initials . $rightStr;

        // Add status indicator if present
        if ($this->status !== null) {
            $statusColor = $this->getStatusColor();
            $result .= Ansi::reset();
            $result .= ' ';
            $result .= $statusColor->toFg(ColorProfile::TrueColor);
            $result .= $this->status;
        }

        // Reset ANSI
        if ($this->backgroundColor !== null || $this->foregroundColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Render a placeholder for image avatars.
     */
    private function renderImagePlaceholder(int $size): string
    {
        $result = '';

        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->foregroundColor !== null) {
            $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
        }

        $result .= str_repeat('▓', $size);

        if ($this->status !== null) {
            $result .= Ansi::reset();
            $result .= ' ';
            $statusColor = $this->getStatusColor();
            $result .= $statusColor->toFg(ColorProfile::TrueColor);
            $result .= $this->status;
        }

        if ($this->backgroundColor !== null || $this->foregroundColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Get initials from the name.
     */
    private function getInitials(): string
    {
        if ($this->name === null || $this->name === '') {
            return '??';
        }

        $parts = preg_split('/\s+/', trim($this->name), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return substr($this->name, 0, 2);
        }

        if (count($parts) === 1) {
            return substr($parts[0], 0, 2);
        }

        // Take first letter of first two parts
        $first = substr($parts[0], 0, 1);
        $second = substr($parts[count($parts) - 1], 0, 1);

        return strtoupper($first . $second);
    }

    /**
     * Get color for status indicator.
     */
    private function getStatusColor(): Color
    {
        return match ($this->status) {
            '●' => Color::hex('#22C55E'), // Online/green
            '○' => Color::hex('#6B7280'), // Offline/gray
            '◐' => Color::hex('#F59E0B'), // Away/yellow
            '✕' => Color::hex('#EF4444'), // Busy/error red
            default => Color::hex('#6B7280'),
        };
    }

    /**
     * Calculate the natural dimensions of this avatar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $size = $this->width ?? self::sizeToPixels($this->size);
        $width = $size + ($this->status !== null ? 2 : 0); // Extra for status
        $height = 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the image URL.
     */
    public function withImageUrl(?string $url): self
    {
        return new self(
            imageUrl: $url,
            name: $this->name,
            size: $this->size,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            status: $this->status,
        );
    }

    /**
     * Set the name (used for initials fallback).
     */
    public function withName(?string $name): self
    {
        return new self(
            imageUrl: $this->imageUrl,
            name: $name,
            size: $this->size,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            status: $this->status,
        );
    }

    /**
     * Set the avatar size.
     */
    public function withSize(int $size): self
    {
        return new self(
            imageUrl: $this->imageUrl,
            name: $this->name,
            size: $size,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            status: $this->status,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            imageUrl: $this->imageUrl,
            name: $this->name,
            size: $this->size,
            backgroundColor: $color,
            foregroundColor: $this->foregroundColor,
            status: $this->status,
        );
    }

    /**
     * Set the foreground (text) color.
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self(
            imageUrl: $this->imageUrl,
            name: $this->name,
            size: $this->size,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $color,
            status: $this->status,
        );
    }

    /**
     * Set the status indicator.
     */
    public function withStatus(?string $status): self
    {
        return new self(
            imageUrl: $this->imageUrl,
            name: $this->name,
            size: $this->size,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            status: $status,
        );
    }
}
