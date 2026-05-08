<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Core\Util\Color;

/**
 * A decoded image ready for rendering. Stores raw bytes, detected
 * format, and pixel dimensions. Immutable.
 */
final class ImageSource
{
    /**
     * @param string $bytes    Raw image bytes (PNG/JPEG/GIF)
     * @param string $format   MIME type: 'image/png', 'image/jpeg', 'image/gif'
     * @param int    $width    Pixel width
     * @param int    $height   Pixel height
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * Load from a file on disk.
     *
     * @throws \InvalidArgumentException  if the file does not exist or is not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(Lang::t('image_source.file_not_found', ['path' => $path]));
        }

        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \InvalidArgumentException(Lang::t('image_source.cannot_read', ['path' => $path]));
        }

        $info = @getimagesize($path);
        if ($info === false) {
            throw new \InvalidArgumentException(Lang::t('image_source.unsupported_format', ['path' => $path]));
        }

        $format = match ($info['mime']) {
            'image/png'  => 'image/png',
            'image/jpeg' => 'image/jpeg',
            'image/gif'  => 'image/gif',
            default      => throw new \InvalidArgumentException(
                Lang::t('image_source.unsupported_mime', ['mime' => $info['mime']])
            ),
        };

        // Read dimensions from GD so palette PNGs are already converted.
        $img = match ($format) {
            'image/png'  => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/gif'  => imagecreatefromgif($path),
        };

        if ($img === false) {
            throw new \RuntimeException(Lang::t('image_source.gd_load_failed', ['path' => $path]));
        }

        // Palette PNG → truecolor so PixelGrid always sees 24-bit pixels.
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $width  = imagesx($img);
        $height = imagesy($img);
        imagedestroy($img);

        return new self($bytes, $format, $width, $height);
    }

    /**
     * Load from raw bytes in memory.
     *
     * @throws \InvalidArgumentException  if the bytes are not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromString(string $bytes): self
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mosaic-');
        if ($tmp === false) {
            throw new \RuntimeException(Lang::t('image_source.temp_failed'));
        }
        try {
            file_put_contents($tmp, $bytes);
            return self::fromFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Load from an existing GD image resource.
     *
     * @param \GdImage $resource  Truecolor GD image (palette images are
     *                            automatically converted)
     * @param string   $format    MIME type hint: 'image/png', 'image/jpeg',
     *                            or 'image/gif'. Required because GD cannot
     *                            re-detect format from a resource.
     */
    public static function fromGd(\GdImage $resource, string $format): self
    {
        if (!imageistruecolor($resource)) {
            imagepalettetotruecolor($resource);
        }

        $width  = imagesx($resource);
        $height = imagesy($resource);

        // Re-encode to bytes for Kitty pass-through (preserves original format).
        $bytes = match ($format) {
            'image/png'  => imagepng($resource),
            'image/jpeg' => imagejpeg($resource, null, 100),
            'image/gif'  => imagegif($resource),
            default      => throw new \InvalidArgumentException(
                Lang::t('image_source.unsupported_mime', ['mime' => $format])
            ),
        };

        return new self($bytes, $format, $width, $height);
    }

    /**
     * Aspect ratio as a float (width / height).
     */
    public function aspectRatio(): float
    {
        return $this->height === 0 ? 1.0 : $this->width / $this->height;
    }
}
