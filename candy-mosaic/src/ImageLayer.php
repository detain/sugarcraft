<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\ImagePlacement;
use SugarCraft\Mosaic\Renderer\Renderer;

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
 *
 * Scrolling UIs additionally need viewport windowing: once images scroll out
 * of view their terminal-side allocations should be freed. Give the layer the
 * active {@see Renderer} and every placement is tracked under a
 * {@see digestFor()} content+size window key; when the visible set changes,
 * {@see releaseAllExcept()} drops everything outside the window and hands back
 * the per-id delete escape sequences to emit ({@see release()} for the single
 * id case). Released ids stay claimed by their content (dedup survives a
 * release, matching {@see removeById()}), so a poster scrolled back into view
 * re-registers under the id the markers already reference.
 */
final class ImageLayer
{
    /** @var array<string, int> content hash → image id. */
    private array $idByDigest = [];

    /** @var array<int, ImagePlacement> image id → bytes + cell footprint. */
    private array $placementById = [];

    /** @var array<string, int> window digest ({@see digestFor()}) → image id. */
    private array $idByWindowDigest = [];

    /** @var array<int, string> image id → its currently tracked window digest. */
    private array $windowDigestById = [];

    public function __construct(
        private readonly ?Renderer $renderer = null,
    ) {}

    /**
     * The renderer delete sequences are drawn from, or null when the layer
     * was constructed without one ({@see release()}/{@see releaseAllExcept()}
     * then still un-register placements but report empty sequences).
     */
    public function renderer(): ?Renderer
    {
        return $this->renderer;
    }

    /**
     * Window key for a placement: content hash over bytes + cell footprint.
     *
     * Same bytes at different sizes are different allocations to the
     * terminal, so the size belongs in the digest — this is the identity a
     * scrolling viewport keys on ({@see imageIdForDigest()}). The layer
     * tracks at most one footprint per id (the most recently placed one —
     * see {@see placeTracked()}), so a check-then-place viewport never sees
     * a digest claiming a placement size the layer no longer holds.
     */
    public static function digestFor(string $bytes, int $width, int $height): string
    {
        return hash('xxh3', $bytes . ':' . $width . ':' . $height);
    }

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
     *
     * Window tracking is last-footprint-wins: placing the same bytes at a new
     * size replaces the id's tracked {@see digestFor()} entry (the placement
     * slot itself has always held only the most recent footprint), so
     * {@see imageIdForDigest()} mirrors what the layer and terminal actually
     * hold right now.
     */
    public function placeTracked(string $bytes, int $width, int $height): PlacedImage
    {
        $digest = hash('xxh3', $bytes);
        $id = $this->idByDigest[$digest] ??= count($this->idByDigest);

        if ($id >= ImageOverlay::MAX_IMAGES) {
            return new PlacedImage(self::blankBlock($width, $height), null);
        }

        $this->placementById[$id] = new ImagePlacement($bytes, $width, $height);
        $this->trackWindowDigest($id, self::digestFor($bytes, $width, $height));

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
     * The content keeps its id (dedup is content-addressed), so re-placing
     * the same bytes returns that id — but the bytes must be re-sent to the
     * terminal. No delete sequence is emitted; use {@see release()} when the
     * terminal-side allocation must be freed too. The removed image's window
     * digests stop being tracked either way — a digest maps to a placement
     * that exists now, not one that existed.
     */
    public function removeById(int $imageId): void
    {
        unset($this->placementById[$imageId]);
        $this->forgetWindowDigests($imageId);
    }

    /**
     * Every window digest currently tracked (one per distinct bytes+size
     * combination that has been placed), in first-seen order.
     *
     * @return list<string>
     */
    public function trackedDigests(): array
    {
        return array_keys($this->idByWindowDigest);
    }

    /**
     * The image id a window digest is currently placed under, or null when it
     * has never been placed, was since removed/released, or the id was
     * re-placed at a different footprint (last-footprint-wins, see
     * {@see placeTracked()}). Lets a viewport check its scratch digest
     * against the layer before deciding to re-fetch/re-render — a null here
     * after a size change is the layer telling you to re-place.
     */
    public function imageIdForDigest(string $digest): ?int
    {
        return $this->idByWindowDigest[$digest] ?? null;
    }

    /**
     * Free one image: drop its placement (and its window-digest tracking) and
     * return the escape sequence that deletes its terminal-side allocation.
     * Unknown id → no-op, empty string.
     */
    public function release(int $imageId): string
    {
        if (!isset($this->placementById[$imageId])) {
            return '';
        }

        unset($this->placementById[$imageId]);
        $this->forgetWindowDigests($imageId);

        return $this->deleteSequence($imageId);
    }

    /**
     * Viewport windowing: free every placement whose id is NOT in $keepIds
     * and return the delete sequences to emit, keyed by released image id.
     *
     * The scrolled-frame primitive — compute the visible ids, call this,
     * write the returned values in order, and the terminal holds exactly the
     * window. Ids in $keepIds that were never placed are simply absent from
     * the result; nothing throws.
     *
     * @param list<int> $keepIds
     * @return array<int, string> released image id → delete sequence
     */
    public function releaseAllExcept(array $keepIds): array
    {
        $keep = array_flip($keepIds);
        $released = [];

        foreach (array_keys($this->placementById) as $id) {
            if (isset($keep[$id])) {
                continue;
            }
            unset($this->placementById[$id]);
            $this->forgetWindowDigests($id);
            $released[$id] = $this->deleteSequence($id);
        }

        return $released;
    }

    /**
     * Register $id's current window footprint, superseding (and un-tracking)
     * whatever footprint the id held before — the placement slot is
     * single-valued, so the digest index must be too.
     */
    private function trackWindowDigest(int $imageId, string $digest): void
    {
        $previous = $this->windowDigestById[$imageId] ?? null;
        if ($previous !== null && $previous !== $digest) {
            unset($this->idByWindowDigest[$previous]);
        }

        $this->idByWindowDigest[$digest] = $imageId;
        $this->windowDigestById[$imageId] = $digest;
    }

    /**
     * Drop the window digest pointing at a now-gone placement, so
     * {@see imageIdForDigest()} never hands back an id the terminal no longer
     * holds. O(1) via the reverse index — cheap even for a full-window
     * {@see releaseAllExcept()} on a saturated id space. Content-addressed
     * dedup ($idByDigest) is deliberately untouched: the bytes keep their id
     * for re-placement.
     */
    private function forgetWindowDigests(int $imageId): void
    {
        $digest = $this->windowDigestById[$imageId] ?? null;
        if ($digest === null) {
            return;
        }

        unset($this->idByWindowDigest[$digest], $this->windowDigestById[$imageId]);
    }

    /**
     * Protocol delete sequence for an image id — empty when the layer has no
     * renderer (or the protocol has no delete mechanism, like Sixel).
     */
    private function deleteSequence(int $imageId): string
    {
        return $this->renderer?->delete((string) $imageId) ?? '';
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
