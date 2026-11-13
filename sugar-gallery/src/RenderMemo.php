<?php

declare(strict_types=1);

namespace SugarCraft\Gallery;

/**
 * The one eviction law shared by the library's two render memos.
 *
 * `PosterCard` memoizes fitted poster/image/skeleton body rows; `PosterGrid`
 * memoizes boxed cells. Both are process-wide key→bytes maps with the same
 * lifecycle problem: the working set is one screenful of cells, re-rendered
 * every frame, but a long-lived program (a user scrolling a big library)
 * inserts one new key per new content×geometry until memory says stop. The
 * remedy both memos want is identical — when the map reaches the cap, drop
 * the OLDEST HALF in insertion order, keeping the survivors keyed by their
 * original keys (`array_slice(..., use_keys: true)`) so a survivor's next
 * frame is still a hit and an evicted key simply recomputes byte-identically.
 * Two copies of that sentence were two drift hazards (sugar-gallery-046);
 * it now has one home, and the cap has one name.
 *
 * The KEY SHAPES deliberately stay per-memo — {@see PosterCard::renderMemoKey()}
 * and the grid's box keys differ by purpose and must not be unified, or a
 * card body and a boxed cell could collide onto each other's bytes.
 *
 * Not public API. The two memos alias {@see CACHE_CAP} through their own
 * private constants so each class still names its own bound (the README's
 * cache-levers paragraph quotes those names); external code has no reason to
 * reach in — the evictor only ever runs at a memo's insertion point.
 *
 * @internal
 */
final class RenderMemo
{
    /**
     * 2,048 entries ≈ dozens of full 1080p grid frames of distinct content
     * resident at once; anything past that is a backlog the next frames have
     * already forgotten, and halving (rather than clearing) keeps the live
     * working set warm through the eviction.
     */
    public const CACHE_CAP = 2048;

    /**
     * Apply the oldest-half eviction if (and only if) the memo is at cap.
     *
     * Pure: takes the memo's current array, returns the array it should carry
     * forward — callers write the result back to their static/by-reference
     * storage before inserting the new key.
     *
     * @param array<array-key, mixed> $cache
     * @return array<array-key, mixed>
     */
    public static function evictIfFull(array $cache): array
    {
        if (count($cache) < self::CACHE_CAP) {
            return $cache;
        }

        return array_slice($cache, self::CACHE_CAP >> 1, null, true);
    }
}
