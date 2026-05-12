<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An alert / message box component.
 *
 * Displays a message with optional:
 * - Title/header text
 * - Different severity styles (info, warning, error, success)
 * - Left border accent
 * - Icon prefix
 *
 * Mirrors the alert concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Alert implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $message,
        private readonly ?string $title = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $foregroundColor = null,
        private readonly string $borderChar = '│',
        private readonly string $icon = '',
    ) {}

    /**
     * Create a new alert with default styling.
     *
     * Default: blue-bordered info alert.
     */
    public static function new(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            borderColor: Color::hex('#3B82F6'),
            backgroundColor: Color::hex('#1E3A5F'),
            foregroundColor: Color::hex('#E5E7EB'),
            borderChar: '│',
            icon: 'ℹ',
        );
    }

    /**
     * Create an info-style alert.
     */
    public static function info(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            borderColor: Color::hex('#3B82F6'),
            backgroundColor: Color::hex('#1E3A5F'),
            foregroundColor: Color::hex('#E5E7EB'),
            borderChar: '│',
            icon: 'ℹ',
        );
    }

    /**
     * Create a warning-style alert.
     */
    public static function warning(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            borderColor: Color::hex('#F59E0B'),
            backgroundColor: Color::hex('#451A03'),
            foregroundColor: Color::hex('#FEF3C7'),
            borderChar: '│',
            icon: '⚠',
        );
    }

    /**
     * Create an error/danger-style alert.
     */
    public static function error(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            borderColor: Color::hex('#EF4444'),
            backgroundColor: Color::hex('#450A0A'),
            foregroundColor: Color::hex('#FEE2E2'),
            borderChar: '│',
            icon: '✖',
        );
    }

    /**
     * Create a success-style alert.
     */
    public static function success(string $message): self
    {
        return new self(
            message: $message,
            title: null,
            borderColor: Color::hex('#22C55E'),
            backgroundColor: Color::hex('#052E16'),
            foregroundColor: Color::hex('#DCFCE7'),
            borderChar: '│',
            icon: '✓',
        );
    }

    /**
     * Set the allocated dimensions for this alert.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the alert as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 40;
        $contentWidth = $useWidth - 2; // -2 for border chars

        $lines = [];

        // Build the content lines
        if ($this->title !== null) {
            $titleLines = $this->wrapText($this->title, $contentWidth - 2);
            $lines = array_merge($lines, $titleLines);
        }

        // Prepend icon to message if present
        $messageWithIcon = $this->icon !== '' ? $this->icon . ' ' . $this->message : $this->message;
        $messageLines = $this->wrapText($messageWithIcon, $contentWidth - 2);
        $lines = array_merge($lines, $messageLines);

        if ($lines === []) {
            $lines = [''];
        }

        // Build the output
        $result = '';

        // Apply colors
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // Render each line with border
        foreach ($lines as $line) {
            $paddedLine = ' ' . $line . str_repeat(' ', max(0, $contentWidth - 2 - Width::string($line)));
            $result .= $this->borderChar . $paddedLine . $this->borderChar . "\n";
        }

        // Reset colors at end
        $result .= Ansi::reset();

        // Remove trailing newline
        return rtrim($result, "\n");
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
     * Calculate the natural dimensions of this alert.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 40;
        $contentWidth = $useWidth - 2;

        $lineCount = 0;

        if ($this->title !== null) {
            $lineCount += count($this->wrapText($this->title, $contentWidth - 2));
        }

        $messageLines = $this->wrapText($this->message, $contentWidth - 2);
        $lineCount += count($messageLines);

        return [$useWidth, max(1, $lineCount)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the alert message.
     */
    public function withMessage(string $message): self
    {
        return new self(
            message: $message,
            title: $this->title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderChar: $this->borderChar,
            icon: $this->icon,
        );
    }

    /**
     * Set the alert title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            message: $this->message,
            title: $title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderChar: $this->borderChar,
            icon: $this->icon,
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
            borderColor: $color,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderChar: $this->borderChar,
            icon: $this->icon,
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
            borderColor: $this->borderColor,
            backgroundColor: $color,
            foregroundColor: $this->foregroundColor,
            borderChar: $this->borderChar,
            icon: $this->icon,
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
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $color,
            borderChar: $this->borderChar,
            icon: $this->icon,
        );
    }

    /**
     * Set the border character.
     */
    public function withBorderChar(string $char): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderChar: $char,
            icon: $this->icon,
        );
    }

    /**
     * Set the icon prefix.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderChar: $this->borderChar,
            icon: $icon,
        );
    }
}
