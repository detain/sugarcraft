<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * The tick {@see Chat::subscriptions()} declares while the open session is
 * READ-ONLY because another TUI holds its lock (audit SES-3 residual): when it
 * lands, {@see Chat} retries the non-blocking lock, and on success reloads the
 * stored transcript and becomes writable.
 *
 * Payload-free: the session to retry is whichever one the Chat on screen has
 * open when the tick arrives, so a tick armed before a session switch can
 * never take the lock on the session that was left.
 */
final class SessionLockRetryMsg implements Msg
{
}
