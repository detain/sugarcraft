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
 * Two bounds hold at once: {@see CAPACITY} slots, and {@see BYTE_BUDGET} bytes
 * of decoded pixels (four per pixel). A handle larger than the whole budget
 * is not retained, so a very large sheet is decoded on each call instead of
 * being held in memory. PHP frees a GdImage with its last reference, so an
 * unretained handle is released when the caller returns.
 */
final class DecodedImageCache
{
    /** Slot count. Eight covers a sprite sheet and its neighbours without holding a video's worth of frames. */
    public const CAPACITY = 8;

    /** Retained decoded pixels, in bytes (32 MiB): well under the 128 MiB default memory_limit. */
    public const BYTE_BUDGET = 33_554_432;

    /**
     * Insertion-ordered: the first key is the least recently used.
     *
     * @var array<string, \GdImage>
     */
    private static array $entries = [];

    /**
     * Retained bytes per entry, keyed like {@see self::$entries}.
     *
     * @var array<string, int>
     */
    private static array $sizes = [];

    /** Sum of {@see self::$sizes}. */
    private static int $retained = 0;

    /** @internal Test seam — replaces {@see BYTE_BUDGET}; null restores it. reset() clears it. */
    private static ?int $budgetOverride = null;

    /** Decodes performed (cache misses) since the last {@see reset()}. */
    private static int $misses = 0;

    /**
     * The decoded, truecolour handle for $source. A hit refreshes the entry's
     * recency; a miss decodes once and evicts least recently used entries until
     * both bounds hold again.
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

        $bytes = imagesx($image) * imagesy($image) * 4;
        if ($bytes > self::budget()) {
            return $image;
        }

        self::$entries[$key] = $image;
        self::$sizes[$key]   = $bytes;
        self::$retained     += $bytes;
        while (count(self::$entries) > self::CAPACITY || self::$retained > self::budget()) {
            $victim = array_key_first(self::$entries);
            self::$retained -= self::$sizes[$victim];
            unset(self::$sizes[$victim], self::$entries[$victim]);
        }

        return $image;
    }

    /** Number of decodes performed since the last {@see reset()}; a hit adds nothing. */
    public static function misses(): int
    {
        return self::$misses;
    }

    /** Entries currently held (never more than {@see CAPACITY}, nor more than {@see BYTE_BUDGET} bytes). */
    public static function size(): int
    {
        return count(self::$entries);
    }

    /** Drop every entry, zero the decode counter and clear the test budget seam (test isolation). */
    public static function reset(): void
    {
        self::$entries        = [];
        self::$sizes          = [];
        self::$retained       = 0;
        self::$misses         = 0;
        self::$budgetOverride = null;
    }

    /**
     * @internal Test seam — bound the byte budget so the retention arms run on
     *           small images; null restores {@see BYTE_BUDGET}. reset() clears it.
     */
    public static function setByteBudgetForTesting(?int $bytes): void
    {
        self::$budgetOverride = $bytes;
    }

    private static function budget(): int
    {
        return self::$budgetOverride ?? self::BYTE_BUDGET;
    }

    private static function keyOf(ImageSource $source): string
    {
        return hash('xxh3', $source->bytes) . ':' . $source->format . ':' . $source->width . 'x' . $source->height;
    }
}
