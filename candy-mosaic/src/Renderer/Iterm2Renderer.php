<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;

/**
 * iTerm2 / WezTerm inline image renderer via OSC 1337.
 *
 * Encodes the image as base64 PNG and emits a single OSC 1337
 * sequence. Terminals that support this protocol render the image
 * inline without any pixel-space manipulation on our side — they
 * handle aspect-ratio and scaling themselves.
 */
final class Iterm2Renderer implements Renderer
{
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
                // imagepng() with no path writes to STDOUT and returns bool —
                // capture the bytes via an output buffer instead (otherwise the
                // raw PNG is dumped to the terminal and the image is empty).
                ob_start();
                imagepng($img);
                $pngBytes = (string) ob_get_clean();
            } finally {
                imagedestroy($img);
            }
        }

        $base64 = base64_encode($pngBytes);

        return Ansi::iterm2InlineImage($base64, [
            'width'               => $width,
            'height'              => $effectiveHeight,
            'preserveAspectRatio' => true,
        ]);
    }

    public function name(): string
    {
        return 'iterm2';
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
     * Remove the most recently displayed iTerm2 / WezTerm image.
     *
     * iTerm2 does not support deleting by image id — "Pop" removes the
     * topmost image from the stack. The `$imageId` parameter is accepted
     * for interface compatibility but has no effect.
     */
    public function delete(string $imageId): string
    {
        return Ansi::iterm2Delete();
    }
}
