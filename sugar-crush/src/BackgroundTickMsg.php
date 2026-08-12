<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg produced by the background-session poll subscription
 * (crush_feat.md section 5 E4).
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
 * Carries no payload: the supervisor is the state, this is only the clock.
 */
final class BackgroundTickMsg implements Msg
{
}
