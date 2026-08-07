<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;

/**
 * A per-frame registry that turns pixel-graphics blobs into tiling-safe cell
 * blocks and collects them for a {@see \SugarCraft\Core\View}'s image layer.
 *
 * This is the turnkey half of the image-overlay feature (the other half being
 * {@see ImageOverlay}, which the runtime drives). An app that wants real images
 * tiled in a text UI does just two things:
 *
 * ```php
 * $layer = new ImageLayer();
 * // wherever an image should sit, reserve its box and stash the bytes:
 * $cell = $mosaic->isInline() ? $blob : $layer->place($blob, $w, $h);
 * // …compose $cell into the frame like any other text…
 * return new View($frame, images: $layer->placements());
 * ```
 *
 * Identical bytes register once (deduped by content hash), so the same image
 * shown in several places shares one id and paints at every marker. The id space
 * is the PUA window ({@see ImageOverlay::MAX_IMAGES}); once exhausted, further
 * images get a blank block rather than a wrong one.
 *
 * Widgets that take the bytes *and* the id — a poster card that renders its own
 * marker, say — use {@see placeTracked()}, which returns both as a
 * {@see PlacedImage}:
 *
 * ```php
 * $placed = $layer->placeTracked($blob, $w, $h);
 * if ($placed->imageId !== null) {
 *     $card = $card->withImage($blob, $placed->imageId);
 * }
 * ```
 */
final class ImageLayer
{
    /** @var array<string, int> content hash → image id. */
    private array $idByDigest = [];

    /** @var array<int, ImagePlacement> image id → bytes + cell footprint. */
    private array $placementById = [];

    /**
     * Register $bytes and return a $width × $height marker block to drop in the
     * frame (or a blank block of the same size once the id space is full).
     * Deduplicates by content, so repeated bytes reuse their id.
     *
     * Frame-composition callers want exactly this: a string to concatenate. Use
     * {@see placeTracked()} instead when you also need the id that was assigned.
     */
    public function place(string $bytes, int $width, int $height): string
    {
        return $this->placeTracked($bytes, $width, $height)->marker;
    }

    /**
     * {@see place()}, but also reporting the overlay id the bytes were assigned.
     *
     * The id is a property of the content (dedup is by `xxh3` of $bytes), so a
     * repeat placement returns the id that content already holds — never a new
     * one. It is `null` only when the id space is exhausted, in which case the
     * returned marker is a blank block.
     *
     * Do not try to recover the id from `array_key_last(placements())`: a dedup
     * hit re-assigns an existing key without moving it, so that reports the
     * highest id rather than the one just placed, and the exhaustion branch
     * writes no placement at all, so it reports a stale unrelated id.
     */
    public function placeTracked(string $bytes, int $width, int $height): PlacedImage
    {
        $digest = hash('xxh3', $bytes);
        $id = $this->idByDigest[$digest] ??= count($this->idByDigest);

        if ($id >= ImageOverlay::MAX_IMAGES) {
            return new PlacedImage(self::blankBlock($width, $height), null);
        }

        $this->placementById[$id] = new ImagePlacement($bytes, $width, $height);

        return new PlacedImage(ImageOverlay::markerBlock($id, $width, $height), $id);
    }

    /**
     * The accumulated image layer (id → {@see ImagePlacement}) to hand to a
     * {@see \SugarCraft\Core\View}. The runtime paints only the markers a given
     * frame actually contains, so over-registering (e.g. images scrolled out of
     * view) is harmless.
     *
     * @return array<int, ImagePlacement>
     */
    public function placements(): array
    {
        return $this->placementById;
    }

    /**
     * Remove a placement by its image id.
     *
     * Allows the id space to be reclaimed when an image scrolls out of view.
     */
    public function removeById(int $imageId): void
    {
        unset($this->placementById[$imageId]);
    }

    public function isEmpty(): bool
    {
        return $this->placementById === [];
    }

    private static function blankBlock(int $width, int $height): string
    {
        return implode("\n", array_fill(0, max(1, $height), str_repeat(' ', max(1, $width))));
    }
}
