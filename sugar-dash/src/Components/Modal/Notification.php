<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A notification component for displaying alert messages.
 *
 * Notifications are used to display important information to the user
 * such as success messages, warnings, errors, or general info.
 * They can be styled to indicate severity and can include an icon.
 *
 * Mirrors the notification/alert concept adapted to PHP with wither-style immutable setters.
 */
final class Notification implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $message = '',
        private readonly string $title = '',
        private readonly ?Color $borderColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $titleColor = null,
        private readonly ?Color $messageColor = null,
        private readonly string $icon = '',
    ) {}

    /**
     * Create a new notification with default styling.
     */
    public static function new(string $message = ''): self
    {
        return new self(
            message: $message,
            title: '',
            borderColor: Color::hex('#3F3F46'),
            backgroundColor: Color::hex('#18181B'),
            titleColor: Color::hex('#FAFAFA'),
            messageColor: Color::hex('#A1A1AA'),
            icon: '',
        );
    }

    /**
     * Create a success notification.
     */
    public static function success(string $message): self
    {
        return new self(
            message: $message,
            title: 'Success',
            borderColor: Color::hex('#22C55E'),
            backgroundColor: Color::hex('#052E16'),
            titleColor: Color::hex('#4ADE80'),
            messageColor: Color::hex('#86EFAC'),
            icon: '✓',
        );
    }

    /**
     * Create a warning notification.
     */
    public static function warning(string $message): self
    {
        return new self(
            message: $message,
            title: 'Warning',
            borderColor: Color::hex('#F59E0B'),
            backgroundColor: Color::hex('#451A03'),
            titleColor: Color::hex('#FCD34D'),
            messageColor: Color::hex('#FDE68A'),
            icon: '⚠',
        );
    }

    /**
     * Create an error/danger notification.
     */
    public static function error(string $message): self
    {
        return new self(
            message: $message,
            title: 'Error',
            borderColor: Color::hex('#EF4444'),
            backgroundColor: Color::hex('#450A0A'),
            titleColor: Color::hex('#FCA5A5'),
            messageColor: Color::hex('#FECACA'),
            icon: '✕',
        );
    }

    /**
     * Create an info notification.
     */
    public static function info(string $message): self
    {
        return new self(
            message: $message,
            title: 'Info',
            borderColor: Color::hex('#3B82F6'),
            backgroundColor: Color::hex('#1E3A5F'),
            titleColor: Color::hex('#93C5FD'),
            messageColor: Color::hex('#BFDBFE'),
            icon: 'ℹ',
        );
    }

    /**
     * Set the allocated dimensions for this notification.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the notification as a string.
     */
    public function render(): string
    {
        $useWidth = $this->getWidth();
        $contentWidth = $useWidth - 4; // Accounting for borders and padding

        $result = '';

        // Apply background color for entire box
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // Top border
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= '╔' . str_repeat('═', max(0, $useWidth - 2)) . '╗' . "\n";

        // Icon + Title line (if present)
        if ($this->title !== '' || $this->icon !== '') {
            $titleLine = $this->formatTitleLine($useWidth);
            $result .= $titleLine . "\n";
        }

        // Message content
        $result .= $this->renderMessage($contentWidth);

        // Bottom border
        if ($this->borderColor !== null) {
            $result .= Ansi::reset();
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
        }
        $result .= '╚' . str_repeat('═', max(0, $useWidth - 2)) . '╝';

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Format the title line with icon and title.
     */
    private function formatTitleLine(int $totalWidth): string
    {
        $result = '║';

        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        $titleContent = '';
        if ($this->icon !== '') {
            $titleContent .= $this->icon . ' ';
        }
        if ($this->title !== '') {
            $titleContent .= $this->title;
        }

        if ($this->titleColor !== null) {
            $result .= $this->titleColor->toFg(ColorProfile::TrueColor);
        }
        $result .= ' ' . $titleContent . ' ';

        $contentWidth = Width::string($titleContent) + 2;
        $padding = max(0, $totalWidth - $contentWidth - 1);
        $result .= str_repeat(' ', $padding);

        $result .= '║';

        return $result;
    }

    /**
     * Render the message content with word wrapping.
     */
    private function renderMessage(int $width): string
    {
        $result = '';

        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        $lines = $this->wrapText($this->message, $width);

        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            $padding = $width - $lineWidth;

            $result .= '║';
            if ($this->messageColor !== null) {
                $result .= $this->messageColor->toFg(ColorProfile::TrueColor);
            }
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
            $result .= ' ' . $line . str_repeat(' ', max(0, $padding)) . ' ';
            if ($this->borderColor !== null) {
                $result .= Ansi::reset();
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
                if ($this->backgroundColor !== null) {
                    $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
                }
            }
            $result .= '║' . "\n";
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
            return [''];
        }

        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return [''];
        }

        $lines = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentLine === '') {
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } elseif ($currentWidth + 1 + $wordWidth <= $width) {
                $currentLine .= ' ' . $word;
                $currentWidth += 1 + $wordWidth;
            } else {
                $lines[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Get the width to use for the notification.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        // Auto-calculate based on content
        $titleWidth = Width::string($this->icon . ' ' . $this->title);
        $contentWidth = Width::string($this->message);
        $maxContent = max($titleWidth, $contentWidth);
        return max(25, $maxContent + 6); // Min 25, add padding
    }

    /**
     * Calculate the natural dimensions of this notification.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();
        $contentWidth = $width - 4;

        $lineCount = count($this->wrapText($this->message, $contentWidth));
        if ($this->title !== '' || $this->icon !== '') {
            $lineCount++;
        }

        $height = 1 + $lineCount + 1; // top + content + bottom

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the notification message.
     */
    public function withMessage(string $message): self
    {
        return new self(
            message: $message,
            title: $this->title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            messageColor: $this->messageColor,
            icon: $this->icon,
        );
    }

    /**
     * Set the notification title.
     */
    public function withTitle(string $title): self
    {
        return new self(
            message: $this->message,
            title: $title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            messageColor: $this->messageColor,
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
            titleColor: $this->titleColor,
            messageColor: $this->messageColor,
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
            titleColor: $this->titleColor,
            messageColor: $this->messageColor,
            icon: $this->icon,
        );
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $color,
            messageColor: $this->messageColor,
            icon: $this->icon,
        );
    }

    /**
     * Set the message color.
     */
    public function withMessageColor(?Color $color): self
    {
        return new self(
            message: $this->message,
            title: $this->title,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            messageColor: $color,
            icon: $this->icon,
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
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            messageColor: $this->messageColor,
            icon: mb_substr($icon, 0, 2, 'UTF-8'),
        );
    }
}
