<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Core\Msg;

/**
 * Delivered by {@see Toast::tickCmd()} when the soonest live alert reaches
 * its expiry: the host prunes ({@see Toast::pruneExpired()}), redraws, and
 * asks tickCmd() for the next wake-up.
 */
final class ToastTickMsg implements Msg
{
    /** @param float $at the expiry instant (seconds since epoch) the tick was scheduled for */
    public function __construct(public readonly float $at)
    {
    }
}
