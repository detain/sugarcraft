<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\VAlign;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A window component with a title bar and content area.
 *
 * Features:
 * - Title bar with customizable title text
 * - Window control buttons (close, minimize, maximize)
 * - Configurable border style
 * - Optional shadow effect
 * - Content area with padding
 *
 * Mirrors window concepts from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Window implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const ControlsNone = 'none';
    public const ControlsClose = 'close';
    public const ControlsAll = 'all';

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly ?string $title = null,
        private readonly ?Color $titleColor = null,
        private readonly ?Color $borderColor = null,
        private readonly array $padding = [1, 1, 1, 1],
        private readonly string $controls = self::ControlsNone,
        private readonly bool $showShadow = false,
        private readonly VAlign $verticalAlign = VAlign::Top,
    ) {}

    /**
     * Create a new window with default styling.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content, ?string $title = null): self
    {
        return new self(
            content: $content,
            title: $title,
            titleColor: Color::hex('#874BFD'),
            borderColor: Color::hex('#874BFD'),
            padding: [1, 1, 1, 1],
            controls: self::ControlsNone,
            showShadow: false,
            verticalAlign: VAlign::Top,
        );
    }

    /**
     * Set the allocated dimensions for this window.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the window with title bar and content.
     */
    public function render(): string
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        // If no size allocated, render content directly
        if ($w <= 0 || $h <= 0) {
            return $this->content->render();
        }

        // Window requires at least 3 rows (title + border + 1 content)
        // and at least 5 columns (border + 1 content + border)
        if ($w < 5 || $h < 3) {
            return $this->content->render();
        }

        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;

        // Content area dimensions
        $contentW = $w - 2 - $paddingLeft - $paddingRight;  // -2 for borders
        $contentH = $h - 2 - $paddingTop - $paddingBottom;  // -2 for title bar and bottom border

        if ($contentW <= 0 || $contentH <= 0) {
            return $this->content->render();
        }

        // Build the title bar
        $titleBar = $this->buildTitleBar($w);

        // Build the content
        $styledContent = $this->renderContent($contentW, $contentH);

        // Build the border/frame style
        $borderColor = $this->borderColor ?? Color::hex('#874BFD');
        $frameStyle = Style::new()
            ->border(Border::rounded())
            ->borderForeground($borderColor)
            ->width($w)
            ->height($h);

        // Build the inner content (title bar + styled content)
        $innerContent = $titleBar . "\n" . $styledContent;

        // Apply padding to inner content
        $paddedStyle = Style::new()
            ->padding($paddingTop, $paddingRight, $paddingBottom, $paddingLeft)
            ->width($contentW)
            ->height($contentH + 1)  // +1 for title bar
            ->verticalAlign($this->verticalAlign);

        $paddedInner = $paddedStyle->render($innerContent);

        // Wrap in bordered frame
        $result = $frameStyle->render($paddedInner);

        // Add shadow if enabled
        if ($this->showShadow) {
            $result = $this->addShadow($result);
        }

        return $result;
    }

    /**
     * Build the title bar string.
     */
    private function buildTitleBar(int $width): string
    {
        $innerWidth = $width - 2;  // Inside borders

        // Title bar content
        $titleText = $this->title ?? '';
        $titleColor = $this->titleColor ?? Color::hex('#874BFD');

        // Build controls if enabled
        $controlsStr = '';
        if ($this->controls === self::ControlsAll) {
            $controlsStr = '[-][□][X]';
        } elseif ($this->controls === self::ControlsClose) {
            $controlsStr = '[X]';
        }

        // Calculate available space for title
        $controlsWidth = Width::string($controlsStr);
        $availableWidth = $innerWidth - $controlsWidth;

        if ($availableWidth <= 0) {
            return str_repeat(' ', $innerWidth);
        }

        // Format: [controls] title or title [controls]
        if ($controlsStr !== '') {
            // Controls on left, title on right (or vice versa based on preference)
            // Using: controls on left, title truncated on right
            $titleWidth = Width::string($titleText);
            if ($titleWidth > $availableWidth - 1) {
                $titleText = $this->truncateToWidth($titleText, $availableWidth - $controlsWidth - 1) . '…';
            }
            return $controlsStr . $titleText . str_repeat(' ', max(0, $availableWidth - Width::string($controlsStr . $titleText)));
        }

        // No controls - just centered or left-aligned title
        $titleWidth = Width::string($titleText);
        if ($titleWidth > $availableWidth) {
            $titleText = $this->truncateToWidth($titleText, $availableWidth - 1) . '…';
        }

        return $titleText . str_repeat(' ', max(0, $availableWidth - Width::string($titleText)));
    }

    /**
     * Render the content at the given dimensions.
     */
    private function renderContent(int $innerW, int $innerH): string
    {
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
            // Account for title bar in height
            $contentH = max(1, $innerH - 1);
            $sized = $this->content->setSize($innerW, $contentH);
            return $sized->render();
        }

        $rendered = $this->content->render();
        $lines = explode("\n", $rendered);

        $adjusted = [];
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($lineWidth > $innerW) {
                $line = $this->truncateToWidth($line, $innerW);
            } elseif ($lineWidth < $innerW) {
                $line = $line . str_repeat(' ', $innerW - $lineWidth);
            }
            $adjusted[] = $line;
        }

        while (count($adjusted) < $innerH - 1) {  // -1 for title bar
            $adjusted[] = str_repeat(' ', $innerW);
        }

        return implode("\n", array_slice($adjusted, 0, max(1, $innerH - 1)));
    }

    /**
     * Add a simple shadow effect to rendered output.
     */
    private function addShadow(string $content): string
    {
        $lines = explode("\n", $content);
        if (empty($lines)) {
            return $content;
        }

        $maxWidth = 0;
        foreach ($lines as $line) {
            $w = Width::string($line);
            if ($w > $maxWidth) {
                $maxWidth = $w;
            }
        }

        // Build shadow line
        $shadowLine = str_repeat('░', $maxWidth);

        // Add shadow character to each line and append shadow row
        $result = [];
        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            $paddedLine = $line . str_repeat(' ', max(0, $maxWidth - $lineWidth));
            $result[] = $paddedLine . '░';
        }
        $result[] = str_repeat(' ', $maxWidth) . '░';

        return implode("\n", $result);
    }

    /**
     * Truncate a string to fit within the given width.
     */
    private function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (Width::string($s) <= $width) {
            return $s;
        }
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int)(($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (Width::string($candidate) <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $result = mb_substr($s, 0, max(1, $lo), 'UTF-8');
        return $result . '…';
    }

    /**
     * Calculate the inner area available for content.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 0;
        $h = $this->height ?? 0;

        if ($w <= 0 || $h <= 0) {
            return [0, 0];
        }

        // Subtract borders (2 cells each axis) and title bar (1 row)
        [$paddingTop, $paddingRight, $paddingBottom, $paddingLeft] = $this->padding;

        $innerW = $w - 2 - $paddingLeft - $paddingRight;
        $innerH = $h - 3 - $paddingTop - $paddingBottom;  // -3 for title bar, top border, bottom border

        return [max(0, $innerW), max(0, $innerH)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the window title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            content: $this->content,
            title: $title,
            titleColor: $this->titleColor,
            borderColor: $this->borderColor,
            padding: $this->padding,
            controls: $this->controls,
            showShadow: $this->showShadow,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $color,
            borderColor: $this->borderColor,
            padding: $this->padding,
            controls: $this->controls,
            showShadow: $this->showShadow,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $this->titleColor,
            borderColor: $color,
            padding: $this->padding,
            controls: $this->controls,
            showShadow: $this->showShadow,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set uniform padding on all sides.
     */
    public function withPadding(int $n): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $this->titleColor,
            borderColor: $this->borderColor,
            padding: [$n, $n, $n, $n],
            controls: $this->controls,
            showShadow: $this->showShadow,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set padding with separate vertical and horizontal values.
     */
    public function withPaddingXY(int $vertical, int $horizontal): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $this->titleColor,
            borderColor: $this->borderColor,
            padding: [$vertical, $horizontal, $vertical, $horizontal],
            controls: $this->controls,
            showShadow: $this->showShadow,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the window control buttons.
     *
     * @param string $controls ControlsNone|ControlsClose|ControlsAll
     */
    public function withControls(string $controls): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $this->titleColor,
            borderColor: $this->borderColor,
            padding: $this->padding,
            controls: $controls,
            showShadow: $this->showShadow,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Enable or disable the shadow effect.
     */
    public function withShadow(bool $show): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $this->titleColor,
            borderColor: $this->borderColor,
            padding: $this->padding,
            controls: $this->controls,
            showShadow: $show,
            verticalAlign: $this->verticalAlign,
        );
    }

    /**
     * Set the vertical alignment.
     */
    public function withVerticalAlign(VAlign $align): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            titleColor: $this->titleColor,
            borderColor: $this->borderColor,
            padding: $this->padding,
            controls: $this->controls,
            showShadow: $this->showShadow,
            verticalAlign: $align,
        );
    }
}
