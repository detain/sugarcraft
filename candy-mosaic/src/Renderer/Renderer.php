<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Mosaic\ImageSource;

/**
 * Contract for image-to-terminal renderers.
 *
 * Each backend (Sixel, Kitty, iTerm2, HalfBlock) implements this
 * interface. Renderers are immutable — all configuration is supplied
 * at construction time via their own factory methods.
 */
interface Renderer
{
    /**
     * Render `$image` into ANSI bytes at the given cell dimensions.
     *
     * @param ImageSource $image  Source image
     * @param int         $width  Target width in terminal cells
     * @param int|null    $height Target height in terminal cells
     *                            (null = auto from aspect ratio)
     * @return string             Raw ANSI escape bytes — write directly to stdout
     */
    public function render(ImageSource $image, int $width, ?int $height = null): string;

    /**
     * Stable backend identifier: 'sixel' | 'kitty' | 'iterm2' | 'halfblock'.
     */
    public function name(): string;

    /**
     * True if this backend supports partial transparency (alpha channel).
     * Half-block does not; Sixel and Kitty do.
     */
    public function supportsAlpha(): bool;
}
