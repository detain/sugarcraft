<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * The outcome of an {@see ImageLayer::placeTracked()} call: the marker block to
 * compose into the frame, plus the overlay id the layer assigned to the bytes.
 *
 * Widgets that only compose text (the common case) never need this — they use
 * {@see ImageLayer::place()} and get the marker string directly. This object
 * exists for callers that must name the id afterwards, e.g. handing it to a
 * poster widget's `withImage(bytes, id)` accessor or surgically clearing the
 * cells one specific image left behind.
 *
 * Two properties of `$imageId` are load-bearing:
 *
 * - It is `null` **exactly when** the PUA id space
 *   ({@see \SugarCraft\Core\ImageOverlay::MAX_IMAGES}) is exhausted. In that
 *   case `$marker` is a blank block of the requested size rather than a marker,
 *   because there is no id left to paint against. `null` never means "unknown";
 *   it means "this image will not be painted".
 * - It identifies the **content**, not the call. {@see ImageLayer} dedupes on
 *   `hash('xxh3', $bytes)`, so identical bytes always yield the same id no
 *   matter how many times, at what size, or in what order they are placed —
 *   and a repeat placement returns the id the content already had instead of a
 *   fresh one. Callers that previously keyed ids by something call-shaped (a
 *   URL, a requested cell size, a list index) must not assume that mapping
 *   survives: one id may be shared by every placement of the same bytes.
 */
final readonly class PlacedImage
{
    public function __construct(
        public string $marker,
        public ?int $imageId,
    ) {
    }
}
