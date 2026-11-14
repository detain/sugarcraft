<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A popover component that displays content in a bordered box.
 *
 * Popovers are typically triggered on hover and contain supplementary
 * information, tooltips with more detail, or contextual actions.
 *
 * Mirrors the popover concept adapted to PHP with wither-style immutable setters.
 */
final class Popover implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $title = '',
        private readonly string $content = '',
        private readonly ?Color $borderColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $titleColor = null,
        private readonly bool $showArrow = true,
    ) {}

    /**
     * Create a new popover with default styling.
     */
    public static function new(string $title = '', string $content = ''): self
    {
        return new self(
            title: $title,
            content: $content,
            borderColor: Color::hex('#3F3F46'),
            backgroundColor: Color::hex('#18181B'),
            titleColor: Color::hex('#FAFAFA'),
            showArrow: true,
        );
    }

    /**
     * Create an informational popover.
     */
    public static function info(string $title, string $content): self
    {
        return new self(
            title: $title,
            content: $content,
            borderColor: Color::hex('#3B82F6'),
            backgroundColor: Color::hex('#1E3A5F'),
            titleColor: Color::hex('#93C5FD'),
            showArrow: true,
        );
    }

    /**
     * Create a warning popover.
     */
    public static function warning(string $title, string $content): self
    {
        return new self(
            title: $title,
            content: $content,
            borderColor: Color::hex('#F59E0B'),
            backgroundColor: Color::hex('#451A03'),
            titleColor: Color::hex('#FCD34D'),
            showArrow: true,
        );
    }

    /**
     * Create a danger popover.
     */
    public static function danger(string $title, string $content): self
    {
        return new self(
            title: $title,
            content: $content,
            borderColor: Color::hex('#EF4444'),
            backgroundColor: Color::hex('#450A0A'),
            titleColor: Color::hex('#FCA5A5'),
            showArrow: true,
        );
    }

    /**
     * Set the allocated dimensions for this popover.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the popover as a string.
     */
    public function render(): string
    {
        $useWidth = $this->getWidth();
        $contentWidth = $useWidth - 4; // Accounting for borders and padding

        $result = '';

        // Arrow (pointing down by default)
        if ($this->showArrow) {
            $arrowPadding = (int) floor($useWidth / 2) - 1;
            $result .= str_repeat(' ', max(0, $arrowPadding));
            $result .= "▼\n";
        }

        // Top border
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorMode::profile());
        }
        $result .= '┌' . str_repeat('─', max(0, $useWidth - 2)) . '┐' . "\n";

        // Title bar (if present)
        if ($this->title !== '') {
            // Finding sugar-dashboard-072: the measured title width used to be
            // discarded - the row was neither truncated nor padded, so a long
            // title burst the box and a short one left a ragged row. The cell
            // count of every painted row is now exactly $useWidth.
            $textWidth = max(0, $contentWidth);
            $titleText = Width::padRight(Width::truncateAnsi($this->title, $textWidth), $textWidth);

            if ($this->titleColor !== null) {
                $result .= $this->titleColor->toFg(ColorMode::profile());
            }
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorMode::profile());
            }
            $result .= '│ ' . $titleText . ' │' . "\n";
        }

        // Content area
        $result .= $this->renderContent($contentWidth);

        // Bottom border
        if ($this->borderColor !== null) {
            $result .= Ansi::reset();
            $result .= $this->borderColor->toFg(ColorMode::profile());
        }
        $result .= '└' . str_repeat('─', max(0, $useWidth - 2)) . '┘';

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render the content area with word wrapping.
     */
    private function renderContent(int $width): string
    {
        $result = '';

        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorMode::profile());
        }

        $lines = $this->wrapText($this->content, $width);

        foreach ($lines as $line) {
            // A single over-long word cannot wrap; clip it so the frame
            // geometry invariant (every row exactly useWidth cells) holds.
            $line = Width::truncateAnsi($line, max(0, $width));
            $lineWidth = Width::string($line);
            $padding = $width - $lineWidth;
            // Same fix family: the side gaps now match the title row and the
            // frame math, so the row lands on exactly useWidth cells.
            $result .= '│ ' . $line . str_repeat(' ', max(0, $padding)) . ' │' . "\n";
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
     * Get the width to use for the popover.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        // Auto-calculate based on content
        $contentWidth = max(Width::string($this->title), Width::string($this->content));
        return max(20, $contentWidth + 6); // Min 20, add padding
    }

    /**
     * Calculate the natural dimensions of this popover.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        $width = $this->getWidth();
        $contentWidth = $width - 4;

        $lineCount = count($this->wrapText($this->content, $contentWidth));
        if ($this->title !== '') {
            $lineCount++;
        }

        $height = 1 + $lineCount + 1; // top + content + bottom
        if ($this->showArrow) {
            $height++;
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the popover title.
     */
    public function withTitle(string $title): self
    {
        $next = new self(
            title: $title,
            content: $this->content,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            showArrow: $this->showArrow,
        );
        // Finding sugar-dashboard-072 exposed that these rebuilds used to drop
        // the width/height allocation: a wither must only change its own knob.
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the popover content.
     */
    public function withContent(string $content): self
    {
        $next = new self(
            title: $this->title,
            content: $content,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            showArrow: $this->showArrow,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        $next = new self(
            title: $this->title,
            content: $this->content,
            borderColor: $color,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            showArrow: $this->showArrow,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        $next = new self(
            title: $this->title,
            content: $this->content,
            borderColor: $this->borderColor,
            backgroundColor: $color,
            titleColor: $this->titleColor,
            showArrow: $this->showArrow,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        $next = new self(
            title: $this->title,
            content: $this->content,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $color,
            showArrow: $this->showArrow,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }

    /**
     * Show or hide the arrow.
     */
    public function withShowArrow(bool $show): self
    {
        $next = new self(
            title: $this->title,
            content: $this->content,
            borderColor: $this->borderColor,
            backgroundColor: $this->backgroundColor,
            titleColor: $this->titleColor,
            showArrow: $show,
        );
        $next->width = $this->width;
        $next->height = $this->height;
        return $next;
    }
}
