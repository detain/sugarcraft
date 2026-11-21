<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * Internal Msg produced by the background-session poll subscription
 * (section 5 E4).
 *
 * `BackgroundSupervisor::tick()` is what promotes a silent child process to
 * `Stalled` and back, but nothing drives it: heartbeats arrive on the
 * supervisor's socket, not on the TUI's event loop, so without a periodic
 * wake-up a background session's status is only ever re-read by accident.
 * {@see Chat::subscriptions()} declares a tick subscription that emits this
 * Msg while - and only while - there is something to poll, and
 * {@see Chat::update()} turns it into the supervisor call plus a
 * status-change diff.
 *
 * The one flag is {@see $rewake}: a run that registers background work queues
 * a tick carrying it, and that tick re-queues itself while a stop waits on the
 * daemon, so each rung is answered on a short interval instead of one two-second
 * poll per rung. {@see $chain} names the rewake chain the tick belongs to, so
 * overlapping stops share one chain instead of each tick starting its own.
 */
final class BackgroundTickMsg implements Msg
{
    /**
     * @param bool     $rewake true when this tick keeps itself alive while a stop is in
     *                         flight; the subscription's ticks carry false
     * @param int|null $chain  the rewake chain a re-queued tick continues; null on a
     *                         tick a stop or spawn queues
     */
    public function __construct(public readonly bool $rewake = false, public readonly ?int $chain = null)
    {
    }
}
