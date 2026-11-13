<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

/**
 * The per-frame result of an overlay animation: the foreground text as it
 * should be painted at this progress, plus the anchor-relative travel
 * offsets to add to the composite() call's xOffset/yOffset.
 *
 * PUBLIC value object (sugar-overlay-013): it is the typed return of
 * {@see Animation\Slide::applyFrame()} and what {@see Veil::animate()}
 * folds internally — the frame carries types precisely so Slide's edge
 * travel and the Fade/Scale no-offset arms cannot drift apart the way
 * three hand-spelled array literals did.
 */
final readonly class AnimationFrame
{
    public function __construct(
        public string $foreground,
        public int $verticalOffset = 0,
        public int $horizontalOffset = 0,
    ) {
    }

    /**
     * Compatibility adapter for the legacy array shape returned by
     * {@see Animation\Slide::apply()} (public since 1.x — the shape stays
     * until the 2.0 flip, this wraps it at the consumer).
     *
     * @param array{foreground: string, verticalOffset: int, horizontalOffset: int} $frame
     */
    public static function fromArray(array $frame): self
    {
        return new self(
            foreground: $frame['foreground'],
            verticalOffset: $frame['verticalOffset'],
            horizontalOffset: $frame['horizontalOffset'],
        );
    }
}
