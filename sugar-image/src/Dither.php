<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Dithering algorithm selection for Sixel rendering.
 *
 * - `None` — nearest-color quantization, no dithering (fastest, flat fills)
 * - `FloydSteinberg` — classic FS: diffuses the whole error to 4 neighbors
 * - `Stucki` — Stucki variant: diffuses the whole error to 12 neighbors, slightly sharper
 * - `Atkinson` — Apple Atkinson: diffuses only ¾ of error (more contrast)
 * - `Ordered` — C-04: chafa-style ordered dither, a 16×16 Bayer texture biases each
 *   pixel before quantization (no error feedback; stateless per pixel)
 * - `Noise` — C-05: blue-noise ordered dither, a 64×64 CC0 noise table biases each
 *   pixel per channel before quantization (stateless; blue spectrum avoids visible
 *   hatch structure)
 *
 * Default: `FloydSteinberg` — best quality/speed trade-off, matches xterm.
 * The Ordered/Noise axes are strictly opt-in; the default diffusion path is
 * byte-frozen post-C-01.
 */
enum Dither: string
{
    case None          = 'none';
    case FloydSteinberg = 'floyd-steinberg';
    case Stucki         = 'stucki';
    case Atkinson       = 'atkinson';
    case Ordered        = 'ordered';
    case Noise          = 'noise';

    /**
     * Return the human-readable name for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::None           => 'None',
            self::FloydSteinberg => 'Floyd–Steinberg',
            self::Stucki         => 'Stucki',
            self::Atkinson       => 'Atkinson',
            self::Ordered        => 'Ordered',
            self::Noise          => 'Noise',
        };
    }
}
