<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

/**
 * C-04: Ordered-dither bias texture — an additive PRE-QUANTIZATION offset
 * consulted per pixel before the palette lookup, with no error feedback
 * (PC-8: this is chafa's bias model, not a matrix-threshold model).
 *
 * Mirrors chafa's chafa_gen_bayer_matrix() + fill_matrix()/fill_matrix_r()
 * from chafa/chafa-util.c: the recursion is transcribed call-for-call, and
 * the recenter step mirrors fill_matrix()'s `(v - maxval/2) * (256/maxval)
 * * magnitude` form.
 *
 * Rounding deviation (NTC-2, orchestrator-ruled): chafa adds +0.5f then
 * truncates toward zero via the gint cast — which biases negatives UP by
 * one unit ((int)(-127.5) == -127). PHP's round() is symmetric
 * (half away from zero), so at magnitude 1.0 this port yields the exact
 * -128..127 range its comment advertises, where chafa yields -127..127.
 * The symmetric rounding is deliberate and pinned by tests.
 */
final class OrderedTexture
{
    /**
     * @param list<int>       $data     Recentered bias values, layout [row][col][channel].
     * @param int             $size     Texture side length in samples (a power of two).
     * @param int             $channels Channels per sample; 1 = the same offset for all RGB
     *                                  channels (classic ordered Bayer, chafa gen_mods
     *                                  non-NOISE arm), 3 = decorrelated per-channel offsets.
     */
    private function __construct(
        public readonly array $data,
        public readonly int $size,
        public readonly int $channels,
    ) {
    }

    /**
     * Generate the 16×16 Bayer texture chafa uses for CHAFA_DITHER_MODE_ORDERED
     * (TEXTURE_DIM_SHIFT 4 in chafa-dither.c; magnitude is the already-folded
     * dither intensity).
     *
     * Mirrors chafa_gen_bayer_matrix (which asserts size ∈ {2,4,8,16}) at the
     * 16-sample edge chafa itself selects.
     */
    public static function bayer16(float $magnitude): self
    {
        $size   = 16;
        $maxval = $size * $size;

        /** @var list<int> $matrix */
        $matrix = array_fill(0, $maxval, 0);
        self::fillBayer($matrix, $size, $size, 0, 0, 0, 1);

        // fill_matrix() recenter: (v - maxval/2) * (256/maxval) * magnitude.
        $data = [];
        foreach ($matrix as $value) {
            $data[] = (int) round(($value - $maxval / 2.0) * (256.0 / $maxval) * $magnitude);
        }

        return new self($data, $size, 1);
    }

    /**
     * Transcription of fill_matrix_r(): recurse over quadrants in chafa's exact
     * order — top-left +0, bottom-right +step, top-right +2·step, bottom-left
     * +3·step, each with step × 4 — writing the accumulated value at sub_size 1.
     *
     * @param list<int> $matrix
     */
    private static function fillBayer(array &$matrix, int $size, int $subSize, int $x, int $y, int $value, int $step): void
    {
        if ($subSize === 1) {
            $matrix[$x + $y * $size] = $value;

            return;
        }

        $half = intdiv($subSize, 2);

        self::fillBayer($matrix, $size, $half, $x, $y, $value, $step * 4);
        self::fillBayer($matrix, $size, $half, $x + $half, $y + $half, $value + $step, $step * 4);
        self::fillBayer($matrix, $size, $half, $x + $half, $y, $value + $step * 2, $step * 4);
        self::fillBayer($matrix, $size, $half, $x, $y + $half, $value + $step * 3, $step * 4);
    }

    /**
     * The texture offset for one pixel channel, tiling with a wrap mask —
     * chafa_dither_pixels() phases rows by `y >> grain_height_shift` and
     * columns by `x >> grain_width_shift`; the grain defaults to one pixel
     * (calc_grain_shift(1) == 0), so both shifts are zero here and only the
     * mask remains.
     *
     * chafa's ORDERED arm (gen_mods, non-NOISE branch) reads
     * texture_data[texture_index] for ALL three channels; the NOISE arm reads
     * texture_data[texture_index * channels + ch]. With channels == 1 the
     * general layout below degenerates to exactly the ORDERED read.
     */
    public function mod(int $x, int $y, int $channel): int
    {
        $mask  = $this->size - 1;
        $index = (($y & $mask) * $this->size + ($x & $mask)) * $this->channels;

        return $this->data[$index + ($this->channels === 1 ? 0 : $channel)];
    }
}
