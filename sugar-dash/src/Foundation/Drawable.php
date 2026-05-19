<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Anything that can be drawn into a Buffer at a specific Rect.
 *
 * Mirrors charmbracelet/lipgloss.Drawable / bubbletea Tea.Model geometry.
 */
interface Drawable
{
    /**
     * Get the rectangle this drawable occupies.
     */
    public function getRect(): Rect;

    /**
     * Set the rectangle this drawable should fill.
     *
     * @return $this for fluent composition
     */
    public function setRect(Rect $rect): self;

    /**
     * Apply a theme to this drawable, returning a new instance with
     * the theme's colors. Default implementation returns $this
     * (no-op) so existing drawables continue to work unchanged.
     *
     * Layout containers should fan the theme down to their children
     * via their own withTheme() implementations.
     */
    public function withTheme(Theme $theme): self;

    /**
     * Draw the content into the provided buffer.
     */
    public function draw(Buffer $buffer): void;
}
