<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A toast / notification component.
 *
 * Displays a message notification:
 * - Multiple toast types (info, success, warning, error)
 * - Optional title
 * - Auto-dismiss timer display (visual only)
 * - Customizable icon and colors
 *
 * Mirrors the toast concept from typical UI toolkits but adapted
 * to PHP with wither-style immutable setters.
 */
final class Toast implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $message,
        private readonly ?string $title = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $foregroundColor = null,
        private readonly ?Color $borderColor = null,
        private readonly string $icon = '',
        private readonly int $maxWidth = 60,
    ) {}

    /**
     * Create a new toast with default styling.
     *
     * Default: dark background, white text.
     */
    public static function new(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#1F2937'),
            foregroundColor: Color::hex('#F9FAFB'),
            borderColor: Color::hex('#374151'),
            icon: '',
            maxWidth: 60,
        );
    }

    /**
     * Create an info-style toast.
     */
    public static function info(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#1E3A5F'),
            foregroundColor: Color::hex('#E5E7EB'),
            borderColor: Color::hex('#3B82F6'),
            icon: 'ℹ',
            maxWidth: 60,
        );
    }

    /**
     * Create a success-style toast.
     */
    public static function success(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#052E16'),
            foregroundColor: Color::hex('#DCFCE7'),
            borderColor: Color::hex('#22C55E'),
            icon: '✓',
            maxWidth: 60,
        );
    }

    /**
     * Create a warning-style toast.
     */
    public static function warning(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#451A03'),
            foregroundColor: Color::hex('#FEF3C7'),
            borderColor: Color::hex('#F59E0B'),
            icon: '⚠',
            maxWidth: 60,
        );
    }

    /**
     * Create an error-style toast.
     */
    public static function error(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            backgroundColor: Color::hex('#450A0A'),
            foregroundColor: Color::hex('#FEE2E2'),
            borderColor: Color::hex('#EF4444'),
            icon: '✖',
            maxWidth: 60,
        );
    }

    /**
     * Set the allocated dimensions for this toast.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the toast as a string.
     */
    public function render(): string
    {
        $useWidth = min($this->width ?? $this->maxWidth, $this->maxWidth);
        $useWidth = max($useWidth, 10);

        $contentWidth = $useWidth - 4; // -4 for padding and borders

        $result = '';

        // Apply background and foreground colors
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->foregroundColor !== null) {
            $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
        }

        // Top border
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= '╭' . str_repeat('─', $useWidth - 2) . '╮' . "\n";

        // Icon and title line (if title is set)
        if ($this->title !== null) {
            $iconStr = $this->icon !== '' ? $this->icon . ' ' : '';
            $titleLine = $iconStr . $this->title;
            $paddedTitle = $this->padLine($titleLine, $contentWidth);
            $result .= '│ ' . $paddedTitle . ' │' . "\n";

            // Message line
            $messageLines = $this->wrapText($this->message, $contentWidth);
            foreach ($messageLines as $msgLine) {
                $paddedMsg = $this->padLine($msgLine, $contentWidth);
                $result .= '│ ' . $paddedMsg . ' │' . "\n";
            }
        } else {
            // Just message, possibly with icon
            $iconStr = $this->icon !== '' ? $this->icon . ' ' : '';
            $fullMessage = $iconStr . $this->message;
            $messageLines = $this->wrapText($fullMessage, $contentWidth);

            foreach ($messageLines as $msgLine) {
                $paddedMsg = $this->padLine($msgLine, $contentWidth);
                $result .= '│ ' . $paddedMsg . ' │' . "\n";
            }
        }

        // Bottom border
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= '╰' . str_repeat('─', $useWidth - 2) . '╯';

        // Reset ANSI
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Pad a line to the given width.
     */
    private function padLine(string $line, int $width): string
    {
        $lineWidth = Width::string($line);
        if ($lineWidth >= $width) {
            return $line;
        }
        return $line . str_repeat(' ', $width - $lineWidth);
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
     * Calculate the natural dimensions of this toast.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = min($this->width ?? $this->maxWidth, $this->maxWidth);
        $contentWidth = $useWidth - 4;

        // Height: top border + content lines + bottom border
        $lineCount = 1; // top border

        if ($this->title !== null) {
            $lineCount++; // title line
            $lineCount += count($this->wrapText($this->message, $contentWidth));
        } else {
            $iconStr = $this->icon !== '' ? $this->icon . ' ' : '';
            $lineCount += count($this->wrapText($iconStr . $this->message, $contentWidth));
        }

        $lineCount++; // bottom border

        return [$useWidth, $lineCount];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the toast message.
     */
    public function withMessage(string $message): self
    {
        return new self(
            message: $message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
        );
    }

    /**
     * Set the toast title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            message: $this->message,
            title: $title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $color,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
        );
    }

    /**
     * Set the foreground (text) color.
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $color,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $color,
            icon: $this->icon,
            maxWidth: $this->maxWidth,
        );
    }

    /**
     * Set the icon.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $icon,
            maxWidth: $this->maxWidth,
        );
    }

    /**
     * Set the maximum width.
     */
    public function withMaxWidth(int $maxWidth): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
            icon: $this->icon,
            maxWidth: $maxWidth,
        );
    }
}