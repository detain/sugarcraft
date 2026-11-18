<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Image\Concerns\ForceTrueColor;

/**
 * Process-wide LRU of decoded GD handles, keyed on the content of an
 * {@see ImageSource} (rank 72), so a sheet that is cropped once per tile
 * (rank 71) is decoded once instead of once per call.
 *
 * The key is the content hash of the bytes plus the declared format and
 * dimensions, not object identity: two ImageSource values built from the same
 * bytes share one decode, and an ImageSource rebuilt from a fetch does too.
 *
 * The handles are shared. Callers READ them (imagecrop, imagecopyresampled and
 * friends return new images and leave the source alone) and must never mutate
 * or destroy the handle they are given; the cache owns its lifetime.
 *
 * Capacity is a fixed slot count, not a byte budget: a decoded frame is the
 * source's own pixel count times four bytes, so {@see CAPACITY} large images
 * are the most this holds at once.
 */
final class DecodedImageCache
{
    /** Slot count. Eight covers a sprite sheet and its neighbours without holding a video's worth of frames. */
    public const CAPACITY = 8;

    /**
     * Insertion-ordered: the first key is the least recently used.
     *
     * @var array<string, \GdImage>
     */
    private static array $entries = [];

    /** Decodes performed (cache misses) since the last {@see reset()}. */
    private static int $misses = 0;

    /**
     * The decoded, truecolour handle for $source. A hit refreshes the entry's
     * recency; a miss decodes once and may evict the least recently used slot.
     *
     * @throws \RuntimeException when GD cannot decode the bytes
     */
    public static function gd(ImageSource $source): \GdImage
    {
        $key = self::keyOf($source);

        if (isset(self::$entries[$key])) {
            $image = self::$entries[$key];
            unset(self::$entries[$key]);
            self::$entries[$key] = $image;

            return $image;
        }

        $image = @imagecreatefromstring($source->bytes);
        if ($image === false) {
            throw new \RuntimeException(Lang::t('image_source.gd_load_failed_from_string'));
        }
        ForceTrueColor::convert($image);
        self::$misses++;

        self::$entries[$key] = $image;
        while (count(self::$entries) > self::CAPACITY) {
            array_shift(self::$entries);
        }

        return $image;
    }

    /** Number of decodes performed since the last {@see reset()}; a hit adds nothing. */
    public static function misses(): int
    {
        return self::$misses;
    }

    /** Entries currently held (never more than {@see CAPACITY}). */
    public static function size(): int
    {
        return count(self::$entries);
    }

    /** Drop every entry and zero the decode counter (test isolation). */
    public static function reset(): void
    {
        self::$entries = [];
        self::$misses  = 0;
    }

    private static function keyOf(ImageSource $source): string
    {
        return hash('xxh3', $source->bytes) . ':' . $source->format . ':' . $source->width . 'x' . $source->height;
    }
}
