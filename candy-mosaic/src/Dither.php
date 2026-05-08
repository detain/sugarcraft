<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Error-diffusion dithering algorithm selection for Sixel rendering.
 *
 * - `None` — nearest-color quantization, no dithering (fastest, flat fills)
 * - `FloydSteinberg` — classic FS: diffuses ¾ of error to 3 neighbors
 * - `Stucki` — Stucki variant: diffuses to 12 neighbors, slightly sharper
 * - `Atkinson` — Apple Atkinson: diffuses only ¾ of error (more contrast)
 *
 * Default: `FloydSteinberg` — best quality/speed trade-off, matches xterm.
 */
enum Dither: string
{
    case None          = 'none';
    case FloydSteinberg = 'floyd-steinberg';
    case Stucki         = 'stucki';
    case Atkinson       = 'atkinson';

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
        };
    }
}
