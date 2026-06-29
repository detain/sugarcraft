<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

/**
 * One downsampled GIF frame. Stored as a 2-D grid of RGB triples or
 * null (transparent) in cell-coordinate space (post-downsample).
 * The `Renderer` walks the grid and emits ANSI escapes per cell.
 *
 * @phpstan-type RgbCell array{0:int,1:int,2:int}
 */
final class Frame
{
    /**
     * Disposal method constants from GIF89a spec.
     * Per GCE transparency/disposal (gce-transparency-disposal):
     *   0 = none (no disposal, leave composite as-is)
     *   1 = keep  (do not dispose, leave the frame in place)
     *   2 = restore-bg (restore to background/transparent fill)
     *   3 = restore-prev (restore to previous — unsupported; treated as NONE)
     */
    public const DISPOSAL_NONE        = 0;
    public const DISPOSAL_KEEP        = 1;
    public const DISPOSAL_BACKGROUND  = 2;
    public const DISPOSAL_PREVIOUS    = 3;

    /**
     * @param list<list<array{0:int,1:int,2:int}|null>> $cells  null = transparent
     * @param int $delay  Centiseconds (1/100 s) from GIF Graphic Control Extension; 0 means no delay specified
     * @param int $disposal  Disposal method (0-3) from GIF89a Graphic Control Extension
     * @param bool $transparent  Whether this frame has a transparent pixel
     */
    public function __construct(
        public readonly array $cells,
        public readonly int $delay = 10,
        public readonly int $disposal = self::DISPOSAL_NONE,
        public readonly bool $transparent = false,
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
