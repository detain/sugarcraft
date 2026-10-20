<?php

declare(strict_types=1);

namespace SugarCraft\Image\Concerns;

use SugarCraft\Image\Codec\RawRgba;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\Support\GdAvailability;

/**
 * Shared "obtain a raw RGBA buffer for an ImageSource" step for the
 * F-13 GD-free transmission paths.
 *
 * Two ingress shapes:
 *  - caller-supplied `$rgba` (w×h×4 bytes of the SOURCE's pixel grid) —
 *    never touches GD, which is what makes the kitty f=32 / iTerm2 TIFF
 *    paths transmissible without the extension once ingress decouples;
 *  - GD decode of the stored bytes — guarded through
 *    {@see GdAvailability} so a genuinely GD-less runtime gets a clean
 *    translated refusal instead of an undefined-function fatal.
 */
trait ResolvesRawPixelsTrait
{
    /**
     * @throws \InvalidArgumentException  when a supplied buffer doesn't match the pixel grid
     * @throws \RuntimeException          when GD is needed but unavailable, or decodes fail
     */
    protected function resolveRgba(ImageSource $image, ?string $rgba): string
    {
        if ($rgba !== null) {
            return RawRgba::validate($rgba, $image->width, $image->height);
        }

        if (!GdAvailability::available()) {
            throw new \RuntimeException(Lang::t('renderer.gd_unavailable'));
        }

        $src = @imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        try {
            return RawRgba::fromGdImage($src);
        } finally {
            imagedestroy($src);
        }
    }
}
