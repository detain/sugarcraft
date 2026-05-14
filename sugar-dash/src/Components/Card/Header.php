<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A page header component for displaying titles and subtitles.
 *
 * Headers typically appear at the top of a page or section and can
 * include a title, subtitle, breadcrumb path, and optional actions.
 * Supports left, center, and right alignment.
 *
 * Mirrors header/page-title concepts adapted to PHP with wither-style immutable setters.
 */
final class Header implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $title = '',
        private readonly string $subtitle = '',
        private readonly string $alignment = 'left',
        private readonly ?Color $titleColor = null,
        private readonly ?Color $subtitleColor = null,
        private readonly ?Color $borderColor = null,
        private readonly bool $showDivider = false,
    ) {}

    /**
     * Create a new header with default styling.
     */
    public static function new(string $title = ''): self
    {
        return new self(
            title: $title,
            subtitle: '',
            alignment: 'left',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            borderColor: Color::hex('#3F3F46'),
            showDivider: false,
        );
    }

    /**
     * Create a centered header.
     */
    public static function centered(string $title): self
    {
        return new self(
            title: $title,
            subtitle: '',
            alignment: 'center',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            borderColor: Color::hex('#3F3F46'),
            showDivider: false,
        );
    }

    /**
     * Create a large hero header with title and subtitle.
     */
    public static function hero(string $title, string $subtitle = ''): self
    {
        return new self(
            title: $title,
            subtitle: $subtitle,
            alignment: 'center',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            borderColor: null,
            showDivider: false,
        );
    }

    /**
     * Set the allocated dimensions for this header.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the header as a string.
     */
    public function render(): string
    {
        $useWidth = $this->getWidth();

        if ($this->title === '') {
            return '';
        }

        $result = '';

        // Title line
        if ($this->alignment === 'center') {
            $result .= $this->renderCenteredLine($useWidth);
        } elseif ($this->alignment === 'right') {
            $result .= $this->renderRightAlignedLine($useWidth);
        } else {
            $result .= $this->renderLeftAlignedLine($useWidth);
        }

        // Subtitle line
        if ($this->subtitle !== '') {
            $result .= "\n";
            if ($this->alignment === 'center') {
                $result .= $this->renderCenteredSubtitle($useWidth);
            } elseif ($this->alignment === 'right') {
                $result .= $this->renderRightAlignedSubtitle($useWidth);
            } else {
                $result .= $this->renderLeftAlignedSubtitle($useWidth);
            }
        }

        // Optional divider
        if ($this->showDivider) {
            $result .= "\n" . $this->renderDivider($useWidth);
        }

        return $result;
    }

    /**
     * Render a left-aligned title line.
     */
    private function renderLeftAlignedLine(int $width): string
    {
        $result = '';

        if ($this->titleColor !== null) {
            $result .= $this->titleColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $this->title;

        if ($this->subtitle === '' && !$this->showDivider) {
            $result .= Ansi::reset();
            return $result;
        }

        // Pad to width if we have a subtitle or divider
        $titleWidth = Width::string($this->title);
        $padding = max(0, $width - $titleWidth);
        $result .= str_repeat(' ', $padding);

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render a centered title line.
     */
    private function renderCenteredLine(int $width): string
    {
        $result = '';

        $titleWidth = Width::string($this->title);
        $leftPad = (int) floor(($width - $titleWidth) / 2);
        $rightPad = $width - $titleWidth - $leftPad;

        if ($leftPad > 0) {
            $result .= str_repeat(' ', $leftPad);
        }

        if ($this->titleColor !== null) {
            $result .= $this->titleColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $this->title;

        if ($this->titleColor !== null) {
            $result .= Ansi::reset();
        }

        if ($rightPad > 0) {
            $result .= str_repeat(' ', $rightPad);
        }

        return $result;
    }

    /**
     * Render a right-aligned title line.
     */
    private function renderRightAlignedLine(int $width): string
    {
        $result = '';

        $titleWidth = Width::string($this->title);
        $padding = max(0, $width - $titleWidth);

        $result .= str_repeat(' ', $padding);

        if ($this->titleColor !== null) {
            $result .= $this->titleColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $this->title;
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render a left-aligned subtitle line.
     */
    private function renderLeftAlignedSubtitle(int $width): string
    {
        $result = '';

        if ($this->subtitleColor !== null) {
            $result .= $this->subtitleColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $this->subtitle;

        $subtitleWidth = Width::string($this->subtitle);
        $padding = max(0, $width - $subtitleWidth);
        $result .= str_repeat(' ', $padding);

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render a centered subtitle line.
     */
    private function renderCenteredSubtitle(int $width): string
    {
        $result = '';

        $subtitleWidth = Width::string($this->subtitle);
        $leftPad = (int) floor(($width - $subtitleWidth) / 2);
        $rightPad = $width - $subtitleWidth - $leftPad;

        if ($leftPad > 0) {
            $result .= str_repeat(' ', $leftPad);
        }

        if ($this->subtitleColor !== null) {
            $result .= $this->subtitleColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $this->subtitle;

        if ($this->subtitleColor !== null) {
            $result .= Ansi::reset();
        }

        if ($rightPad > 0) {
            $result .= str_repeat(' ', $rightPad);
        }

        return $result;
    }

    /**
     * Render a right-aligned subtitle line.
     */
    private function renderRightAlignedSubtitle(int $width): string
    {
        $result = '';

        $subtitleWidth = Width::string($this->subtitle);
        $padding = max(0, $width - $subtitleWidth);

        $result .= str_repeat(' ', $padding);

        if ($this->subtitleColor !== null) {
            $result .= $this->subtitleColor->toFg(ColorProfile::TrueColor);
        }

        $result .= $this->subtitle;
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render a divider line.
     */
    private function renderDivider(int $width): string
    {
        $result = '';

        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        $result .= str_repeat('─', $width);

        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Get the width to use for this header.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Calculate natural width from content
        $titleWidth = Width::string($this->title);
        $subtitleWidth = Width::string($this->subtitle);

        return max($titleWidth, $subtitleWidth);
    }

    /**
     * Calculate the natural dimensions of this header.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        $height = 1; // Title
        if ($this->subtitle !== '') {
            $height++;
        }
        if ($this->showDivider) {
            $height++;
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the header title.
     */
    public function withTitle(string $title): self
    {
        return new self(
            title: $title,
            subtitle: $this->subtitle,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            borderColor: $this->borderColor,
            showDivider: $this->showDivider,
        );
    }

    /**
     * Set the header subtitle.
     */
    public function withSubtitle(string $subtitle): self
    {
        return new self(
            title: $this->title,
            subtitle: $subtitle,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            borderColor: $this->borderColor,
            showDivider: $this->showDivider,
        );
    }

    /**
     * Set the alignment.
     */
    public function withAlignment(string $alignment): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            alignment: $alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            borderColor: $this->borderColor,
            showDivider: $this->showDivider,
        );
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            alignment: $this->alignment,
            titleColor: $color,
            subtitleColor: $this->subtitleColor,
            borderColor: $this->borderColor,
            showDivider: $this->showDivider,
        );
    }

    /**
     * Set the subtitle color.
     */
    public function withSubtitleColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $color,
            borderColor: $this->borderColor,
            showDivider: $this->showDivider,
        );
    }

    /**
     * Set the border/divider color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            borderColor: $color,
            showDivider: $this->showDivider,
        );
    }

    /**
     * Show a divider line below the header.
     */
    public function withDivider(bool $show = true): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            borderColor: $this->borderColor,
            showDivider: $show,
        );
    }
}
