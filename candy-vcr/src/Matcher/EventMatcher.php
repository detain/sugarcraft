<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Matcher;

use SugarCraft\Vcr\Event;

/**
 * Strategy interface for comparing a recorded {@see Event} against
 * an actual {@see Event} during replay.
 *
 * Implementations encode the policy for when a recorded event is
 * considered "matching" the actual event that was produced. This allows
 * flexible tolerance (timing drift, payload divergence, kind-only match)
 * without changing the core {@see \SugarCraft\Vcr\Player} dispatch loop.
 *
 * Mirrors charmbracelet/x/vcr MatcherFunc.
 */
interface EventMatcher
{
    /**
     * Decide whether `$recorded` and `$actual` are equivalent for
     * replay purposes.
     *
     * @param Event $recorded The event as it was stored in the cassette.
     * @param Event $actual   The event produced during the live replay run.
     *                          The `$actual->kind` determines which matcher
     *                          strategy to apply; matchers that ignore timing
     *                          or payload fields can simply return true when
     *                          the kinds match.
     * @return bool True if the events are considered a match, false otherwise.
     */
    public function matches(Event $recorded, Event $actual): bool;
}
