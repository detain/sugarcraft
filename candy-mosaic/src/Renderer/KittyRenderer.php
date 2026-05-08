<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
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

        // Use the stored bytes directly if already PNG, otherwise re-encode.
        if ($image->format === 'image/png') {
            $pngBytes = $image->bytes;
        } else {
            $img = imagecreatefromstring($image->bytes);
            if ($img === false) {
                throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
            }
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }
            try {
                $pngBytes = imagepng($img);
            } finally {
                imagedestroy($img);
            }
        }

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

    public function supportsAlpha(): bool
    {
        return true;
    }

    /**
     * Split base64 string into protocol-compliant chunks (max 4092 bytes).
     *
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
