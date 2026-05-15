<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Matcher;

use SugarCraft\Vcr\Event;

/**
 * Permissive matcher that only checks event kind.
 *
 * Two events match when they share the same {@see EventKind}, regardless
 * of timestamp or payload. This is useful when cassette replay must
 * tolerate programs that emit events in a different order or with
 * slightly different timing.
 *
 * Mirrors charmbracelet/x/vcr PassthroughMatcher (kind-only HTTP match).
 */
final class PassthroughMatcher implements EventMatcher
{
    public function matches(Event $recorded, Event $actual): bool
    {
        return $recorded->kind === $actual->kind;
    }
}
