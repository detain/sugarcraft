<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

/**
 * One downsampled GIF frame. Stored as a 2-D grid of RGB triples in
 * cell-coordinate space (post-downsample). The `Renderer` walks the
 * grid and emits ANSI escapes per cell.
 *
 * @phpstan-type RgbCell array{0:int,1:int,2:int}
 */
final class Frame
{
    /**
     * @param list<list<array{0:int,1:int,2:int}>> $cells
     * @param int $delay  Centiseconds (1/100 s) from GIF Graphic Control Extension; 0 means no delay specified
     */
    public function __construct(
        public readonly array $cells,
        public readonly int $delay = 10,
    ) {}

    public function width(): int
    {
        return $this->cells === [] ? 0 : count($this->cells[0]);
    }

    public function height(): int
    {
        return count($this->cells);
    }
}
