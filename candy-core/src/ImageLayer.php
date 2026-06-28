<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A per-frame registry that turns pixel-graphics blobs into tiling-safe cell
 * blocks and collects them for a {@see View}'s image layer.
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
     */
    public function place(string $bytes, int $width, int $height): string
    {
        $digest = hash('xxh3', $bytes);
        $id = $this->idByDigest[$digest] ??= count($this->idByDigest);

        if ($id >= ImageOverlay::MAX_IMAGES) {
            return self::blankBlock($width, $height);
        }

        $this->placementById[$id] = new ImagePlacement($bytes, $width, $height);

        return ImageOverlay::markerBlock($id, $width, $height);
    }

    /**
     * The accumulated image layer (id → {@see ImagePlacement}) to hand to a
     * {@see View}. The runtime paints only the markers a given frame actually
     * contains, so over-registering (e.g. images scrolled out of view) is
     * harmless.
     *
     * @return array<int, ImagePlacement>
     */
    public function placements(): array
    {
        return $this->placementById;
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
