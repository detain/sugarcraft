<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Matcher;

use SugarCraft\Vcr\Event;

/**
 * Matcher that accepts events of the same kind within a timing window.
 *
 * Two events match when they share the same {@see EventKind} and their
 * timestamps differ by at most {@see $timingTolerance} seconds. This
 * is useful for CI environments where CPU scheduling variance may cause
 * replay events to arrive slightly earlier or later than recorded.
 *
 * @see PassthroughMatcher For kind-only matching without timing checks.
 */
final class TimingTolerantMatcher implements EventMatcher
{
    /**
     * @param float $timingTolerance Maximum allowed timestamp delta in seconds.
     *                               Defaults to 0.1 s (100 ms). A value of 0.0
     *                               makes this equivalent to exact timestamp
     *                               equality; a large value makes it effectively
     *                               a kind-only matcher.
     */
    public function __construct(
        private readonly float $timingTolerance = 0.1,
    ) {
        if ($timingTolerance < 0.0) {
            throw new \InvalidArgumentException(
                sprintf('TimingTolerantMatcher: timingTolerance must be non-negative, got %f', $timingTolerance),
            );
        }
    }

    public function matches(Event $recorded, Event $actual): bool
    {
        if ($recorded->kind !== $actual->kind) {
            return false;
        }

        return abs($recorded->t - $actual->t) <= $this->timingTolerance;
    }
}
