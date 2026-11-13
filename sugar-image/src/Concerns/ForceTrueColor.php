<?php

declare(strict_types=1);

namespace SugarCraft\Image\Concerns;

use SugarCraft\Image\Lang;

/**
 * The one truecolor gate for every GD image this lib reads pixels from.
 *
 * Pixel readers (PixelGrid, RawRgba, the text tiers' samplers) all assume
 * 24-bit truecolor; a palette image must be converted before sampling or
 * the caller silently reads palette-INDEXED pixels as if they were RGB.
 * The conversion itself is fallible (ext-gd returns false when the
 * allocation behind it fails), and fifteen hand-rolled
 * `if (!imageistruecolor($x)) { imagepalettetotruecolor($x); }` blocks
 * each dropped that return value — an exhausted-memory conversion rode on
 * as garbage instead of failing loud. One door, one truth, now:
 * {@see GdCallIdiomCensusTest} pins that no src file calls
 * imagepalettetotruecolor outside this class.
 */
final class ForceTrueColor
{
    private function __construct()
    {
    }

    /**
     * Convert a palette image to truecolor in place, or refuse loudly.
     *
     * A no-op when the image already is truecolor. The @-suppression
     * keeps ext-gd's own warning off the stream on the failure path —
     * the throw is the contract, the warning is noise.
     *
     * @throws \RuntimeException when ext-gd refuses the conversion
     */
    public static function convert(\GdImage $image): void
    {
        if (imageistruecolor($image)) {
            return;
        }

        if (!@imagepalettetotruecolor($image)) {
            throw new \RuntimeException(Lang::t('gd.palette_conversion_failed'));
        }
    }
}
