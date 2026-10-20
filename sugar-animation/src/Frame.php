<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

/**
 * One downsampled GIF frame. Stored as a 2-D grid of RGB triples or
 * null (transparent) in cell-coordinate space (post-downsample).
 * The `Renderer` walks the grid and emits ANSI escapes per cell.
 *
 * Optionally also carries the PRE-DOWNSAMPLE composited canvas as packed
 * RGBA bytes (E-07) — the single frame representation chicle hands every
 * loader (`guint8 *` pixels, `chicle-gif-loader.c` full-canvas frames).
 * Consumers that do their own cell work (sugar-image's GIF ingress) read
 * {@see self::rgbaBytes()} instead of re-walking the lossy grid; terminal
 * players keep using `$cells`. The capture is opt-in at decode time
 * ({@see Decoder::decodeBytes()}) so plain playback pays no extra memory.
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
     *   3 = restore-prev (restore the canvas to its pre-frame snapshot;
     *       implemented by {@see Decoder} via DISPOSAL_PREVIOUS compositing)
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
     * @param ?string $rgba  Packed RGBA bytes (row-major, 4 bytes per pixel) of the
     *                       PRE-DOWNSAMPLE composited canvas; null when the frame was
     *                       decoded without {@see Decoder::decodeBytes()}'s
     *                       `$withCanvasRgba` capture (E-07)
     * @param int $rgbaWidth  Canvas width the `$rgba` buffer is sized for (0 without capture)
     * @param int $rgbaHeight Canvas height the `$rgba` buffer is sized for (0 without capture)
     */
    public function __construct(
        public readonly array $cells,
        public readonly int $delay = 10,
        public readonly int $disposal = self::DISPOSAL_NONE,
        public readonly bool $transparent = false,
        public readonly ?string $rgba = null,
        public readonly int $rgbaWidth = 0,
        public readonly int $rgbaHeight = 0,
    ) {}

    /**
     * Whether this frame carries the full-canvas RGBA capture (E-07).
     */
    public function hasRgba(): bool
    {
        return $this->rgba !== null;
    }

    /**
     * The composited frame as packed RGBA bytes — row-major, 4 bytes per
     * pixel, `rgbaWidth() × rgbaHeight()` pixels. This is the chicle-parity
     * frame representation: lossless pixels, so a consumer can size its own
     * output at print time instead of inheriting this decoder's grid.
     *
     * Alpha mapping: the Decoder composites onto a canvas that is either
     * fully opaque or fully transparent (GIF has one transparent index), so
     * the alpha byte is 255 (opaque) or 0 (background shows through) — the
     * same contract {@see self::$cells} nulls encode, packed four times
     * tighter than the cell arrays.
     *
     * @throws \LogicException when the frame was decoded without the capture
     */
    public function rgbaBytes(): string
    {
        if ($this->rgba === null) {
            throw new \LogicException('Frame was decoded without a canvas RGBA capture; decode with $withCanvasRgba = true (Decoder::decodeBytes).');
        }

        return $this->rgba;
    }

    public function rgbaWidth(): int
    {
        return $this->rgbaWidth;
    }

    public function rgbaHeight(): int
    {
        return $this->rgbaHeight;
    }

    public function width(): int
    {
        return $this->cells === [] ? 0 : count($this->cells[0]);
    }

    public function height(): int
    {
        return count($this->cells);
    }
}
