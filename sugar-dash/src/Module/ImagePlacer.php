<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Optional interface for modules that can specify image placements.
 *
 * Modules implementing this interface can position images within their
 * rendered content at specific coordinates.
 *
 * Mirrors the lattice ImagePlacer pattern.
 */
interface ImagePlacer
{
    /**
     * Get the image placements for this module.
     *
     * @return list<ImagePlacement>
     */
    public function imagePlacements(): array;
}
