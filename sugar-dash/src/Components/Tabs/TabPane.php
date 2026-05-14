<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tabs;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A pane container for tab content.
 *
 * Wrapper component that renders content within a tab pane context,
 * providing optional styling for the content area.
 *
 * Mirrors pane/container UI patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class TabPane implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly ?Color $borderColor = null,
        private readonly string $borderChar = '│',
        private readonly bool $padded = true,
    ) {}

    /**
     * Create a new tab pane with default styling.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            borderColor: Color::hex('#874BFD'),
            borderChar: '│',
            padded: true,
        );
    }

    /**
     * Set the allocated dimensions for this tab pane.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the tab pane.
     */
    public function render(): string
    {
        $contentWidth = $this->width;
        $contentHeight = $this->height;

        // Size the content if we have dimensions
        $content = $this->content;
        if ($content instanceof \SugarCraft\Dash\Foundation\Sizer && $contentWidth !== null && $contentHeight !== null) {
            $content = $content->setSize($contentWidth, $contentHeight);
        }

        $rendered = $content->render();

        // Apply border styling if color is set
        if ($this->borderColor !== null) {
            $rendered = $this->borderColor->toFg(ColorProfile::TrueColor) . $rendered . Ansi::reset();
        }

        return $rendered;
    }

    /**
     * Calculate the natural dimensions of this tab pane.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $content = $this->content;

        if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
            return $content->getInnerSize();
        }

        return [0, 0];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            borderColor: $color,
            borderChar: $this->borderChar,
            padded: $this->padded,
        );
    }

    /**
     * Set the border character.
     */
    public function withBorderChar(string $char): self
    {
        return new self(
            content: $this->content,
            borderColor: $this->borderColor,
            borderChar: $char,
            padded: $this->padded,
        );
    }

    /**
     * Set the padded flag.
     */
    public function withPadded(bool $padded): self
    {
        return new self(
            content: $this->content,
            borderColor: $this->borderColor,
            borderChar: $this->borderChar,
            padded: $padded,
        );
    }
}
