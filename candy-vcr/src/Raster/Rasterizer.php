<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

use SugarCraft\Vt\Snapshot;

/**
 * Interface for Snapshot-to-image rasterizers.
 */
interface Rasterizer
{
    /**
     * Rasterize a terminal Snapshot to an image.
     *
     * @return \GdImage|\Imagick
     */
    public function rasterize(Snapshot $snapshot, int $cellW, int $cellH, ?FontLoader $fonts = null, bool $renderCursor = true): \GdImage|\Imagick;
}
