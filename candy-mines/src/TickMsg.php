<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

use SugarCraft\Core\Msg;

/**
 * Fired at 1 Hz by the 'clock' tick subscription while a game's timer runs.
 *
 * The message carries no payload on purpose: {@see Game::elapsed()} reads the
 * wall clock at render time, so a tick only needs to force a repaint — the
 * clock value never enters the model. Because Program::reconcileSubscriptions()
 * diffs subscriptions by id (the produce closure is locked once armed), any
 * per-fire decision has to live in update(), not in the closure — the same
 * shape as candy-query's AdminTickMsg.
 *
 * Mirrors maxpaulus43/go-sweep — the live status-line timer.
 */
final readonly class TickMsg implements Msg
{
}
