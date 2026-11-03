<?php

declare(strict_types=1);

namespace SugarCraft\Overlay;

/**
 * The per-frame result of an overlay animation: the foreground text as it
 * should be painted at this progress, plus the anchor-relative travel
 * offsets to add to the composite() call's xOffset/yOffset.
 *
 * @internal Implementation detail of {@see Veil::animate()} — the public
 * animation surface is the AnimationKind enum, not this value object.
 * It exists so the animation results carry types instead of an untyped
 * 3-key array shape whose keys drift silently between Slide's edge travel
 * and the Fade/Scale no-offset arms.
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
     * Adapt the legacy array shape returned by {@see Animation\Slide::apply()}
     * (public API since 1.x — the shape stays, this wraps it at the consumer).
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
