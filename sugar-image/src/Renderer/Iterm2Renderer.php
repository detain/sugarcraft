<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\Codec\TiffUncompressed;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\Support\GdAvailability;

/**
 * iTerm2 / WezTerm inline image renderer via OSC 1337.
 *
 * Encodes the image as base64 PNG and emits a single OSC 1337
 * sequence. Terminals that support this protocol render the image
 * inline without any pixel-space manipulation on our side — they
 * handle aspect-ratio and scaling themselves.
 *
 * F-13 adds {@see renderTiff()}: chafa's GD-free container (an
 * uncompressed single-strip TIFF) selectable with an explicit raw-RGBA
 * buffer, so transmission works without imagepng() entirely.
 */
final class Iterm2Renderer implements Renderer
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;
    use \SugarCraft\Image\Concerns\ResolvesRawPixelsTrait;

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        // Use the stored bytes directly if they really are PNG (checked by
        // signature, not by the format label), otherwise re-encode.
        if ($image->isPng()) {
            $pngBytes = $image->bytes;
        } else {
            // F-13: without GD the re-encode is impossible — refuse cleanly
            // (and see renderTiff() for the raw-buffer way around it).
            if (!GdAvailability::available()) {
                throw new \RuntimeException(Lang::t('renderer.gd_unavailable'));
            }
            $img = @imagecreatefromstring($image->bytes);
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
                $ok = imagepng($img);
                $pngBytes = (string) ob_get_clean();
            } finally {
                imagedestroy($img);
            }
            if ($ok === false || $pngBytes === '') {
                throw new \RuntimeException(Lang::t('renderer.gd_encode_failed'));
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

    /**
     * Transmit as an uncompressed TIFF (`type=tiff`) — the GD-free wire format.
     *
     * F-13, per chafa's iTerm2 path (chafa/internal/chafa-iterm2-renderer.c
     * wraps raw RGBA8_UNASSOCIATED quads in an 11-entry IFD TIFF and never
     * calls an image library): the payload comes from `$rgba` directly —
     * no GD — or from a GD decode when omitted. iTerm2 composites on the
     * terminal's own background color when the stream carries
     * unassociated alpha (chafa-iterm2-renderer.c:266-275 notes the two
     * valid alpha paths); flatten against a background BEFORE encoding if
     * that is not the desired look.
     *
     * {@see render()} keeps its historical base64-PNG envelope byte-for-
     * byte; this is the explicit opt-in sibling.
     */
    public function renderTiff(ImageSource $image, int $width, ?int $height = null, ?string $rgba = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);
        $tiffBytes       = TiffUncompressed::encode($this->resolveRgba($image, $rgba), $image->width, $image->height);

        return Ansi::iterm2InlineImage(base64_encode($tiffBytes), [
            'type'                => 'tiff',
            'width'               => $width,
            'height'              => $effectiveHeight,
            'preserveAspectRatio' => true,
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
