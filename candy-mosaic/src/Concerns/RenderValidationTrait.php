<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Concerns;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;

/**
 * Shared validation and height-computation for renderers.
 *
 * Mirrors charmbracelet/mosaic render — all 7 renderers duplicate the same
 * 4-line validation + height-computation block. Extracting it here ensures
 * consistent error messages and reduces maintenance burden.
 */
trait RenderValidationTrait
{
    /**
     * Validate dimensions and compute the effective render height.
     *
     * Updates $height (by reference) to the computed effective height and
     * returns the same value. Callers should use the returned value as the
     * effective height is stored in $height for consistency.
     *
     * @param ImageSource $image  Source image for aspect-ratio computation
     * @param int         $width  Target width in terminal cells (must be > 0)
     * @param int|null    &$height On input: desired height (null = auto). On output: computed effective height
     * @return int The effective height to use for rendering
     */
    protected function prepareRender(ImageSource $image, int $width, ?int &$height): int
    {
        if ($width <= 0) {
            throw new \InvalidArgumentException(
                Lang::t('renderer.invalid_width', ['width' => $width])
            );
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(
                Lang::t('renderer.invalid_height', ['height' => $height])
            );
        }

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        $height = $effectiveHeight;

        return $effectiveHeight;
    }
}
