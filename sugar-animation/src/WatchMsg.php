<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

use SugarCraft\Core\Msg;

/**
 * E-10: One poll of the {@see Player} watch seam. Armed by the Player itself
 * (init and every completed poll re-arm it at WATCH_POLL_SECONDS), consumed
 * by {@see Player::update()} — the sugar-animation twin of chicle's
 * run_watch re-stat cycle (tools/chafa/chafa.c:896-940), lifted from a
 * g_usleep busy-loop into the event loop the TEA runtime already owns.
 */
final class WatchMsg implements Msg
{
}
