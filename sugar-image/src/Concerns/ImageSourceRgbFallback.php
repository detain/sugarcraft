<?php

declare(strict_types=1);

namespace SugarCraft\Image\Concerns;

use SugarCraft\Image\ImageSource;

/**
 * Default {@see \SugarCraft\Image\Renderer\Renderer} raw-pixel and frame-present
 * methods for backends whose output has no id-addressable frame.
 *
 * The text tiers and Sixel have nothing to delete by id ({@see delete()}
 * returns '' or the cell grid simply overwrites), so their present() is the
 * plain render with the previous frame's delete in front, and renderRgb()
 * routes the raw RGBA through an ImageSource. Kitty and iTerm2 override
 * renderRgb() with a direct encoder so the PNG container is never built.
 */
trait ImageSourceRgbFallback
{
    public function renderRgb(string $rgba, int $pixelWidth, int $pixelHeight, int $width, ?int $height = null): string
    {
        return $this->render(ImageSource::fromRgb($rgba, $pixelWidth, $pixelHeight, true), $width, $height);
    }

    public function present(ImageSource $image, int $width, ?int $height, string $imageId, ?string $prevId): string
    {
        $prefix = $prevId === null ? '' : $this->delete($prevId);

        return $prefix . $this->render($image, $width, $height);
    }

    public function supportsIdDelete(): bool
    {
        return false;
    }
}
