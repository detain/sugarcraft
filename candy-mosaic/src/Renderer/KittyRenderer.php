<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\KittyOptions;
use SugarCraft\Mosaic\Lang;
use SugarCraft\Mosaic\PixelGrid;

/**
 * Kitty graphics protocol renderer via chunked APC sequences.
 *
 * Encodes the image as base64 PNG and transmits it in 4092-byte
 * chunks (leaving room for base64 padding overhead) per the Kitty
 * graphics protocol spec. Terminals handle aspect-ratio and scaling.
 */
final class KittyRenderer implements Renderer
{
    private const CHUNK_SIZE = 4092;

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
        if ($width <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_width', ['width' => $width]));
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('renderer.invalid_height', ['height' => $height]));
        }

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        $pngBytes = $this->ensurePng($image);
        $base64 = base64_encode($pngBytes);
        $chunks = $this->chunk($base64);
        $total  = count($chunks);

        $out = Ansi::kittyGraphicsBegin([
            'c' => $width,
            'r' => $effectiveHeight,
        ]);

        foreach ($chunks as $idx => $chunk) {
            $more = ($idx < $total - 1);
            $out .= Ansi::kittyGraphicsChunk($chunk, $more);
        }

        $out .= Ansi::kittyGraphicsEnd();

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
     *                            in one step. Use withCompression(1) to gzip
     *                            the PNG payload before base64-encoding (f=1).
     */
    public function renderWithOptions(
        ImageSource $image,
        int $width,
        ?int $height,
        KittyOptions $opts,
    ): string {
        if ($opts->isPlace()) {
            return $this->buildBegin([
                'a' => 'p',
                'i' => $opts->toArray()['i'],
                'x' => $opts->toArray()['x'],
                'y' => $opts->toArray()['y'],
            ]);
        }

        $pngBytes  = $this->ensurePng($image);
        $compress  = ($opts->toArray()['f'] ?? 100) === 1;
        $base64    = $compress ? base64_encode(gzcompress($pngBytes)) : base64_encode($pngBytes);
        $chunks    = $this->chunk($base64);
        $total     = count($chunks);
        $optsArr   = $opts->toArray();
        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());

        $action = $opts->useVirtual() ? 'p' : ($optsArr['a'] ?? 'T');

        $begin = $this->buildBegin([
            'a' => $action,
            'i' => $optsArr['i'],
            'z' => $optsArr['z'],
            'f' => $optsArr['f'] ?? 100,
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
        $out .= Ansi::kittyGraphicsEnd();

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
     * {@see \SugarCraft\Mosaic\KittyOptions::transmit($imageId)}.
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
        if ($image->format === 'image/png') {
            return $image->bytes;
        }

        $src = imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        $tmp = fopen('php://temp', 'w+b');
        try {
            imagepng($src, $tmp, 9);
            rewind($tmp);
            $bytes = stream_get_contents($tmp);
        } finally {
            fclose($tmp);
            imagedestroy($src);
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private function chunk(string $base64): array
    {
        $chunks = [];
        $offset = 0;
        $len    = strlen($base64);

        while ($offset < $len) {
            $chunks[] = substr($base64, $offset, self::CHUNK_SIZE);
            $offset  += self::CHUNK_SIZE;
        }

        return $chunks;
    }
}
