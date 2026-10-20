<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Lang;
use SugarCraft\Image\PixelGrid;

/**
 * Kitty graphics protocol renderer via chunked APC sequences.
 *
 * F-11: payloads are chunked in RAW BYTES (default 2967 per chunk, see
 * {@see KittyOptions::DEFAULT_CHUNK_BYTES}) and each slice is base64-encoded
 * independently, so every emitted frame — APC introducer, parameters,
 * base64, string terminator — stays within the protocol's 4096-byte cap.
 * (The previous shape sliced a 4092-character BASE64 stream, putting 4101
 * bytes on the wire per data frame: kitty truncates such frames and the
 * image corrupts.) Terminals handle aspect-ratio and scaling.
 */
final class KittyRenderer implements Renderer
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;

    /**
     * Render an image as a Kitty graphics protocol sequence.
     *
     * @param ImageSource $image  Source image (PNG/JPEG/GIF)
     * @param int         $width  Target cell width
     * @param int|null    $height Target cell height (defaults to width/aspect-ratio)
     * @return string              APC sequence to transmit the image
     */
    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        $pngBytes = $this->ensurePng($image);
        $chunks   = $this->chunkPayload($pngBytes, KittyOptions::DEFAULT_CHUNK_BYTES);
        $total    = count($chunks);

        $out = Ansi::kittyGraphicsBegin([
            'c' => $width,
            'r' => $effectiveHeight,
            // f=100 declares the payload as PNG. An absent `f` means raw
            // RGBA pixels in the Kitty protocol — harmless while this
            // renderer emitted sixel-shaped DCS garbage, mandatory now
            // that the APC frames actually activate the graphics path.
            'f' => 100,
        ]);

        foreach ($chunks as $idx => $chunk) {
            $more = ($idx < $total - 1);
            $out .= Ansi::kittyGraphicsChunk($chunk, $more);
        }
        if ($total === 0) {
            // The final m=0 data chunk never ran — close the transaction.
            $out .= Ansi::kittyGraphicsEnd();
        }

        return $out;
    }

    public function name(): string
    {
        return 'kitty';
    }

    /**
     * Render with explicit Kitty protocol options.
     *
     * @param KittyOptions $opts  Use {@see KittyOptions::transmit()} for inline
     *                            transmission (a=T), {@see KittyOptions::place()}
     *                            for virtual-image placement (a=p), or chain
     *                            withUseVirtual(true) on transmit to store+place
     *                            in one step. Use withCompression(1) to zlib-compress
     *                            the PNG payload before base64-encoding, signalled
     *                            as the spec's `o=z` key (`f` stays the data format).
     */
    public function renderWithOptions(
        ImageSource $image,
        int $width,
        ?int $height,
        KittyOptions $opts,
    ): string {
        $optsArr   = $opts->toArray();

        if ($opts->isPlace()) {
            // Place is a one-shot control frame — it must NOT open a
            // chunked transmission (nothing would close it).
            return Ansi::kittyGraphicsControl([
                'a' => 'p',
                'i' => $optsArr['i'],
                'x' => $optsArr['x'],
                'y' => $optsArr['y'],
                'z' => $optsArr['z'],
            ]);
        }

        $pngBytes  = $this->ensurePng($image);
        $compress  = $opts->isCompressed();
        if ($compress) {
            $z = gzcompress($pngBytes);
            if ($z === false) {
                throw new \RuntimeException(Lang::t('renderer.gzcompress_failed'));
            }
            $payload = $z;
        } else {
            $payload = $pngBytes;
        }
        // F-11: chunk the raw payload — post-compression bytes when zlib is
        // on, PNG bytes otherwise — then base64 each slice. Sizing the
        // base64 string (the old shape) overshot the 4 KiB frame cap;
        // sizing the raw bytes bounds every frame before encoding.
        $chunks    = $this->chunkPayload($payload, $opts->chunkBytes() ?? KittyOptions::DEFAULT_CHUNK_BYTES);
        $total     = count($chunks);
        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());

        $action = $opts->useVirtual() ? 'p' : ($optsArr['a'] ?? 'T');

        $begin = $this->buildBegin([
            'a' => $action,
            'i' => $optsArr['i'],
            'z' => $optsArr['z'],
            // Compression rides 'o=z' (spec); 'f' keeps its constant data-
            // format meaning and buildBegin emits f=100 for every transmit.
            'o' => $optsArr['o'],
            'q' => 2,
            'c' => $width,
            'r' => $effectiveHeight > 0 ? $effectiveHeight : 1,
            's' => $optsArr['s'],
            'v' => $optsArr['v'],
        ]);

        $out = $begin;
        foreach ($chunks as $idx => $chunk) {
            $out .= Ansi::kittyGraphicsChunk($chunk, $idx < $total - 1);
        }
        if ($total === 0) {
            // The final m=0 data chunk never ran — close the transaction.
            $out .= Ansi::kittyGraphicsEnd();
        }

        return $out;
    }

    /** @internal */
    private function buildBegin(array $opts): string
    {
        return Ansi::kittyGraphicsBegin([
            'a' => $opts['a'] ?? 'T',
            'i' => $opts['i'] ?? null,
            'z' => $opts['z'] ?? null,
            'f' => $opts['f'] ?? 100,
            'o' => $opts['o'] ?? null,
            'q' => $opts['q'] ?? 2,
            'c' => $opts['c'] ?? null,
            'r' => $opts['r'] ?? null,
            's' => $opts['s'] ?? null,
            'v' => $opts['v'] ?? null,
            'x' => $opts['x'] ?? null,
            'y' => $opts['y'] ?? null,
        ]);
    }

    public function supportsAlpha(): bool
    {
        return true;
    }

    public function isInline(): bool
    {
        return false;
    }

    /**
     * Remove a previously rendered image by its numeric id.
     *
     * Uses Kitty protocol action 'd' (delete specific image).
     */
    public function delete(string $imageId): string
    {
        return Ansi::kittyGraphicsClear((int) $imageId);
    }

    /**
     * Render a single animation frame with a stable image id.
     *
     * The id is what {@see delete()} later targets, so calling
     * `delete($id)` followed by `renderFrame(..., $id)` yields a clean
     * per-frame redraw on Kitty-capable terminals.
     *
     * Internally calls {@see renderWithOptions()} with
     * {@see \SugarCraft\Image\KittyOptions::transmit($imageId)}.
     *
     * Mirrors charmbracelet/x/mosaic frame-render API (conceptual).
     */
    public function renderFrame(ImageSource $image, int $width, ?int $height, int $imageId): string
    {
        return $this->renderWithOptions(
            $image,
            $width,
            $height,
            KittyOptions::transmit($imageId),
        );
    }

    /**
     * Ensure PNG bytes, re-encoding if the source format is not PNG.
     *
     * Uses php://temp to handle GD builds that write to output instead of
     * returning a string from imagepng().
     *
     * @internal
     */
    private function ensurePng(ImageSource $image): string
    {
        // Trust the bytes, not the label: f=100 tells the terminal the payload is PNG.
        if ($image->isPng()) {
            return $image->bytes;
        }

        $src = @imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        $tmp = fopen('php://temp', 'w+b');
        try {
            $ok = imagepng($src, $tmp, 9);
            rewind($tmp);
            $bytes = stream_get_contents($tmp);
        } finally {
            fclose($tmp);
            imagedestroy($src);
        }

        if ($ok === false || $bytes === false || $bytes === '') {
            throw new \RuntimeException(Lang::t('renderer.gd_encode_failed'));
        }

        return $bytes;
    }

    /**
     * Split a RAW payload into base64 slices bounded per F-11: every
     * resulting data-chunk frame (envelope APC `ESC _ G` + `m=<0|1>` + ';' +
     * ST, 9 bytes) must stay within {@see KittyOptions::MAX_FRAME_BYTES}.
     *
     * Slices are {@see $chunkBytes} raw bytes — a multiple of 3 by the
     * options-boundary normalization — so no intermediate slice carries
     * base64 padding and the receiver's concatenated decode is byte-exact.
     *
     * Mirrors chafa's raw-byte chunking (chafa/internal/chafa-kitty-renderer.c
     * build_image_chunks: 510 raw bytes, 63 under Screen).
     *
     * @return list<string>
     */
    private function chunkPayload(string $payload, int $chunkBytes): array
    {
        if ($payload === '') {
            return [];
        }

        return array_map('base64_encode', str_split($payload, $chunkBytes));
    }
}
