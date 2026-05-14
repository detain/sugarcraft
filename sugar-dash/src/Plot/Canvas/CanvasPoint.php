<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Canvas;

/**
 * Point in 2D space.
 */
final readonly class CanvasPoint
{
    public function __construct(
        public int $x,
        public int $y,
    ) {}
}
