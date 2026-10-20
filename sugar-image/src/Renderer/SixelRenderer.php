<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\ColorProfile\Color as PaletteColor;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\PaletteIndex;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\Quantizer;

/**
 * Sixel graphics renderer — DEC sixel to terminal.
 *
 * Algorithm (per plan):
 *   1. Resize image to ($width × $effectiveHeight) via GD
 *   2. Quantize: extract pixels → median-cut → max $maxColors palette entries
 *   3. Error-diffusion dithering (optional, per Dither enum)
 *   4. Build index grid: grid[row][col] = palette index
 *   5. For each 6-row band:
 *        For each color used in band: emit color introducer + sixel data
 *        (RLE applied: "!" + count prefix before runs of same sixel byte)
 *
 * The Sixel protocol supports a maximum of 256 colors. Pass
 * {@see maxColors()} to limit the palette when 256-color fallback is
 * desired (e.g. terminals that advertise Sixel but have limited
 * truecolor support).
 */
final class SixelRenderer implements Renderer, ConfigFingerprintProvider
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;

    /**
     * Sixel background-declaration for register 0: coordinate system 2
     * (RGB) with the `P` (transparent) qualifier instead of colour
     * components — DEC spec, echoed by every sixel decoder that supports
     * transparent backgrounds.
     */
    private const TRANSPARENT_BACKGROUND = '#0;2;P';

    /**
     * F-09: DECSIXEL caps a `!N` repeat count at 255 — a decoder reading
     * `!1024` sees `!1` then treats `0`/`2`/`4` as resync/literal noise and
     * the band corrupts. Longer runs must split into `!255` chunks.
     */
    private const RLE_MAX_COUNT = 255;

    /**
     * F-09: chafa emits runs shorter than this as bare repeated bytes —
     * `!2q` costs five bytes against `qq` for two, so repetition is both
     * cheaper and free of any decoder that mis-handles short counts.
     */
    private const RLE_LITERAL_LIMIT = 4;

    /**
     * C-05: chafa folds this factor into the NOISE texture magnitude —
     * chafa_dither_init()'s NOISE arm calls
     * `chafa_gen_noise_matrix (dither->intensity * 0.1f)`, shrinking the
     * table's ±128-scale offsets to ±12.8 so a full-intensity noise bias
     * stays well inside one palette-bin width. Named here so the constant
     * has a home (NTC-3).
     */
    private const NOISE_BIAS_SCALE = 0.1;

    /**
     * C-07: chafa's depth base-intensity ladder (chafa-canvas.c:56-59,
     * applied to the ORDERED arm only at :591-614 — TRUECOLOR/256/240 →
     * 0.1, 16 colors → 0.25, 8 colors → 0.5, FG/BG → 1.0). Sixel has no
     * discrete canvas-type knob, so $maxColors is the depth proxy: the
     * 17..239 span collapses onto the 0.1 rung (fine bins want the weak
     * bias) — a disclosed interpolation of chafa's step function.
     */
    private const BASE_INTENSITY_FGBG = 1.0;
    private const BASE_INTENSITY_8C   = 0.5;
    private const BASE_INTENSITY_16C  = 0.25;
    private const BASE_INTENSITY_256C = 0.1;

    /**
     * B-14: palette-sample ceiling. chafa scales its census ceiling with the
     * -w rung (quality_params.n_samples = 2^14..2^26); the port holds the
     * historical 4096 cap fixed — the dial moves the histogram DEPTH (bits
     * per channel), not the sample census. Disclosed deviation.
     */
    private const SAMPLE_CAP = 4096;

    /**
     * B-10: default alpha threshold — GD's fully-transparent value (0 =
     * opaque .. 127 = transparent). The hole test `alpha >= threshold` at
     * the default therefore matches exactly today's `=== 127` semantics
     * (GD clamps alpha to 127, so `>= 127` and `=== 127` agree on every
     * pixel), keeping the byte-frozen default encodings untouched. A lower
     * threshold widens the hole set to semi-transparent pixels, mirroring
     * chafa's `color.ch[3] < alpha_threshold` quantize skip
     * (chafa/internal/chafa-indexed-image.c:117) on GD's inverted scale.
     */
    public const DEFAULT_ALPHA_THRESHOLD = 127;

    /**
     * C-04: dither bias intensity for the opt-in Ordered/Noise axes.
     * NULL (default) means "user did not ask" and behaves exactly as the
     * pre-C-04 renderer on every path — the default diffusion stream is
     * byte-frozen post-C-01. Chafa's chafa_dither_init() clamps diffusion
     * strength to 1.0 and feeds ordered/noise magnitudes straight through,
     * so values above 1.0 clamp here and negatives/NaN are refused.
     */
    private readonly ?float $intensity;

    /**
     * @param int $cellWidth  Pixel width of a terminal cell — the render() cell
     *                        dimensions are multiplied by this so a sixel poster
     *                        fills its cell box (not one device pixel per cell).
     * @param int $cellHeight Pixel height of a terminal cell.
     * @param float|null $intensity Ordered/Noise bias strength 0.0..1.0
     *                              (null = chafa's config default 1.0; the
     *                              depth ladder rides on Ordered only, C-07).
     * @param int $alphaThreshold   B-10: GD-alpha hole cut-off 0..127 — pixels
     *                              with `alpha >= threshold` become background
     *                              holes and are excluded from the palette
     *                              census. 127 (default) reproduces the
     *                              pre-B-10 `=== 127` test byte-identically.
     * @param Color|null $flattenBackground
     *                              B-10: when set, semi-transparent pixels
     *                              (0 < alpha < threshold) are composited onto
     *                              this colour at base-read time, BEFORE any
     *                              sampling or diffusion, mirroring chafa's
     *                              prepare-pixels bg-carry so error diffusion
     *                              works on what the terminal will show.
     *                              Null (default) = no flattening.
     * @param bool $serpentine      C-08: serpentine (boustrophedon) scan for
     *                              the diffusion kernels — odd rows walk x in
     *                              reverse and the kernel mirrors horizontally,
     *                              mirroring chafa's fs_dither row flip
     *                              (chafa-pixops.c:327-459: `if (!(y & 1))
     *                              forward else backwards`). Default false:
     *                              the forward-only walk is what the
     *                              byte-frozen digests were captured with.
     * @param bool $mltermEdgeRows  F-03: mlterm workaround — force the global
     *                              first/last band of a transparent canvas to
     *                              carry a full-width run on the background
     *                              register (chafa-sixel-renderer.c:330-410
     *                              build_sixel_row_ansi `force_full_width`,
     *                              applied at :415-440 to
     *                              is_global_first_row || is_global_last_row).
     *                              Default false: auto-enabling belongs to the
     *                              F-02 TermDb capability table, not here.
     * @param ?int $quality         B-14: chafa -w quality ladder rung (1-9)
     *                              selecting the PNN histogram depth via
     *                              {@see \SugarCraft\Image\Quantizer} — null
     *                              (default) keeps median-cut, so the
     *                              byte-frozen default path is untouched.
     */
    public function __construct(
        private readonly Dither $dither = Dither::FloydSteinberg,
        private readonly int $maxColors = 256,
        private readonly int $cellWidth = 10,
        private readonly int $cellHeight = 20,
        ?float $intensity = null,
        private readonly int $alphaThreshold = self::DEFAULT_ALPHA_THRESHOLD,
        private readonly ?Color $flattenBackground = null,
        private readonly bool $serpentine = false,
        private readonly bool $mltermEdgeRows = false,
        private readonly ?int $quality = null,
    ) {
        if ($maxColors < 1 || $maxColors > 256) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.max_colors_out_of_range', ['maxColors' => $maxColors])
            );
        }
        // Explicit is_nan: unlike IEEE/C, PHP orders NAN above every number
        // (`NAN >= 0.0` is TRUE there), so the `!($x >= 0.0)` trick would
        // let it through — refuse it by name, and negatives by sign.
        if ($intensity !== null && (is_nan($intensity) || $intensity < 0.0)) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.intensity_out_of_range', ['intensity' => $intensity])
            );
        }
        if ($alphaThreshold < 0 || $alphaThreshold > self::DEFAULT_ALPHA_THRESHOLD) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.alpha_threshold_out_of_range', ['alphaThreshold' => $alphaThreshold])
            );
        }
        // B-14: the quality rung is the SAME knob Quantizer::bitsForQuality
        // parses, so the refusal comes from the shared ladder message.
        if ($quality !== null && ($quality < Quantizer::MIN_QUALITY || $quality > Quantizer::MAX_QUALITY)) {
            throw new \InvalidArgumentException(
                Lang::t('quantizer.quality_out_of_range', ['quality' => $quality])
            );
        }
        $this->intensity = $intensity === null ? null : min(1.0, $intensity);
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        // Whole-buffer sink over the same streaming encoder, so the one-shot
        // path and {@see encodeBandStream()} can never drift byte-for-byte.
        $out = '';
        $this->encodeInto($image, $width, $height, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }

    /**
     * Stream the Sixel encoding one 6-pixel-tall band at a time.
     *
     * Emits the DCS header + background register + palette ONCE on the first
     * `$write`, then one `$write` per band body (the graphics-newline `-` that
     * joins bands prefixes every band after the first), then the ST terminator
     * on the final `$write`. The concatenation of every `$write` argument is
     * byte-for-byte identical to {@see render()}'s return value — the two share
     * {@see encodeInto()} and the same band emitter.
     *
     * Why this streams and the source decode does not: the median-cut palette
     * must be sampled from the FULL image before any band is emitted (a
     * per-band palette breaks colour consistency across bands), so the GD
     * decode + resize stays whole-image. What previously cost O(pixelW×pixelH) —
     * the fully-materialised index grid, the error-diffusion accumulator, and
     * the complete output string — is now O(pixelW): {@see indexRows()} yields
     * grid rows from a rolling one/two-row lookahead window and each band is
     * handed to `$write` and released. A consumer can start pushing bytes to the
     * TTY while later bands are still quantising.
     *
     * The API is the one recorded in CALIBER_LEARNINGS §Deferred (#17) so a
     * later caller migrates mechanically; the optional `$height` (default null →
     * aspect-derived, exactly like {@see render()}) is the one addition, so a
     * fixed-height video frame need not fall back to `render()`.
     *
     * @param callable(string):void $write  Receives each encoded fragment in order.
     * @param int|null              $height Target height in cells (null = from aspect).
     */
    public function encodeBandStream(ImageSource $image, int $width, callable $write, ?int $height = null): void
    {
        $this->encodeInto($image, $width, $height, $write);
    }

    /**
     * Shared encode core: prepare, resize, quantise, then emit header + bands +
     * terminator through `$write` in render order. Both {@see render()} and
     * {@see encodeBandStream()} funnel here so the byte stream is defined once.
     *
     * @param callable(string):void $write
     */
    private function encodeInto(ImageSource $image, int $width, ?int $height, callable $write): void
    {
        $cellH = $this->prepareRender($image, $width, $height);

        // The cell box maps to a PIXEL canvas (cells × terminal cell size) so the
        // image fills its area; one device-pixel-per-cell would be microscopic.
        $pixelW = max(1, $width * $this->cellWidth);
        $pixelH = max(1, $cellH * $this->cellHeight);

        // The canvas is cells × font-cell pixels, so an oversized cell box multiplies
        // into a canvas far beyond the SOURCE's pixel budget. Hold it to the same
        // ceiling the image was loaded under (<= 0 disables, as everywhere else)
        // BEFORE imagecreatetruecolor() attempts the allocation.
        if ($image->maxPixels > 0 && $pixelW * $pixelH > $image->maxPixels) {
            throw new \InvalidArgumentException(Lang::t('sixel.canvas_too_large', [
                'width'  => $pixelW,
                'height' => $pixelH,
                'cols'   => $width,
                'rows'   => $cellH,
                'max'    => $image->maxPixels,
            ]));
        }

        // Load and resize the image to the pixel canvas. GD raises a PHP warning on
        // unrecognised bytes; the falsy result is the real signal and is turned into
        // a typed exception below, so the warning is suppressed rather than sprayed
        // across a terminal (this library's product is terminal bytes).
        $src = @imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $resized = imagecreatetruecolor($pixelW, $pixelH);
        if ($resized === false) {
            imagedestroy($src);
            throw new \RuntimeException(Lang::t('renderer.gd_resize_failed'));
        }
        // Carry the source alpha through the resample: with blending off the
        // copy writes pixels verbatim, and only saveAlpha keeps the alpha byte
        // visible to imagecolorat() downstream.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled(
            $resized, $src,
            0, 0, 0, 0,
            $pixelW, $pixelH,
            imagesx($src), imagesy($src)
        );
        imagedestroy($src);

        $headWritten = false;
        $terminated = false;

        try {
            // Fully-transparent pixels become the background register: when the
            // canvas has any, palette indices shift up by one and register 0 is
            // declared transparent (`#0;2;P`), so uncovered cells show the
            // terminal's own background instead of a painted colour.
            $offset = $this->hasTransparentPixels($resized) ? 1 : 0;

            // The palette only needs a representative sample, not every pixel —
            // median-cut over a capped subset is far cheaper and visually
            // indistinguishable at thumbnail sizes. When a background register
            // is reserved, the colour budget shrinks by one so the highest
            // shifted register stays inside DEC's 0..255 range (#256 aliases
            // onto the transparent background on tolerant decoders).
            // B-14: with the quality dial armed the census goes to the PNN
            // quantiser instead — see buildPalette() for the routing rule.
            $palette = $this->buildPalette(
                $this->samplePixels($resized, self::SAMPLE_CAP, $offset),
                $this->maxColors - $offset
            );

            // Header, optional transparent background, and the palette are a
            // one-time prefix: a streaming consumer must see them before any band
            // body so the decoder can allocate registers and set the raster.
            $head = Ansi::sixelDcsHeader($pixelW, $pixelH);
            if ($offset === 1) {
                $head .= self::TRANSPARENT_BACKGROUND;
            }
            $head .= $this->emitPalette($palette, $offset);
            // Mark the DCS open BEFORE handing the head to the consumer: if the
            // first `$write` forwards the bytes then throws (a pty that flushes
            // then dies), the flag is already set so `finally` still emits the
            // terminator. Setting it after would strand an unterminated DCS on
            // the TTY. A stray terminator on a `$write` that delivered nothing
            // is harmless; an open DCS is not.
            $headWritten = true;
            $write($head);

            // Pull index rows lazily and emit a 6-row band per write. The
            // graphics-newline `-` that separates bands PREFIXES every band
            // after the first, so the last band (partial or full) carries no
            // trailing `-` — byte-for-byte matching the original
            // `if ($bandBottom < $pixelH)` join.
            $rowsInBand = [];
            $firstBand = true;
            $writtenRows = 0;
            foreach ($this->indexRows($resized, $palette, $this->dither, $offset) as $gridRow) {
                $rowsInBand[] = $gridRow;
                if (count($rowsInBand) === 6) {
                    $writtenRows += 6;
                    $write($this->bandBytes($rowsInBand, $pixelW, $palette, $offset, $firstBand, $writtenRows >= $pixelH));
                    $rowsInBand = [];
                    $firstBand = false;
                }
            }
            if ($rowsInBand !== []) {
                $write($this->bandBytes($rowsInBand, $pixelW, $palette, $offset, $firstBand, true));
            }

            $write(Ansi::sixelTerminator());
            $terminated = true;
        } finally {
            // Never strand the terminal inside a DCS: if the header reached the
            // wire but a later band or a consumer `$write` blew up (a closed pipe
            // mid-playback), emit the string terminator anyway so subsequent output
            // is not swallowed as sixel parameters. On the happy path `$terminated`
            // is already true, so this is a no-op and the byte stream is unchanged.
            if ($headWritten && !$terminated) {
                try {
                    $write(Ansi::sixelTerminator());
                } catch (\Throwable) {
                    // The consumer is gone; nothing left to terminate for.
                }
            }
            imagedestroy($resized);
        }
    }

    /**
     * Encode one band's bytes, prefixing the graphics-newline separator unless
     * it is the first band. The band grid is passed 0-based (its own local row
     * indices) so {@see emitBand()} is agnostic to where the band sits in the
     * full canvas.
     *
     * F-03: when the mlterm quirk is armed, a GLOBAL edge band (first or last)
     * of a transparent canvas that encodes to NOTHING at all is replaced by a
     * full-width zero-bit run on the background register. Per mlterm's own
     * comment in chafa (chafa-sixel-renderer.c:330-410 build_sixel_row_ansi):
     * mlterm shows garbage unless the entire first row is drawn even when its
     * rightmost pixels are transparent; chafa forces full width exactly on
     * is_global_first_row || is_global_last_row (chafa-sixel-renderer.c:415-440)
     * and flushes the trailing run with one pen (`#0?W`). Here only fully-empty
     * edge bands can lack the full-width sweep at all — emitBandRuns() feeds
     * every column of the band on each colour pass (stored columns answer
     * their mask, gaps answer zero bits, the tail closes at full width), so
     * any non-empty band
     * already advances the graphics position across the whole row. Deviation
     * disclosed: chafa re-checks per transparent pixel, SugarCraft keys on the
     * empty-body case, which is its only observable form of the same defect.
     *
     * @param list<list<int>>          $bandRows
     * @param list<array{int,int,int}> $palette
     */
    private function bandBytes(array $bandRows, int $pixelW, array $palette, int $offset, bool $firstBand, bool $lastBand): string
    {
        $body = $this->emitBand($bandRows, 0, count($bandRows), $pixelW, $palette, $offset);

        if ($body === '' && $this->mltermEdgeRows && $offset === 1 && ($firstBand || $lastBand)) {
            $body = Ansi::sixelColorSelect(0) . $this->emitRle(0, $pixelW);
        }

        return ($firstBand ? '' : '-') . $body;
    }

    public function name(): string
    {
        return 'sixel';
    }

    /**
     * Sixel supports transparent backgrounds via the `#0;2;P` background
     * register: fully-transparent pixels are left unpainted and show the
     * terminal's own background. See {@see render()}.
     */
    public function supportsAlpha(): bool
    {
        return true;
    }

    public function isInline(): bool
    {
        return false;
    }

    /**
     * Sixel has no standard delete mechanism — DECSIXEL does not support
     * removing individual images after emission. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }

    public function dither(): Dither
    {
        return $this->dither;
    }

    /**
     * D-04: every ctor config that changes the encoded bytes rides the
     * fingerprint — dither arms the index grid differently, maxColors
     * changes the palette, and the cell-pixel geometry resamples to a
     * different pixel canvas, so two SixelRenderers named 'sixel' with any
     * of these differing must never cross-serve a poster cache entry.
     *
     * C-04: the intensity segment appears ONLY when set — the pinned
     * default fingerprint stays byte-identical (DiskCacheKeyTest), while
     * every ordered/noise render under a non-default bias gets its own
     * cache key instead of cross-serving the 1.0-bias bytes.
     *
     * B-10: threshold and flatten segments follow the same discipline —
     * they appear only when they deviate from the byte-frozen default.
     * C-08: the serpentine segment likewise appears only when armed.
     * B-14: the quality segment likewise appears ONLY when armed — the
     * pinned default fingerprint stays byte-identical (C-04/B-10 discipline).
     */
    public function configFingerprint(): string
    {
        return 'dither=' . $this->dither->value
            . '|maxColors=' . $this->maxColors
            . '|cellWidth=' . $this->cellWidth
            . '|cellHeight=' . $this->cellHeight
            . ($this->intensity === null ? '' : '|intensity=' . $this->intensity)
            . ($this->alphaThreshold === self::DEFAULT_ALPHA_THRESHOLD
                ? ''
                : '|alphaThreshold=' . $this->alphaThreshold)
            . ($this->flattenBackground === null
                ? ''
                : sprintf('|flatten=#%02X%02X%02X',
                    $this->flattenBackground->r,
                    $this->flattenBackground->g,
                    $this->flattenBackground->b))
            . ($this->serpentine ? '|serpentine=1' : '')
            . ($this->mltermEdgeRows ? '|mlterm=1' : '')
            . ($this->quality === null ? '' : '|quality=' . $this->quality);
    }

    /**
     * A copy of this renderer using $dither, keeping its colour budget,
     * bias intensity and cell-pixel geometry — so re-tinting a configured
     * Sixel (custom maxColors or real terminal cell sizes) never silently
     * resets them.
     */
    public function withDither(Dither $dither): self
    {
        return new self(
            $dither,
            $this->maxColors,
            $this->cellWidth,
            $this->cellHeight,
            $this->intensity,
            $this->alphaThreshold,
            $this->flattenBackground,
            $this->serpentine,
            $this->mltermEdgeRows,
            $this->quality,
        );
    }

    /**
     * B-10: the GD-alpha hole cut-off in force (0..127; 127 = only fully
     * transparent pixels are holes, the pre-B-10 behaviour).
     */
    public function alphaThreshold(): int
    {
        return $this->alphaThreshold;
    }

    /**
     * B-10: the flatten background in force, or null when semi-transparent
     * pixels keep their stored colour.
     */
    public function flattenBackground(): ?Color
    {
        return $this->flattenBackground;
    }

    /**
     * B-10 copy with a different alpha threshold: pixels whose GD alpha
     * reaches `$threshold` become background holes, and the hole decision
     * happens before the pixel ever enters the palette census or the
     * diffusion stream (chafa quantize_pixel skip semantics,
     * chafa/internal/chafa-indexed-image.c:117-125 — lowered thresholds
     * also shed semi-transparent fringe colours from median-cut buckets,
     * the counterpart of chafa's MAX(alpha_threshold,1) sampler guard).
     * 127 is byte-identical to the historical `=== 127` hole test; the
     * extreme 0 declares every pixel (opaque included) a hole — legal, but
     * the encode paints nothing.
     */
    public function withAlphaThreshold(int $threshold): self
    {
        return new self(
            $this->dither,
            $this->maxColors,
            $this->cellWidth,
            $this->cellHeight,
            $this->intensity,
            $threshold,
            $this->flattenBackground,
            $this->serpentine,
            $this->mltermEdgeRows,
            $this->quality,
        );
    }

    /**
     * B-10: opt-in pre-sampling flatten — every semi-transparent pixel
     * (0 < alpha < threshold) is composited toward `$background` inside the
     * base read, BEFORE quantisation or diffusion touch it, so the encoder
     * dithers the colour the terminal will actually show instead of the raw
     * un-premultiplied fringe (which is typically near-black — the
     * dark-halo defect). `null` (default) disables flattening entirely.
     */
    public function withFlatten(?Color $background): self
    {
        return new self(
            $this->dither,
            $this->maxColors,
            $this->cellWidth,
            $this->cellHeight,
            $this->intensity,
            $this->alphaThreshold,
            $background,
            $this->serpentine,
            $this->mltermEdgeRows,
            $this->quality,
        );
    }

    /**
     * C-08: true when the diffusion walk snakes (odd rows reversed).
     */
    public function serpentine(): bool
    {
        return $this->serpentine;
    }

    /**
     * C-08 copy with the serpentine scan armed. Only diffusion dithers are
     * affected — Ordered is a position-taken bias and None has no walk to
     * reverse, so both render byte-identically with or without it.
     */
    public function withSerpentine(bool $serpentine = true): self
    {
        return new self(
            $this->dither,
            $this->maxColors,
            $this->cellWidth,
            $this->cellHeight,
            $this->intensity,
            $this->alphaThreshold,
            $this->flattenBackground,
            $serpentine,
            $this->mltermEdgeRows,
            $this->quality,
        );
    }

    /**
     * F-03: true when the mlterm first/last-row quirk is armed.
     */
    public function mltermEdgeRows(): bool
    {
        return $this->mltermEdgeRows;
    }

    /**
     * F-03 copy with the mlterm edge-band quirk armed. The quirk only ever
     * fires on a canvas that carries the transparent background register
     * (offset 1) whose global first or last band encodes to nothing at all;
     * those bands then draw a full-width zero-bit run on register 0 so the
     * graphics position advances across the whole row. Off by default until
     * the F-02 TermDb table can select it per terminal.
     */
    public function withMltermEdgeRows(bool $mltermEdgeRows = true): self
    {
        return new self(
            $this->dither,
            $this->maxColors,
            $this->cellWidth,
            $this->cellHeight,
            $this->intensity,
            $this->alphaThreshold,
            $this->flattenBackground,
            $this->serpentine,
            $mltermEdgeRows,
            $this->quality,
        );
    }

    /**
     * B-14: the PNN quality rung in force (1-9), or null when the renderer
     * quantises with median-cut — the byte-frozen default.
     */
    public function quality(): ?int
    {
        return $this->quality;
    }

    /**
     * B-14 copy on a different rung of the chafa -w ladder (1-9). Arming it
     * routes palette construction through {@see Quantizer} when the sample
     * census outweighs the colour budget; every other setting rides along.
     * The default path never calls this, so default bytes are untouched.
     */
    public function withQuality(int $quality): self
    {
        return new self(
            $this->dither,
            $this->maxColors,
            $this->cellWidth,
            $this->cellHeight,
            $this->intensity,
            $this->alphaThreshold,
            $this->flattenBackground,
            $this->serpentine,
            $this->mltermEdgeRows,
            $quality,
        );
    }

    /**
     * The maximum number of colors in the quantized Sixel palette.
     *
     * The Sixel protocol supports at most 256 colors. Values below 256
     * invoke the 256-color fallback — useful for terminals that advertise
     * Sixel but have limited truecolor support.
     */
    public function maxColors(): int
    {
        return $this->maxColors;
    }

    // ─── Pixel extraction ─────────────────────────────────────────────────────

    /**
     * Quick full-canvas scan for any pixel at or above the alpha threshold
     * (GD alpha >= 127 at the default = fully transparent).
     *
     * B-10: with the default threshold only exact-127 pixels qualify, so the
     * background-register decision stays byte-identical to the pre-B-10
     * scan; a lowered threshold additionally counts semi-transparent pixels
     * as holes (they will not be painted), keeping this census in lockstep
     * with the hole mask in {@see indexRows()}. The scan short-circuits on
     * the first hit — opaque images pay one pass of cheap integer tests, and
     * only transparent ones pay the palette shift.
     */
    private function hasTransparentPixels(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ((imagecolorat($img, $x, $y) >> 24) >= $this->alphaThreshold) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Collect up to $max representative pixels by striding over the image, for
     * building the palette. Sampling instead of reading every pixel keeps
     * median-cut cheap on the larger pixel canvas.
     *
     * @param int $offset 1 when a background register is reserved — pixels at
     *                    or above the alpha threshold are holes, carry no
     *                    colour, and must not dilute the median-cut buckets
     *                    (B-10; chafa's sample_to_bins_impl drops exactly
     *                    these, chafa/internal/chafa-palette.c:569).
     * @return list<array{int,int,int}>
     */
    private function samplePixels(\GdImage $img, int $max, int $offset = 0): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $total = $w * $h;
        $step = $total > $max ? (int) ceil($total / $max) : 1;

        $pixels = [];
        $i = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (($i++ % $step) !== 0) {
                    continue;
                }
                // The canvas is truecolor, so imagecolorat returns a packed
                // 0xAARRGGBB int — extract channels directly rather than paying for
                // an imagecolorsforindex associative-array allocation per pixel.
                $rgb = imagecolorat($img, $x, $y);
                $alpha = ($rgb >> 24) & 0xFF;
                // B-10 ORDERING LAW: the hole census runs FIRST — a pixel at
                // or above the threshold never enters the sample, whether or
                // not flattening is armed (flatten composites toward the bg
                // for the terminal to PAINT; holes are never painted).
                if ($offset === 1 && $alpha >= $this->alphaThreshold) {
                    continue;
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // B-10: semi-transparent survivors are flattened onto the
                // background here, before quantisation — the palette learns
                // the colours the encoder will actually emit.
                if ($this->flattenBackground !== null && $alpha > 0) {
                    [$r, $g, $b] = $this->flattenOnto($r, $g, $b, $alpha, $this->flattenBackground);
                }
                $pixels[] = [$r, $g, $b];
            }
        }

        return $pixels;
    }

    /**
     * B-10: src-over composite of one pixel's RGB toward the flatten
     * background, on GD's inverted alpha scale (0 = opaque, 127 = fully
     * transparent), so background weight is `alpha / 127`.
     *
     * @return array{int,int,int}
     */
    private static function flattenOnto(int $r, int $g, int $b, int $alpha, Color $bg): array
    {
        $t = $alpha / 127.0;

        return [
            (int) round($r + ($bg->r - $r) * $t),
            (int) round($g + ($bg->g - $g) * $t),
            (int) round($b + ($bg->b - $b) * $t),
        ];
    }

    // ─── Quantizer ────────────────────────────────────────────────────────────

    /**
     * Median-cut quantizer.
     *
     * @param list<array{int,int,int}> $pixels
     * @return list<array{int,int,int}>  RGB palette, max 256 entries
     */
    private function medianCut(array $pixels, int $maxColors): array
    {
        $maxColors = max(1, min(256, $maxColors));

        if ($pixels === []) {
            return [[0, 0, 0]];
        }

        /** @var list<list<array{int,int,int}>> */
        $buckets = [$pixels];

        $bucketCount = count($buckets);
        while ($bucketCount < $maxColors) {
            $largestIdx = $this->largestBucketIndex($buckets);
            if ($largestIdx === null) {
                break;
            }
            $split = $this->splitBucket($buckets[$largestIdx]);
            if ($split === null) {
                break;
            }
            array_splice($buckets, $largestIdx, 1, $split);
            $bucketCount = count($buckets);
        }

        return array_map(
            fn(array $bucket): array => $this->avgBucket($bucket),
            $buckets
        );
    }

    /**
     * B-14: palette construction router.
     *
     * Median-cut stays the default AND the fallback: it wins whenever the
     * quality dial is unarmed, the census is empty, or the sample stream
     * carries no merge mass to exploit (one sample per requested colour or
     * fewer — a histogram cannot compress what is already sparse). Only an
     * armed dial with surplus samples routes to the PNN quantiser, where
     * chafa's -w rung selects the histogram depth. The default render thus
     * never reaches Quantizer and its bytes are frozen as before.
     *
     * @param list<array{int,int,int}> $samples
     * @return list<array{int,int,int}>
     */
    private function buildPalette(array $samples, int $budget): array
    {
        if ($this->quality === null || $samples === [] || count($samples) <= max(1, $budget)) {
            return $this->medianCut($samples, $budget);
        }

        return Quantizer::build($samples, $budget, Quantizer::bitsForQuality($this->quality));
    }

    private function largestBucketIndex(array $buckets): ?int
    {
        $largestIdx   = null;
        $largestRange = -1;

        foreach ($buckets as $idx => $bucket) {
            if (count($bucket) < 2) {
                continue;
            }
            $range = $this->bucketRange($bucket);
            if ($range > $largestRange) {
                $largestRange = $range;
                $largestIdx   = $idx;
            }
        }

        return $largestIdx;
    }

    /**
     * @param list<array{int,int,int}> $bucket
     * @return array{0:list<array{int,int,int}>,1:list<array{int,int,int}>}|null
     */
    private function splitBucket(array $bucket): ?array
    {
        if (count($bucket) < 2) {
            return null;
        }

        [$minR, $maxR] = [$bucket[0][0], $bucket[0][0]];
        [$minG, $maxG] = [$bucket[0][1], $bucket[0][1]];
        [$minB, $maxB] = [$bucket[0][2], $bucket[0][2]];
        foreach ($bucket as $px) {
            if ($px[0] < $minR) { $minR = $px[0]; }
            if ($px[0] > $maxR) { $maxR = $px[0]; }
            if ($px[1] < $minG) { $minG = $px[1]; }
            if ($px[1] > $maxG) { $maxG = $px[1]; }
            if ($px[2] < $minB) { $minB = $px[2]; }
            if ($px[2] > $maxB) { $maxB = $px[2]; }
        }
        $rRange = $maxR - $minR;
        $gRange = $maxG - $minG;
        $bRange = $maxB - $minB;

        if ($rRange === 0 && $gRange === 0 && $bRange === 0) {
            return null;
        }

        $axis = $rRange >= $gRange && $rRange >= $bRange ? 0
            : ($gRange >= $bRange ? 1 : 2);

        usort($bucket, static fn(array $a, array $b): int => $a[$axis] <=> $b[$axis]);
        $mid = (int) floor(count($bucket) / 2);

        $a = array_slice($bucket, 0, $mid);
        $b = array_slice($bucket, $mid);

        return ($a !== [] && $b !== []) ? [$a, $b] : null;
    }

    private function bucketRange(array $bucket): int
    {
        $minR = $maxR = $bucket[0][0];
        $minG = $maxG = $bucket[0][1];
        $minB = $maxB = $bucket[0][2];
        foreach ($bucket as $px) {
            if ($px[0] < $minR) { $minR = $px[0]; }
            if ($px[0] > $maxR) { $maxR = $px[0]; }
            if ($px[1] < $minG) { $minG = $px[1]; }
            if ($px[1] > $maxG) { $maxG = $px[1]; }
            if ($px[2] < $minB) { $minB = $px[2]; }
            if ($px[2] > $maxB) { $maxB = $px[2]; }
        }
        return ($maxR - $minR) + ($maxG - $minG) + ($maxB - $minB);
    }

    /** @param list<array{int,int,int}> $bucket */
    private function avgBucket(array $bucket): array
    {
        $sumR = $sumG = $sumB = 0;
        foreach ($bucket as $px) {
            $sumR += $px[0];
            $sumG += $px[1];
            $sumB += $px[2];
        }
        $n = count($bucket);
        return [
            (int) round($sumR / $n),
            (int) round($sumG / $n),
            (int) round($sumB / $n),
        ];
    }

    // ─── Index grid (lazy, rolling-window) ────────────────────────────────────

    /**
     * Yield the index grid one row at a time, keyed by absolute row index.
     *
     * The single source of truth for pixel→palette-index mapping, shared by the
     * one-shot ({@see render()}) and streaming ({@see encodeBandStream()})
     * paths so the two can never diverge. Every quantisation decision is
     * reproduced exactly as the previous full-canvas grid builders did:
     *
     *   • Euclidean nearest-palette lookup, memoised on a coarse 5-bit RGB cube
     *     (≤32768 entries) — identical key arithmetic to the old code.
     *   • A pixel at or above the alpha threshold (GD alpha 127 at the
     *     default) maps to index 0 and diffuses no error whenever a
     *     background register is reserved (B-10).
     *   • Error-diffusion dithering quantises each pixel, clamps the accumulated
     *     value to 0..255, and propagates the rounding error to unprocessed
     *     neighbours. C-08 (opt-in, default OFF): with `serpentine` armed the
     *     diffusion kernels snake — odd rows walk right→left and the kernel
     *     mirrors horizontally, so taps always land on unvisited pixels.
     *
     * The rolling window is what makes it O(pixelW) not O(pixelW×pixelH): error
     * diffusion is strictly causal (it only reaches rows ≥ the current one), so
     * a row's final index is fixed once every earlier row has diffused into it.
     * A row is read into the window only once no earlier row can still touch it,
     * and released the instant it has pushed its own error downward — at most
     * `reach + 1` accumulator rows are alive (1 for Floyd–Steinberg, 2 for
     * Stucki/Atkinson). Loading row `y + reach + 1` right after processing `y`
     * is provably early enough because the FIRST diffuser into that row is
     * `y + 1`, so no contribution is ever written into a not-yet-loaded slot or
     * clobbered by a late base read.
     *
     * @param list<array{int,int,int}> $palette
     * @param int $offset 1 when register 0 is the transparent background —
     *                    opaque pixels index from $offset and holes map to 0.
     * @param int $yOrigin C-04: absolute pixel row of this call's row 0 — the
     *                    texture phase is taken on $y + $yOrigin, so encoding
     *                    a band sub-image yields exactly the grid rows the
     *                    full-canvas pass produces (D-02 band-split equality;
     *                    error diffusion is by design NOT sliceable this way,
     *                    which is why the reach window stays stateful above).
     *                    The serpentine flip reads LOCAL $y, not $y +
     *                    $yOrigin — latent phase trap: a per-band indexRows
     *                    call would restart the snake mid-image (safe today,
     *                    every call walks a whole canvas at $yOrigin = 0).
     * @return \Generator<int, list<int>>  row index → grid[row][col] palette index
     */
    private function indexRows(\GdImage $img, array $palette, Dither $dither, int $offset, int $yOrigin = 0): \Generator
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // C-04/C-05: Ordered and Noise are bias textures, not error
        // diffusions — the old `$dither !== Dither::None` test would wrongly
        // arm the kernel for them, so the arms are spelled out.
        $hasDiffusion = match ($dither) {
            Dither::FloydSteinberg, Dither::Stucki, Dither::Atkinson => true,
            Dither::None, Dither::Ordered, Dither::Noise             => false,
        };

        // The lowest row a single pixel's error can still reach — the window
        // must keep every row awaiting an inbound contribution.
        $reach = match ($dither) {
            Dither::FloydSteinberg => 1,
            Dither::Stucki, Dither::Atkinson => 2,
            // Named (not `default`) so a future Dither case that forgets to declare
            // its error-diffusion reach raises UnhandledMatchError here rather than
            // silently getting a 1-row window and corrupting output.
            Dither::None, Dither::Ordered, Dither::Noise => 0,
        };

        $texture = match ($dither) {
            Dither::Ordered => OrderedTexture::bayer16($this->orderedTextureMagnitude()),
            Dither::Noise   => OrderedTexture::blueNoise64($this->noiseTextureMagnitude()),
            default         => null,
        };

        // C-07: chafa scales the diffusion error itself — chafa-pixops.c
        // (fs_dither_grain): `(accum - col) * dither->intensity` before the
        // kernel split. With intensity null the factor is exactly 1.0 and
        // float × 1.0 is bit-exact, so the byte-frozen default stream is
        // untouched; chafa's init also clamps diffusion strength to 1.0
        // (MIN(intensity, 1.0)) — the ctor clamp above already mirrors it.
        $diffusionStrength = $this->intensity ?? 1.0;

        /** @var array<int, list<array{float,float,float}>> $window  absolute row → RGB accumulators */
        $window = [];
        /** @var array<int, list<bool>> $holes  absolute row → transparent-pixel mask */
        $holes = [];

        // Read one row's raw pixels into floating-point accumulators; when a
        // background register is reserved, remember which pixels are holes so
        // the diffusion pass (which carries colour, not transparency) can skip
        // them exactly as the old full-canvas builder did. C-04: the ordered
        // bias is added HERE — before quantisation, after nothing diffuses —
        // mirroring chafa's simple_dither pass running ahead of palette lookup
        // (chafa-pixops.c fs/ordered walk order).
        $loadBase = function (int $y) use ($img, $w, $offset, $texture, $yOrigin, &$window, &$holes): void {
            $row = [];
            $textureY = $y + $yOrigin;
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $alpha = ($rgb >> 24) & 0xFF;
                // B-10: the hole census is decided FIRST and on the RAW
                // alpha — lowering the threshold widens the hole set, and a
                // hole is never flattened (unpainted pixels carry no colour
                // to the terminal). ORDERING LAW: everything below — the
                // flatten, the texture bias, the accumulator push — runs on
                // the post-decision pixel, so both the sample census and the
                // diffusion stream see transparent-excluded, optionally
                // bg-composited colour.
                $isHole = $offset === 1 && $alpha >= $this->alphaThreshold;
                if ($offset === 1) {
                    $holes[$y][$x] = $isHole;
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($this->flattenBackground !== null && !$isHole && $alpha > 0) {
                    // B-10: semi-transparent (0 < alpha < threshold) → src-over
                    // onto the background BEFORE quantisation/diffusion.
                    [$r, $g, $b] = self::flattenOnto($r, $g, $b, $alpha, $this->flattenBackground);
                }
                $rf = (float) $r;
                $gf = (float) $g;
                $bf = (float) $b;
                if ($texture !== null) {
                    $rf = self::applyTextureMod($rf, $texture->mod($x, $textureY, 0));
                    $gf = self::applyTextureMod($gf, $texture->mod($x, $textureY, 1));
                    $bf = self::applyTextureMod($bf, $texture->mod($x, $textureY, 2));
                }
                $row[] = [$rf, $gf, $bf];
            }
            $window[$y] = $row;
        };

        // Prime the window: rows [0, reach] before any row is quantised.
        for ($y = 0; $y <= $reach && $y < $h; $y++) {
            $loadBase($y);
        }

        $cache = []; // 5-bit RGB cube → nearest colour of the BLOCK CENTRE (B-13), reused across pixels.

        // F-14: nearest lookup now rides the B-04 PaletteIndex. The metric is
        // pinned to Euclidean because its recheck is the same integer-sum
        // comparison the old linear walk made — including lowest-index ties —
        // while the default Cie76 would reorder answers (differentially
        // pinned in SixelStreamTest). fromPalette() memoises per palette
        // byte-identity (md5 + CACHE_LIMIT), so per-frame sugar-video
        // re-encodes that rebuild an identical palette short-circuit the
        // histogram build: the palette-hash short-circuit, across encodes.
        $index = PaletteIndex::fromPalette(
            array_map(static fn(array $rgb): PaletteColor => new PaletteColor($rgb[0], $rgb[1], $rgb[2]), $palette),
            ColorDistance::Euclidean,
        );

        // C-08: serpentine only means anything for a walk that carries
        // error — Ordered's bias is position-taken and None quantises
        // cold, so both render byte-identically snaked or straight.
        $snake = $this->serpentine && $hasDiffusion;

        for ($y = 0; $y < $h; $y++) {
            // Per chafa chafa-pixops.c:327-459 (fs_dither): even rows walk
            // left→right, odd rows right→left, and the kernel mirrors with
            // the direction so every tap still lands on an unprocessed
            // pixel — that parity and mirroring is a faithful port. At the
            // row ends chafa does NOT park error in padding: it FOLDS the
            // boundary taps into the nearest visible cells
            // (chafa-pixops.c:377-405 — the leftmost grain re-adds
            // error_row[1]+1, the rightmost re-adds error_row[1]+x; the +2
            // columns of its padded rows are memset-0 layout slack, not
            // accumulators). SugarCraft drops out-of-bounds taps at the
            // bounds test here — a real, disclosed divergence:
            // conserved-vs-dropped error differs at the row ends.
            $reversed = $snake && (($y & 1) === 1);
            $row = array_fill(0, $w, 0);
            for ($i = 0; $i < $w; $i++) {
                $x = $reversed ? $w - 1 - $i : $i;
                if ($offset === 1 && $holes[$y][$x]) {
                    continue; // hole: index 0, no error — left at the fill value
                }
                // Quantise the accumulated (possibly error-diffused) value.
                [$r, $g, $b] = $window[$y][$x];
                $clampedR = max(0.0, min(255.0, $r));
                $clampedG = max(0.0, min(255.0, $g));
                $clampedB = max(0.0, min(255.0, $b));

                $ri = (int) round($clampedR);
                $gi = (int) round($clampedG);
                $bi = (int) round($clampedB);
                // B-13: key AND lookup input snap together to the block
                // representative — the memo stores the nearest colour of the
                // 8³-block CENTRE, not of whichever pixel happened to warm
                // the entry first. A lookup for any pixel in the block then
                // asks (and caches) the same question, so the chosen index
                // is arrival-order-independent (the pre-fix first-wins 5-bit
                // memo made dither walks position-order-dependent). The key
                // space stays 32³ = 32768 entries per render — bounded by
                // construction.
                $br = ($ri & ~7) | 4;
                $bg = ($gi & ~7) | 4;
                $bb = ($bi & ~7) | 4;
                $key = (($br >> 3) << 10) | (($bg >> 3) << 5) | ($bb >> 3);
                $palIdx = $cache[$key] ??= $index->nearest(new PaletteColor($br, $bg, $bb));

                if ($hasDiffusion) {
                    [$pr, $pg, $pb] = $palette[$palIdx];
                    // Quantization error (original − quantized), intensity-scaled
                    // (C-07, 1.0 by default), diffused onward.
                    // C-08: on a reversed pass every horizontal tap mirrors
                    // (x±1 → x∓1), so error keeps flowing to pixels the walk
                    // has not visited yet.
                    $this->diffuseError(
                        $window, $w, $h, $x, $y,
                        ($clampedR - (float) $pr) * $diffusionStrength,
                        ($clampedG - (float) $pg) * $diffusionStrength,
                        ($clampedB - (float) $pb) * $diffusionStrength,
                        $dither,
                        $reversed ? -1 : 1,
                    );
                }

                $row[$x] = $offset + $palIdx;
            }
            yield $y => $row;

            // Row $y is fully consumed — its error has propagated downward.
            unset($window[$y], $holes[$y]);

            // Pull the new far-edge row into the window. The first row whose
            // error can reach $y + $reach + 1 is $y + 1, so loading it now —
            // before $y + 1 is processed — never overwrites a received
            // contribution.
            $next = $y + $reach + 1;
            if ($next < $h) {
                $loadBase($next);
            }
        }
    }

    /**
     * C-04/C-07: Ordered bias magnitude — chafa folds the depth base into
     * the config intensity before reaching chafa_dither_init()
     * (chafa-canvas.c:591-614: `dither_intensity * config.dither_intensity`,
     * then `chafa_gen_bayer_matrix (TEXTURE_DIM, intensity)`), so this is
     * base(maxColors) × user(null = 1.0). The base is a pure function of
     * maxColors — pinned by SixelIntensityTest per chafa-canvas.c:56-59.
     */
    private function orderedTextureMagnitude(): float
    {
        return $this->orderedDepthBase() * ($this->intensity ?? 1.0);
    }

    /** C-07: the depth rung of the ordered ladder (see BASE_INTENSITY_*). */
    private function orderedDepthBase(): float
    {
        return match (true) {
            $this->maxColors > 16 => self::BASE_INTENSITY_256C,
            $this->maxColors > 8  => self::BASE_INTENSITY_16C,
            $this->maxColors > 2  => self::BASE_INTENSITY_8C,
            default               => self::BASE_INTENSITY_FGBG,
        };
    }

    /**
     * C-05: Noise bias magnitude — chafa's chafa_dither_init() NOISE arm,
     * `chafa_gen_noise_matrix (dither->intensity * 0.1f)`, with the base
     * intensity left at 1.0: chafa-canvas.c (:591-614) applies the depth
     * ladder to ORDERED only, so Noise never sees a maxColors-derived
     * factor (pinned by SixelIntensityTest with C-07).
     */
    private function noiseTextureMagnitude(): float
    {
        return self::NOISE_BIAS_SCALE * ($this->intensity ?? 1.0);
    }

    /**
     * C-04: chafa_gen_mods + dither_pixel_u32 — split the signed texture
     * offset into saturating pos/neg modifiers and add them to one channel.
     * Alpha never passes through here (holes short-circuit above; GD has no
     * per-channel alpha in the accumulator), matching chafa's `for (i=0; i<3)`
     * loop bound.
     */
    private static function applyTextureMod(float $value, int $mod): float
    {
        $pos = max(0, min(255, $mod));
        $neg = max(0, min(255, -$mod));

        $biased = $value + $pos;
        $biased = min($biased, 255.0);
        $biased -= $neg;

        return max($biased, 0.0);
    }

    /**
     * Diffuse quantization error to future (unprocessed) neighbors.
     *
     * Coefficient layouts (current pixel marked ·):
     *
     * Floyd–Steinberg (propagates the whole error):
     *    ·  7/16
     *  3/16 5/16 1/16
     *
     * Stucki (propagates the whole error to 12 neighbors, slightly sharper):
     *            ·     8/42  4/42
     *  2/42  4/42  8/42  4/42  2/42
     *  1/42  2/42  4/42  2/42  1/42
     *
     * Atkinson (Apple; propagates ¾, more contrast/lighter result):
     *    ·  1/8  1/8
     *  1/8  1/8  1/8
     *       1/8
     *
     * @param list<list<array{float,float,float}> $accum
     * @param int $dxSign C-08: +1 on a forward row, -1 on a serpentine
     *                    (reversed) row — the horizontal component of every
     *                    tap mirrors with the walk direction, the analogue
     *                    of chafa swapping its neighbour arguments when
     *                    fs_dither walks right→left (chafa-pixops.c:327-459).
     *                    Default +1 keeps the historical forward kernel and
     *                    every existing caller byte-unchanged.
     */
    private function diffuseError(
        array &$accum,
        int $w, int $h,
        int $x, int $y,
        float $eR, float $eG, float $eB,
        Dither $dither,
        int $dxSign = 1,
    ): void {
        // Taps are (dx, dy, factor) RELATIVE to the diffusing pixel so the
        // reversed pass mirrors the whole kernel by negating dx.
        $neighbors = match ($dither) {
            // Floyd–Steinberg: 4 neighbors
            Dither::FloydSteinberg => [
                [1,  0, 7.0 / 16.0],
                [-1, 1, 3.0 / 16.0],
                [0,  1, 5.0 / 16.0],
                [1,  1, 1.0 / 16.0],
            ],
            // Stucki: 12 neighbors
            Dither::Stucki => [
                [1,  0, 8.0 / 42.0],
                [2,  0, 4.0 / 42.0],
                [-2, 1, 2.0 / 42.0],
                [-1, 1, 4.0 / 42.0],
                [0,  1, 8.0 / 42.0],
                [1,  1, 4.0 / 42.0],
                [2,  1, 2.0 / 42.0],
                [-2, 2, 1.0 / 42.0],
                [-1, 2, 2.0 / 42.0],
                [0,  2, 4.0 / 42.0],
                [1,  2, 2.0 / 42.0],
                [2,  2, 1.0 / 42.0],
            ],
            // Atkinson: 6 neighbors, only ¾ of error propagates
            Dither::Atkinson => [
                [1,  0, 1.0 / 8.0],
                [2,  0, 1.0 / 8.0],
                [-1, 1, 1.0 / 8.0],
                [0,  1, 1.0 / 8.0],
                [1,  1, 1.0 / 8.0],
                [0,  2, 1.0 / 8.0],
            ],
            default => [],
        };

        foreach ($neighbors as [$dx, $dy, $factor]) {
            $nx = $x + $dx * $dxSign;
            $ny = $y + $dy;
            if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                $accum[$ny][$nx][0] += $eR * $factor;
                $accum[$ny][$nx][1] += $eG * $factor;
                $accum[$ny][$nx][2] += $eB * $factor;
            }
        }
    }

    // ─── Sixel encoding ───────────────────────────────────────────────────────

    /**
     * F-14 removed the private linear nearestColor walk: the B-13 memo's miss
     * handler rides the shared PaletteIndex (B-04) instead, and a fourth
     * nearest-path in this file is banned by the single-implementation law.
     */

    /**
     * @param list<array{int,int,int}> $palette
     * @param int $offset 1 when register 0 is reserved for the transparent
     *                    background — palette entries then live at 1..N.
     */
    private function emitPalette(array $palette, int $offset = 0): string
    {
        $out = '';
        foreach ($palette as $i => [$r, $g, $b]) {
            $out .= Ansi::sixelColorIntroducer($i + $offset, $r, $g, $b);
        }
        return $out;
    }

    /**
     * Encode one 6-pixel-tall band.
     *
     * @param list<list<int>>          $grid
     * @param list<array{int,int,int}> $palette
     * @param int $offset 1 when register 0 is the transparent background —
     *                    index 0 cells are holes and get no colour pass.
     */
    private function emitBand(
        array $grid,
        int $bandTop,
        int $bandBottom,
        int $width,
        array $palette,
        int $offset = 0,
    ): string {
        // F-14 band-diff: one sweep builds a per-colour column bitset
        // (palette index → column → 6-row mask), replacing the old two-pass
        // shape (discovery walk, then a per-colour full-width re-walk). The
        // emitted bytes are the same by construction: a column absent from a
        // colour's mask carries bits 0, exactly the value the per-colour walk
        // recomputed for it. Grid rows are full-width by construction
        // (indexRows materialises every column via array_fill), so the old
        // `?? []` / `?? 0` defensives were unreachable and are dropped.
        /** @var array<int, array<int, int>> $masks */
        $masks = [];
        $activeColors = [];
        for ($row = $bandTop; $row < $bandBottom; $row++) {
            $bit = 1 << ($row - $bandTop);
            foreach ($grid[$row] as $col => $pal) {
                if ($offset === 1 && $pal === 0) {
                    continue; // hole: paints no colour register
                }
                $masks[$pal][$col] = ($masks[$pal][$col] ?? 0) | $bit;
                $activeColors[$pal] = true;
            }
        }

        if ($activeColors === []) {
            return '';
        }

        $out = '';
        $first = true;

        foreach (array_keys($activeColors) as $palIndex) {
            // Each colour pass starts back at the band's left edge. `$` is a
            // graphics carriage-return; the colours overlay on the same 6-row
            // band rather than printing one after another.
            if (!$first) {
                $out .= '$';
            }
            $first = false;

            $out .= Ansi::sixelColorSelect($palIndex);
            $out .= $this->emitBandRuns($masks[$palIndex], $width);
        }

        return $out;
    }

    /**
     * Feed the stored (column → bitmask) sparse mask through the RLE run
     * state machine as the identical per-column bit sequence the full-width
     * walk used to produce: gaps answer bits 0, stored columns answer their
     * mask, and the tail to $width closes the band.
     *
     * @param array<int, int> $cols column index → 6-row bitmask (only stored
     *                              where at least one row paints)
     */
    private function emitBandRuns(array $cols, int $width): string
    {
        ksort($cols, SORT_NUMERIC);

        $out = '';
        $runCount = 0;
        $prevBits = -1;

        $feed = function (int $bits, int $len) use (&$out, &$runCount, &$prevBits): void {
            if ($bits === $prevBits) {
                $runCount += $len;
                return;
            }
            if ($runCount > 0) {
                $out .= $this->emitRle($prevBits, $runCount);
            }
            $prevBits = $bits;
            $runCount = $len;
        };

        $col = 0;
        foreach ($cols as $mCol => $bits) {
            if ($mCol > $col) {
                $feed(0, $mCol - $col);
            }
            $feed($bits, 1);
            $col = $mCol + 1;
        }
        if ($col < $width) {
            $feed(0, $width - $col);
        }

        if ($runCount > 0) {
            $out .= $this->emitRle($prevBits, $runCount);
        }

        return $out;
    }

    /**
     * One sixel data byte = `bits + 63` (printable range 63-126), run-length
     * encoded per chafa's `format_schar_reps`
     * (chafa/internal/chafa-sixel-renderer.c:218-251): counts of 4..254 ride
     * as `!count byte`, runs of 255 or more split into repeated `!255`
     * chunks plus remainder, and runs of 1..3 stay bare literals (F-09 —
     * an uncapped `!900` is illegal SIXEL and corrupts the band on real
     * decoders).
     */
    private function emitRle(int $bits, int $count): string
    {
        $char = chr(($bits & 0x3F) + 63);

        if ($count < self::RLE_LITERAL_LIMIT) {
            return str_repeat($char, $count);
        }

        $out = '';
        while ($count >= self::RLE_MAX_COUNT) {
            $out .= '!' . self::RLE_MAX_COUNT . $char;
            $count -= self::RLE_MAX_COUNT;
        }

        // The loop can only leave 0..254 behind; 0 means the chunks already
        // covered the whole run (length 255, 510, 765, …).
        if ($count === 0) {
            return $out;
        }

        if ($count < self::RLE_LITERAL_LIMIT) {
            return $out . str_repeat($char, $count);
        }

        return $out . '!' . $count . $char;
    }
}
