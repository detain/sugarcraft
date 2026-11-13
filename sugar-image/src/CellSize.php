<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Terminal cell pixel dimensions (width × height in pixels).
 *
 * Mirrors no upstream — SugarCraft addition. Upstream passes cell
 * dimensions as two numbers at a time; naming the pair as a value object
 * is this library's own.
 */
final class CellSize
{
    public function __construct(
        public readonly int $cellWidth,
        public readonly int $cellHeight,
    ) {}
}
