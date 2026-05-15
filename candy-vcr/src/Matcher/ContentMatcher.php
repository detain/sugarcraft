<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Matcher;

use SugarCraft\Vcr\Event;

/**
 * Strict matcher that requires exact payload equality for same-kind events.
 *
 * Two events match when they share the same {@see EventKind} AND their
 * full payload arrays are identical. Timestamp differences are ignored.
 * This is the strictest built-in matcher and is appropriate when replay
 * must produce bit-identical recordings.
 *
 * Use {@see TimingTolerantMatcher} when timing drift must be tolerated,
 * or {@see PassthroughMatcher} when kind-only matching is sufficient.
 */
final class ContentMatcher implements EventMatcher
{
    public function matches(Event $recorded, Event $actual): bool
    {
        if ($recorded->kind !== $actual->kind) {
            return false;
        }

        return $recorded->payload === $actual->payload;
    }
}
