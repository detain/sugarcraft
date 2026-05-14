<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A hero/jumbotron section component.
 *
 * Displays a prominent hero section with:
 * - Large title text
 * - Optional subtitle/description
 * - Optional call-to-action button label
 * - Optional background accent or border
 * - Centered or left-aligned content
 *
 * Mirrors jumbotron/hero-section concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Jumbotron implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $title = '',
        private readonly string $subtitle = '',
        private readonly string $buttonText = '',
        private readonly string $alignment = 'center',
        private readonly ?Color $titleColor = null,
        private readonly ?Color $subtitleColor = null,
        private readonly ?Color $buttonColor = null,
        private readonly ?Color $borderColor = null,
        private readonly bool $showBorder = true,
        private readonly bool $showShadow = false,
    ) {}

    /**
     * Create a new jumbotron with default styling.
     */
    public static function new(string $title, string $subtitle = ''): self
    {
        return new self(
            title: $title,
            subtitle: $subtitle,
            buttonText: '',
            alignment: 'center',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            buttonColor: Color::hex('#874BFD'),
            borderColor: Color::hex('#3F3F46'),
            showBorder: true,
            showShadow: false,
        );
    }

    /**
     * Create a jumbotron with a call-to-action button.
     */
    public static function withButton(string $title, string $subtitle, string $buttonText): self
    {
        return new self(
            title: $title,
            subtitle: $subtitle,
            buttonText: $buttonText,
            alignment: 'center',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            buttonColor: Color::hex('#874BFD'),
            borderColor: Color::hex('#3F3F46'),
            showBorder: true,
            showShadow: false,
        );
    }

    /**
     * Create a minimal jumbotron without borders.
     */
    public static function minimal(string $title, string $subtitle = ''): self
    {
        return new self(
            title: $title,
            subtitle: $subtitle,
            buttonText: '',
            alignment: 'center',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            buttonColor: null,
            borderColor: null,
            showBorder: false,
            showShadow: false,
        );
    }

    /**
     * Create a left-aligned jumbotron.
     */
    public static function left(string $title, string $subtitle = ''): self
    {
        return new self(
            title: $title,
            subtitle: $subtitle,
            buttonText: '',
            alignment: 'left',
            titleColor: Color::hex('#FAFAFA'),
            subtitleColor: Color::hex('#A1A1AA'),
            buttonColor: Color::hex('#874BFD'),
            borderColor: Color::hex('#3F3F46'),
            showBorder: true,
            showShadow: false,
        );
    }

    /**
     * Set the allocated dimensions for this jumbotron.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the jumbotron as a string.
     */
    public function render(): string
    {
        $useWidth = $this->getWidth();

        if ($this->title === '') {
            return '';
        }

        $lines = [];

        // Top border
        if ($this->showBorder && $this->borderColor !== null) {
            $lines[] = $this->renderTopBorder($useWidth);
        }

        // Shadow effect
        if ($this->showShadow) {
            $lines[] = $this->renderShadowLine($useWidth);
        }

        // Title
        $lines[] = $this->renderTitleLine($useWidth);

        // Subtitle
        if ($this->subtitle !== '') {
            $lines[] = $this->renderSubtitleLine($useWidth);
        }

        // Button
        if ($this->buttonText !== '') {
            $lines[] = '';
            $lines[] = $this->renderButtonLine($useWidth);
        }

        // Bottom border
        if ($this->showBorder && $this->borderColor !== null) {
            $lines[] = '';
            $lines[] = $this->renderBottomBorder($useWidth);
        }

        return implode("\n", $lines);
    }

    /**
     * Render the top border line.
     */
    private function renderTopBorder(int $width): string
    {
        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) .
                '┌' . str_repeat('─', $width - 2) . '┐' .
                Ansi::reset();
        }
        return '┌' . str_repeat('─', $width - 2) . '┐';
    }

    /**
     * Render a shadow line (decorative).
     */
    private function renderShadowLine(int $width): string
    {
        $shadow = '│' . str_repeat('░', $width - 2) . '│';
        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) . $shadow . Ansi::reset();
        }
        return $shadow;
    }

    /**
     * Render the title line.
     */
    private function renderTitleLine(int $width): string
    {
        if ($this->showBorder) {
            $contentWidth = $width - 4; // Account for │ and spaces
        } else {
            $contentWidth = $width;
        }

        $titleWidth = Width::string($this->title);
        $padding = max(0, $contentWidth - $titleWidth);

        $line = '';

        if ($this->showBorder) {
            $line .= '│ ';
            $line .= str_repeat(' ', (int) floor($padding / 2));

            if ($this->titleColor !== null) {
                $line .= $this->titleColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $this->title;
            $line .= Ansi::reset();

            $line .= str_repeat(' ', (int) ceil($padding / 2));
            $line .= ' │';
        } else {
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $line .= str_repeat(' ', $leftPad);

            if ($this->titleColor !== null) {
                $line .= $this->titleColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $this->title;
            $line .= Ansi::reset();

            $line .= str_repeat(' ', $rightPad);
        }

        return $line;
    }

    /**
     * Render the subtitle line.
     */
    private function renderSubtitleLine(int $width): string
    {
        if ($this->showBorder) {
            $contentWidth = $width - 4;
        } else {
            $contentWidth = $width;
        }

        $subtitleWidth = Width::string($this->subtitle);
        $padding = max(0, $contentWidth - $subtitleWidth);

        $line = '';

        if ($this->showBorder) {
            $line .= '│ ';
            $line .= str_repeat(' ', (int) floor($padding / 2));

            if ($this->subtitleColor !== null) {
                $line .= $this->subtitleColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $this->subtitle;
            $line .= Ansi::reset();

            $line .= str_repeat(' ', (int) ceil($padding / 2));
            $line .= ' │';
        } else {
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $line .= str_repeat(' ', $leftPad);

            if ($this->subtitleColor !== null) {
                $line .= $this->subtitleColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $this->subtitle;
            $line .= Ansi::reset();

            $line .= str_repeat(' ', $rightPad);
        }

        return $line;
    }

    /**
     * Render the button line.
     */
    private function renderButtonLine(int $width): string
    {
        $buttonContent = '[' . $this->buttonText . ']';
        $buttonWidth = Width::string($buttonContent);

        if ($this->showBorder) {
            $contentWidth = $width - 4;
            $padding = max(0, $contentWidth - $buttonWidth);
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $line = '│ ';
            $line .= str_repeat(' ', $leftPad);

            if ($this->buttonColor !== null) {
                $line .= $this->buttonColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $buttonContent;
            $line .= Ansi::reset();

            $line .= str_repeat(' ', $rightPad);
            $line .= ' │';
        } else {
            $padding = max(0, $width - $buttonWidth);
            $leftPad = (int) floor($padding / 2);
            $rightPad = $padding - $leftPad;

            $line = str_repeat(' ', $leftPad);

            if ($this->buttonColor !== null) {
                $line .= $this->buttonColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $buttonContent;
            $line .= Ansi::reset();

            $line .= str_repeat(' ', $rightPad);
        }

        return $line;
    }

    /**
     * Render the bottom border line.
     */
    private function renderBottomBorder(int $width): string
    {
        if ($this->borderColor !== null) {
            return $this->borderColor->toFg(ColorProfile::TrueColor) .
                '└' . str_repeat('─', $width - 2) . '┘' .
                Ansi::reset();
        }
        return '└' . str_repeat('─', $width - 2) . '┘';
    }

    /**
     * Calculate the natural dimensions of this jumbotron.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->getWidth();

        $height = 0;

        if ($this->showBorder && $this->borderColor !== null) {
            $height += 1; // top border
        }
        if ($this->showShadow) {
            $height += 1; // shadow line
        }
        $height += 1; // title

        if ($this->subtitle !== '') {
            $height += 1; // subtitle
        }

        if ($this->buttonText !== '') {
            $height += 2; // empty line + button
        }

        if ($this->showBorder && $this->borderColor !== null) {
            $height += 1; // bottom border
        }

        return [$width, $height];
    }

    /**
     * Get the width to use for this jumbotron.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }

        // Calculate natural width from content
        $titleWidth = Width::string($this->title);
        $subtitleWidth = Width::string($this->subtitle);
        $buttonWidth = $this->buttonText !== '' ? Width::string('[' . $this->buttonText . ']') : 0;

        $contentWidth = max($titleWidth, $subtitleWidth, $buttonWidth);

        // Add padding for borders
        return $this->showBorder ? $contentWidth + 4 : $contentWidth;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the title.
     */
    public function withTitle(string $title): self
    {
        return new self(
            title: $title,
            subtitle: $this->subtitle,
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
        );
    }

    /**
     * Set the subtitle.
     */
    public function withSubtitle(string $subtitle): self
    {
        return new self(
            title: $this->title,
            subtitle: $subtitle,
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
        );
    }

    /**
     * Set the button text.
     */
    public function withButtonText(string $buttonText): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            buttonText: $buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
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
            buttonText: $this->buttonText,
            alignment: $alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
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
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $color,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
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
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $color,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
        );
    }

    /**
     * Set the button color.
     */
    public function withButtonColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $color,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $color,
            showBorder: $this->showBorder,
            showShadow: $this->showShadow,
        );
    }

    /**
     * Toggle border visibility.
     */
    public function withBorder(bool $show): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $show,
            showShadow: $this->showShadow,
        );
    }

    /**
     * Toggle shadow effect.
     */
    public function withShadow(bool $show): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            buttonText: $this->buttonText,
            alignment: $this->alignment,
            titleColor: $this->titleColor,
            subtitleColor: $this->subtitleColor,
            buttonColor: $this->buttonColor,
            borderColor: $this->borderColor,
            showBorder: $this->showBorder,
            showShadow: $show,
        );
    }
}
