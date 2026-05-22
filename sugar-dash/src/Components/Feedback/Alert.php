<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Feedback;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A non-modal alert feedback component.
 *
 * Displays an inline alert message with configurable severity level,
 * message content, and optional title. Unlike Modal/Alert, this is
 * a non-modal feedback alert intended for inline display.
 *
 * Mirrors alert UI patterns adapted to PHP with wither-style immutable setters.
 */
final class Alert implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const Info = 'info';
    public const Success = 'success';
    public const Warning = 'warning';
    public const Error = 'error';

    public function __construct(
        private readonly string $message = '',
        private readonly string $level = self::Info,
        private readonly ?string $title = null,
        private readonly ?Color $color = null,
        private readonly ?string $borderChar = null,
    ) {}

    /**
     * Create a new alert with the given message.
     */
    public static function new(string $message = ''): self
    {
        return new self(message: $message);
    }

    /**
     * Create a new info alert.
     */
    public static function info(string $message): self
    {
        return new self(
            message: $message,
            level: self::Info,
            title: 'Info',
            color: Color::hex('#3B82F6'),
        );
    }

    /**
     * Create a new success alert.
     */
    public static function success(string $message): self
    {
        return new self(
            message: $message,
            level: self::Success,
            title: 'Success',
            color: Color::hex('#22C55E'),
        );
    }

    /**
     * Create a new warning alert.
     */
    public static function warning(string $message): self
    {
        return new self(
            message: $message,
            level: self::Warning,
            title: 'Warning',
            color: Color::hex('#F59E0B'),
        );
    }

    /**
     * Create a new error alert.
     */
    public static function error(string $message): self
    {
        return new self(
            message: $message,
            level: self::Error,
            title: 'Error',
            color: Color::hex('#EF4444'),
        );
    }

    /**
     * Set the allocated dimensions for this alert.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
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
        $prefix = $this->getIcon();
        $color = $this->color ?? Color::hex('#6B7280');
        $border = $this->borderChar ?? '│';

        $width = $this->width ?? 40;
        $contentWidth = $width - 4; // Account for border chars and padding

        $lines = [];
        $coloredLines = [];

        // Title line if present
        if ($this->title !== null) {
            $titleLine = $prefix . ' ' . $this->title;
            $titleLine = $this->padRight($titleLine, $contentWidth);
            $lines[] = $titleLine;
            $coloredLines[] = $color->toFg(ColorProfile::TrueColor) . $titleLine . Ansi::reset();
        }

        // Message line with word wrapping
        $messageText = $this->title !== null ? '  ' . $this->message : $prefix . ' ' . $this->message;
        $wrappedLines = $this->wordWrap($messageText, $contentWidth);

        foreach ($wrappedLines as $msgLine) {
            $msgLinePadded = $this->padRight($msgLine, $contentWidth);
            $lines[] = $msgLinePadded;
            $coloredLines[] = $color->toFg(ColorProfile::TrueColor) . $msgLinePadded . Ansi::reset();
        }

        // Build output with borders and colors
        $hasTitle = $this->title !== null;

        // Top border (only if title present)
        $topBottomBorder = $border . ' ' . str_repeat('─', $contentWidth) . ' ' . $border;
        $output = $hasTitle ? [$topBottomBorder] : [];

        // Content lines with side borders
        foreach ($coloredLines as $cl) {
            $output[] = $border . ' ' . $cl . ' ' . $border;
        }

        // Bottom border (only if title present)
        if ($hasTitle) {
            $output[] = $topBottomBorder;
        }

        return implode("\n", $output) . Ansi::reset();
    }

    /**
     * Word-wrap text to fit within a given width.
     *
     * @return array<int, string>
     */
    private function wordWrap(string $text, int $width): array
    {
        $words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($words === false) {
            return [$text];
        }

        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $potentialLine = $currentLine === '' ? $word : $currentLine . $word;
            $lineLength = Width::string($potentialLine);

            if ($lineLength <= $width) {
                $currentLine = $potentialLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                // If a single word is longer than width, break it
                if (Width::string($word) > $width) {
                    $lines[] = $word;
                    $currentLine = '';
                } else {
                    $currentLine = $word;
                }
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Pad a string to the right to fill a given width (ANSI-aware).
     */
    private function padRight(string $text, int $width): string
    {
        return Width::padRight($text, $width);
    }

    /**
     * Get the icon for the current alert level.
     */
    private function getIcon(): string
    {
        return match ($this->level) {
            self::Info => 'ℹ',
            self::Success => '✓',
            self::Warning => '⚠',
            self::Error => '✖',
            default => 'ℹ',
        };
    }

    /**
     * Calculate the natural dimensions of this alert.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 40;
        $height = $this->title !== null ? 2 : 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the alert message.
     */
    public function withMessage(string $message): self
    {
        return new self(
            message: $message,
            level: $this->level,
            title: $this->title,
            color: $this->color,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the alert level.
     */
    public function withLevel(string $level): self
    {
        return new self(
            message: $this->message,
            level: $level,
            title: $this->title,
            color: $this->color,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the alert title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            message: $this->message,
            level: $this->level,
            title: $title,
            color: $this->color,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the alert color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            level: $this->level,
            title: $this->title,
            color: $color,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            level: $this->level,
            title: $this->title,
            color: $this->color,
            borderChar: $this->borderChar,
        );
    }

    /**
     * Set the border character.
     */
    public function withBorderChar(string $char): self
    {
        return new self(
            message: $this->message,
            level: $this->level,
            title: $this->title,
            color: $this->color,
            borderChar: $char,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            level: $this->level,
            title: $this->title,
            color: $this->color,
            borderChar: $this->borderChar,
        );
    }
}
