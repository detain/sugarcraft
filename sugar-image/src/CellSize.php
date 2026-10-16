<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Terminal cell pixel dimensions (width × height in pixels).
 */
final class CellSize
{
    public function __construct(
        public readonly int $cellWidth,
        public readonly int $cellHeight,
    ) {}
}
