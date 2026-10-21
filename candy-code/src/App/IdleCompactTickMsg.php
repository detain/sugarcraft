<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * Internal Msg produced by the idle auto-compaction watch (CTX-5,
 * `compaction.idleAuto`).
 *
 * {@see \SugarCraft\Code\App\App::subscriptions()} declares a tick
 * subscription while (and only while) {@see Chat::shouldWatchIdleCompaction()}
 * says the session could fold, and {@see Chat::handleIdleCompactTick()} turns
 * each tick into the verdict: still idle, still over tier, still nobody
 * watching — then buy the summaries.
 *
 * Carries no payload: the transcript and the clock live on the Chat, this is
 * only the clock. That also makes the arm-time produce closure stateless,
 * which matters because sugar-core's Program keeps the closure it installed
 * first for as long as the subscription id is wanted — a closure that read
 * live state to DECIDE whether to emit would be frozen at arm time, which is
 * exactly the E655 lesson the agent-view tick already documents.
 */
final class IdleCompactTickMsg implements Msg
{
}
