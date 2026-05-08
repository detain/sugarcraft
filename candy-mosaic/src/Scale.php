<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Image scaling / cropping modes used when rendering into a fixed cell grid.
 */
enum Scale
{
    /**
     * Preserve aspect ratio; fit the entire image within the target bounds.
     * If the image is smaller than the target, it is centred with padding.
     * The rendered size is ≤ the target cell dimensions.
     */
    case Fit;

    /**
     * Preserve aspect ratio; fill the entire target bounds.
     * Parts of the image that overflow the target are cropped.
     * The rendered size is ≥ the target cell dimensions.
     */
    case Fill;

    /**
     * Ignore aspect ratio; stretch or squash the image to exactly
     * the target cell dimensions.
     */
    case Stretch;

    /**
     * No resize is applied.  If the image is larger than the target
     * bounds it is clipped; if smaller it is rendered at its native
     * size with no padding.
     */
    case None;

    /**
     * Explicitly crop the image to the target cell dimensions, taking
     * the centre-most region at the source image's native pixel density.
     */
    case Crop;

    /**
     * Compute source crop rect and destination dimensions for this scale mode.
     *
     * @param int $srcW  Source image width in pixels
     * @param int $srcH  Source image height in pixels
     * @param int $dstW  Target width in cells or pixels (renderer-dependent)
     * @param int $dstH  Target height in cells or pixels (renderer-dependent)
     * @return array{srcX:int, srcY:int, srcW:int, srcH:int, dstW:int, dstH:int}
     *                  Source crop rect and the destination size to pass to the
     *                  renderer.  dstW/dstH are > 0.
     */
    public function computeDimensions(int $srcW, int $srcH, int $dstW, int $dstH): array
    {
        if ($dstW <= 0 || $dstH <= 0 || $srcW <= 0 || $srcH <= 0) {
            return ['srcX' => 0, 'srcY' => 0, 'srcW' => $srcW, 'srcH' => $srcH,
                    'dstW' => max(1, $dstW), 'dstH' => max(1, $dstH)];
        }

        return match ($this) {
            self::Fit     => $this->fit($srcW, $srcH, $dstW, $dstH),
            self::Fill    => $this->fill($srcW, $srcH, $dstW, $dstH),
            self::Stretch => ['srcX' => 0, 'srcY' => 0, 'srcW' => $srcW, 'srcH' => $srcH,
                              'dstW' => $dstW, 'dstH' => $dstH],
            self::None    => ['srcX' => 0, 'srcY' => 0, 'srcW' => $srcW, 'srcH' => $srcH,
                              'dstW' => $srcW, 'dstH' => $srcH],
            self::Crop    => $this->crop($srcW, $srcH, $dstW, $dstH),
        };
    }

    /**
     * Fit: scale so the entire image fits within dst, preserving aspect ratio.
     * Scales by the smaller factor so neither dimension exceeds target.
     */
    private function fit(int $srcW, int $srcH, int $dstW, int $dstH): array
    {
        $factor = min($dstW / $srcW, $dstH / $srcH);
        $renderW = (int) round($srcW * $factor);
        $renderH = (int) round($srcH * $factor);

        return [
            'srcX' => 0, 'srcY' => 0,
            'srcW' => $srcW, 'srcH' => $srcH,
            'dstW' => max(1, $renderW),
            'dstH' => max(1, $renderH),
        ];
    }

    /**
     * Fill: scale so the image covers dst, preserving aspect ratio.
     * Scales by the larger factor; overflow is cropped from the centre.
     */
    private function fill(int $srcW, int $srcH, int $dstW, int $dstH): array
    {
        $factor = max($dstW / $srcW, $dstH / $srcH);
        $scaleW = $srcW * $factor;
        $scaleH = $srcH * $factor;

        // Crop from the centre to get the dst dimensions.
        $srcCropW = (int) round($dstW / $factor);
        $srcCropH = (int) round($dstH / $factor);
        $srcX = (int) (($srcW - $srcCropW) / 2);
        $srcY = (int) (($srcH - $srcCropH) / 2);

        return [
            'srcX' => $srcX, 'srcY' => $srcY,
            'srcW' => $srcCropW, 'srcH' => $srcCropH,
            'dstW' => $dstW, 'dstH' => $dstH,
        ];
    }

    /**
     * Crop: take the centre-most region of the source at its native pixel
     * density and scale it to exactly the destination dimensions.
     */
    private function crop(int $srcW, int $srcH, int $dstW, int $dstH): array
    {
        $srcCropW = (int) round($srcW * $dstW / $dstH * $srcH / $srcW);
        $srcCropH = (int) round($srcH * $dstH / $dstW * $srcW / $srcH);

        // If source is smaller than the computed crop region, use full source.
        if ($srcCropW > $srcW) { $srcCropW = $srcW; }
        if ($srcCropH > $srcH) { $srcCropH = $srcH; }

        $srcX = (int) (($srcW - $srcCropW) / 2);
        $srcY = (int) (($srcH - $srcCropH) / 2);

        return [
            'srcX' => $srcX, 'srcY' => $srcY,
            'srcW' => $srcCropW, 'srcH' => $srcCropH,
            'dstW' => $dstW, 'dstH' => $dstH,
        ];
    }
}
