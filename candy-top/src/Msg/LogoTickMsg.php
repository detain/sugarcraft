<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;

/**
 * The logo animation tick. `$epoch` is the App's logo-generation counter
 * (bumped by every re-pick): a tick armed under a superseded pick must
 * retire itself without re-arming, because candy-core ticks are one-shot
 * and cannot be cancelled, only outdated. `$time` is the wall clock at fire
 * time — the frame index is its phase in the ArtLoop cycle, so a late tick
 * lands on the correct frame instead of drifting one step per delay.
 */
final class LogoTickMsg implements Msg
{
    public function __construct(
        public readonly int $epoch,
        public readonly float $time,
    ) {
    }
}
